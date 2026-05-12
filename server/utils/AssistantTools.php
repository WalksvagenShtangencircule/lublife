<?php

/**
 * Безопасные read-only запросы для ассистента (DeepSeek / MCP).
 * Без произвольного SQL из текста пользователя — только именованные операции.
 */

if (!function_exists("assistant_tools_flat_ids_for_house")) {

    function assistant_tools_flat_ids_for_house($db, int $houseId): array {
        if ($houseId <= 0) {
            return [];
        }
        $rows = $db->get(
            "select house_flat_id from houses_flats where address_house_id = :h",
            ["h" => $houseId],
            [],
            ["silent"]
        );
        if ($rows === false || !is_array($rows)) {
            return [];
        }
        return array_map("intval", array_column($rows, "house_flat_id"));
    }

    function assistant_tools_flat_filter_sql(array $flatIds): string {
        if (!count($flatIds)) {
            return "1=0";
        }
        $flatIds = array_values(array_unique(array_filter($flatIds)));
        return "flat_id in (" . implode(",", $flatIds) . ")";
    }

    /**
     * @return array<string, mixed>
     */
    function assistant_tools_ch_select(array $config, string $query): ?array {
        $c = @$config["clickhouse"];
        if (!$c || !@$c["host"]) {
            return null;
        }
        $host = $c["host"];
        $port = @$c["port"] ?: 8123;
        $user = @$c["username"] ?: "default";
        $pass = @$c["password"] ?: "";

        $curl = curl_init();
        $headers = [];
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Content-Type: text/plain; charset=UTF-8",
            "X-ClickHouse-User: {$user}",
            "X-ClickHouse-Key: {$pass}",
        ]);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION,
            function ($curl, $header) use (&$headers) {
                $len = strlen($header);
                $header = explode(":", $header, 2);
                if (count($header) < 2) {
                    return $len;
                }
                $headers[strtolower(trim($header[0]))][] = trim($header[1]);
                return $len;
            }
        );
        // Для быстрого UX режем долгие CH-запросы жёстче.
        $body = trim($query) . " SETTINGS max_execution_time = 7, max_result_rows = 300000 FORMAT JSON";
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_URL, "http://{$host}:{$port}/");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($curl);
        curl_close($curl);
        if (@$headers["x-clickhouse-exception-code"]) {
            error_log("assistant CH: " . $raw);
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return null;
        }
        return $j["data"] ?? null;
    }

    /**
     * @param object $db PDOExt
     * @return array<string, mixed>
     */
    function assistant_tools_run(string $name, array $args, $db, array $config): array {
        switch ($name) {
            case "resolve_house":
                return assistant_tool_resolve_house($db, $args);
            case "count_keys_for_flat":
                return assistant_tool_count_keys_for_flat($db, $args);
            case "flats_count":
                return assistant_tool_flats_count($db, $args);
            case "mobile_users_stats":
                return assistant_tool_mobile_users_stats($db, $config, $args);
            case "mobile_access_funnel":
                return assistant_tool_mobile_access_funnel($db, $config, $args);
            case "active_subscribers_for_house":
                return assistant_tool_active_subscribers_for_house($db, $args);
            case "subscriber_lookup":
                return assistant_tool_subscriber_lookup($db, $args);
            case "plog_events_list":
                return assistant_tool_plog_events_list($db, $config, $args);
            case "device_user_agents_for_house":
                return assistant_tool_device_user_agents_for_house($db, $args);
            case "rfid_events_in_period":
                return assistant_tool_rfid_events_in_period($db, $config, $args);
            case "flat_activity_in_house":
                return assistant_tool_flat_activity_in_house($db, $config, $args);
            case "flat_info":
                return assistant_tool_flat_info($db, $args);
            case "house_entrances_list":
                return assistant_tool_house_entrances_list($db, $args);
            case "house_domophones_list":
                return assistant_tool_house_domophones_list($db, $args);
            case "blocked_flats":
                return assistant_tool_blocked_flats($db, $args);
            case "rfid_lookup":
                return assistant_tool_rfid_lookup($db, $args);
            case "all_houses_list":
                return assistant_tool_all_houses_list($db, $args);
            default:
                return ["error" => "unknown_tool", "name" => $name];
        }
    }

    function assistant_tool_plog_event_label(int $e): string {
        static $m = [
            1 => "пропущенный_звонок",
            2 => "ответ_на_звонок",
            3 => "открыто_ключом_RFID",
            4 => "открыто_из_мобильного_приложения",
            5 => "открыто_по_лицу",
            6 => "открыто_кодом",
            7 => "открытие_ворот_по_звонку",
            9 => "открыто_транспорт",
        ];
        return $m[$e] ?? ("событие_" . $e);
    }

    /**
     * Нормализация телефона для сравнения с plog.phones JSON user_phone.
     */
    function assistant_tools_normalize_phone(string $phone): string {
        return preg_replace("/[^0-9]/", "", $phone);
    }

    /**
     * Для РФ: приводит строку из цифр к национальному виду без ведущей 7/8 (10 цифр, обычно 9…).
     * Позволяет сопоставить 7915…, 8915… и 915… с записью в БД в любом из этих форматов.
     */
    function assistant_tools_ru_mobile_canonical_tail(string $digitsOnly): string {
        $d = preg_replace("/[^0-9]/", "", $digitsOnly);
        if ($d === "") {
            return "";
        }
        if (strlen($d) === 11 && ($d[0] === "7" || $d[0] === "8")) {
            return substr($d, 1);
        }
        return $d;
    }

    function assistant_tool_resolve_house($db, array $args): array {
        $search = isset($args["search"]) ? trim((string) $args["search"]) : "";
        if (mb_strlen($search) < 2) {
            return ["error" => "search_too_short", "hint" => "Минимум 2 символа в строке поиска."];
        }
        $like = "%" . $search . "%";
        $rows = $db->get(
            "select address_house_id as \"houseId\", house_full as \"houseFull\", house as \"houseNumber\"
             from addresses_houses
             where house_full ilike :q or cast(house as varchar) ilike :q
             order by house_full
             limit 25",
            ["q" => $like],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }
        foreach ($rows as &$r) {
            $r["_url"] = "?#addresses.houses&houseId=" . $r["houseId"];
        }
        unset($r);
        return ["houses" => $rows, "count" => count($rows)];
    }

    function assistant_tool_count_keys_for_flat($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $flat = isset($args["flat_number"]) ? trim((string) $args["flat_number"]) : "";
        if ($houseId <= 0 || $flat === "") {
            return ["error" => "invalid_params", "need" => ["house_id", "flat_number"]];
        }
        $r = $db->get(
            "select count(*) as c
             from houses_rfids r
             inner join houses_flats f on f.house_flat_id = r.access_to and r.access_type = 2
             where f.address_house_id = :hid and trim(cast(f.flat as varchar)) = :flat",
            ["hid" => $houseId, "flat" => $flat],
            ["c" => "count"],
            ["fieldlify"]
        );
        if ($r === false) {
            return ["error" => "db_error"];
        }
        return ["house_id" => $houseId, "flat_number" => $flat, "keys_registered" => (int) $r];
    }

    function assistant_tool_device_user_agents_for_house($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $limit = isset($args["limit"]) ? min(50, max(1, (int) $args["limit"])) : 25;
        if ($houseId <= 0) {
            return ["error" => "invalid_house_id"];
        }
        $rows = $db->get(
            "select coalesce(d.ua, '') as ua, count(*)::int as cnt
             from houses_subscribers_devices d
             inner join houses_subscribers_mobile m on m.house_subscriber_id = d.house_subscriber_id
             inner join houses_flats_subscribers fs on fs.house_subscriber_id = m.house_subscriber_id
             inner join houses_flats f on f.house_flat_id = fs.house_flat_id
             where f.address_house_id = :hid
             and coalesce(d.ua, '') <> ''
             group by d.ua
             order by cnt desc
             limit " . (int) $limit,
            ["hid" => $houseId],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }
        $totalUa = $db->get(
            "select count(*)::int as c
             from (
                select coalesce(d.ua, '') as ua
                from houses_subscribers_devices d
                inner join houses_subscribers_mobile m on m.house_subscriber_id = d.house_subscriber_id
                inner join houses_flats_subscribers fs on fs.house_subscriber_id = m.house_subscriber_id
                inner join houses_flats f on f.house_flat_id = fs.house_flat_id
                where f.address_house_id = :hid and coalesce(d.ua, '') <> ''
                group by d.ua
             ) t",
            ["hid" => $houseId],
            ["c" => "count"],
            ["fieldlify", "silent"]
        );
        return [
            "house_id" => $houseId,
            "total_unique_user_agents" => $totalUa !== false ? (int) $totalUa : count($rows),
            "list_limit" => $limit,
            "list_returned" => count($rows),
            "user_agents" => $rows
        ];
    }

    function assistant_tool_rfid_events_in_period($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $rfid = isset($args["rfid"]) ? strtoupper(preg_replace("/[^0-9A-F]/i", "", (string) $args["rfid"])) : "";
        $since = isset($args["since_unix"]) ? (int) $args["since_unix"] : 0;
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : 0;
        $daysBack = isset($args["days_back"]) ? max(1, min(90, (int) $args["days_back"])) : 0;
        if ($houseId <= 0 || strlen($rfid) < 4) {
            return ["error" => "invalid_params", "need" => ["house_id", "rfid"]];
        }
        if ($since <= 0 || $until <= 0 || $until < $since) {
            $until = time();
            $since = $until - ($daysBack > 0 ? $daysBack : 7) * 86400;
        }
        $flatIds = assistant_tools_flat_ids_for_house($db, $houseId);
        $ff = assistant_tools_flat_filter_sql($flatIds);
        $rfidEsc = str_replace("'", "''", $rfid);
        $q = "
            select count(*) as cnt
            from plog
            where not hidden
            and date >= " . (int) $since . "
            and date <= " . (int) $until . "
            and (" . $ff . ")
            and upper(replaceRegexpAll(coalesce(rfid, ''), '[^0-9A-F]', '')) = '" . $rfidEsc . "'
        ";
        $data = assistant_tools_ch_select($config, $q);
        if ($data === null) {
            return ["error" => "clickhouse_unavailable_or_query_failed", "hint" => "Проверьте ClickHouse и таблицу plog."];
        }
        $cnt = isset($data[0]["cnt"]) ? (int) $data[0]["cnt"] : 0;
        return [
            "house_id" => $houseId,
            "rfid" => $rfid,
            "since_unix" => $since,
            "until_unix" => $until,
            "event_count" => $cnt,
        ];
    }

    /**
     * Активность по квартирам (flat_id в plog): топ квартир по числу событий за период.
     */
    function assistant_tool_flat_activity_in_house($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $since = isset($args["since_unix"]) ? (int) $args["since_unix"] : 0;
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : 0;
        $daysBack = isset($args["days_back"]) ? max(1, min(90, (int) $args["days_back"])) : 0;
        $topLimit = isset($args["top_limit"]) ? max(5, min(100, (int) $args["top_limit"])) : 20;
        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }
        if ($since <= 0 || $until <= 0 || $until < $since) {
            $until = time();
            $since = $until - ($daysBack > 0 ? $daysBack : 7) * 86400;
        }
        $flatIds = assistant_tools_flat_ids_for_house($db, $houseId);
        $ff = assistant_tools_flat_filter_sql($flatIds);
        $q = "
            select flat_id, count(*) as cnt
            from plog
            where not hidden
            and date >= " . (int) $since . "
            and date <= " . (int) $until . "
            and (" . $ff . ")
            group by flat_id
            order by cnt desc
            limit " . (int) $topLimit . "
        ";
        $data = assistant_tools_ch_select($config, $q);
        if ($data === null) {
            return ["error" => "clickhouse_unavailable_or_query_failed"];
        }
        $totalQ = "
            select countDistinct(flat_id) as c
            from plog
            where not hidden
            and date >= " . (int) $since . "
            and date <= " . (int) $until . "
            and (" . $ff . ")
        ";
        $totalData = assistant_tools_ch_select($config, $totalQ);
        $totalFlatsWithActivity = is_array($totalData) && isset($totalData[0]["c"]) ? (int) $totalData[0]["c"] : count($data);
        foreach ($data as &$row) {
            $fid = isset($row["flat_id"]) ? (int) $row["flat_id"] : 0;
            if ($fid > 0) {
                $flat = $db->get(
                    "SELECT hf.house_flat_id, hf.address_house_id AS house_id,
                            CAST(hf.flat AS VARCHAR) AS flat_number
                     FROM houses_flats hf WHERE hf.house_flat_id = :fid LIMIT 1",
                    ["fid" => $fid], [], ["silent", "singlify"]
                );
                if (is_array($flat)) {
                    $row["flat_number"] = $flat["flat_number"];
                    $row["_url"] = "?#addresses.subscribers&flatId={$fid}&houseId=" . $flat["house_id"] . "&flat=" . urlencode($flat["flat_number"]);
                }
            }
        }
        unset($row);
        return [
            "house_id" => $houseId,
            "since_unix" => $since,
            "until_unix" => $until,
            "total_flats_with_activity" => $totalFlatsWithActivity,
            "list_limit" => $topLimit,
            "list_returned" => count($data),
            "top_flats" => $data
        ];
    }

    function assistant_tool_flats_count($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $topHousesLimit = isset($args["top_houses_limit"]) ? min(80, max(1, (int) $args["top_houses_limit"])) : 35;

        if ($houseId > 0) {
            $r = $db->get(
                "select count(*)::int as c from houses_flats where address_house_id = :h",
                ["h" => $houseId],
                ["c" => "count"],
                ["fieldlify"]
            );
            if ($r === false) {
                return ["error" => "db_error"];
            }
            $meta = $db->get(
                "select address_house_id as \"houseId\", house_full as \"houseFull\"
                 from addresses_houses where address_house_id = :h limit 1",
                ["h" => $houseId],
                [],
                ["silent", "singlify"]
            );
            return [
                "house_id" => $houseId,
                "flats_count" => (int) $r,
                "house" => is_array($meta) ? $meta : null,
            ];
        }

        $total = $db->get(
            "select count(*)::int as c from houses_flats",
            [],
            ["c" => "count"],
            ["fieldlify"]
        );
        if ($total === false) {
            return ["error" => "db_error"];
        }

        $byHouse = $db->get(
            "select f.address_house_id as \"houseId\", h.house_full as \"houseFull\", count(*)::int as \"flatsCount\"
             from houses_flats f
             inner join addresses_houses h on h.address_house_id = f.address_house_id
             group by f.address_house_id, h.house_full
             order by \"flatsCount\" desc
             limit " . (int) $topHousesLimit,
            [],
            [],
            ["silent"]
        );
        if ($byHouse === false) {
            return ["error" => "db_error"];
        }

        return [
            "scope" => "all_houses",
            "total_flats" => (int) $total,
            "houses_in_response" => count($byHouse),
            "by_house_top" => $byHouse,
        ];
    }

    function assistant_tool_mobile_users_stats($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $since = isset($args["since_unix"]) ? (int) $args["since_unix"] : (time() - 7 * 86400);
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : time();
        if ($until < $since) {
            return ["error" => "invalid_time_range"];
        }

        if ($houseId > 0) {
            $cnt = $db->get(
                "select count(distinct m.house_subscriber_id)::int as c
                 from houses_subscribers_mobile m
                 inner join houses_flats_subscribers fs on fs.house_subscriber_id = m.house_subscriber_id
                 inner join houses_flats f on f.house_flat_id = fs.house_flat_id
                 where f.address_house_id = :h",
                ["h" => $houseId],
                ["c" => "count"],
                ["fieldlify"]
            );
            $dev = $db->get(
                "select count(distinct d.house_subscriber_id)::int as c
                 from houses_subscribers_devices d
                 inner join houses_subscribers_mobile m on m.house_subscriber_id = d.house_subscriber_id
                 inner join houses_flats_subscribers fs on fs.house_subscriber_id = m.house_subscriber_id
                 inner join houses_flats f on f.house_flat_id = fs.house_flat_id
                 where f.address_house_id = :h",
                ["h" => $houseId],
                ["c" => "count"],
                ["fieldlify"]
            );
        } else {
            $cnt = $db->get(
                "select count(*)::int as c from houses_subscribers_mobile",
                [],
                ["c" => "count"],
                ["fieldlify"]
            );
            $dev = $db->get(
                "select count(distinct house_subscriber_id)::int as c from houses_subscribers_devices",
                [],
                ["c" => "count"],
                ["fieldlify"]
            );
        }
        if ($cnt === false || $dev === false) {
            return ["error" => "db_error"];
        }

        $plat = $db->get(
            $houseId > 0
                ? "select coalesce(m.platform, -1)::int as platform, count(distinct m.house_subscriber_id)::int as cnt
                   from houses_subscribers_mobile m
                   inner join houses_flats_subscribers fs on fs.house_subscriber_id = m.house_subscriber_id
                   inner join houses_flats f on f.house_flat_id = fs.house_flat_id
                   where f.address_house_id = :h
                   group by m.platform
                   order by cnt desc"
                : "select coalesce(platform, -1)::int as platform, count(*)::int as cnt from houses_subscribers_mobile group by platform order by cnt desc",
            $houseId > 0 ? ["h" => $houseId] : [],
            [],
            ["silent"]
        );
        if ($plat === false) {
            $plat = [];
        }

        $flatIds = $houseId > 0 ? assistant_tools_flat_ids_for_house($db, $houseId) : [];
        $ff = count($flatIds) ? assistant_tools_flat_filter_sql($flatIds) : "1=1";

        $plogUniqPhones = null;
        if ($houseId > 0 && count($flatIds)) {
            $pq = "
                select uniqExact(
                    nullIf(replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', ''), '')
                ) as c
                from plog
                where not hidden and event = 4
                and date >= " . (int) $since . " and date <= " . (int) $until . "
                and (" . $ff . ")
            ";
            $pr = assistant_tools_ch_select($config, $pq);
            $plogUniqPhones = isset($pr[0]["c"]) ? (int) $pr[0]["c"] : null;
        } elseif ($houseId <= 0) {
            $pq = "
                select uniqExact(
                    nullIf(replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', ''), '')
                ) as c
                from plog
                where not hidden and event = 4
                and date >= " . (int) $since . " and date <= " . (int) $until . "
            ";
            $pr = assistant_tools_ch_select($config, $pq);
            $plogUniqPhones = isset($pr[0]["c"]) ? (int) $pr[0]["c"] : null;
        }

        $plogNote = "plog_distinct_app_phones_event4 — число разных нормализованных user_phone с открытиями из приложения (event=4) в ClickHouse за период.";
        if ($houseId > 0 && !count($flatIds)) {
            $plogNote .= " Для этого дома нет квартир в houses_flats — счётчик plog по квартирам недоступен.";
        }

        return [
            "house_id" => $houseId > 0 ? $houseId : null,
            "pg_mobile_subscribers_distinct" => (int) $cnt,
            "pg_subscribers_with_device_row" => (int) $dev,
            "platform_breakdown" => $plat,
            "plog_distinct_app_phones_event4" => $plogUniqPhones,
            "plog_note" => $plogNote,
            "since_unix" => $since,
            "until_unix" => $until,
        ];
    }

    /**
     * Несколько временных окон для воронки (меньше итераций LLM, чем серия mobile_users_stats).
     *
     * @param object $db
     * @return array<string, mixed>
     */
    function assistant_tool_mobile_access_funnel($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : time();
        if ($until <= 0) {
            $until = time();
        }
        $periods = [7, 30];
        if (isset($args["periods_days"]) && is_array($args["periods_days"])) {
            $periods = [];
            foreach ($args["periods_days"] as $d) {
                $periods[] = min(366, max(1, (int) $d));
            }
        }
        $periods = array_values(array_unique($periods));
        if (!count($periods)) {
            $periods = [7, 30];
        }
        if (count($periods) > 6) {
            $periods = array_slice($periods, 0, 6);
        }
        $windows = [];
        foreach ($periods as $days) {
            $since = $until - $days * 86400;
            $stats = assistant_tool_mobile_users_stats($db, $config, [
                "house_id" => $houseId,
                "since_unix" => $since,
                "until_unix" => $until,
            ]);
            $windows[] = [
                "days" => $days,
                "since_unix" => $since,
                "until_unix" => $until,
                "pg_mobile_subscribers_distinct" => isset($stats["pg_mobile_subscribers_distinct"]) ? (int) $stats["pg_mobile_subscribers_distinct"] : null,
                "pg_subscribers_with_device_row" => isset($stats["pg_subscribers_with_device_row"]) ? (int) $stats["pg_subscribers_with_device_row"] : null,
                "plog_distinct_app_phones_event4" => isset($stats["plog_distinct_app_phones_event4"]) ? $stats["plog_distinct_app_phones_event4"] : null,
                "error" => isset($stats["error"]) ? (string) $stats["error"] : null,
            ];
        }
        return [
            "house_id" => $houseId > 0 ? $houseId : null,
            "until_unix" => $until,
            "windows" => $windows,
            "note" => "Сводка по окнам: учётки и устройства — PostgreSQL; активные по приложению — uniq user_phone в plog при event=4 за каждый интервал.",
        ];
    }

    function assistant_tool_active_subscribers_for_house($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $daysBack = isset($args["days_back"]) ? max(1, min(365, (int) $args["days_back"])) : 30;
        $limit = isset($args["limit"]) ? max(10, min(500, (int) $args["limit"])) : 100;
        $onlyWithDevice = !empty($args["only_with_device"]);

        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }

        $since = time() - $daysBack * 86400;
        $platformMap = [1 => "iOS", 2 => "Android", 3 => "Web"];

        $deviceJoin = $onlyWithDevice ? "INNER" : "LEFT";

        $rows = $db->get(
            "SELECT DISTINCT ON (fs.house_subscriber_id)
                fs.house_subscriber_id,
                m.id AS phone,
                m.subscriber_full AS name,
                hf.flat,
                fs.role,
                d.platform,
                d.last_seen,
                d.version,
                d.bundle
             FROM houses_flats_subscribers fs
             JOIN houses_flats hf ON hf.house_flat_id = fs.house_flat_id
             JOIN houses_subscribers_mobile m ON m.house_subscriber_id = fs.house_subscriber_id
             " . $deviceJoin . " JOIN houses_subscribers_devices d ON d.house_subscriber_id = fs.house_subscriber_id
                AND d.last_seen >= :since
             WHERE hf.address_house_id = :hid
             ORDER BY fs.house_subscriber_id, d.last_seen DESC NULLS LAST
             LIMIT :lim",
            ["hid" => $houseId, "since" => $since, "lim" => $limit],
            [],
            ["silent"]
        );

        if ($rows === false) {
            return ["error" => "db_error"];
        }

        // Реальные итоги без лимита — чтобы модель не путала размер списка с общим числом абонентов
        $totals = $db->get(
            "SELECT
                COUNT(DISTINCT fs.house_subscriber_id)::int AS total_subscribers,
                COUNT(DISTINCT CASE WHEN d.last_seen >= :since THEN fs.house_subscriber_id END)::int AS active_in_period
             FROM houses_flats_subscribers fs
             JOIN houses_flats hf ON hf.house_flat_id = fs.house_flat_id
             JOIN houses_subscribers_mobile m ON m.house_subscriber_id = fs.house_subscriber_id
             LEFT JOIN houses_subscribers_devices d ON d.house_subscriber_id = fs.house_subscriber_id
             WHERE hf.address_house_id = :hid",
            ["hid" => $houseId, "since" => $since],
            [],
            ["singlify", "silent"]
        );

        foreach ($rows as &$r) {
            $r["platform_name"] = isset($r["platform"]) && $r["platform"] ? ($platformMap[(int)$r["platform"]] ?? "Unknown") : null;
            $r["active"] = isset($r["last_seen"]) && $r["last_seen"] && (int)$r["last_seen"] >= $since;
            $r["role_name"] = (int)($r["role"] ?? 0) === 0 ? "владелец" : "пользователь";
            if ($r["last_seen"]) {
                $r["last_seen_date"] = date("Y-m-d H:i", (int)$r["last_seen"]);
            }
            $r["_url"] = "?#addresses.subscriberDevices&subscriberId=" . $r["house_subscriber_id"];
        }
        unset($r);

        $active = array_values(array_filter($rows, fn($r) => $r["active"]));

        $totalSubscribers = is_array($totals) ? (int) ($totals["total_subscribers"] ?? count($rows)) : count($rows);
        $activeInPeriod   = is_array($totals) ? (int) ($totals["active_in_period"]   ?? count($active)) : count($active);

        return [
            "house_id"                  => $houseId,
            "days_back"                 => $daysBack,
            "total_subscribers_in_house" => $totalSubscribers,
            "active_in_period_count"    => $activeInPeriod,
            "NOTE"                      => "total_subscribers_in_house и active_in_period_count — точные итоги по всему дому. subscribers[] — выборка до $limit записей.",
            "list_limit"                => $limit,
            "list_returned"             => count($rows),
            "subscribers"               => $rows,
        ];
    }

    function assistant_tool_subscriber_lookup($db, array $args): array {
        $sid = isset($args["house_subscriber_id"]) ? (int) $args["house_subscriber_id"] : 0;
        $phone = isset($args["phone"]) ? trim((string) $args["phone"]) : "";

        $row = null;
        if ($sid > 0) {
            $row = $db->get(
                "select house_subscriber_id, id as phone, registered,
                        subscriber_name, subscriber_patronymic, subscriber_last, subscriber_full
                 from houses_subscribers_mobile
                 where house_subscriber_id = :s limit 1",
                ["s" => $sid],
                [],
                ["silent", "singlify"]
            );
        } elseif ($phone !== "") {
            $digits = assistant_tools_normalize_phone($phone);
            if (strlen($digits) < 10) {
                return ["error" => "phone_too_short"];
            }
            $tail = assistant_tools_ru_mobile_canonical_tail($digits);
            $idDigits = "regexp_replace(coalesce(id::text, ''), '[^0-9]', '', 'g')";
            $idCanon = "(case when length($idDigits) = 11 and substring($idDigits, 1, 1) in ('7','8') then substring($idDigits, 2, 10) else $idDigits end)";
            $row = $db->get(
                "select house_subscriber_id, id as phone, registered,
                        subscriber_name, subscriber_patronymic, subscriber_last, subscriber_full
                 from houses_subscribers_mobile
                 where id = :p
                    or regexp_replace(coalesce(id::text, ''), '[^0-9]', '', 'g') = :d
                    or ($idCanon) = :tail
                 order by house_subscriber_id limit 1",
                ["p" => $phone, "d" => $digits, "tail" => $tail],
                [],
                ["silent", "singlify"]
            );
        } else {
            return ["error" => "invalid_params", "need" => ["phone или house_subscriber_id"]];
        }

        if (($row === false || !is_array($row) || !isset($row["house_subscriber_id"])) && $phone !== "") {
            $households = function_exists("loadBackend") ? loadBackend("households") : false;
            if ($households) {
                $hits = $households->searchSubscriber($phone);
                if ((!is_array($hits) || !count($hits)) && isset($digits) && $digits !== "" && $digits !== $phone && strlen($digits) >= 10) {
                    $hits = $households->searchSubscriber($digits);
                }
                if (is_array($hits) && count($hits) && isset($hits[0]["subscriberId"])) {
                    $sidPick = (int) $hits[0]["subscriberId"];
                    if ($sidPick > 0) {
                        $row = $db->get(
                            "select house_subscriber_id, id as phone, registered,
                                    subscriber_name, subscriber_patronymic, subscriber_last, subscriber_full
                             from houses_subscribers_mobile
                             where house_subscriber_id = :s limit 1",
                            ["s" => $sidPick],
                            [],
                            ["silent", "singlify"]
                        );
                    }
                }
            }
        }

        if ($row === false || !is_array($row) || !isset($row["house_subscriber_id"])) {
            return ["error" => "subscriber_not_found"];
        }

        $hid = (int) $row["house_subscriber_id"];

        $flats = $db->get(
            "select f.house_flat_id as \"flatId\", f.address_house_id as \"houseId\", h.house_full as \"houseFull\",
                    trim(cast(f.flat as varchar)) as \"flatNumber\"
             from houses_flats_subscribers fs
             inner join houses_flats f on f.house_flat_id = fs.house_flat_id
             inner join addresses_houses h on h.address_house_id = f.address_house_id
             where fs.house_subscriber_id = :s
             order by h.house_full, f.flat
             limit 50",
            ["s" => $hid],
            [],
            ["silent"]
        );
        if ($flats === false) {
            $flats = [];
        }

        $devices = $db->get(
            "select subscriber_device_id as \"deviceId\", coalesce(ua, '') as ua,
                    last_seen as \"lastSeen\", coalesce(version, '') as version,
                    (coalesce(NULLIF(trim(device_token), ''), '') <> '' ) as \"hasPushToken\"
             from houses_subscribers_devices
             where house_subscriber_id = :s
             order by last_seen desc nulls last
             limit 30",
            ["s" => $hid],
            [],
            ["silent"]
        );
        if ($devices === false) {
            $devices = [];
        }

        $keys = $db->get(
            "select r.house_rfid_id as \"keyId\", r.rfid, r.access_type as \"accessType\", r.last_seen as \"lastSeen\",
                    trim(cast(f.flat as varchar)) as \"flatNumber\", f.address_house_id as \"houseId\"
             from houses_rfids r
             left join houses_flats f on r.access_type = 2 and f.house_flat_id = r.access_to
             where (r.access_type = 1 and r.access_to = :s)
                or (
                    r.access_type = 2 and exists (
                        select 1 from houses_flats_subscribers xfs
                        where xfs.house_flat_id = r.access_to and xfs.house_subscriber_id = :s2
                    )
                )
             order by r.last_seen desc nulls last
             limit 40",
            ["s" => $hid, "s2" => $hid],
            [],
            ["silent"]
        );
        if ($keys === false) {
            $keys = [];
        }

        $row["_url"] = "?#addresses.subscriberDevices&subscriberId=" . $hid;
        foreach ($flats as &$f) {
            $f["_url"] = "?#addresses.subscribers&flatId=" . $f["flatId"] . "&houseId=" . $f["houseId"] . "&flat=" . urlencode($f["flatNumber"]);
        }
        unset($f);

        return [
            "subscriber" => $row,
            "flats" => $flats,
            "devices" => $devices,
            "keys_linked" => $keys,
        ];
    }

    function assistant_tool_plog_events_list($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $since = isset($args["since_unix"]) ? (int) $args["since_unix"] : 0;
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : 0;
        $limit = isset($args["limit"]) ? min(200, max(5, (int) $args["limit"])) : 40;
        $rfid = isset($args["rfid"]) ? strtoupper(preg_replace("/[^0-9A-F]/i", "", (string) $args["rfid"])) : "";
        $phone = isset($args["phone"]) ? trim((string) $args["phone"]) : "";
        $scopeSubscriberId = isset($args["house_subscriber_id"]) ? (int) $args["house_subscriber_id"] : 0;
        $daysBack = isset($args["days_back"]) ? max(1, min(90, (int) $args["days_back"])) : 0;

        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }
        if ($since <= 0 || $until <= 0 || $until < $since) {
            $until = time();
            $since = $until - ($daysBack > 0 ? $daysBack : 2) * 86400;
        }

        if ($scopeSubscriberId > 0) {
            $subFlats = $db->get(
                "select f.house_flat_id from houses_flats_subscribers fs
                 inner join houses_flats f on f.house_flat_id = fs.house_flat_id
                 where fs.house_subscriber_id = :sid and f.address_house_id = :hid",
                ["sid" => $scopeSubscriberId, "hid" => $houseId],
                [],
                ["silent"]
            );
            if ($subFlats === false || !count($subFlats)) {
                return [
                    "error" => "subscriber_has_no_flats_in_house",
                    "house_id" => $houseId,
                    "house_subscriber_id" => $scopeSubscriberId,
                ];
            }
            $flatIds = array_map("intval", array_column($subFlats, "house_flat_id"));
        } else {
            $flatIds = assistant_tools_flat_ids_for_house($db, $houseId);
        }
        if (!count($flatIds)) {
            return ["error" => "no_flats_for_house", "house_id" => $houseId];
        }
        $ff = assistant_tools_flat_filter_sql($flatIds);

        $extra = "";
        if (strlen($rfid) >= 6) {
            $rfidEsc = str_replace("'", "''", $rfid);
            $extra .= " and upper(replaceRegexpAll(coalesce(rfid, ''), '[^0-9A-F]', '')) = '" . $rfidEsc . "'";
        }

        $phoneCond = "";
        if ($phone !== "") {
            $pd = assistant_tools_normalize_phone($phone);
            if (strlen($pd) >= 10) {
                $tail = assistant_tools_ru_mobile_canonical_tail($pd);
                $tailEsc = str_replace("'", "''", $tail);
                $chDigits = "replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', '')";
                $chCanon = "if(length($chDigits) = 11 AND substring($chDigits, 1, 1) IN ('7','8'), substring($chDigits, 2), $chDigits)";
                $phoneCond = " and $chCanon = '" . $tailEsc . "'";
            }
        }

        $q = "
            select date, event, opened, flat_id, rfid, code,
                toJSONString(domophone) as domophone_json,
                replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', '') as user_phone_digits
            from plog
            where not hidden
            and date >= " . (int) $since . "
            and date <= " . (int) $until . "
            and (" . $ff . ")
            " . $extra . $phoneCond . "
            order by date desc
            limit " . (int) $limit . "
        ";

        $data = assistant_tools_ch_select($config, $q);
        if ($data === null) {
            return ["error" => "clickhouse_unavailable_or_query_failed"];
        }
        $cntQ = "
            select count(*) as cnt
            from plog
            where not hidden
            and date >= " . (int) $since . "
            and date <= " . (int) $until . "
            and (" . $ff . ")
            " . $extra . $phoneCond . "
        ";
        $cntData = assistant_tools_ch_select($config, $cntQ);
        $totalEvents = is_array($cntData) && isset($cntData[0]["cnt"]) ? (int) $cntData[0]["cnt"] : count($data);

        $out = [];
        foreach ($data as $r) {
            $ev = isset($r["event"]) ? (int) $r["event"] : 0;
            $dj = isset($r["domophone_json"]) ? json_decode((string) $r["domophone_json"], true) : [];
            $domo = [
                "entrance_id" => is_array($dj) && isset($dj["entrance_id"]) ? $dj["entrance_id"] : null,
                "camera_id" => is_array($dj) && isset($dj["camera_id"]) ? $dj["camera_id"] : null,
                "domophone_id" => is_array($dj) && isset($dj["domophone_id"]) ? $dj["domophone_id"] : null,
            ];
            $out[] = [
                "timestamp_unix" => isset($r["date"]) ? (int) $r["date"] : 0,
                "event_code" => $ev,
                "event_name_ru" => assistant_tool_plog_event_label($ev),
                "opened" => isset($r["opened"]) ? (int) $r["opened"] : 0,
                "flat_id" => isset($r["flat_id"]) ? (int) $r["flat_id"] : 0,
                "rfid" => isset($r["rfid"]) ? (string) $r["rfid"] : "",
                "code" => isset($r["code"]) ? (string) $r["code"] : "",
                "user_phone_digits" => isset($r["user_phone_digits"]) ? (string) $r["user_phone_digits"] : "",
                "domophone" => $domo,
            ];
        }

        return [
            "house_id" => $houseId,
            "house_subscriber_id_filter" => $scopeSubscriberId > 0 ? $scopeSubscriberId : null,
            "since_unix" => $since,
            "until_unix" => $until,
            "total_events_in_period" => $totalEvents,
            "list_limit" => $limit,
            "list_returned" => count($out),
            "events" => $out,
        ];
    }

    function assistant_tool_flat_info($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $flatNum = isset($args["flat_number"]) ? trim((string) $args["flat_number"]) : "";
        $flatId  = isset($args["flat_id"]) ? (int) $args["flat_id"] : 0;

        if ($houseId <= 0 && $flatId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id + flat_number или flat_id"]];
        }

        if ($flatId > 0) {
            $cond = "hf.house_flat_id = :fid";
            $binds = ["fid" => $flatId];
        } else {
            if ($flatNum === "") {
                return ["error" => "invalid_params", "need" => ["flat_number"]];
            }
            $cond = "hf.address_house_id = :hid AND CAST(hf.flat AS VARCHAR) = :fn";
            $binds = ["hid" => $houseId, "fn" => $flatNum];
        }

        $row = $db->get(
            "SELECT hf.house_flat_id, hf.address_house_id AS house_id,
                    CAST(hf.flat AS VARCHAR) AS flat_number, hf.floor,
                    hf.code AS open_code, hf.manual_block, hf.auto_block, hf.admin_block,
                    hf.sip_enabled, hf.cms_enabled, hf.contract, hf.login,
                    hf.auto_open, hf.white_rabbit, hf.plog,
                    ah.house_full,
                    (SELECT COUNT(*) FROM houses_rfids r
                     WHERE r.access_type = 2 AND r.access_to = hf.house_flat_id) AS keys_count,
                    (SELECT COUNT(*) FROM houses_flats_subscribers fs
                     WHERE fs.house_flat_id = hf.house_flat_id) AS subscribers_count
             FROM houses_flats hf
             JOIN addresses_houses ah ON ah.address_house_id = hf.address_house_id
             WHERE " . $cond . "
             LIMIT 1",
            $binds,
            [],
            ["silent", "singlify"]
        );

        if ($row === false || !is_array($row)) {
            return ["error" => "flat_not_found"];
        }

        $fid  = (int) $row["house_flat_id"];
        $hid  = (int) $row["house_id"];
        $flat = urlencode((string)($row["flat_number"] ?? ""));
        $row["_url"]      = "?#addresses.subscribers&flatId={$fid}&houseId={$hid}&flat={$flat}";
        $row["house_url"] = "?#addresses.houses&houseId={$hid}";

        $blocked = ((int)$row["manual_block"] > 0 || (int)$row["auto_block"] > 0 || (int)$row["admin_block"] > 0);
        $row["is_blocked"] = $blocked;
        $row["block_reason"] = [];
        if ((int)$row["manual_block"] > 0) $row["block_reason"][] = "ручная";
        if ((int)$row["auto_block"] > 0)   $row["block_reason"][] = "автоматическая";
        if ((int)$row["admin_block"] > 0)  $row["block_reason"][] = "административная";

        $subs = $db->get(
            "SELECT fs.house_subscriber_id, m.id AS phone, m.subscriber_full AS name, fs.role
             FROM houses_flats_subscribers fs
             JOIN houses_subscribers_mobile m ON m.house_subscriber_id = fs.house_subscriber_id
             WHERE fs.house_flat_id = :fid
             ORDER BY fs.role, m.subscriber_full
             LIMIT 30",
            ["fid" => $fid],
            [],
            ["silent"]
        );
        if ($subs === false) {
            $subs = [];
        }
        foreach ($subs as &$s) {
            $s["role_name"] = (int)($s["role"] ?? 0) === 0 ? "владелец" : "пользователь";
            $s["_url"] = "?#addresses.subscriberDevices&subscriberId=" . $s["house_subscriber_id"];
        }
        unset($s);

        $row["subscribers"] = $subs;
        return $row;
    }

    function assistant_tool_house_entrances_list($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }

        $rows = $db->get(
            "SELECT he.house_entrance_id, he.entrance_type, he.entrance,
                    he.house_domophone_id, he.camera_id, he.cms_type,
                    hd.model AS domophone_model, hd.ip AS domophone_ip,
                    hd.name AS domophone_name, hd.enabled AS domophone_enabled
             FROM houses_houses_entrances hhe
             JOIN houses_entrances he ON he.house_entrance_id = hhe.house_entrance_id
             LEFT JOIN houses_domophones hd ON hd.house_domophone_id = he.house_domophone_id
             WHERE hhe.address_house_id = :hid
             ORDER BY he.entrance",
            ["hid" => $houseId],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }

        foreach ($rows as &$r) {
            if ($r["house_domophone_id"]) {
                $r["domophone_url"] = "?#addresses.domophones&id=" . $r["house_domophone_id"];
            }
            if ($r["camera_id"]) {
                $r["camera_url"] = "?#addresses.cameras&id=" . $r["camera_id"];
            }
        }
        unset($r);

        return [
            "house_id" => $houseId,
            "house_url" => "?#addresses.houses&houseId={$houseId}",
            "count" => count($rows),
            "entrances" => $rows,
        ];
    }

    function assistant_tool_house_domophones_list($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }

        $rows = $db->get(
            "SELECT DISTINCT ON (hd.house_domophone_id)
                    hd.house_domophone_id, hd.model, hd.ip, hd.name, hd.enabled, hd.monitoring,
                    hd.url AS stream_url, hd.comments, hd.sub_id,
                    he.entrance_type, he.entrance
             FROM houses_entrances he
             JOIN houses_houses_entrances hhe ON hhe.house_entrance_id = he.house_entrance_id
             JOIN houses_domophones hd ON hd.house_domophone_id = he.house_domophone_id
             WHERE hhe.address_house_id = :hid
             ORDER BY hd.house_domophone_id, he.entrance",
            ["hid" => $houseId],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }

        foreach ($rows as &$r) {
            $r["_url"] = "?#addresses.domophones&id=" . $r["house_domophone_id"];
            $r["enabled_label"] = (int)($r["enabled"] ?? 1) ? "активен" : "отключён";
        }
        unset($r);

        return [
            "house_id" => $houseId,
            "house_url" => "?#addresses.houses&houseId={$houseId}",
            "domophones_list_url" => "?#addresses.domophones",
            "count" => count($rows),
            "domophones" => $rows,
        ];
    }

    function assistant_tool_blocked_flats($db, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $blockType = isset($args["block_type"]) ? trim((string) $args["block_type"]) : "any";
        $limit = isset($args["limit"]) ? max(5, min(500, (int) $args["limit"])) : 100;

        if ($houseId <= 0) {
            return ["error" => "invalid_params", "need" => ["house_id"]];
        }

        $where = "hf.address_house_id = :hid";
        if ($blockType === "manual") {
            $where .= " AND hf.manual_block > 0";
        } elseif ($blockType === "auto") {
            $where .= " AND hf.auto_block > 0";
        } elseif ($blockType === "admin") {
            $where .= " AND hf.admin_block > 0";
        } else {
            $where .= " AND (hf.manual_block > 0 OR hf.auto_block > 0 OR hf.admin_block > 0)";
        }

        $rows = $db->get(
            "SELECT hf.house_flat_id, hf.address_house_id AS house_id,
                    CAST(hf.flat AS VARCHAR) AS flat_number, hf.floor,
                    hf.manual_block, hf.auto_block, hf.admin_block,
                    hf.contract
             FROM houses_flats hf
             WHERE " . $where . "
             ORDER BY hf.flat
             LIMIT :lim",
            ["hid" => $houseId, "lim" => $limit],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }
        $totalBlocked = $db->get(
            "SELECT COUNT(*)::int AS c
             FROM houses_flats hf
             WHERE " . $where,
            ["hid" => $houseId],
            ["c" => "count"],
            ["fieldlify", "silent"]
        );

        foreach ($rows as &$r) {
            $fid  = (int) $r["house_flat_id"];
            $hid  = (int) $r["house_id"];
            $flat = urlencode((string)($r["flat_number"] ?? ""));
            $r["_url"] = "?#addresses.subscribers&flatId={$fid}&houseId={$hid}&flat={$flat}";
            $reasons = [];
            if ((int)$r["manual_block"] > 0) $reasons[] = "ручная";
            if ((int)$r["auto_block"] > 0)   $reasons[] = "авто";
            if ((int)$r["admin_block"] > 0)  $reasons[] = "административная";
            $r["block_reasons"] = $reasons;
        }
        unset($r);

        return [
            "house_id" => $houseId,
            "block_type_filter" => $blockType,
            "total_blocked_flats" => $totalBlocked !== false ? (int) $totalBlocked : count($rows),
            "list_limit" => $limit,
            "list_returned" => count($rows),
            "flats" => $rows,
        ];
    }

    function assistant_tool_rfid_lookup($db, array $args): array {
        $rfid = isset($args["rfid"]) ? strtoupper(preg_replace("/[^0-9A-F]/i", "", (string) $args["rfid"])) : "";
        if (strlen($rfid) < 4) {
            return ["error" => "invalid_params", "need" => ["rfid (минимум 4 символа)"]];
        }

        $rows = $db->get(
            "SELECT r.house_rfid_id, r.rfid, r.access_type, r.access_to,
                    r.last_seen, r.comments,
                    CASE WHEN r.access_type = 1 THEN 'абонент'
                         WHEN r.access_type = 2 THEN 'квартира'
                         ELSE 'неизвестно' END AS access_type_label
             FROM houses_rfids r
             WHERE upper(r.rfid) LIKE :q
             ORDER BY r.last_seen DESC NULLS LAST
             LIMIT 20",
            ["q" => "%" . $rfid . "%"],
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }
        if (!count($rows)) {
            return ["error" => "rfid_not_found", "rfid" => $rfid];
        }

        $totalMatches = $db->get(
            "SELECT COUNT(*)::int AS c
             FROM houses_rfids r
             WHERE upper(r.rfid) LIKE :q",
            ["q" => "%" . $rfid . "%"],
            ["c" => "count"],
            ["fieldlify", "silent"]
        );

        foreach ($rows as &$r) {
            $accessType = (int) $r["access_type"];
            $accessTo   = (int) $r["access_to"];

            if ($accessType === 1) {
                $sub = $db->get(
                    "SELECT m.id AS phone, m.subscriber_full AS name, m.house_subscriber_id
                     FROM houses_subscribers_mobile m WHERE m.house_subscriber_id = :sid LIMIT 1",
                    ["sid" => $accessTo],
                    [],
                    ["silent", "singlify"]
                );
                if (is_array($sub)) {
                    $r["owner_phone"] = $sub["phone"];
                    $r["owner_name"]  = $sub["name"];
                    $r["owner_subscriber_id"] = $sub["house_subscriber_id"];
                    $r["owner_url"] = "?#addresses.subscriberDevices&subscriberId=" . $sub["house_subscriber_id"];
                }
            } elseif ($accessType === 2) {
                $flat = $db->get(
                    "SELECT hf.house_flat_id, hf.address_house_id AS house_id,
                            CAST(hf.flat AS VARCHAR) AS flat_number, ah.house_full
                     FROM houses_flats hf
                     JOIN addresses_houses ah ON ah.address_house_id = hf.address_house_id
                     WHERE hf.house_flat_id = :fid LIMIT 1",
                    ["fid" => $accessTo],
                    [],
                    ["silent", "singlify"]
                );
                if (is_array($flat)) {
                    $r["flat_number"] = $flat["flat_number"];
                    $r["flat_house_full"] = $flat["house_full"];
                    $r["flat_url"] = "?#addresses.subscribers&flatId=" . $flat["house_flat_id"] . "&houseId=" . $flat["house_id"] . "&flat=" . urlencode((string)($flat["flat_number"] ?? ""));
                    $r["keys_url"] = "?#addresses.keys&query=" . urlencode($r["rfid"]);
                }
            }

            if ($r["last_seen"]) {
                $r["last_seen_date"] = date("Y-m-d H:i", (int) $r["last_seen"]);
            }
        }
        unset($r);

        return [
            "rfid_query" => $rfid,
            "total_matches" => $totalMatches !== false ? (int) $totalMatches : count($rows),
            "list_limit" => 20,
            "list_returned" => count($rows),
            "keys" => $rows,
        ];
    }

    function assistant_tool_all_houses_list($db, array $args): array {
        $search = isset($args["search"]) ? trim((string) $args["search"]) : "";
        $limit = isset($args["limit"]) ? max(10, min(500, (int) $args["limit"])) : 100;

        $where = "1=1";
        $binds = ["lim" => $limit];
        if ($search !== "") {
            $where = "ah.house_full ILIKE :q";
            $binds["q"] = "%" . $search . "%";
        }

        $rows = $db->get(
            "SELECT ah.address_house_id AS house_id, ah.house_full,
                    COUNT(hf.house_flat_id) AS flats_count,
                    COUNT(DISTINCT fs.house_subscriber_id) AS subscribers_count
             FROM addresses_houses ah
             LEFT JOIN houses_flats hf ON hf.address_house_id = ah.address_house_id
             LEFT JOIN houses_flats_subscribers fs ON fs.house_flat_id = hf.house_flat_id
             WHERE " . $where . "
             GROUP BY ah.address_house_id, ah.house_full
             ORDER BY ah.house_full
             LIMIT :lim",
            $binds,
            [],
            ["silent"]
        );
        if ($rows === false) {
            return ["error" => "db_error"];
        }
        $totalHouses = $db->get(
            "SELECT COUNT(*)::int AS c
             FROM addresses_houses ah
             WHERE " . $where,
            $search !== "" ? ["q" => "%" . $search . "%"] : [],
            ["c" => "count"],
            ["fieldlify", "silent"]
        );

        foreach ($rows as &$r) {
            $r["_url"] = "?#addresses.houses&houseId=" . $r["house_id"];
        }
        unset($r);

        return [
            "search" => $search,
            "total_houses" => $totalHouses !== false ? (int) $totalHouses : count($rows),
            "list_limit" => $limit,
            "list_returned" => count($rows),
            "houses" => $rows,
        ];
    }
}


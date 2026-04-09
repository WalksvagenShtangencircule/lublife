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
        curl_setopt($curl, CURLOPT_POSTFIELDS, trim($query) . " FORMAT JSON");
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_URL, "http://{$host}:{$port}/");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 45);
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
        return ["house_id" => $houseId, "user_agents" => $rows];
    }

    function assistant_tool_rfid_events_in_period($db, array $config, array $args): array {
        $houseId = isset($args["house_id"]) ? (int) $args["house_id"] : 0;
        $rfid = isset($args["rfid"]) ? strtoupper(preg_replace("/[^0-9A-F]/i", "", (string) $args["rfid"])) : "";
        $since = isset($args["since_unix"]) ? (int) $args["since_unix"] : 0;
        $until = isset($args["until_unix"]) ? (int) $args["until_unix"] : 0;
        if ($houseId <= 0 || strlen($rfid) < 6 || $since <= 0 || $until <= 0 || $until < $since) {
            return ["error" => "invalid_params", "need" => ["house_id", "rfid", "since_unix", "until_unix"]];
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
        if ($houseId <= 0 || $since <= 0 || $until <= 0 || $until < $since) {
            return ["error" => "invalid_params"];
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
            limit 20
        ";
        $data = assistant_tools_ch_select($config, $q);
        if ($data === null) {
            return ["error" => "clickhouse_unavailable_or_query_failed"];
        }
        return ["house_id" => $houseId, "since_unix" => $since, "until_unix" => $until, "top_flats" => $data];
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

    function assistant_tool_subscriber_lookup($db, array $args): array {
        $sid = isset($args["house_subscriber_id"]) ? (int) $args["house_subscriber_id"] : 0;
        $phone = isset($args["phone"]) ? trim((string) $args["phone"]) : "";

        $row = null;
        if ($sid > 0) {
            $row = $db->get(
                "select house_subscriber_id, id as phone, platform, registered, last_seen,
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
            $row = $db->get(
                "select house_subscriber_id, id as phone, platform, registered, last_seen,
                        subscriber_name, subscriber_patronymic, subscriber_last, subscriber_full
                 from houses_subscribers_mobile
                 where id = :p or regexp_replace(coalesce(id, ''), '[^0-9]', '', 'g') = :d
                 order by house_subscriber_id limit 1",
                ["p" => $phone, "d" => $digits],
                [],
                ["silent", "singlify"]
            );
        } else {
            return ["error" => "invalid_params", "need" => ["phone или house_subscriber_id"]];
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
        $limit = isset($args["limit"]) ? min(80, max(5, (int) $args["limit"])) : 40;
        $rfid = isset($args["rfid"]) ? strtoupper(preg_replace("/[^0-9A-F]/i", "", (string) $args["rfid"])) : "";
        $phone = isset($args["phone"]) ? trim((string) $args["phone"]) : "";
        $scopeSubscriberId = isset($args["house_subscriber_id"]) ? (int) $args["house_subscriber_id"] : 0;

        if ($houseId <= 0 || $since <= 0 || $until <= 0 || $until < $since) {
            return ["error" => "invalid_params", "need" => ["house_id", "since_unix", "until_unix"]];
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
                $pdEsc = str_replace("'", "''", $pd);
                $phoneCond = " and replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', '') = '" . $pdEsc . "'";
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
            "returned" => count($out),
            "events" => $out,
        ];
    }
}


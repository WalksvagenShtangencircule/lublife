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
}


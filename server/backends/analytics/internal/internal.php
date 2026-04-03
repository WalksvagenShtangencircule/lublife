<?php

    /**
     * backends analytics internal
     */

    namespace backends\analytics {

        class internal extends analytics {

            private $clickhouse = null;

            private function chConfig(): ?array {
                $c = @$this->config["clickhouse"];
                if (!$c || !@$c["host"]) {
                    return null;
                }
                return [
                    "host" => $c["host"],
                    "port" => @$c["port"] ?: 8123,
                    "username" => @$c["username"] ?: "default",
                    "password" => @$c["password"] ?: "",
                    "database" => @$c["database"] ?: "default",
                ];
            }

            private function ch(): ?\clickhouse {
                if ($this->clickhouse !== null) {
                    return $this->clickhouse;
                }
                $c = $this->chConfig();
                if (!$c) {
                    return null;
                }
                require_once __DIR__ . "/../../../utils/clickhouse.php";
                $this->clickhouse = new \clickhouse(
                    $c["host"],
                    $c["port"],
                    $c["username"],
                    $c["password"],
                    $c["database"]
                );
                return $this->clickhouse;
            }

            /**
             * SELECT в ClickHouse с увеличенным таймаутом (агрегации).
             */
            private function chSelect(string $query): ?array {
                $c = $this->chConfig();
                if (!$c) {
                    return null;
                }
                $host = $c["host"];
                $port = $c["port"];
                $user = $c["username"];
                $pass = $c["password"];

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
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                $raw = curl_exec($curl);
                curl_close($curl);
                if (@$headers["x-clickhouse-exception-code"]) {
                    error_log("analytics CH: " . $raw);
                    return null;
                }
                $j = json_decode($raw, true);
                if (!is_array($j)) {
                    return null;
                }
                return $j["data"] ?? null;
            }

            private function flatIdsForHouse(int $houseId): array {
                $rows = $this->db->get(
                    "select house_flat_id from houses_flats where address_house_id = :h",
                    ["h" => $houseId],
                    [],
                    ["silent"]
                );
                if ($rows === false) {
                    return [];
                }
                return array_map("intval", array_column($rows, "house_flat_id"));
            }

            private function flatIdsForHouseAndPhone(int $houseId, string $phone): array {
                $digits = preg_replace("/\D+/", "", $phone);
                if (strlen($digits) < 6) {
                    return [];
                }
                $like = "%" . $digits . "%";
                $rows = $this->db->get(
                    "select distinct hf.house_flat_id as house_flat_id
                     from houses_flats hf
                     inner join houses_flats_subscribers hfs on hfs.house_flat_id = hf.house_flat_id
                     inner join houses_subscribers_mobile m on m.house_subscriber_id = hfs.house_subscriber_id
                     where hf.address_house_id = :h
                     and (m.id like :l or replace(replace(replace(m.id, '+', ''), '-', ''), ' ', '') like :d)",
                    ["h" => $houseId, "l" => $like, "d" => "%{$digits}%"],
                    [],
                    ["silent"]
                );
                if ($rows === false) {
                    return [];
                }
                return array_map("intval", array_column($rows, "house_flat_id"));
            }

            private function mapFlatAddressByIds(array $flatIds): array {
                $flatIds = array_values(array_unique(array_filter(array_map("intval", $flatIds))));
                if (!count($flatIds)) {
                    return [];
                }
                $in = implode(",", $flatIds);
                $rows = $this->db->get(
                    "select hf.house_flat_id as house_flat_id, hf.flat as flat, ah.house_full as house_full
                     from houses_flats hf
                     left join addresses_houses ah on ah.address_house_id = hf.address_house_id
                     where hf.house_flat_id in (" . $in . ")",
                    [],
                    [],
                    ["silent"]
                );
                if ($rows === false) {
                    return [];
                }
                $out = [];
                foreach ($rows as $row) {
                    $id = (int)$row["house_flat_id"];
                    $flat = isset($row["flat"]) ? trim((string)$row["flat"]) : "";
                    $full = isset($row["house_full"]) ? trim((string)$row["house_full"]) : "";
                    if ($full !== "" && $flat !== "") {
                        $line = $full . ", кв. " . $flat;
                    } elseif ($full !== "") {
                        $line = $full;
                    } elseif ($flat !== "") {
                        $line = "кв. " . $flat;
                    } else {
                        $line = "";
                    }
                    $out[$id] = [
                        "flatNumber" => $flat !== "" ? $flat : null,
                        "houseFull" => $full !== "" ? $full : null,
                        "addressLine" => $line !== "" ? $line : null,
                    ];
                }
                return $out;
            }

            /**
             * Телефон из поля phones в plog (например открытие из приложения — user_phone).
             */
            private function extractPlogUserPhone($phonesRaw): ?string {
                if ($phonesRaw === null || $phonesRaw === "") {
                    return null;
                }
                if (is_string($phonesRaw)) {
                    $decoded = json_decode($phonesRaw, true);
                } elseif (is_array($phonesRaw)) {
                    $decoded = $phonesRaw;
                } else {
                    return null;
                }
                if (!is_array($decoded) || !isset($decoded["user_phone"])) {
                    return null;
                }
                $t = trim((string)$decoded["user_phone"]);
                return $t !== "" ? $t : null;
            }

            private function flatFilterSql(array $flatIds): string {
                if (!count($flatIds)) {
                    return "1=0";
                }
                $flatIds = array_values(array_unique(array_filter($flatIds)));
                return "flat_id in (" . implode(",", $flatIds) . ")";
            }

            /**
             * Уникальные абоненты (house_subscriber_id), у которых хотя бы одно устройство
             * отметилось last_seen в [since, until]. Близко к MAU Firebase / активности приложения на API.
             *
             * @return null при ошибке БД
             */
            private function countDistinctSubscribersByDeviceActivity(int $since, int $until, ?int $houseId): ?int {
                $sql = "select count(distinct d.house_subscriber_id) as c
                        from houses_subscribers_devices d
                        where d.last_seen >= :since and d.last_seen <= :until";
                $params = [
                    "since" => $since,
                    "until" => $until,
                ];
                if ($houseId !== null && $houseId > 0) {
                    $sql .= " and exists (
                        select 1 from houses_flats_subscribers hfs
                        inner join houses_flats hf on hf.house_flat_id = hfs.house_flat_id
                        where hfs.house_subscriber_id = d.house_subscriber_id
                        and hf.address_house_id = :hid
                    )";
                    $params["hid"] = $houseId;
                }
                $r = $this->db->get($sql, $params, ["c" => "count"], ["fieldlify"]);
                if ($r === false) {
                    return null;
                }
                return (int)$r;
            }

            public function getStats(int $days, ?int $houseId) {
                if ($days < 1) {
                    $days = 1;
                }
                if ($days > 62) {
                    $days = 62;
                }
                $now = time();
                $seriesSince = $now - $days * 86400;
                $flatFilter = "1=1";
                if ($houseId !== null && $houseId > 0) {
                    $ids = $this->flatIdsForHouse($houseId);
                    $flatFilter = $this->flatFilterSql($ids);
                }

                $mauSource = @$this->bconfig["mau_source"] ?: "devices";
                $seriesMode = @$this->bconfig["series_mode"] ?: "plog_composite";

                $wauSince = $now - 7 * 86400;
                $periodSince = $now - $days * 86400;

                if ($seriesMode === "plog_app_only") {
                    $seriesExpr = "nullIf(replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', ''), '')";
                    $seriesWhereExtra = " and event = 4 ";
                } else {
                    $seriesExpr = "if(
                        length(trim(JSONExtractString(toJSONString(phones), 'user_phone'))) > 0,
                        concat('p:', replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', '')),
                        concat('f:', toString(flat_id))
                    )";
                    $seriesWhereExtra = " ";
                }

                $qSeries = "
                    select
                        toYYYYMMDD(FROM_UNIXTIME(date)) as day,
                        uniqExact(" . $seriesExpr . ") as active_users
                    from plog
                    where
                        not hidden
                        and date >= " . (int)$seriesSince . "
                        and date <= " . (int)$now . "
                        and (" . $flatFilter . ")
                        " . $seriesWhereExtra . "
                    group by day
                    order by day asc
                ";

                $series = $this->chSelect($qSeries);
                $outSeries = [];
                if ($series !== null) {
                    foreach ($series as $row) {
                        $d = (string)$row["day"];
                        if (strlen($d) === 8) {
                            $outSeries[] = [
                                "day" => substr($d, 0, 4) . "-" . substr($d, 4, 2) . "-" . substr($d, 6, 2),
                                "activeUsers" => (int)$row["active_users"],
                            ];
                        }
                    }
                }

                if ($mauSource === "plog_app_only") {
                    $userKey = "nullIf(replaceRegexpAll(trim(JSONExtractString(toJSONString(phones), 'user_phone')), '[^0-9]', ''), '')";
                    $qWau = "
                        select uniqExact(" . $userKey . ") as c from plog
                        where not hidden and event = 4 and date >= " . (int)$wauSince . " and date <= " . (int)$now . "
                        and (" . $flatFilter . ")
                    ";
                    $qPeriod = "
                        select uniqExact(" . $userKey . ") as c from plog
                        where not hidden and event = 4 and date >= " . (int)$periodSince . " and date <= " . (int)$now . "
                        and (" . $flatFilter . ")
                    ";
                    $wau = $this->chSelect($qWau);
                    $period = $this->chSelect($qPeriod);
                    if ($wau === null || $period === null) {
                        return false;
                    }
                    $wauN = (int)($wau[0]["c"] ?? 0);
                    $periodN = (int)($period[0]["c"] ?? 0);
                    $metricHint = "WAU: uniq phones on app-open (7d). Period bar: same metric over selected depth (days). Daily series: plog.";
                } else {
                    $wauN = $this->countDistinctSubscribersByDeviceActivity($wauSince, $now, $houseId);
                    $periodN = $this->countDistinctSubscribersByDeviceActivity($periodSince, $now, $houseId);
                    if ($wauN === null || $periodN === null) {
                        return false;
                    }
                    $metricHint = "WAU: distinct subscribers with device last_seen in 7d. Period bar: same over selected days. Small bases often have WAU≈long window if everyone opens the app weekly.";
                }

                return [
                    "metric" => "activeMobileUsers",
                    "metricHint" => $metricHint,
                    "mauSource" => $mauSource,
                    "seriesMode" => $seriesMode,
                    "days" => $days,
                    "houseId" => $houseId,
                    "series" => $outSeries,
                    "wau7" => $wauN,
                    "activeUsersPeriod" => $periodN,
                    "periodDays" => $days,
                ];
            }

            public function getEvents(array $opts) {
                $houseId = isset($opts["houseId"]) ? (int)$opts["houseId"] : 0;
                $phone = isset($opts["phone"]) ? trim((string)$opts["phone"]) : "";
                $limit = isset($opts["limit"]) ? (int)$opts["limit"] : 100;
                if ($limit < 1) {
                    $limit = 1;
                }
                if ($limit > 500) {
                    $limit = 500;
                }
                $until = isset($opts["until"]) ? (int)$opts["until"] : time();
                $sinceDefault = $until - 62 * 86400;
                $since = isset($opts["since"]) ? (int)$opts["since"] : $sinceDefault;
                if ($since > $until) {
                    $t = $since;
                    $since = $until;
                    $until = $t;
                }

                if ($houseId <= 0) {
                    return [
                        "events" => [],
                        "since" => $since,
                        "until" => $until,
                        "limit" => $limit,
                    ];
                }

                $flatIds = ($phone !== "")
                    ? $this->flatIdsForHouseAndPhone($houseId, $phone)
                    : $this->flatIdsForHouse($houseId);
                $flatFilter = $this->flatFilterSql($flatIds);

                $q = "
                    select
                        date,
                        event_uuid,
                        flat_id,
                        event,
                        opened,
                        preview,
                        image_uuid,
                        toJSONString(domophone) as domophone,
                        toJSONString(phones) as phones,
                        rfid,
                        code
                    from plog
                    where
                        not hidden
                        and date >= " . (int)$since . "
                        and date <= " . (int)$until . "
                        and (" . $flatFilter . ")
                    order by date desc
                    limit " . (int)$limit . "
                ";
                $rows = $this->chSelect($q);
                if ($rows === null) {
                    return false;
                }

                $flatIdsIn = [];
                foreach ($rows as $row) {
                    if (isset($row["flat_id"])) {
                        $flatIdsIn[] = (int)$row["flat_id"];
                    }
                }
                $addrMap = $this->mapFlatAddressByIds($flatIdsIn);
                foreach ($rows as &$row) {
                    $fid = isset($row["flat_id"]) ? (int)$row["flat_id"] : 0;
                    if ($fid && isset($addrMap[$fid])) {
                        $row["flatNumber"] = $addrMap[$fid]["flatNumber"];
                        $row["houseFull"] = $addrMap[$fid]["houseFull"];
                        $row["addressLine"] = $addrMap[$fid]["addressLine"];
                    } else {
                        $row["flatNumber"] = null;
                        $row["houseFull"] = null;
                        $row["addressLine"] = null;
                    }
                    $row["eventUserPhone"] = $this->extractPlogUserPhone($row["phones"] ?? null);
                }
                unset($row);

                return [
                    "events" => $rows,
                    "since" => $since,
                    "until" => $until,
                    "limit" => $limit,
                ];
            }
        }
    }


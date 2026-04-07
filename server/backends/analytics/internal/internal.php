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
             * Нулевой UUID в CH — кадра в GridFS нет.
             */
            private function stripNilImageUuid($v) {
                if ($v === null || $v === "") {
                    return null;
                }
                if (!is_scalar($v) || is_bool($v)) {
                    return null;
                }
                $s = trim((string)$v);
                if ($s === "") {
                    return null;
                }
                $hex = strtolower(str_replace("-", "", $s));
                if (strlen($hex) === 32 && ctype_xdigit($hex) && $hex === str_repeat("0", 32)) {
                    return null;
                }
                return $s;
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
                    if (isset($row["event_uuid"])) {
                        $row["event_uuid"] = trim((string)$row["event_uuid"]);
                    }
                    if (!array_key_exists("image_uuid", $row) && array_key_exists("Image_UUID", $row)) {
                        $row["image_uuid"] = $row["Image_UUID"];
                    }
                    if (array_key_exists("image_uuid", $row)) {
                        $iu = $row["image_uuid"];
                        if ($iu !== null && !is_scalar($iu)) {
                            $iu = is_object($iu) && method_exists($iu, "__toString") ? (string)$iu : null;
                        }
                        $row["image_uuid"] = $this->stripNilImageUuid($iu);
                    }
                    $row["camera_id"] = 0;
                    if (isset($row["domophone"]) && is_string($row["domophone"]) && $row["domophone"] !== "") {
                        $dj = json_decode($row["domophone"], true);
                        if (is_array($dj) && isset($dj["camera_id"])) {
                            $row["camera_id"] = (int)$dj["camera_id"];
                        }
                    }
                    unset($row["domophone"]);
                }
                unset($row);

                return [
                    "events" => $rows,
                    "since" => $since,
                    "until" => $until,
                    "limit" => $limit,
                ];
            }

            /**
             * Полуинтервал вокруг времени события (сек), из config backends.analytics.plog_archive_half_duration_sec.
             */
            private function plogArchiveHalfDurationSec(): int {
                $h = (int)(@$this->config["backends"]["analytics"]["plog_archive_half_duration_sec"] ?? 20);
                if ($h < 5) {
                    $h = 5;
                }
                if ($h > 300) {
                    $h = 300;
                }
                return $h;
            }

            /**
             * Одна строка plog для архива DVR / превью (дата, domophone, кадр).
             */
            private function selectPlogEventForArchive(int $houseId, string $eventUuid): ?array {
                if ($houseId <= 0) {
                    return null;
                }
                $eventUuid = trim($eventUuid);
                if (!preg_match(
                    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                    $eventUuid
                )) {
                    return null;
                }
                $flatIds = $this->flatIdsForHouse($houseId);
                $flatFilter = $this->flatFilterSql($flatIds);
                if ($flatFilter === "1=0") {
                    return null;
                }
                $eu = strtolower($eventUuid);
                $q = "
                    select
                        date,
                        toJSONString(domophone) as domophone,
                        image_uuid
                    from plog
                    where
                        event_uuid = toUUID('" . $eu . "')
                        and not hidden
                        and (" . $flatFilter . ")
                    limit 1
                ";
                $rows = $this->chSelect($q);
                if ($rows === null || !count($rows)) {
                    return null;
                }
                return $rows[0];
            }

            private function fetchBinaryFromUrl(string $url, int $maxBytes = 9437184): ?array {
                if ($url === "") {
                    return null;
                }
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);
                if ($data === false || $code < 200 || $code >= 300) {
                    return null;
                }
                $len = strlen($data);
                if ($len === 0 || $len > $maxBytes) {
                    return null;
                }
                return [
                    "data" => $data,
                    "contentType" => is_string($ct) ? $ct : "",
                ];
            }

            /**
             * Только настоящее растровое изображение (не mp4-«превью» Nimble/Flussonic).
             */
            private function isRasterImagePayload(string $data, string $contentTypeHeader): bool {
                if ($contentTypeHeader !== "" && preg_match('#^image/(jpeg|jpg|png|gif|webp|bmp|tiff)\b#i', $contentTypeHeader)) {
                    return true;
                }
                $n = strlen($data);
                if ($n >= 3 && $data[0] === "\xFF" && $data[1] === "\xD8" && $data[2] === "\xFF") {
                    return true;
                }
                if ($n >= 8 && substr($data, 0, 8) === "\x89PNG\r\n\x1a\n") {
                    return true;
                }
                if ($n >= 6 && (str_starts_with($data, "GIF87a") || str_starts_with($data, "GIF89a"))) {
                    return true;
                }
                if ($n >= 12 && substr($data, 0, 4) === "RIFF" && substr($data, 8, 4) === "WEBP") {
                    return true;
                }
                return false;
            }

            private function isMp4Payload(string $data, string $contentTypeHeader): bool {
                if ($contentTypeHeader !== "" && preg_match('#^video/mp4\b#i', $contentTypeHeader)) {
                    return true;
                }
                $n = strlen($data);
                if ($n >= 12 && substr($data, 4, 4) === "ftyp") {
                    return true;
                }
                return false;
            }

            /**
             * Кадр JPEG из короткого mp4 (Flussonic *-preview.mp4, Nimble dvr_thumbnail_*.mp4).
             */
            private function rasterFromShortMp4(string $mp4Binary, float $seekSec = 0.5): ?string {
                $len = strlen($mp4Binary);
                if ($len < 64 || $len > 10 * 1024 * 1024) {
                    return null;
                }
                $ffmpeg = "/usr/bin/ffmpeg";
                if (!is_executable($ffmpeg)) {
                    return null;
                }
                $dir = sys_get_temp_dir();
                $id = bin2hex(random_bytes(8));
                $in = $dir . "/rbt_an_prv_" . $id . ".mp4";
                $out = $dir . "/rbt_an_prv_" . $id . ".jpg";
                if (file_put_contents($in, $mp4Binary) === false) {
                    return null;
                }
                $seek = max(0.0, min(60.0, $seekSec));
                $ss = $seek > 0.001 ? (" -ss " . escapeshellarg((string)$seek)) : "";
                $cmd = $ffmpeg . " -hide_banner -loglevel error" . $ss
                    . " -i " . escapeshellarg($in) . " -frames:v 1 -q:v 4 -update 1 -y " . escapeshellarg($out) . " 2>/dev/null";
                exec($cmd, $unused, $ret);
                $jpeg = (is_file($out) && $ret === 0) ? (string)file_get_contents($out) : "";
                @unlink($in);
                @unlink($out);
                if ($jpeg === "" || strlen($jpeg) < 1024) {
                    return null;
                }
                if ($jpeg[0] !== "\xFF" || $jpeg[1] !== "\xD8") {
                    return null;
                }
                return $jpeg;
            }

            private function normalizePreviewContentType(string $contentType): string {
                $contentType = trim(explode(";", $contentType, 2)[0]);
                if ($contentType !== "" && preg_match('#^image/#i', $contentType)) {
                    return $contentType;
                }
                return "image/jpeg";
            }

            /**
             * Кадр plog из GridFS (логика как в api analytics camshot).
             *
             * @return array{contentType: string, base64: string}|null
             */
            private function loadPlogCamshotPreview(string $rawImageId): ?array {
                $rawImageId = trim($rawImageId);
                if ($rawImageId === "") {
                    return null;
                }
                $files = loadBackend("files");
                if (!$files) {
                    return null;
                }
                $fromPlog = $files->plogImageIdToStorageId($rawImageId);
                $fromLegacy = strtolower((string)$files->fromGUIDv4($rawImageId));
                $candidates = [];
                if ($fromLegacy !== "" && strlen($fromLegacy) === 24) {
                    $candidates[] = $fromLegacy;
                }
                if ($fromPlog !== "" && strlen($fromPlog) === 24) {
                    $p = strtolower($fromPlog);
                    if (!in_array($p, $candidates, true)) {
                        $candidates[] = $p;
                    }
                }
                if (!count($candidates)) {
                    return null;
                }
                $maxBytes = 12 * 1024 * 1024;
                foreach ($candidates as $uuid) {
                    if ($uuid === str_repeat("0", 24)) {
                        continue;
                    }
                    try {
                        $t = $files->getFile($uuid);
                        if (!$t || empty($t["stream"])) {
                            continue;
                        }
                        $fi = $t["fileInfo"] ?? null;
                        $ct = "image/jpeg";
                        if ($fi && isset($fi->metadata)) {
                            $md = $fi->metadata;
                            $cval = null;
                            if (is_object($md) && isset($md->contentType)) {
                                $cval = $md->contentType;
                            } elseif (is_array($md) && isset($md["contentType"])) {
                                $cval = $md["contentType"];
                            }
                            if ($cval !== null && $cval !== "") {
                                $ct = (string)$cval;
                            }
                        }
                        $body = stream_get_contents($t["stream"], $maxBytes + 1);
                        if ($body === false || strlen($body) > $maxBytes || strlen($body) === 0) {
                            continue;
                        }
                        if (str_starts_with(strtolower($ct), "video/")) {
                            continue;
                        }
                        return [
                            "contentType" => $this->normalizePreviewContentType($ct),
                            "base64" => base64_encode($body),
                        ];
                    } catch (\Throwable $e) {
                        error_log("analytics preview plog: " . $e->getMessage());
                    }
                }
                return null;
            }

            /**
             * @inheritDoc
             */
            public function getDvrArchiveVideoUrlForEvent(int $houseId, string $eventUuid) {
                $row = $this->selectPlogEventForArchive($houseId, $eventUuid);
                if ($row === null) {
                    return false;
                }
                $date = isset($row["date"]) ? (int)$row["date"] : 0;
                if ($date <= 0) {
                    return false;
                }
                $cameraId = 0;
                if (isset($row["domophone"]) && is_string($row["domophone"]) && $row["domophone"] !== "") {
                    $dj = json_decode($row["domophone"], true);
                    if (is_array($dj) && isset($dj["camera_id"])) {
                        $cameraId = (int)$dj["camera_id"];
                    }
                }
                if ($cameraId <= 0) {
                    return false;
                }
                $households = loadBackend("households");
                if (!$households) {
                    return false;
                }
                $cams = $households->getCameras("id", $cameraId);
                if (!$cams || !count($cams)) {
                    return false;
                }
                $cam = $cams[0];
                if (empty($cam["dvrStream"])) {
                    return false;
                }
                $dvr = loadBackend("dvr");
                if (!$dvr) {
                    return false;
                }
                $half = $this->plogArchiveHalfDurationSec();
                $start = $date - $half;
                $finish = $date + $half;
                $url = $dvr->getUrlOfRecord($cam, 0, $start, $finish);
                if (!$url || !is_string($url)) {
                    return false;
                }
                return [
                    "url" => $url,
                    "start" => $start,
                    "finish" => $finish,
                ];
            }

            /**
             * @inheritDoc
             */
            public function getEventMediaPreview(int $houseId, string $eventUuid) {
                $row = $this->selectPlogEventForArchive($houseId, $eventUuid);
                if ($row === null) {
                    return null;
                }
                $date = isset($row["date"]) ? (int)$row["date"] : 0;
                $iuRaw = $row["image_uuid"] ?? null;
                if ($iuRaw !== null && !is_scalar($iuRaw) && !(is_object($iuRaw) && method_exists($iuRaw, "__toString"))) {
                    $iuRaw = null;
                }
                $imageUuid = $this->stripNilImageUuid($iuRaw !== null ? (string)$iuRaw : null);

                $cameraId = 0;
                if (isset($row["domophone"]) && is_string($row["domophone"]) && $row["domophone"] !== "") {
                    $dj = json_decode($row["domophone"], true);
                    if (is_array($dj) && isset($dj["camera_id"])) {
                        $cameraId = (int)$dj["camera_id"];
                    }
                }

                $cam = null;
                if ($cameraId > 0) {
                    $households = loadBackend("households");
                    if ($households) {
                        $cams = $households->getCameras("id", $cameraId);
                        if ($cams && count($cams)) {
                            $cam = $cams[0];
                        }
                    }
                }

                $half = $this->plogArchiveHalfDurationSec();
                $midTime = $date;

                $hasVideo = false;
                if ($cam && !empty($cam["dvrStream"])) {
                    $dvr = loadBackend("dvr");
                    if ($dvr) {
                        $start = $date - $half;
                        $finish = $date + $half;
                        $vurl = $dvr->getUrlOfRecord($cam, 0, $start, $finish);
                        $hasVideo = $vurl && is_string($vurl);
                    }
                }

                $preview = null;
                $previewSource = "none";

                if ($cam && !empty($cam["dvrStream"])) {
                    $dvr = loadBackend("dvr");
                    if ($dvr) {
                        $shotUrl = $dvr->getUrlOfScreenshot($cam, $midTime);
                        if ($shotUrl && is_string($shotUrl)) {
                            $bin = $this->fetchBinaryFromUrl($shotUrl, 10 * 1024 * 1024);
                            if ($bin && $this->isRasterImagePayload($bin["data"], $bin["contentType"])) {
                                $preview = [
                                    "contentType" => $this->normalizePreviewContentType($bin["contentType"]),
                                    "base64" => base64_encode($bin["data"]),
                                ];
                                $previewSource = "dvr";
                            } elseif ($bin && $this->isMp4Payload($bin["data"], $bin["contentType"])) {
                                // Короткий *-preview.mp4 (Flussonic/Nimble) — кадр с начала ролика (-update 1 для image2).
                                $jpeg = $this->rasterFromShortMp4($bin["data"], 0.0);
                                if ($jpeg !== null) {
                                    $preview = [
                                        "contentType" => "image/jpeg",
                                        "base64" => base64_encode($jpeg),
                                    ];
                                    $previewSource = "dvr";
                                }
                            }
                        }
                    }
                }

                if ($preview === null && $imageUuid !== null && $imageUuid !== "") {
                    $plogShot = $this->loadPlogCamshotPreview($imageUuid);
                    if ($plogShot) {
                        $preview = [
                            "contentType" => $plogShot["contentType"],
                            "base64" => $plogShot["base64"],
                        ];
                        $previewSource = "plog";
                    }
                }

                return [
                    "preview" => $preview,
                    "previewSource" => $previewSource,
                    "hasVideo" => $hasVideo,
                ];
            }
        }
    }


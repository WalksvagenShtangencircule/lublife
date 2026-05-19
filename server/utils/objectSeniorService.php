<?php

    /**
     * Данные и проверки для ЛК старшего по объекту и для админского CRUD.
     */

    class ObjectSeniorService {

        /**
         * PDOExt с опцией singlify возвращает либо одну строку (assoc), либо false.
         * Не использовать $rows[0] — у assoc нет числового ключа 0.
         *
         * @param mixed $rows
         */
        private static function singlifyOneRow($rows): ?array {
            if ($rows === false || $rows === null) {
                return null;
            }
            if (!is_array($rows)) {
                return null;
            }
            if (array_key_exists(0, $rows) && is_array($rows[0])) {
                return $rows[0];
            }
            return $rows;
        }

        public static function rowById($db, int $id): ?array {
            $rows = $db->get(
                "SELECT s.*, h.house_full AS house_full FROM houses_object_seniors s LEFT JOIN addresses_houses h ON h.address_house_id = s.address_house_id WHERE s.house_object_senior_id = :id",
                [ "id" => $id ],
                [],
                [ "singlify" ]
            );
            return self::singlifyOneRow($rows);
        }

        public static function rowBySlug($db, string $slug): ?array {
            $slug = trim($slug);
            if ($slug === "") {
                return null;
            }
            $rows = $db->get(
                "SELECT s.*, h.house_full AS house_full FROM houses_object_seniors s LEFT JOIN addresses_houses h ON h.address_house_id = s.address_house_id WHERE lower(s.slug) = lower(:slug)",
                [ "slug" => $slug ],
                [],
                [ "singlify" ]
            );
            return self::singlifyOneRow($rows);
        }

        /**
         * @return int[]|null null — все квартиры дома; иначе список house_flat_id
         */
        public static function scopedFlatIds($db, int $seniorId): ?array {
            $rows = $db->get(
                "SELECT house_flat_id FROM houses_object_senior_flats WHERE house_object_senior_id = :id",
                [ "id" => $seniorId ],
                [],
                [ "silent" ]
            );
            if ($rows === false) {
                return null;
            }
            if (!count($rows)) {
                return null;
            }
            return array_values(array_unique(array_map("intval", array_column($rows, "house_flat_id"))));
        }

        public static function flatBelongsToHouse($db, int $houseId, int $flatId): bool {
            $n = $db->get(
                "SELECT 1 AS x FROM houses_flats WHERE house_flat_id = :f AND address_house_id = :h LIMIT 1",
                [ "f" => $flatId, "h" => $houseId ],
                [],
                [ "singlify" ]
            );
            return $n && count($n) > 0;
        }

        public static function flatAllowedForSenior($db, array $seniorRow, ?array $scopedFlatIds, int $flatId): bool {
            if (!self::flatBelongsToHouse($db, (int)$seniorRow["address_house_id"], $flatId)) {
                return false;
            }
            if ($scopedFlatIds === null) {
                return true;
            }
            return in_array($flatId, $scopedFlatIds, true);
        }

        public static function listAll($db, ?int $houseId = null): array {
            $sql = "SELECT s.house_object_senior_id, s.address_house_id, s.slug, s.login, s.title, s.can_view_events, s.can_manage_subscribers, s.can_manage_entrance_access, s.created_at, h.house_full AS house_full FROM houses_object_seniors s LEFT JOIN addresses_houses h ON h.address_house_id = s.address_house_id";
            $p = [];
            if ($houseId !== null && $houseId > 0) {
                $sql .= " WHERE s.address_house_id = :hid";
                $p["hid"] = $houseId;
            }
            $sql .= " ORDER BY s.house_object_senior_id DESC";
            $rows = $db->get($sql, $p, [], [ "silent" ]);
            return is_array($rows) ? $rows : [];
        }

        public static function deleteSenior($db, int $id): bool {
            return $db->modify("DELETE FROM houses_object_seniors WHERE house_object_senior_id = :id", [ "id" => $id ]) !== false;
        }

        public static function setScopedFlats($db, int $seniorId, array $flatIds): bool {
            if ($db->modify("DELETE FROM houses_object_senior_flats WHERE house_object_senior_id = :id", [ "id" => $seniorId ]) === false) {
                return false;
            }
            foreach ($flatIds as $fid) {
                $fid = (int)$fid;
                if ($fid <= 0) {
                    continue;
                }
                if ($db->insert(
                    "INSERT INTO houses_object_senior_flats (house_object_senior_id, house_flat_id) VALUES (:s, :f)",
                    [ "s" => $seniorId, "f" => $fid ]
                ) === false) {
                    return false;
                }
            }
            return true;
        }

        public static function entranceBelongsToFlat($db, int $flatId, int $entranceId): bool {
            $n = $db->get(
                "SELECT 1 AS x FROM houses_entrances_flats WHERE house_flat_id = :f AND house_entrance_id = :e LIMIT 1",
                [ "f" => $flatId, "e" => $entranceId ],
                [],
                [ "singlify" ]
            );
            return $n && count($n) > 0;
        }

        public static function replaceSubscriberEntrances($db, int $flatId, int $subscriberId, array $entranceIds): bool {
            if ($db->modify(
                "DELETE FROM houses_flats_subscribers_entrances WHERE house_flat_id = :f AND house_subscriber_id = :s",
                [ "f" => $flatId, "s" => $subscriberId ]
            ) === false) {
                return false;
            }
            foreach ($entranceIds as $eid) {
                $eid = (int)$eid;
                if ($eid <= 0) {
                    continue;
                }
                if (!self::entranceBelongsToFlat($db, $flatId, $eid)) {
                    continue;
                }
                if ($db->insert(
                    "INSERT INTO houses_flats_subscribers_entrances (house_flat_id, house_subscriber_id, house_entrance_id) VALUES (:f, :s, :e)",
                    [ "f" => $flatId, "s" => $subscriberId, "e" => $eid ]
                ) === false) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Владелец квартиры: role = 0 (как в мобильном API). Не более одного владельца на квартиру.
         */
        public static function setSubscriberFlatOwner($db, int $flatId, int $subscriberId, bool $isOwner): bool {
            $row = $db->get(
                "SELECT 1 AS x FROM houses_flats_subscribers WHERE house_flat_id = :f AND house_subscriber_id = :s LIMIT 1",
                [ "f" => $flatId, "s" => $subscriberId ],
                [],
                [ "singlify" ]
            );
            if (!$row || !is_array($row)) {
                return false;
            }
            if ($isOwner) {
                if ($db->modify(
                    "UPDATE houses_flats_subscribers SET role = 1 WHERE house_flat_id = :f",
                    [ "f" => $flatId ]
                ) === false) {
                    return false;
                }
                if ($db->modify(
                    "UPDATE houses_flats_subscribers SET role = 0 WHERE house_flat_id = :f AND house_subscriber_id = :s",
                    [ "f" => $flatId, "s" => $subscriberId ]
                ) === false) {
                    return false;
                }
            } else {
                if ($db->modify(
                    "UPDATE houses_flats_subscribers SET role = 1 WHERE house_flat_id = :f AND house_subscriber_id = :s",
                    [ "f" => $flatId, "s" => $subscriberId ]
                ) === false) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Все привязки камер к объекту (дом): карточка дома, квартиры, абоненты дома, камеры на входах/панелях.
         *
         * @return array<int, array{path: ?string, sources: string[]}> cameraId => { path — первый непустой по приоритету house→flat→subscriber→entrance, sources }
         */
        public static function collectObjectHouseCameraBindings($db, int $houseId): array {
            if ($houseId <= 0) {
                return [];
            }
            $out = [];

            $add = function (int $cid, $path, string $src) use (&$out): void {
                if ($cid <= 0) {
                    return;
                }
                if (!isset($out[$cid])) {
                    $out[$cid] = [ "path" => null, "sources" => [] ];
                }
                if (!in_array($src, $out[$cid]["sources"], true)) {
                    $out[$cid]["sources"][] = $src;
                }
                $p = $path !== null && $path !== "" ? trim((string)$path) : "";
                if ($p !== "" && $out[$cid]["path"] === null) {
                    $out[$cid]["path"] = $p;
                }
            };

            $rows = $db->get(
                "SELECT camera_id, path FROM houses_cameras_houses WHERE address_house_id = :h",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $add((int)($r["camera_id"] ?? 0), $r["path"] ?? null, "house");
                }
            }

            $rows = $db->get(
                "SELECT hcf.camera_id, hcf.path FROM houses_cameras_flats hcf " .
                "INNER JOIN houses_flats hf ON hf.house_flat_id = hcf.house_flat_id AND hf.address_house_id = :h",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $add((int)($r["camera_id"] ?? 0), $r["path"] ?? null, "flat");
                }
            }

            $rows = $db->get(
                "SELECT DISTINCT hcs.camera_id, hcs.path FROM houses_cameras_subscribers hcs " .
                "INNER JOIN houses_flats_subscribers hfs ON hfs.house_subscriber_id = hcs.house_subscriber_id " .
                "INNER JOIN houses_flats hf ON hf.house_flat_id = hfs.house_flat_id AND hf.address_house_id = :h",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $add((int)($r["camera_id"] ?? 0), $r["path"] ?? null, "subscriber");
                }
            }

            $rows = $db->get(
                "SELECT he.camera_id, he.alt_camera_id_1, he.alt_camera_id_2, he.alt_camera_id_3, " .
                "he.alt_camera_id_4, he.alt_camera_id_5, he.alt_camera_id_6, he.alt_camera_id_7, he.path " .
                "FROM houses_entrances he " .
                "INNER JOIN houses_houses_entrances hhe ON hhe.house_entrance_id = he.house_entrance_id " .
                "WHERE hhe.address_house_id = :h",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $pathEnt = $r["path"] ?? null;
                    foreach ([
                        "camera_id",
                        "alt_camera_id_1",
                        "alt_camera_id_2",
                        "alt_camera_id_3",
                        "alt_camera_id_4",
                        "alt_camera_id_5",
                        "alt_camera_id_6",
                        "alt_camera_id_7",
                    ] as $col) {
                        $add((int)($r[$col] ?? 0), $pathEnt, "entrance");
                    }
                }
            }

            ksort($out);
            return $out;
        }

        public static function objectHouseContainsCameraId($db, int $houseId, int $cameraId): bool {
            if ($cameraId <= 0) {
                return false;
            }
            $b = self::collectObjectHouseCameraBindings($db, $houseId);
            return isset($b[$cameraId]);
        }

        /**
         * Полная строка камеры для DVR, если она входит в объект (любая из привязок).
         */
        public static function resolveSeniorViewCameraRow($db, int $houseId, int $cameraId): ?array {
            if ($houseId <= 0 || $cameraId <= 0) {
                return null;
            }
            $b = self::collectObjectHouseCameraBindings($db, $houseId);
            if (!isset($b[$cameraId])) {
                return null;
            }
            $households = loadBackend("households");
            if (!$households) {
                return null;
            }
            $list = $households->getCameras("id", $cameraId);
            if (!is_array($list) || !count($list)) {
                return null;
            }
            $cam = $list[0];
            $path = $b[$cameraId]["path"] ?? null;
            if ($path !== null && $path !== "") {
                $cam["path"] = $path;
            }
            return $cam;
        }

        private static function entranceDisplayTitle(array $e): string {
            $t = trim((string)($e["entrance"] ?? ""));
            $ty = trim((string)($e["entranceType"] ?? ""));
            if ($t !== "" && $ty !== "") {
                return $t . " (" . $ty . ")";
            }
            if ($t !== "") {
                return $t;
            }
            if ($ty !== "") {
                return $ty;
            }
            return "вход " . (int)($e["entranceId"] ?? 0);
        }

        /**
         * Первый cameraId входа, доступный для превью в ЛК (привязка к объекту).
         */
        public static function entrancePreviewCameraId($db, int $houseId, array $entranceRow): int {
            $keys = [
                "cameraId",
                "altCameraId1",
                "altCameraId2",
                "altCameraId3",
                "altCameraId4",
                "altCameraId5",
                "altCameraId6",
                "altCameraId7",
            ];
            foreach ($keys as $k) {
                $cid = (int)($entranceRow[$k] ?? 0);
                if ($cid > 0 && self::objectHouseContainsCameraId($db, $houseId, $cid)) {
                    return $cid;
                }
            }
            return 0;
        }

        /**
         * Все входы дома для вкладки «Входы» ЛК старшего.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function listHouseEntrances($db, int $houseId): array {
            if ($houseId <= 0) {
                return [];
            }
            $households = loadBackend("households");
            if (!$households) {
                return [];
            }
            $rows = $households->getEntrances("houseId", $houseId);
            if (!is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $eid = (int)($e["entranceId"] ?? 0);
                if ($eid <= 0) {
                    continue;
                }
                $out[] = [
                    "entranceId" => $eid,
                    "title" => self::entranceDisplayTitle($e),
                    "entranceType" => (string)($e["entranceType"] ?? ""),
                    "domophoneId" => (int)($e["domophoneId"] ?? 0),
                    "doorId" => (int)($e["domophoneOutput"] ?? 0),
                    "previewCameraId" => self::entrancePreviewCameraId($db, $houseId, $e),
                ];
            }
            usort($out, function ($a, $b) {
                return strcasecmp((string)$a["title"], (string)$b["title"]);
            });
            return $out;
        }

        /**
         * Открыть дверь входа (без проверки блокировок квартир, как у жильца в mobile).
         *
         * @return array{ok?: true, error?: string}
         */
        public static function openEntranceDoor($db, int $houseId, int $entranceId, string $plogDetail): array {
            if ($houseId <= 0 || $entranceId <= 0) {
                return [ "error" => "badRequest" ];
            }
            $households = loadBackend("households");
            if (!$households) {
                return [ "error" => "notFound" ];
            }
            $houseRows = $households->getEntrances("houseId", $houseId);
            if (!is_array($houseRows)) {
                return [ "error" => "notFound" ];
            }
            $entranceRow = null;
            foreach ($houseRows as $e) {
                if ((int)($e["entranceId"] ?? 0) === $entranceId) {
                    $entranceRow = $e;
                    break;
                }
            }
            if (!$entranceRow) {
                return [ "error" => "notFound" ];
            }
            $domophoneId = (int)($entranceRow["domophoneId"] ?? 0);
            $doorId = (int)($entranceRow["domophoneOutput"] ?? 0);
            if ($domophoneId <= 0) {
                return [ "error" => "domophoneUnavailable" ];
            }
            $domophone = $households->getDomophone($domophoneId);
            if (!$domophone || !is_array($domophone)) {
                return [ "error" => "domophoneUnavailable" ];
            }

            $plog = loadBackend("plog");
            $eventType = 4;

            $ext = $domophone["ext"] ?? null;
            if (is_string($ext) && $ext !== "") {
                $decoded = json_decode($ext);
                if ($decoded !== null) {
                    $ext = $decoded;
                }
            }
            $doorOpeningUrls = [];
            if (is_object($ext) && isset($ext->doorOpeningUrls)) {
                $doorOpeningUrls = (array)$ext->doorOpeningUrls;
            } elseif (is_array($ext) && isset($ext["doorOpeningUrls"])) {
                $doorOpeningUrls = (array)$ext["doorOpeningUrls"];
            }

            if (isset($doorOpeningUrls[$doorId])) {
                $url = $doorOpeningUrls[$doorId];
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    error_log("Senior LK door URL validation error for domophone id=$domophoneId ($url)");
                    return [ "error" => "domophoneUnavailable" ];
                }
                $response = @file_get_contents($url, false, stream_context_create([
                    "http" => [ "timeout" => 3.0 ],
                ]));
                if ($response === false) {
                    error_log("Senior LK error opening door for domophone id=$domophoneId via $url");
                    return [ "error" => "domophoneUnavailable" ];
                }
                if ($plog) {
                    $plog->addDoorOpenDataById(time(), $domophoneId, $eventType, $doorId, $plogDetail);
                }
                return [ "ok" => true ];
            }

            try {
                $model = loadDevice("domophone", $domophone["model"], $domophone["url"], $domophone["credentials"]);
                $model->openLock($doorId);
                if ($plog) {
                    $plog->addDoorOpenDataById(time(), $domophoneId, $eventType, $doorId, $plogDetail);
                }
                return [ "ok" => true ];
            } catch (\Exception $e) {
                error_log("Senior LK openLock failed domophone id=$domophoneId: " . $e->getMessage());
                return [ "error" => "domophoneUnavailable" ];
            }
        }

        private static function equipmentReasonText(string $reason): string {
            $map = [
                "no_preview" => "Нет превью с камеры",
                "not_registered" => "Не зарегистрирован на сервере",
                "api_unreachable" => "Не отвечает по API",
                "disabled" => "Отключён в админке",
            ];
            return $map[$reason] ?? $reason;
        }

        private static function equipmentFault(string $kind, int $id, string $name, string $reason): array {
            return [
                "kind" => $kind,
                "id" => $id,
                "name" => $name,
                "reason" => $reason,
                "reasonText" => self::equipmentReasonText($reason),
            ];
        }

        /**
         * @return array{eventServer: string, title: string}|null
         */
        private static function domophoneModelMeta(string $modelFile): ?array {
            static $cache = null;
            if ($cache === null) {
                $cache = [];
                $configs = loadBackend("configs");
                if ($configs) {
                    $models = $configs->getDomophonesModels();
                    if (is_array($models)) {
                        foreach ($models as $file => $m) {
                            if (is_array($m)) {
                                $cache[(string)$file] = [
                                    "eventServer" => trim((string)($m["eventServer"] ?? "")),
                                    "title" => trim((string)($m["title"] ?? $file)),
                                ];
                            }
                        }
                    }
                }
            }
            $key = trim($modelFile);
            return isset($cache[$key]) ? $cache[$key] : null;
        }

        private static function domophoneSysinfoOk(array $info): bool {
            if (isset($info["DeviceID"]) && trim((string)$info["DeviceID"]) !== "") {
                return true;
            }
            if (isset($info["SoftwareVersion"]) && trim((string)$info["SoftwareVersion"]) !== "") {
                return true;
            }
            return count($info) > 0;
        }

        /**
         * API-опрос панели (loadDevice + getSysinfo).
         */
        public static function probeDomophoneApi(array $domophone): bool {
            $model = trim((string)($domophone["model"] ?? ""));
            $url = trim((string)($domophone["url"] ?? ""));
            $credentials = (string)($domophone["credentials"] ?? "");
            if ($model === "" || $url === "") {
                return false;
            }
            try {
                $dev = loadDevice("domophone", $model, $url, $credentials, false);
                if (!$dev) {
                    return false;
                }
                $info = $dev->getSysinfo();
                return is_array($info) && self::domophoneSysinfoOk($info);
            } catch (\Throwable $e) {
                return false;
            }
        }

        /**
         * Проверка камер и домофонов дома для вкладки «Оборудование».
         */
        public static function checkHouseEquipmentHealth($db, int $houseId): array {
            $faults = [];
            $cameraCount = 0;
            $domophoneCount = 0;

            $households = loadBackend("households");
            $analytics = loadBackend("analytics");

            if ($households && $analytics) {
                $bindings = self::collectObjectHouseCameraBindings($db, $houseId);
                foreach ($bindings as $cameraId => $meta) {
                    $cid = (int)$cameraId;
                    if ($cid <= 0) {
                        continue;
                    }
                    $cameraCount++;
                    $name = "Камера " . $cid;
                    try {
                        $list = $households->getCameras("id", $cid);
                        if (is_array($list) && count($list) && !empty($list[0]["name"])) {
                            $name = (string)$list[0]["name"];
                        }
                        $preview = $analytics->getHouseCameraMediaPreview($houseId, $cid, null);
                        $ok = is_array($preview)
                            && !empty($preview["preview"])
                            && is_array($preview["preview"])
                            && !empty($preview["preview"]["base64"]);
                        if (!$ok) {
                            $faults[] = self::equipmentFault("camera", $cid, $name, "no_preview");
                        }
                    } catch (\Throwable $e) {
                        $faults[] = self::equipmentFault("camera", $cid, $name, "no_preview");
                    }
                }
            }

            if ($households) {
                $domophones = $households->getDomophones("house", $houseId);
                if (is_array($domophones)) {
                    $seen = [];
                    foreach ($domophones as $d) {
                        if (!is_array($d)) {
                            continue;
                        }
                        $did = (int)($d["domophoneId"] ?? 0);
                        if ($did <= 0 || isset($seen[$did])) {
                            continue;
                        }
                        $seen[$did] = true;

                        $modelFile = trim((string)($d["model"] ?? ""));
                        if ($modelFile === "virtual.json") {
                            continue;
                        }

                        if (empty($d["enabled"])) {
                            continue;
                        }

                        $domophoneCount++;
                        $meta = self::domophoneModelMeta($modelFile);
                        $name = trim((string)($d["name"] ?? ""));
                        if ($name === "") {
                            $name = $meta && $meta["title"] !== "" ? $meta["title"] : ("Панель " . $did);
                        }

                        $eventServer = $meta ? $meta["eventServer"] : "";
                        $subId = trim((string)($d["sub_id"] ?? ""));

                        try {
                            if ($eventServer === "") {
                                if (!self::probeDomophoneApi($d)) {
                                    $faults[] = self::equipmentFault("domophone", $did, $name, "api_unreachable");
                                }
                            } else {
                                $registered = ($subId !== "");
                                if (!$registered && !self::probeDomophoneApi($d)) {
                                    $faults[] = self::equipmentFault("domophone", $did, $name, "not_registered");
                                }
                            }
                        } catch (\Throwable $e) {
                            $reason = ($eventServer === "") ? "api_unreachable" : "not_registered";
                            $faults[] = self::equipmentFault("domophone", $did, $name, $reason);
                        }
                    }
                }
            }

            return [
                "allOk" => count($faults) === 0,
                "checkedAt" => time(),
                "summary" => [
                    "cameras" => $cameraCount,
                    "domophones" => $domophoneCount,
                    "faults" => count($faults),
                ],
                "faults" => $faults,
            ];
        }

        /**
         * Нормализация телефона для ЛК старшего: 11 цифр, Россия 7XXXXXXXXXX.
         */
        public static function normalizeMobile(string $raw): ?string {
            $s = trim($raw);
            if ($s === "") {
                return null;
            }
            $digits = preg_replace('/\D/', '', $s);
            if ($digits === "") {
                return null;
            }
            if (strlen($digits) === 11 && $digits[0] === "8") {
                $digits = "7" . substr($digits, 1);
            } elseif (strlen($digits) === 10) {
                $digits = "7" . $digits;
            }
            if (strlen($digits) === 11 && $digits[0] === "7") {
                return $digits;
            }
            return null;
        }

        private const PLATE_RU_LAT = [
            "А" => "A", "В" => "B", "Е" => "E", "К" => "K", "М" => "M", "Н" => "H",
            "О" => "O", "Р" => "P", "С" => "C", "Т" => "T", "У" => "Y", "Х" => "X",
        ];

        private static function plateToLatin(string $line): string {
            $s = mb_strtoupper(trim($line));
            if ($s === "") {
                return "";
            }
            $out = "";
            $len = mb_strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $ch = mb_substr($s, $i, 1);
                if (isset(self::PLATE_RU_LAT[$ch])) {
                    $out .= self::PLATE_RU_LAT[$ch];
                } elseif (preg_match('/^[A-Z0-9]$/u', $ch)) {
                    $out .= $ch;
                }
            }
            return $out;
        }

        private static function isValidPlate(string $number): bool {
            $chars = ["A", "B", "C", "E", "H", "K", "M", "O", "P", "T", "X", "Y"];
            $digits = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
            $len = strlen($number);
            if ($len < 8 || $len > 9) {
                return false;
            }
            for ($i = 0; $i < $len; $i++) {
                $c = $number[$i];
                if ($i === 0 || $i === 4 || $i === 5) {
                    if (!in_array($c, $chars, true)) {
                        return false;
                    }
                } elseif (!in_array($c, $digits, true)) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Нормализация списка госномеров (по строкам). null — ошибка формата.
         */
        public static function normalizeCarsString(string $raw): ?string {
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $out = [];
            foreach ($lines as $line) {
                $trimmed = trim((string)$line);
                if ($trimmed === "") {
                    continue;
                }
                $lat = self::plateToLatin($trimmed);
                if ($lat === "" || !self::isValidPlate($lat)) {
                    return null;
                }
                $out[] = $lat;
            }
            return implode("\n", $out);
        }

    }


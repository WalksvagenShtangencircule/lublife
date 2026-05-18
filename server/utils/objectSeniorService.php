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


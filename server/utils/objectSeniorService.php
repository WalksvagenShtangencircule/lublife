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
    }


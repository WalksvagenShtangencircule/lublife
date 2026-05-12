<?php

/**
 * @api {get} /api/objectSeniors/items список ЛК старших по объектам
 */

namespace api\objectSeniors {

    use api\api;

    class items extends api {

        public static function GET($params) {
            global $db;
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $houseId = isset($params["houseId"]) ? (int)$params["houseId"] : 0;
            $rows = \ObjectSeniorService::listAll($db, $houseId > 0 ? $houseId : null);
            $out = [];
            foreach ($rows as $r) {
                $fidRows = $db->get(
                    "SELECT house_flat_id FROM houses_object_senior_flats WHERE house_object_senior_id = :id",
                    [ "id" => (int)$r["house_object_senior_id"] ],
                    [],
                    [ "silent" ]
                );
                $flatIds = [];
                if (is_array($fidRows)) {
                    foreach ($fidRows as $fr) {
                        $flatIds[] = (int)$fr["house_flat_id"];
                    }
                }
                $out[] = [
                    "seniorId" => (int)$r["house_object_senior_id"],
                    "houseId" => (int)$r["address_house_id"],
                    "houseFull" => (string)($r["house_full"] ?? ""),
                    "slug" => (string)$r["slug"],
                    "login" => (string)$r["login"],
                    "title" => (string)($r["title"] ?? ""),
                    "can_view_events" => (int)($r["can_view_events"] ?? 0),
                    "can_manage_subscribers" => (int)($r["can_manage_subscribers"] ?? 0),
                    "can_manage_entrance_access" => (int)($r["can_manage_entrance_access"] ?? 0),
                    "created_at" => (int)($r["created_at"] ?? 0),
                    "scopedFlatIds" => $flatIds,
                ];
            }
            return api::ANSWER($out, "objectSeniors");
        }

        public static function index() {
            return [
                /* Как у справочника адресов: пункт меню виден всем с common GET, без привязки к QR-панелям. */
                "GET" => "#same(addresses,addresses,GET)",
            ];
        }
    }
}

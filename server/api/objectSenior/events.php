<?php

namespace api\objectSenior {

    use api\api;

    class events extends api {

        public static function GET($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_view_events"])) {
                return api::ERROR("accessDenied");
            }
            $a = loadBackend("analytics");
            if (!$a) {
                return api::ERROR("notFound");
            }
            $opts = [
                "houseId" => (int)($om["houseId"] ?? 0),
                "phone" => trim((string)@$params["phone"]),
                "limit" => isset($params["limit"]) ? (int)$params["limit"] : 100,
            ];
            if (isset($params["since"])) {
                $opts["since"] = (int)$params["since"];
            }
            if (isset($params["until"])) {
                $opts["until"] = (int)$params["until"];
            }
            if (isset($params["offset"])) {
                $opts["offset"] = (int)$params["offset"];
            }
            if (!empty($om["flatIds"]) && is_array($om["flatIds"])) {
                $opts["flatIds"] = array_values(array_unique(array_map("intval", $om["flatIds"])));
            }
            $flatId = isset($params["flatId"]) ? (int)$params["flatId"] : 0;
            if ($flatId > 0) {
                require_once __DIR__ . "/../../utils/objectSeniorService.php";
                $houseId = (int)($om["houseId"] ?? 0);
                $scoped = isset($om["flatIds"]) && is_array($om["flatIds"])
                    ? array_values(array_unique(array_map("intval", $om["flatIds"])))
                    : null;
                $seniorRow = [ "address_house_id" => $houseId ];
                if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                    return api::ERROR("accessDenied");
                }
                $opts["onlyFlatId"] = $flatId;
            }
            $r = $a->getEvents($opts);
            return api::ANSWER($r, $r !== false ? "events" : "notFound");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


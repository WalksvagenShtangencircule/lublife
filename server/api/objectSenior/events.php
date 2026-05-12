<?php

namespace api\objectSenior {

    use api\api;

    class events extends api {

        public static function GET($params) {
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
            if (!empty($om["flatIds"]) && is_array($om["flatIds"])) {
                $opts["flatIds"] = $om["flatIds"];
            }
            $r = $a->getEvents($opts);
            return api::ANSWER($r, $r !== false ? "events" : "notFound");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


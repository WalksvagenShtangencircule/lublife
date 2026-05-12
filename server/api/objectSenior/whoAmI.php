<?php

namespace api\objectSenior {

    use api\api;

    class whoAmI extends api {

        public static function GET($params) {
            $om = @$params["_objectSenior"];
            if (!is_array($om)) {
                return api::ERROR("accessDenied");
            }
            return api::ANSWER([
                "seniorId" => (int)($om["seniorId"] ?? 0),
                "houseId" => (int)($om["houseId"] ?? 0),
                "title" => (string)($om["title"] ?? ""),
                "houseFull" => (string)($om["houseFull"] ?? ""),
                "can_view_events" => (int)($om["can_view_events"] ?? 0),
                "can_manage_subscribers" => (int)($om["can_manage_subscribers"] ?? 0),
                "can_manage_entrance_access" => (int)($om["can_manage_entrance_access"] ?? 0),
                "flatIds" => $om["flatIds"] ?? null,
            ], "objectSenior");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


<?php

namespace api\objectSenior {

    use api\api;

    class entrances extends api {

        public static function GET($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om)) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            if ($houseId <= 0) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $list = \ObjectSeniorService::listHouseEntrances($db, $houseId);
            return api::ANSWER([ "entrances" => $list ], "__asis__");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}

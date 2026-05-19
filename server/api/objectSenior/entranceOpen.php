<?php

namespace api\objectSenior {

    use api\api;

    class entranceOpen extends api {

        public static function POST($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om)) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $entranceId = (int)@$params["entranceId"];
            if ($houseId <= 0 || $entranceId <= 0) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $seniorId = (int)($om["seniorId"] ?? 0);
            $login = trim((string)($om["login"] ?? ""));
            $plogDetail = "senior:" . ($seniorId > 0 ? $seniorId : $login);
            if ($plogDetail === "senior:") {
                $plogDetail = "senior:lk";
            }

            $result = \ObjectSeniorService::openEntranceDoor($db, $houseId, $entranceId, $plogDetail);
            if (!empty($result["ok"])) {
                return api::ANSWER([ "ok" => true ], "operationResult");
            }
            $err = (string)($result["error"] ?? "internal");
            if ($err === "domophoneUnavailable" || $err === "notFound" || $err === "badRequest") {
                return api::ERROR($err);
            }
            return api::ERROR("internal");
        }

        public static function index() {
            return [ "POST" => false ];
        }
    }
}

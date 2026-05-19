<?php

namespace api\objectSenior {

    use api\api;

    class cameraFragment extends api {

        public static function GET($params) {
            $om = @$params["_objectSenior"];
            if (!is_array($om)) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $cameraId = isset($params["_id"]) ? (int)$params["_id"] : 0;
            $from = isset($params["from"]) ? (int)$params["from"] : 0;
            $to = isset($params["to"]) ? (int)$params["to"] : 0;
            if ($houseId <= 0 || $cameraId <= 0 || $from <= 0 || $to <= 0) {
                return api::ERROR("badRequest");
            }
            $a = loadBackend("analytics");
            if (!$a) {
                return api::ERROR("notFound");
            }
            $r = $a->getDvrArchiveVideoUrlForHouseCamera($houseId, $cameraId, $from, $to);
            if ($r === false || empty($r["url"])) {
                return api::ERROR("notFound");
            }
            return api::ANSWER($r, "cameraFragment");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


<?php

namespace api\objectSenior {

    use api\api;

    class cameraPreview extends api {

        public static function GET($params) {
            $om = @$params["_objectSenior"];
            if (!is_array($om)) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $cameraId = isset($params["_id"]) ? (int)$params["_id"] : 0;
            if ($houseId <= 0 || $cameraId <= 0) {
                return api::ERROR("badRequest");
            }
            $at = null;
            if (isset($params["at"])) {
                $at = (int)$params["at"];
                if ($at <= 0) {
                    $at = null;
                }
            }
            $a = loadBackend("analytics");
            if (!$a) {
                return api::ERROR("notFound");
            }
            $r = $a->getHouseCameraMediaPreview($houseId, $cameraId, $at);
            return api::ANSWER($r, "cameraPreview");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


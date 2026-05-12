<?php

namespace api\objectSenior {

    use api\api;

    class eventPreview extends api {

        public static function GET($params) {
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_view_events"])) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $eventUuid = isset($params["_id"]) ? trim((string)$params["_id"]) : "";
            if ($houseId <= 0 || $eventUuid === "") {
                return api::ERROR("badRequest");
            }
            $restrict = null;
            if (!empty($om["flatIds"]) && is_array($om["flatIds"])) {
                $restrict = $om["flatIds"];
            }
            $a = loadBackend("analytics");
            if (!$a) {
                return api::ERROR("notFound");
            }
            $r = $a->getEventMediaPreview($houseId, $eventUuid, $restrict);
            if ($r === null) {
                return api::ERROR("notFound");
            }
            return api::ANSWER($r, "eventPreview");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


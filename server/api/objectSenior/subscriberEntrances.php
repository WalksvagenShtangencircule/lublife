<?php

namespace api\objectSenior {

    use api\api;

    class subscriberEntrances extends api {

        public static function PUT($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_entrance_access"])) {
                return api::ERROR("accessDenied");
            }
            $flatId = (int)@$params["flatId"];
            $subscriberId = (int)@$params["subscriberId"];
            $entranceIds = @$params["entranceIds"];
            if ($flatId <= 0 || $subscriberId <= 0 || !is_array($entranceIds)) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => (int)($om["houseId"] ?? 0) ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            $eids = [];
            foreach ($entranceIds as $e) {
                $eids[] = (int)$e;
            }
            if (!\ObjectSeniorService::replaceSubscriberEntrances($db, $flatId, $subscriberId, $eids)) {
                return api::ERROR("internal");
            }
            $queue = loadBackend("queue");
            if ($queue) {
                $queue->changed("subscriber", $subscriberId);
                $queue->changed("flat", $flatId);
            }
            return api::ANSWER(true, "operationResult");
        }

        public static function index() {
            return [ "PUT" => false ];
        }
    }
}


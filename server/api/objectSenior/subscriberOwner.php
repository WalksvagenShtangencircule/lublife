<?php

namespace api\objectSenior {

    use api\api;

    class subscriberOwner extends api {

        public static function PUT($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $flatId = (int)@$params["flatId"];
            $subscriberId = (int)@$params["subscriberId"];
            $raw = @$params["isOwner"];
            $isOwner = ($raw === true || $raw === 1 || $raw === "1" || $raw === "true");
            $isNotOwner = ($raw === false || $raw === 0 || $raw === "0" || $raw === "false");
            if ($flatId <= 0 || $subscriberId <= 0 || (!$isOwner && !$isNotOwner)) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => (int)($om["houseId"] ?? 0) ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            if (!\ObjectSeniorService::setSubscriberFlatOwner($db, $flatId, $subscriberId, $isOwner)) {
                return api::ERROR("badRequest");
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

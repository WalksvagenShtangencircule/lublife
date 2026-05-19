<?php

namespace api\objectSenior {

    use api\api;

    /**
     * Ручная блокировка квартиры (houses_flats.manual_block) для ЛК старшего.
     */
    class flatBlock extends api {

        public static function PUT($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $flatId = (int)@$params["flatId"];
            if ($houseId <= 0 || $flatId <= 0) {
                return api::ERROR("badRequest");
            }
            if (!array_key_exists("manualBlock", $params)) {
                return api::ERROR("badRequest");
            }
            $manualBlock = (int)$params["manualBlock"];
            if ($manualBlock !== 0 && $manualBlock !== 1) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => $houseId ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }

            $ok = $households->modifyFlat($flatId, [ "manualBlock" => $manualBlock ]);
            if (!$ok) {
                return api::ERROR("internal");
            }

            $flat = $households->getFlat($flatId);
            $mb = (int)($flat["manualBlock"] ?? 0);
            $ab = (int)($flat["adminBlock"] ?? 0);
            $autob = (int)($flat["autoBlock"] ?? 0);
            $blocked = ($mb > 0 || $ab > 0 || $autob > 0);

            $queue = loadBackend("queue");
            if ($queue) {
                $queue->changed("flat", $flatId);
            }

            return api::ANSWER([
                "ok" => true,
                "manualBlock" => $mb,
                "adminBlock" => $ab,
                "autoBlock" => $autob,
                "blocked" => $blocked,
            ], "operationResult");
        }

        public static function index() {
            return [ "PUT" => false ];
        }
    }
}

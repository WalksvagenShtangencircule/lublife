<?php

namespace api\objectSenior {

    use api\api;

    /**
     * Сохранение списка автомобильных номеров квартиры (houses_flats.cars) для ЛК старшего.
     */
    class flatCars extends api {

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
            if (!array_key_exists("cars", $params)) {
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

            $cars = $params["cars"];
            if (!is_string($cars)) {
                $cars = "";
            }
            $carsNorm = \ObjectSeniorService::normalizeCarsString($cars);
            if ($carsNorm === null) {
                return api::ERROR("badRequest");
            }

            $ok = $households->modifyFlat($flatId, [ "cars" => $carsNorm ]);
            if (!$ok) {
                return api::ERROR("internal");
            }

            $flat = $households->getFlat($flatId);
            $outCars = ($flat && array_key_exists("cars", $flat)) ? $flat["cars"] : null;

            $queue = loadBackend("queue");
            if ($queue) {
                $queue->changed("flat", $flatId);
            }

            return api::ANSWER([ "ok" => true, "cars" => $outCars ], "operationResult");
        }

        public static function index() {
            return [ "PUT" => false ];
        }
    }
}


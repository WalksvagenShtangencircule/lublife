<?php

namespace api\objectSenior {

    use api\api;

    class flats extends api {

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
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;

            $rows = $db->get(
                "SELECT house_flat_id, flat, floor FROM houses_flats WHERE address_house_id = :h ORDER BY floor, flat",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if ($rows === false) {
                return api::ERROR("internal");
            }
            $out = [];
            foreach ($rows as $r) {
                $fid = (int)$r["house_flat_id"];
                if ($scoped !== null && !in_array($fid, $scoped, true)) {
                    continue;
                }
                $households = loadBackend("households");
                $entrances = [];
                if ($households) {
                    $flat = $households->getFlat($fid);
                    if ($flat && !empty($flat["entrances"])) {
                        foreach ($flat["entrances"] as $e) {
                            $entrances[] = [
                                "entranceId" => (int)($e["entranceId"] ?? 0),
                                "apartment" => (string)($e["apartment"] ?? ""),
                                "domophoneId" => (int)($e["domophoneId"] ?? 0),
                            ];
                        }
                    }
                }
                $out[] = [
                    "flatId" => $fid,
                    "flat" => (string)($r["flat"] ?? ""),
                    "floor" => (string)($r["floor"] ?? ""),
                    "entrances" => $entrances,
                ];
            }
            return api::ANSWER($out, "flats");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


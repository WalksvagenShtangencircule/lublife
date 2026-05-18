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
                "SELECT house_flat_id, flat, floor, cars FROM houses_flats WHERE address_house_id = :h ORDER BY floor, flat",
                [ "h" => $houseId ],
                [],
                [ "silent" ]
            );
            if ($rows === false) {
                return api::ERROR("internal");
            }

            $households = loadBackend("households");
            $houseEntrances = [];
            if ($households && !empty($om["can_manage_entrance_access"])) {
                $he = $households->getEntrances("houseId", $houseId);
                if (is_array($he)) {
                    foreach ($he as $row) {
                        $eid = (int)($row["entranceId"] ?? 0);
                        if ($eid <= 0) {
                            continue;
                        }
                        $houseEntrances[] = [
                            "entranceId" => $eid,
                            "entranceTitle" => trim((string)($row["entrance"] ?? "")),
                            "entranceType" => (string)($row["entranceType"] ?? ""),
                            "domophoneId" => (int)($row["domophoneId"] ?? 0),
                        ];
                    }
                }
            }

            $out = [];
            foreach ($rows as $r) {
                $fid = (int)$r["house_flat_id"];
                if ($scoped !== null && !in_array($fid, $scoped, true)) {
                    continue;
                }
                $entrances = [];
                if ($households) {
                    $flat = $households->getFlat($fid);
                    if ($flat && !empty($flat["entrances"])) {
                        foreach ($flat["entrances"] as $e) {
                            $entrances[] = [
                                "entranceId" => (int)($e["entranceId"] ?? 0),
                                "apartment" => (string)($e["apartment"] ?? ""),
                                "domophoneId" => (int)($e["domophoneId"] ?? 0),
                                "entranceTitle" => (string)($e["entranceTitle"] ?? ""),
                                "entranceType" => (string)($e["entranceType"] ?? ""),
                                "domophoneName" => (string)($e["domophoneName"] ?? ""),
                                "apartmentLevels" => (string)($e["apartmentLevels"] ?? ""),
                            ];
                        }
                    }
                }
                $out[] = [
                    "flatId" => $fid,
                    "flat" => (string)($r["flat"] ?? ""),
                    "floor" => (string)($r["floor"] ?? ""),
                    "cars" => isset($r["cars"]) && $r["cars"] !== null && $r["cars"] !== "" ? (string)$r["cars"] : null,
                    "entrances" => $entrances,
                ];
            }

            $payload = [ "flats" => $out ];
            if (!empty($om["can_manage_entrance_access"])) {
                $payload["houseEntrances"] = $houseEntrances;
            }
            return api::ANSWER($payload, "__asis__");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}

<?php

namespace api\objectSenior {

    use api\api;

    /**
     * Сохранение привязки входов к квартире (houses_entrances_flats) для ЛК старшего.
     */
    class flatEntrances extends api {

        public static function PUT($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_entrance_access"])) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $flatId = (int)@$params["flatId"];
            $entranceIds = @$params["entranceIds"];
            if ($houseId <= 0 || $flatId <= 0 || !is_array($entranceIds)) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => $houseId ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            $clean = [];
            foreach ($entranceIds as $e) {
                $ei = (int)$e;
                if ($ei > 0) {
                    $clean[$ei] = true;
                }
            }
            $eids = array_keys($clean);
            if (!count($eids)) {
                return api::ERROR("badRequest");
            }

            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }

            $houseRows = $households->getEntrances("houseId", $houseId);
            if (!is_array($houseRows)) {
                return api::ERROR("internal");
            }
            $allowed = [];
            foreach ($houseRows as $hr) {
                $hid = (int)($hr["entranceId"] ?? 0);
                if ($hid > 0) {
                    $allowed[$hid] = true;
                }
            }
            foreach ($eids as $ei) {
                if (empty($allowed[$ei])) {
                    return api::ERROR("badRequest");
                }
            }

            $flat = $households->getFlat($flatId);
            if (!$flat || !is_array($flat)) {
                return api::ERROR("badRequest");
            }

            $flatLabel = (string)($flat["flat"] ?? "1");
            $defaultAp = 1;
            if (preg_match('/\d+/', $flatLabel, $m)) {
                $defaultAp = (int)$m[0];
            }
            if ($defaultAp <= 0 || $defaultAp > 9999) {
                $defaultAp = 1;
            }

            $oldByEid = [];
            foreach (($flat["entrances"] ?? []) as $er) {
                $ei = (int)($er["entranceId"] ?? 0);
                if ($ei > 0) {
                    $oldByEid[$ei] = $er;
                }
            }

            $apartmentsAndLevels = [];
            foreach ($eids as $eid) {
                if (!empty($oldByEid[$eid])) {
                    $o = $oldByEid[$eid];
                    $ap = (int)($o["apartment"] ?? 0);
                    if ($ap <= 0 || $ap > 9999) {
                        $ap = $defaultAp;
                    }
                    $apartmentsAndLevels[$eid] = [
                        "apartment" => $ap,
                        "apartmentLevels" => (string)($o["apartmentLevels"] ?? ""),
                    ];
                } else {
                    $apartmentsAndLevels[$eid] = [
                        "apartment" => $defaultAp,
                        "apartmentLevels" => "",
                    ];
                }
            }

            $ok = $households->modifyFlat($flatId, [
                "entrances" => $eids,
                "apartmentsAndLevels" => $apartmentsAndLevels,
                "flat" => $flatLabel,
            ]);
            if (!$ok) {
                return api::ERROR("internal");
            }

            $db->modify(
                "DELETE FROM houses_flats_subscribers_entrances WHERE house_flat_id = :f AND house_entrance_id NOT IN (SELECT house_entrance_id FROM houses_entrances_flats WHERE house_flat_id = :f)",
                [ "f" => $flatId ]
            );

            $queue = loadBackend("queue");
            if ($queue) {
                $queue->changed("flat", $flatId);
            }

            return api::ANSWER([ "ok" => true ], "operationResult");
        }

        public static function index() {
            return [ "PUT" => false ];
        }
    }
}

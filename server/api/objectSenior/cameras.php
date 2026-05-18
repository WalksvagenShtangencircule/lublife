<?php

namespace api\objectSenior {

    use api\api;

    class cameras extends api {

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
            $bindings = \ObjectSeniorService::collectObjectHouseCameraBindings($db, $houseId);
            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }
            $out = [];
            foreach ($bindings as $cameraId => $meta) {
                $list = $households->getCameras("id", (int)$cameraId);
                if (!is_array($list) || !count($list)) {
                    continue;
                }
                $c = $list[0];
                $p = $meta["path"] ?? null;
                if ($p !== null && $p !== "") {
                    $c["path"] = $p;
                }
                $out[] = [
                    "cameraId" => (int)($c["cameraId"] ?? 0),
                    "name" => (string)($c["name"] ?? ""),
                    "path" => $c["path"] ?? null,
                    "enabled" => (int)($c["enabled"] ?? 0),
                    "dvrStream" => (string)($c["dvrStream"] ?? ""),
                    "status" => $c["status"] ?? null,
                    "bindings" => $meta["sources"] ?? [],
                ];
            }
            usort($out, function ($a, $b) {
                $na = strtolower($a["name"] ?: ("" . $a["cameraId"]));
                $nb = strtolower($b["name"] ?: ("" . $b["cameraId"]));
                if ($na !== $nb) {
                    return $na <=> $nb;
                }
                return ($a["cameraId"] ?? 0) <=> ($b["cameraId"] ?? 0);
            });
            return api::ANSWER([ "cameras" => $out ], "__asis__");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


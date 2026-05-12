<?php

namespace api\objectSenior {

    use api\api;

    class subscribers extends api {

        public static function GET($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            $flatId = isset($params["flatId"]) ? (int)$params["flatId"] : 0;

            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;

            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }

            if ($flatId > 0) {
                if (!\ObjectSeniorService::flatAllowedForSenior($db, [ "address_house_id" => $houseId ], $scoped, $flatId)) {
                    return api::ERROR("accessDenied");
                }
                $list = $households->getSubscribers("flatId", $flatId);
            } else {
                $list = $households->getSubscribers("houseId", $houseId);
                if ($scoped !== null && is_array($list)) {
                    $allowedSet = array_flip($scoped);
                    foreach ($list as &$sub) {
                        $nf = [];
                        if (!empty($sub["flats"]) && is_array($sub["flats"])) {
                            foreach ($sub["flats"] as $f) {
                                $fid = (int)($f["flatId"] ?? 0);
                                if ($fid && isset($allowedSet[$fid])) {
                                    $nf[] = $f;
                                }
                            }
                        }
                        $sub["flats"] = $nf;
                    }
                    unset($sub);
                }
            }

            return api::ANSWER(is_array($list) ? $list : [], "subscribers");
        }

        public static function index() {
            return [ "GET" => false ];
        }
    }
}


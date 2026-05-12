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
                if (is_array($list) && count($list) > 0) {
                    $bindRows = $db->get(
                        "SELECT house_subscriber_id, house_entrance_id FROM houses_flats_subscribers_entrances WHERE house_flat_id = :f",
                        [ "f" => $flatId ],
                        [],
                        [ "silent" ]
                    );
                    $bySub = [];
                    if (is_array($bindRows)) {
                        foreach ($bindRows as $br) {
                            $sid = (int)($br["house_subscriber_id"] ?? 0);
                            if ($sid <= 0) {
                                continue;
                            }
                            $eid = (int)($br["house_entrance_id"] ?? 0);
                            if ($eid <= 0) {
                                continue;
                            }
                            if (!isset($bySub[$sid])) {
                                $bySub[$sid] = [];
                            }
                            $bySub[$sid][] = $eid;
                        }
                    }
                    foreach ($list as &$subOne) {
                        $sid = (int)($subOne["subscriberId"] ?? 0);
                        if ($sid > 0 && !empty($bySub[$sid])) {
                            $subOne["boundEntranceIds"] = array_values(array_unique($bySub[$sid]));
                            $subOne["entranceAccessAll"] = false;
                        } else {
                            $subOne["boundEntranceIds"] = [];
                            /** Нет строк в houses_flats_subscribers_entrances — доступ со всех подъездов квартиры */
                            $subOne["entranceAccessAll"] = true;
                        }
                    }
                    unset($subOne);
                }
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


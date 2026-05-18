<?php

namespace api\objectSenior {

    use api\api;

    class subscriber extends api {

        public static function POST($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $flatId = (int)@$params["flatId"];
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $mobile = \ObjectSeniorService::normalizeMobile((string)@$params["mobile"]);
            if ($flatId <= 0 || $mobile === null) {
                return api::ERROR("badRequest");
            }
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => (int)($om["houseId"] ?? 0) ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }

            $name = trim((string)@$params["subscriberName"]);
            $pat = trim((string)@$params["subscriberPatronymic"]);
            $last = trim((string)@$params["subscriberLast"]);

            $sid = $households->addSubscriber($mobile, $name, $pat, $last, $flatId, false);
            if ($sid === false) {
                return api::ERROR("badRequest");
            }

            if (!empty($om["can_manage_entrance_access"]) && !empty($params["entranceIds"]) && is_array($params["entranceIds"])) {
                $eids = [];
                foreach ($params["entranceIds"] as $e) {
                    $eids[] = (int)$e;
                }
                if (!\ObjectSeniorService::replaceSubscriberEntrances($db, $flatId, (int)$sid, $eids)) {
                    return api::ERROR("internal");
                }
            }

            return api::ANSWER([ "subscriberId" => (int)$sid ], "subscriber");
        }

        public static function DELETE($params) {
            global $db;
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $flatId = (int)@$params["flatId"];
            $subscriberId = (int)@$params["subscriberId"];
            if ($flatId <= 0 || $subscriberId <= 0) {
                return api::ERROR("badRequest");
            }
            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;
            $seniorRow = [ "address_house_id" => (int)($om["houseId"] ?? 0) ];
            if (!\ObjectSeniorService::flatAllowedForSenior($db, $seniorRow, $scoped, $flatId)) {
                return api::ERROR("accessDenied");
            }

            $households = loadBackend("households");
            if (!$households) {
                return api::ERROR("notFound");
            }
            $db->modify(
                "DELETE FROM houses_flats_subscribers_entrances WHERE house_flat_id = :f AND house_subscriber_id = :s",
                [ "f" => $flatId, "s" => $subscriberId ]
            );
            $ok = $households->removeSubscriberFromFlat($flatId, $subscriberId);
            return api::ANSWER($ok, $ok ? "operationResult" : "badRequest");
        }

        public static function index() {
            return [
                "POST" => false,
                "DELETE" => false,
            ];
        }
    }
}


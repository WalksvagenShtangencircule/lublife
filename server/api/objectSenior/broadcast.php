<?php

namespace api\objectSenior {

    use api\api;

    /**
     * Массовая рассылка в мобильное приложение (как «Оповещение» / broadcast в карточке дома на платформе):
     * для каждого абонента дома в зоне видимости старшего вызывается тот же механизм, что POST /api/inbox/message/:subscriberId.
     */
    class broadcast extends api {

        public static function POST($params) {
            $om = @$params["_objectSenior"];
            if (!is_array($om) || empty($om["can_manage_subscribers"])) {
                return api::ERROR("accessDenied");
            }
            $houseId = (int)($om["houseId"] ?? 0);
            if ($houseId <= 0) {
                return api::ERROR("badRequest");
            }
            $title = trim((string)@$params["title"]);
            $body = trim((string)@$params["body"]);
            $action = trim((string)@$params["action"]);
            if ($title === "" || $body === "") {
                return api::ERROR("badRequest");
            }
            if ($action !== "inbox" && $action !== "money") {
                return api::ERROR("badRequest");
            }
            if (strlen($title) > 512 || strlen($body) > 32000) {
                return api::ERROR("badRequest");
            }

            require_once __DIR__ . "/../../utils/objectSeniorService.php";
            $scoped = isset($om["flatIds"]) && is_array($om["flatIds"]) ? $om["flatIds"] : null;

            $households = loadBackend("households");
            $inbox = loadBackend("inbox");
            if (!$households || !$inbox) {
                return api::ERROR("notFound");
            }

            $list = $households->getSubscribers("houseId", $houseId);
            if (!is_array($list)) {
                $list = [];
            }

            if ($scoped !== null) {
                $allowedSet = array_flip(array_values(array_unique(array_map("intval", $scoped))));
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

            $seen = [];
            $subscriberIds = [];
            foreach ($list as $sub) {
                $sid = (int)($sub["subscriberId"] ?? 0);
                if ($sid <= 0 || isset($seen[$sid])) {
                    continue;
                }
                if ($scoped !== null) {
                    if (empty($sub["flats"]) || !is_array($sub["flats"]) || !count($sub["flats"])) {
                        continue;
                    }
                }
                $seen[$sid] = true;
                $subscriberIds[] = $sid;
            }

            if (!count($subscriberIds)) {
                return api::ERROR("noSubscribers");
            }

            $ok = 0;
            $fail = 0;
            $pushTotal = 0;
            foreach ($subscriberIds as $sid) {
                $r = $inbox->sendMessage($sid, $title, $body, $action);
                if ($r !== false && is_array($r)) {
                    $ok++;
                    if (isset($r["count"])) {
                        $pushTotal += (int)$r["count"];
                    }
                } else {
                    $fail++;
                }
            }

            return api::ANSWER([
                "recipients" => count($subscriberIds),
                "deliveredSubscribers" => $ok,
                "failedSubscribers" => $fail,
                "pushTotal" => $pushTotal,
            ], "broadcast");
        }

        public static function index() {
            return [ "POST" => false ];
        }
    }
}


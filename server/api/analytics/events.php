<?php

    /**
     * @api {get} /api/analytics/events plog event feed
     */

    namespace api\analytics {

        use api\api;

        class events extends api {

            public static function GET($params) {
                $a = loadBackend("analytics");
                if (!$a) {
                    return api::ANSWER(false, "notFound");
                }
                $opts = [
                    "houseId" => @$params["houseId"],
                    "phone" => @$params["phone"],
                    "limit" => isset($params["limit"]) ? (int)$params["limit"] : 100,
                ];
                if (isset($params["since"])) {
                    $opts["since"] = (int)$params["since"];
                }
                if (isset($params["until"])) {
                    $opts["until"] = (int)$params["until"];
                }
                $r = $a->getEvents($opts);
                return api::ANSWER($r, $r !== false ? "events" : "notFound");
            }

            public static function index() {
                if (loadBackend("analytics")) {
                    return [
                        "GET" => "#same(addresses,addresses,GET)",
                    ];
                }
                return false;
            }
        }
    }


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
                $search = trim((string)@$params["search"]);
                if ($search === "") {
                    $search = trim((string)@$params["phone"]);
                }
                $opts = [
                    "houseId" => @$params["houseId"],
                    "search" => $search,
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
                        "GET" => "#same(analytics,stats,GET)",
                    ];
                }
                return false;
            }
        }
    }


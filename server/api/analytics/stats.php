<?php

    /**
     * @api {get} /api/analytics/stats DAU series over days, WAU (7d), activeUsersPeriod (uniques over days). See backends.analytics mau_source, series_mode. Replaces fixed mau30.
     */

    namespace api\analytics {

        use api\api;

        class stats extends api {

            public static function GET($params) {
                $a = loadBackend("analytics");
                if (!$a) {
                    return api::ANSWER(false, "notFound");
                }
                $days = isset($params["days"]) ? (int)$params["days"] : 30;
                $houseId = null;
                if (isset($params["houseId"]) && $params["houseId"] !== "" && $params["houseId"] !== "0") {
                    $houseId = (int)$params["houseId"];
                }
                $r = $a->getStats($days, $houseId);
                return api::ANSWER($r, $r !== false ? "stats" : "notFound");
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


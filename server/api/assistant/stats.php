<?php

    /**
     * @api {get} /api/assistant/stats быстрая статистика системы для дашборда
     *
     * @apiSuccess {Object} stats
     */

    namespace api\assistant {

        use api\api;

        class stats extends api {

            public static function GET($params) {
                $db = $params["_db"];

                $row = $db->get(
                    "SELECT
                        (SELECT COUNT(*)::int FROM addresses_houses)          AS houses,
                        (SELECT COUNT(*)::int FROM houses_flats)              AS flats,
                        (SELECT COUNT(*)::int FROM houses_subscribers_mobile) AS subscribers,
                        (SELECT COUNT(*)::int FROM houses_domophones WHERE enabled = 1) AS domophones_active,
                        (SELECT COUNT(*)::int FROM houses_domophones)         AS domophones_total,
                        (SELECT COUNT(*)::int FROM cameras)                   AS cameras,
                        (SELECT COUNT(*)::int FROM houses_flats
                         WHERE manual_block > 0 OR auto_block > 0 OR admin_block > 0) AS flats_blocked,
                        (SELECT COUNT(DISTINCT house_subscriber_id)::int
                         FROM houses_subscribers_devices
                         WHERE last_seen >= EXTRACT(EPOCH FROM now())::int - 86400 * 7) AS active_7d,
                        (SELECT COUNT(DISTINCT house_subscriber_id)::int
                         FROM houses_subscribers_devices
                         WHERE last_seen >= EXTRACT(EPOCH FROM now())::int - 86400 * 30) AS active_30d",
                    [],
                    [],
                    ["silent", "singlify"]
                );

                if (!is_array($row)) {
                    return api::ANSWER(false, "dbError");
                }

                foreach ($row as $k => $v) {
                    $row[$k] = (int) $v;
                }

                return api::ANSWER(["stats" => $row], "assistantStats");
            }

            public static function index() {
                return ["GET"];
            }
        }
    }

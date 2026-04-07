<?php

    /**
     * @api {get} /api/analytics/eventVideo/:id URL фрагмента архива DVR вокруг времени события (аналог mobile/cctv/recPrepare + DVR).
     *
     * id — UUID события (event_uuid из plog). Query: houseId (обязательно).
     * Права: #same(addresses,addresses,GET).
     */

    namespace api\analytics {

        use api\api;

        class eventVideo extends api {

            public static function GET($params) {
                $houseId = isset($params["houseId"]) ? (int)$params["houseId"] : 0;
                $eventUuid = isset($params["_id"]) ? trim((string)$params["_id"]) : "";
                if ($houseId <= 0 || $eventUuid === "") {
                    return api::ERROR("badRequest");
                }
                $a = loadBackend("analytics");
                if (!$a) {
                    return api::ERROR("notFound");
                }
                $r = $a->getDvrArchiveVideoUrlForEvent($houseId, $eventUuid);
                if ($r === false || empty($r["url"])) {
                    return api::ERROR("notFound");
                }
                return api::ANSWER($r, "eventVideo");
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

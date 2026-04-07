<?php

    /**
     * @api {get} /api/analytics/eventPreview/:id кадр для списка событий (DVR → середина окна, иначе plog), + признак mp4
     *
     * id — event_uuid. Query: houseId. Права: #same(addresses,addresses,GET).
     */

    namespace api\analytics {

        use api\api;

        class eventPreview extends api {

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
                $r = $a->getEventMediaPreview($houseId, $eventUuid);
                if ($r === null) {
                    return api::ERROR("notFound");
                }
                return api::ANSWER($r, "eventPreview");
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

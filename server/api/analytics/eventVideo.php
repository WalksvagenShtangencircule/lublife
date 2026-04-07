<?php

    /**
     * @api {get} /api/analytics/eventVideo/:id URL mp4-фрагмента архива DVR вокруг времени события.
     *
     * Источник: backends.dvr::getUrlOfRecord (тот же тип ссылок, что строит мобильный клиент для выгрузки
     * фрагмента с DVR: camera_id из domophone в plog + интервал date±plog_archive_half_duration_sec).
     * Не то же самое, что mobile/cctv/recPrepare (очередь dvrExports), но тот же медиасервер и окно по времени.
     *
     * id — event_uuid из plog. Query: houseId. Права: #same(analytics,stats,GET).
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
                        "GET" => "#same(analytics,stats,GET)",
                    ];
                }
                return false;
            }
        }
    }

<?php

    /**
     * @api {get} /api/mediaserver/audit Журнал действий модуля медиасервера.
     */

    namespace api\mediaserver {

        use api\api;

        class audit extends api {

            public static function GET($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $limit = isset($params["limit"]) ? (int)$params["limit"] : 200;
                $offset = isset($params["offset"]) ? (int)$params["offset"] : 0;
                $rows = $m->getAuditLog($limit, $offset);
                return api::ANSWER(["entries" => $rows], "mediaserverAudit");
            }

            public static function index() {
                if (loadBackend("mediaserver")) {
                    return [
                        "GET" => false,
                    ];
                }
                return false;
            }
        }
    }

<?php

    /**
     * @api {get} /api/mediaserver/streams Список потоков + камеры: приоритет ext.mediaserverStreamName / flussonicStreamName; для старых карточек — имя из URL dvrStream.
     */

    namespace api\mediaserver {

        use api\api;

        class streams extends api {

            public static function GET($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $r = $m->getStreamsOverview();
                if ($r === false) {
                    return api::ANSWER(false, "notFound");
                }
                return api::ANSWER($r, "mediaserverStreams");
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

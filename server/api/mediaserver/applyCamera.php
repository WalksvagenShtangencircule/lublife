<?php

    /**
     * @api {post} /api/mediaserver/applyCamera Записать в камеру stream (HLS) и dvrStream (embed/архив).
     */

    namespace api\mediaserver {

        use api\api;

        class applyCamera extends api {

            public static function POST($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $cameraId = isset($params["cameraId"]) ? (int)$params["cameraId"] : 0;
                $hls = trim((string)(@$params["hlsUrl"] ?: ""));
                $embed = trim((string)(@$params["embedUrl"] ?: ""));
                if ($cameraId <= 0 || $hls === "" || $embed === "") {
                    return api::ANSWER(false, "badRequest");
                }
                $ok = $m->applyUrlsToCamera($cameraId, $hls, $embed);
                return api::ANSWER($ok);
            }

            public static function index() {
                if (loadBackend("mediaserver")) {
                    return [
                        "POST" => "#same(mediaserver,stream,POST)",
                    ];
                }
                return false;
            }
        }
    }

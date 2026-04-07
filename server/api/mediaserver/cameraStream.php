<?php

    /**
     * @api {post} /api/mediaserver/cameraStream Второй шаг после POST cameras/camera: имя потока на Flussonic — ext.mediaserverStreamName; в dvrStream — HLS, в ext — embed; PUT потока с inputs = RTSP. Тело: { "cameraId": number }.
     */

    namespace api\mediaserver {

        use api\api;

        class cameraStream extends api {

            public static function POST($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $cameraId = isset($params["cameraId"]) ? (int)$params["cameraId"] : 0;
                if ($cameraId <= 0) {
                    return api::ANSWER(false, "badRequest");
                }
                $r = $m->publishStreamForCamera($cameraId);
                if (!empty($r["error"]) && in_array($r["error"], [
                    "noRtspStream",
                    "streamMustBeRtsp",
                    "noMediaserverStreamName",
                    "invalidMediaserverStreamName",
                    "rtspHostMismatch",
                    "badDvrRetentionDays",
                    "noCamera",
                    "noCamerasBackend",
                ], true)) {
                    return api::ANSWER(false, "badRequest");
                }
                if (!empty($r["error"]) && $r["error"] === "noFlussonicServer") {
                    return api::ANSWER(false, "notFound");
                }
                if (!empty($r["error"]) && $r["error"] === "applyUrlsFailed") {
                    return [
                        "400" => [
                            "error" => "applyUrlsFailed",
                            "cameraId" => $r["cameraId"],
                            "streamName" => $r["streamName"] ?? "",
                        ],
                    ];
                }
                if ($r["ok"]) {
                    return api::ANSWER([
                        "cameraId" => $r["cameraId"],
                        "streamName" => $r["streamName"],
                    ], "mediaserverCameraStream");
                }
                if (isset($r["cameraId"])) {
                    return [
                        "502" => [
                            "error" => "flussonicError",
                            "cameraId" => $r["cameraId"],
                            "streamName" => $r["streamName"] ?? "",
                            "flussonicCode" => $r["flussonic"]["code"] ?? 0,
                            "flussonicMessage" => $r["flussonic"]["error"] ?? "",
                            "flussonicBody" => isset($r["flussonic"]["raw"]) && strlen($r["flussonic"]["raw"]) > 800
                                ? (substr($r["flussonic"]["raw"], 0, 800) . "…")
                                : ($r["flussonic"]["raw"] ?? ""),
                        ],
                    ];
                }
                return api::ANSWER(false, "badRequest");
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

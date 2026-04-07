<?php

    /**
     * @api {post} /api/mediaserver/deleteStreamAndCamera Удалить поток на Flussonic и камеру. Тело: { "streamName": string, "cameraId": number }. Потока нет (404) — всё равно удаляем камеру.
     */

    namespace api\mediaserver {

        use api\api;

        class deleteStreamAndCamera extends api {

            public static function POST($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $streamName = isset($params["streamName"]) ? trim((string)$params["streamName"]) : "";
                $cameraId = isset($params["cameraId"]) ? (int)$params["cameraId"] : 0;
                if ($streamName === "" || $cameraId <= 0) {
                    return api::ANSWER(false, "badRequest");
                }
                $r = $m->deleteStreamAndCamera($streamName, $cameraId);
                if (!empty($r["error"]) && in_array($r["error"], [
                    "noStreamName",
                    "noCamera",
                    "noCamerasBackend",
                    "cameraStreamMismatch",
                    "deleteCameraFailed",
                ], true)) {
                    return api::ANSWER(false, "badRequest");
                }
                if ($r["ok"]) {
                    return api::ANSWER([
                        "cameraId" => $r["cameraId"],
                        "streamName" => $r["streamName"],
                    ], "mediaserverStream");
                }
                if (isset($r["cameraId"]) && $r["error"] === "flussonicError") {
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

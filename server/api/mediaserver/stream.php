<?php

    /**
     * @api {post} /api/mediaserver/stream Создать/обновить поток на Flussonic (PUT /streams/{name}).
     * @api {delete} /api/mediaserver/stream/:name Удалить поток (_id — имя потока).
     */

    namespace api\mediaserver {

        use api\api;

        class stream extends api {

            public static function POST($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $name = trim((string)(@$params["name"] ?: ""));
                if ($name === "") {
                    return api::ANSWER(false, "badRequest");
                }
                $body = @$params["config"];
                if (!is_array($body)) {
                    $body = [];
                }
                $r = $m->upsertStream($name, $body);
                if ($r["ok"]) {
                    return api::ANSWER(["result" => true, "code" => $r["code"]], "mediaserverStream");
                }
                return [
                    "502" => [
                        "error" => "flussonicError",
                        "flussonicCode" => $r["code"],
                        "flussonicMessage" => $r["error"],
                        "flussonicBody" => strlen($r["raw"]) > 800 ? (substr($r["raw"], 0, 800) . "…") : $r["raw"],
                    ],
                ];
            }

            public static function DELETE($params) {
                $m = loadBackend("mediaserver");
                if (!$m) {
                    return api::ANSWER(false, "notFound");
                }
                $name = trim((string)(@$params["_id"] ?: ""));
                $r = $m->deleteStream($name);
                if ($r["ok"]) {
                    return api::ANSWER(true);
                }
                return [
                    "502" => [
                        "error" => "flussonicError",
                        "flussonicCode" => $r["code"],
                        "flussonicMessage" => $r["error"],
                        "flussonicBody" => strlen($r["raw"]) > 800 ? (substr($r["raw"], 0, 800) . "…") : $r["raw"],
                    ],
                ];
            }

            public static function index() {
                if (loadBackend("mediaserver")) {
                    return [
                        "POST" => false,
                        "DELETE" => "#same(mediaserver,stream,POST)",
                    ];
                }
                return false;
            }
        }
    }

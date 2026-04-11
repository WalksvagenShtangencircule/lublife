<?php

    /**
     * @api {get} /api/vdom/openDoorOnce/:token одноразовое открытие: сервер дергает URL замка и инвалидирует ключ
     */

    namespace api\vdom {

        use api\api;

        class openDoorOnce extends api {

            private static function httpGetShort(string $url): bool {
                if ($url === "" || strlen($url) > 2048) {
                    return false;
                }
                if (!preg_match('#^https?://#i', $url)) {
                    return false;
                }
                $ctx = stream_context_create([
                    "http" => [
                        "timeout" => 6,
                        "method" => "GET",
                        "ignore_errors" => true,
                    ],
                    "ssl" => [
                        "verify_peer" => true,
                        "verify_peer_name" => true,
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                return $body !== false;
            }

            public static function GET($params) {
                $token = trim((string)@$params["_id"]);
                if ($token === "" || strlen($token) > 64 || !preg_match('/^[a-f0-9]+$/i', $token)) {
                    return api::ANSWER(false, "badRequest");
                }

                $redis = @$params["_redis"];
                if (!$redis) {
                    return api::ANSWER(false, "backend");
                }

                $key = "vdom_door_once:" . $token;
                $lua = "local v = redis.call('GET', KEYS[1]); if v == false then return 0 end; redis.call('DEL', KEYS[1]); return v";
                $raw = $redis->eval($lua, [$key], 1);

                if ($raw === 0 || $raw === "0" || $raw === false || $raw === null) {
                    return api::ANSWER(false, "notFound");
                }

                $row = json_decode((string)$raw, true);
                if (!is_array($row) || empty($row["url"])) {
                    return api::ANSWER(false, "notFound");
                }

                $ok = self::httpGetShort((string)$row["url"]);
                if (!$ok) {
                    return api::ANSWER([ "ok" => false, "error" => "triggerFailed" ], "__asis__");
                }

                return api::ANSWER([ "ok" => true ], "__asis__");
            }

            public static function index() {
                return [
                    "GET" => "#common",
                ];
            }
        }
    }

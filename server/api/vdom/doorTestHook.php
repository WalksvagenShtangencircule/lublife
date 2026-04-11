<?php

    /**
     * @api {get} /api/vdom/doorTestHook тест открытия: сервер пишет факт запроса в logs/vdom_door_test.log
     *
     * @apiQuery {String} [tag] произвольная метка из настроек URL (для различия попыток)
     */

    namespace api\vdom {

        use api\api;

        class doorTestHook extends api {

            private static function logHit(array $params): void {
                $dir = __DIR__ . "/../../logs";
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $file = $dir . "/vdom_door_test.log";
                $ip = (string)@$params["_ip"];
                $ua = (string)@$params["_ua"];
                $tag = isset($params["tag"]) ? trim((string)$params["tag"]) : "";
                if (strlen($tag) > 128) {
                    $tag = substr($tag, 0, 128);
                }
                $line = date("c") . "\t" . $ip . "\t" . $tag . "\t" . $ua . "\n";
                @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            }

            public static function GET($params) {
                self::logHit($params);

                return api::ANSWER([
                    "ok" => true,
                    "message" => "doorTestHook: записано в logs/vdom_door_test.log",
                    "time" => date("c"),
                ], "__asis__");
            }

            public static function index() {
                return [
                    "GET" => "#common",
                ];
            }
        }
    }

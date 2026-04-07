<?php

    /**
     * @api {get} /api/analytics/eventVideoPlay/:id поток mp4 архива с Content-Disposition: inline (просмотр в вкладке, не «Скачать»)
     *
     * id — event_uuid. Query: houseId. Права: #same(analytics,stats,GET).
     */

    namespace api\analytics {

        use api\api;

        class eventVideoPlay extends api {

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
                if ($r === false || empty($r["url"]) || !is_string($r["url"])) {
                    return api::ERROR("notFound");
                }
                $url = $r["url"];

                $ch = curl_init($url);
                if ($ch === false) {
                    return api::ERROR("notFound");
                }

                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                curl_setopt($ch, CURLOPT_HEADER, false);

                header("Content-Type: video/mp4");
                header('Content-Disposition: inline; filename="plog-archive.mp4"');
                header("Cache-Control: private, max-age=0, must-revalidate");
                header("X-Content-Type-Options: nosniff");

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $chunk) {
                    echo $chunk;
                    if (function_exists("flush")) {
                        @flush();
                    }
                    return strlen($chunk);
                });

                $ok = curl_exec($ch);
                $cerr = curl_error($ch);
                curl_close($ch);

                if ($ok === false) {
                    error_log("analytics.eventVideoPlay curl: " . $cerr);
                }

                exit;
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

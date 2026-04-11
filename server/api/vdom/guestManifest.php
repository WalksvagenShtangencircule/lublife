<?php

    /**
     * @api {get} /api/vdom/guestManifest манифест WebRTC для публичной панели по QR (без Bearer)
     *
     * @apiQuery {String} t slug из ext.guestAccessSlug виртуальной панели
     */

    namespace api\vdom {

        use api\api;

        /**
         * Публичные данные для страницы virtual-intercom (без пароля учётной записи админки).
         */

        class guestManifest extends api {

            public static function GET($params) {
                $slug = trim((string)@$params["t"]);
                if ($slug === "") {
                    return api::ANSWER(false, "badRequest");
                }

                $households = loadBackend("households");
                if (!$households) {
                    return api::ANSWER(false, "backend");
                }

                $panel = $households->getDomophoneByGuestSlug($slug);
                if (!$panel || !($panel["enabled"] ?? 0)) {
                    return api::ANSWER(false, "notFound");
                }

                if (($panel["model"] ?? "") !== "virtual.json") {
                    return api::ANSWER(false, "notFound");
                }

                $clientCfgPath = __DIR__ . "/../../../client/config/config.json";
                $asteriskClient = [];
                if (is_readable($clientCfgPath)) {
                    $cj = @json_decode((string)file_get_contents($clientCfgPath), true);
                    if (is_array($cj) && !empty($cj["asterisk"])) {
                        $asteriskClient = $cj["asterisk"];
                    }
                }

                $domophoneId = (int)$panel["domophoneId"];
                $sipUser = sprintf("1%05d", $domophoneId);

                // Страница лежит в /opt/rbt/client/virtual-intercom/ и отдаётся с корня сайта как /virtual-intercom/…
                // Нельзя вшивать /frontend/virtual-intercom/ — nginx шлёт это в frontend.php, и путь парсится как «API» → noToken.
                $frontend = @$params["_config"]["api"]["frontend"];
                $guestPageUrl = "";
                if (is_string($frontend) && $frontend !== "") {
                    $pu = parse_url($frontend);
                    if (is_array($pu) && !empty($pu["scheme"]) && !empty($pu["host"])) {
                        $origin = $pu["scheme"] . "://" . $pu["host"];
                        if (!empty($pu["port"])) {
                            $origin .= ":" . $pu["port"];
                        }
                        $guestPageUrl = $origin . "/virtual-intercom/index.html?t=" . rawurlencode($slug);
                    }
                }

                $payload = [
                    "sipUser" => $sipUser,
                    "sipPassword" => (string)($panel["credentials"] ?? ""),
                    "sipDomain" => (string)($asteriskClient["sipDomain"] ?? ""),
                    "ws" => (string)($asteriskClient["ws"] ?? ""),
                    "ice" => $asteriskClient["ice"] ?? [],
                    "panelName" => (string)($panel["name"] ?? ""),
                    "guestPageUrl" => $guestPageUrl,
                ];

                if ($payload["sipDomain"] === "" || $payload["ws"] === "") {
                    return api::ANSWER(false, "notConfigured");
                }

                return api::ANSWER($payload, "__asis__");
            }

            public static function index() {
                return [
                    "GET" => "#common",
                ];
            }
        }
    }

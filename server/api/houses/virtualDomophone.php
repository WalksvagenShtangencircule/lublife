<?php

    /**
     * @api {post} /api/houses/virtualDomophone создать виртуальную панель (QR)
     *
     * @apiBody {String} name
     * @apiBody {String} server IP или идентификатор SIP-сервера
     * @apiBody {String} [doorUrl0] URL открытия (doorId 0)
     * @apiBody {String} [doorUrl1]
     * @apiBody {String} [comments]
     * @apiBody {Boolean} [enabled]
     */

    /**
     * @api {put} /api/houses/virtualDomophone/:domophoneId изменить виртуальную панель
     */

    /**
     * @api {delete} /api/houses/virtualDomophone/:domophoneId удалить
     */

    namespace api\houses {

        use api\api;

        class virtualDomophone extends api {

            public static function GET($params) {
                $households = loadBackend("households");
                if (!$households) {
                    return api::ERROR();
                }

                if (!@$params["_id"]) {
                    return api::ANSWER(false, "badRequest");
                }

                $d = $households->getDomophone((int)$params["_id"]);
                if (!$d || ($d["model"] ?? "") !== "virtual.json") {
                    return api::ANSWER(false, "notFound");
                }

                return api::ANSWER($d, "domophone");
            }

            private static function randomCredentials(): string {
                try {
                    return substr(bin2hex(random_bytes(16)), 0, 24);
                } catch (\Throwable $e) {
                    return substr(md5((string)microtime(true)), 0, 24);
                }
            }

            private static function normalizeExt($raw, string $slug): array {
                $ext = is_object($raw) ? json_decode(json_encode($raw), true) : (is_array($raw) ? $raw : []);
                $ext["virtualPanel"] = true;
                $ext["guestAccessSlug"] = $slug;

                return $ext;
            }

            public static function POST($params) {
                $households = loadBackend("households");
                if (!$households) {
                    return api::ERROR();
                }

                $name = trim((string)@$params["name"]);
                $server = trim((string)@$params["server"]);
                if ($name === "" || $server === "") {
                    return api::ANSWER(false, "badRequest");
                }

                $slug = bin2hex(random_bytes(16));
                $door0 = trim((string)@$params["doorUrl0"]);
                $door1 = trim((string)@$params["doorUrl1"]);

                $doorOpeningUrls = [];
                if ($door0 !== "") {
                    $doorOpeningUrls[0] = $door0;
                }
                if ($door1 !== "") {
                    $doorOpeningUrls[1] = $door1;
                }

                $ext = [
                    "virtualPanel" => true,
                    "guestAccessSlug" => $slug,
                    "doorOpeningUrls" => $doorOpeningUrls,
                ];

                $enabled = array_key_exists("enabled", $params) ? (int)(bool)$params["enabled"] : 1;
                $comments = trim((string)@$params["comments"]);
                $credentials = self::randomCredentials();

                $domophoneId = $households->addDomophone(
                    $enabled,
                    "virtual.json",
                    $server,
                    "https://virtual.invalid/panel",
                    $credentials,
                    "1",
                    0,
                    $comments,
                    $name,
                    "",
                    "webrtc",
                    0,
                    $ext,
                    "",
                    "",
                    @$params["tree"] ?: "",
                );

                return api::ANSWER($domophoneId, ($domophoneId !== false) ? "domophoneId" : false);
            }

            public static function PUT($params) {
                $households = loadBackend("households");
                if (!$households) {
                    return api::ERROR();
                }

                $id = (int)@$params["_id"];
                $cur = $households->getDomophone($id);
                if (!$cur || ($cur["model"] ?? "") !== "virtual.json") {
                    return api::ANSWER(false, "notFound");
                }

                $slug = "";
                if (is_object($cur["ext"]) && isset($cur["ext"]->guestAccessSlug)) {
                    $slug = (string)$cur["ext"]->guestAccessSlug;
                } elseif (is_array($cur["ext"]) && isset($cur["ext"]["guestAccessSlug"])) {
                    $slug = (string)$cur["ext"]["guestAccessSlug"];
                }
                if ($slug === "") {
                    $slug = bin2hex(random_bytes(16));
                }

                $ext = self::normalizeExt(@$params["ext"], $slug);

                if (array_key_exists("doorUrl0", $params) || array_key_exists("doorUrl1", $params)) {
                    $door0 = trim((string)@$params["doorUrl0"]);
                    $door1 = trim((string)@$params["doorUrl1"]);
                    $doorOpeningUrls = [];
                    if ($door0 !== "") {
                        $doorOpeningUrls[0] = $door0;
                    }
                    if ($door1 !== "") {
                        $doorOpeningUrls[1] = $door1;
                    }
                    $ext["doorOpeningUrls"] = $doorOpeningUrls;
                }

                $credentials = trim((string)@$params["credentials"]) !== "" ? trim((string)$params["credentials"]) : (string)($cur["credentials"] ?? "");

                $success = $households->modifyDomophone(
                    $id,
                    array_key_exists("enabled", $params) ? (int)(bool)$params["enabled"] : (int)($cur["enabled"] ?? 1),
                    "virtual.json",
                    trim((string)(@$params["server"] !== "" && @$params["server"] !== null ? $params["server"] : $cur["server"])),
                    "https://virtual.invalid/panel",
                    $credentials,
                    (string)(@$params["dtmf"] !== "" && @$params["dtmf"] !== null ? $params["dtmf"] : ($cur["dtmf"] ?? "1")),
                    (int)(array_key_exists("firstTime", $params) ? $params["firstTime"] : ($cur["firstTime"] ?? 0)),
                    (int)(array_key_exists("nat", $params) ? $params["nat"] : ($cur["nat"] ?? 0)),
                    (int)(array_key_exists("locksAreOpen", $params) ? $params["locksAreOpen"] : ($cur["locksAreOpen"] ?? 0)),
                    trim((string)(@$params["comments"] !== null ? $params["comments"] : ($cur["comments"] ?? ""))),
                    trim((string)(@$params["name"] !== "" && @$params["name"] !== null ? $params["name"] : ($cur["name"] ?? ""))),
                    (string)(@$params["display"] !== null ? $params["display"] : ($cur["display"] ?? "")),
                    "webrtc",
                    (int)(array_key_exists("monitoring", $params) ? $params["monitoring"] : ($cur["monitoring"] ?? 0)),
                    $ext,
                    (string)(@$params["concierge"] !== null ? $params["concierge"] : ($cur["concierge"] ?? "")),
                    (string)(@$params["sos"] !== null ? $params["sos"] : ($cur["sos"] ?? "")),
                    (string)(@$params["tree"] !== null ? $params["tree"] : ($cur["tree"] ?? "")),
                );

                return api::ANSWER($success);
            }

            public static function DELETE($params) {
                $households = loadBackend("households");
                if (!$households) {
                    return api::ERROR();
                }

                $id = (int)@$params["_id"];
                $cur = $households->getDomophone($id);
                if (!$cur || ($cur["model"] ?? "") !== "virtual.json") {
                    return api::ANSWER(false, "notFound");
                }

                $success = $households->deleteDomophone($id);

                return api::ANSWER($success);
            }

            public static function index() {
                return [
                    "GET" => false,
                    "POST" => false,
                    "PUT" => false,
                    "DELETE" => false,
                ];
            }
        }
    }

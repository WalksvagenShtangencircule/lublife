<?php

    /**
     * @api {post} /api/vdom/issueDoorTokens одноразовые ключи открытия дверей для активного звонка с QR-панели
     *
     * @apiBody {String} t slug (guestAccessSlug)
     */

    namespace api\vdom {

        use api\api;

        class issueDoorTokens extends api {

            private static function extToArray($ext): array {
                if (is_array($ext)) {
                    return $ext;
                }
                if (is_object($ext)) {
                    return json_decode(json_encode($ext), true) ?: [];
                }
                return [];
            }

            private static function pickAltDigit(string $primary): string {
                foreach (["1", "2", "3", "4", "5", "6", "7", "8", "9", "0", "*", "#"] as $d) {
                    if ($d !== $primary) {
                        return $d;
                    }
                }
                return "2";
            }

            public static function POST($params) {
                $slug = trim((string)@$params["t"]);
                if ($slug === "" || strlen($slug) > 128 || !preg_match('/^[a-f0-9]+$/i', $slug)) {
                    return api::ANSWER(false, "badRequest");
                }

                $redis = @$params["_redis"];
                if (!$redis) {
                    return api::ANSWER(false, "backend");
                }

                $rlKey = "vdom_issue_rl:" . md5(strtolower($slug));
                $n = (int)$redis->incr($rlKey);
                if ($n === 1) {
                    $redis->expire($rlKey, 60);
                }
                if ($n > 24) {
                    return api::ANSWER(false, "forbidden");
                }

                $households = loadBackend("households");
                if (!$households) {
                    return api::ANSWER(false, "backend");
                }

                $panel = $households->getDomophoneByGuestSlug($slug);
                if (!$panel || !($panel["enabled"] ?? 0) || ($panel["model"] ?? "") !== "virtual.json") {
                    return api::ANSWER(false, "notFound");
                }

                $ext = self::extToArray($panel["ext"] ?? []);
                $urls = $ext["doorOpeningUrls"] ?? [];
                if (!is_array($urls)) {
                    $urls = [];
                }

                $door0 = isset($urls[0]) ? trim((string)$urls[0]) : "";
                $door1 = isset($urls[1]) ? trim((string)$urls[1]) : "";
                if ($door0 === "" && $door1 === "") {
                    return api::ANSWER([ "doors" => [] ], "__asis__");
                }

                $primary = trim((string)($panel["dtmf"] ?? "1"));
                if (!in_array($primary, ["*", "#", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9"], true)) {
                    $primary = "1";
                }

                $ttl = 900;
                $doors = [];

                $store = function (string $url, int $doorId) use ($redis, $ttl, &$doors, $panel): void {
                    if ($url === "" || strlen($url) > 2048) {
                        return;
                    }
                    if (!preg_match('#^https?://#i', $url)) {
                        return;
                    }
                    $token = bin2hex(random_bytes(24));
                    $key = "vdom_door_once:" . $token;
                    $payload = json_encode([
                        "url" => $url,
                        "doorId" => $doorId,
                        "domophoneId" => (int)$panel["domophoneId"],
                        "created" => time(),
                    ]);
                    $redis->setex($key, $ttl, $payload);
                    $doors[] = [
                        "doorId" => $doorId,
                        "digit" => null,
                        "token" => $token,
                    ];
                };

                if ($door0 !== "") {
                    $store($door0, 0);
                }
                if ($door1 !== "") {
                    $store($door1, 1);
                }

                if (count($doors) === 1) {
                    $doors[0]["digit"] = $primary;
                } elseif (count($doors) === 2) {
                    $doors[0]["digit"] = $primary;
                    $doors[1]["digit"] = self::pickAltDigit($primary);
                }

                return api::ANSWER([ "doors" => $doors ], "__asis__");
            }

            public static function index() {
                return [
                    "POST" => "#common",
                ];
            }
        }
    }

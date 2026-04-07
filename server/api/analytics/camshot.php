<?php

    /**
     * @api {get} /api/analytics/camshot/:id кадр/ролик события plog по UUID файла (как в мобильном plogCamshot)
     *
     * Права: #same(analytics,stats,GET) — вместе с остальными методами модуля аналитики.
     * id: как в git — fromGUIDv4($raw); плюс plogImageIdToStorageId для 32 hex без дефисов из ClickHouse.
     * Порядок кандидатов: сначала legacy (как в HEAD), затем plog при отличии — до непустого тела файла.
     */

    namespace api\analytics {

        use api\api;

        class camshot extends api {

            private static function logCamshot(string $msg): void {
                error_log("analytics.camshot " . $msg);
            }

            public static function GET($params) {
                $raw = isset($params["_id"]) ? trim((string)$params["_id"]) : "";
                if ($raw === "") {
                    self::logCamshot("badRequest: empty _id");
                    return api::ERROR("badRequest");
                }
                $hex0 = strtolower(str_replace("-", "", $raw));
                if (strlen($hex0) === 32 && ctype_xdigit($hex0) && $hex0 === str_repeat("0", 32)) {
                    return api::ERROR("notFound");
                }
                $files = loadBackend("files");
                if (!$files) {
                    self::logCamshot("notFound: files backend unavailable");
                    return api::ERROR("notFound");
                }
                $fromPlog = $files->plogImageIdToStorageId($raw);
                $fromLegacy = strtolower((string)$files->fromGUIDv4($raw));
                $candidates = [];
                if ($fromLegacy !== "" && strlen($fromLegacy) === 24) {
                    $candidates[] = $fromLegacy;
                }
                if ($fromPlog !== "" && strlen($fromPlog) === 24) {
                    $p = strtolower($fromPlog);
                    if (!in_array($p, $candidates, true)) {
                        $candidates[] = $p;
                    }
                }
                if (!count($candidates)) {
                    self::logCamshot("badRequest: raw_len=" . strlen($raw) . " plog_len=" . strlen($fromPlog) . " legacy_len=" . strlen($fromLegacy));
                    return api::ERROR("badRequest");
                }
                $maxBytes = 12 * 1024 * 1024;
                $contentType = "image/jpeg";
                $base64 = null;
                foreach ($candidates as $uuid) {
                    if ($uuid === str_repeat("0", 24)) {
                        continue;
                    }
                    try {
                        $t = $files->getFile($uuid);
                        if (!$t || empty($t["stream"])) {
                            continue;
                        }
                        $fi = $t["fileInfo"] ?? null;
                        $ct = "image/jpeg";
                        if ($fi && isset($fi->metadata)) {
                            $md = $fi->metadata;
                            $cval = null;
                            if (is_object($md) && isset($md->contentType)) {
                                $cval = $md->contentType;
                            } elseif (is_array($md) && isset($md["contentType"])) {
                                $cval = $md["contentType"];
                            }
                            if ($cval !== null && $cval !== "") {
                                $ct = (string)$cval;
                            }
                        }
                        $data = stream_get_contents($t["stream"], $maxBytes + 1);
                        if ($data === false || strlen($data) > $maxBytes) {
                            continue;
                        }
                        if (strlen($data) === 0) {
                            self::logCamshot("empty body for " . substr($uuid, 0, 8) . "… try next candidate");
                            continue;
                        }
                        $contentType = $ct;
                        $base64 = base64_encode($data);
                        break;
                    } catch (\Throwable $e) {
                        self::logCamshot("try " . substr($uuid, 0, 8) . "…: " . $e->getMessage());
                    }
                }
                if ($base64 === null || $base64 === "") {
                    self::logCamshot("notFound: no readable camshot (" . count($candidates) . " candidates)");
                    return api::ERROR("notFound");
                }
                return api::ANSWER([
                    "contentType" => $contentType,
                    "base64" => $base64,
                ], "camshot");
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

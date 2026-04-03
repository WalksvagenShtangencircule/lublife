<?php

    /**
     * @api {get} /api/analytics/camshot/:id кадр/ролик события plog по UUID файла (как в мобильном plogCamshot)
     */

    namespace api\analytics {

        use api\api;

        class camshot extends api {

            public static function GET($params) {
                $raw = isset($params["_id"]) ? trim((string)$params["_id"]) : "";
                if ($raw === "") {
                    return api::ERROR("badRequest");
                }
                $files = loadBackend("files");
                if (!$files) {
                    return api::ERROR("notFound");
                }
                $uuid = $files->fromGUIDv4($raw);
                $img = $files->getFile($uuid);
                if (!$img || empty($img["stream"])) {
                    return api::ERROR("notFound");
                }
                $meta = $files->getFileMetadata($uuid);
                $contentType = "image/jpeg";
                if ($meta && isset($meta->contentType)) {
                    $contentType = (string)$meta->contentType;
                }
                $maxBytes = 12 * 1024 * 1024;
                $data = stream_get_contents($img["stream"], $maxBytes + 1);
                if ($data === false || strlen($data) > $maxBytes) {
                    return api::ERROR("notFound");
                }
                return api::ANSWER([
                    "contentType" => $contentType,
                    "base64" => base64_encode($data),
                ], "camshot");
            }

            public static function index() {
                if (loadBackend("analytics")) {
                    return [
                        "GET" => "#same(addresses,addresses,GET)",
                    ];
                }
                return false;
            }
        }
    }

<?php

    $files = loadBackend('files');
    $uuid = $files->plogImageIdToStorageId((string)$param);
    if ($uuid === '' || strlen($uuid) !== 24) {
        response(404);
    }
    if ($uuid === str_repeat('0', 24)) {
        response(404);
    }
    try {
        $img = $files->getFile($uuid);
    } catch (Throwable $e) {
        response(404);
    }

    if ($img) {
        $content_type = 'image/jpeg';
        $fi = $img['fileInfo'] ?? null;
        if ($fi && isset($fi->metadata)) {
            $md = $fi->metadata;
            $ct = null;
            if (is_object($md) && isset($md->contentType)) {
                $ct = $md->contentType;
            } elseif (is_array($md) && isset($md['contentType'])) {
                $ct = $md['contentType'];
            }
            if ($ct !== null && $ct !== '') {
                $content_type = (string)$ct;
            }
        }
        header("Content-Type: $content_type");
        try {
            echo stream_get_contents($img['stream']);
        } catch (Throwable $e) {
            response(404);
        }
        exit;
    }
    response(404);

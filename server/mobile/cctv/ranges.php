<?php

    /**
     * @api {post} /mobile/cctv/ranges получить список доступных периодов в архиве
     * @apiVersion 1.0.0
     * @apiDescription **почти готов**
     *
     * @apiGroup CCTV
     *
     * @apiBody {Number} [cameraId] идентификатор камеры
     *
     * @apiHeader {String} authorization токен авторизации
     *
     * @apiSuccess {String} stream название потока
     * @apiSuccess {Object[]} ranges массив интервалов
     * @apiSuccess {Number} ranges.from метка начала
     * @apiSuccess {Number} ranges.duration продолжительность периода
     */

    auth();

    $camera_id = (int)@$postdata['cameraId'];

    $cameras = loadBackend("cameras");

    $cam = $cameras->getCamera($camera_id);
    if (!$cam) {
        response(404);
    }

    // Keep API response backward-compatible:
    // expected format for mobile app is [ [ "stream" => "...", "ranges" => [ ... ] ] ].
    $normalizeRanges = function ($rawRanges) {
        if (!is_array($rawRanges)) {
            return [];
        }

        // Some media servers may return a single object instead of a list.
        if (isset($rawRanges['ranges']) && is_array($rawRanges['ranges'])) {
            $stream = isset($rawRanges['stream']) ? strval($rawRanges['stream']) : '';
            return [[
                "stream" => $stream,
                "ranges" => $rawRanges['ranges']
            ]];
        }

        // Standard format: list of objects with ranges.
        if (isset($rawRanges[0]) && is_array($rawRanges[0]) && isset($rawRanges[0]['ranges'])) {
            return $rawRanges;
        }

        return [];
    };

    // Fallback for transient media server/ranges API failures:
    // keeps archive tab available with a conservative recent window.
    $fallbackRanges = function () use ($cam) {
        $tzString = @$config["mobile"]["time_zone"];
        if (!isset($tzString) || !$tzString) {
            $tzString = "Europe/Moscow";
        }
        date_default_timezone_set($tzString);

        $ext = @$cam["ext"];
        if (is_object($ext)) {
            $ext = json_decode(json_encode($ext), true);
        }
        if (!is_array($ext)) {
            $ext = [];
        }

        // Prefer camera-specific retention configured by mediaserver module.
        $days = (int)@$ext["dvrRetentionDays"];
        if ($days <= 0) {
            $days = (int)@$config["mobile"]["archive_fallback_days"];
        }
        if ($days <= 0) {
            $days = 7;
        }
        if ($days > 30) {
            $days = 30;
        }

        $duration = $days * 24 * 3600;
        $from = time() - $duration;
        $stream = isset($cam['cameraId']) ? strval($cam['cameraId']) : 'fallback';

        return [[
            "stream" => $stream,
            "ranges" => [[
                "from" => $from,
                "duration" => $duration
            ]]
        ]];
    };

    $ranges = [];
    try {
        $rawRanges = loadBackend("dvr")->getRanges($cam, $subscriber['subscriberId']);
        $ranges = $normalizeRanges($rawRanges);
    } catch (\Throwable $e) {
        // Use fallback below.
    }

    if (!count($ranges)) {
        $ranges = $fallbackRanges();
    }

    response(200, $ranges);
<?php

/**
 * Снимок кадра через ffmpeg: RTSP / RTSPS / HLS (http(s) + .m3u8).
 * Общий механизм для API camshot, mobile/call/live, FRS.
 */

/**
 * Подходит ли URL для захвата кадра через ffmpeg.
 */
function isFfmpegMediaSnapshotUrl(string $v): bool {
    $v = trim($v);
    if ($v === '') {
        return false;
    }
    $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
    if ($scheme === 'rtsp' || $scheme === 'rtsps') {
        return true;
    }
    if ($scheme === 'http' || $scheme === 'https') {
        $low = strtolower($v);

        return str_contains($low, '.m3u8') || str_contains($low, '/hls/');
    }

    return false;
}

/**
 * Тип URL для сортировки приоритета.
 */
function ffmpegMediaUrlKind(string $v): string {
    $v = strtolower(trim($v));
    if (str_contains($v, '.m3u8') || str_contains($v, '/hls/')) {
        return 'hls';
    }
    $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
    if ($scheme === 'rtsp' || $scheme === 'rtsps') {
        return 'rtsp';
    }

    return 'other';
}

/**
 * Упорядоченный список URL для попыток снапшота.
 * По умолчанию сначала HLS (часто mediaserver в dvr_stream), затем RTSP (stream).
 * ext.preferRtspSnapshot — наоборот: сначала RTSP.
 *
 * @return list<string>
 */
function cameraFfmpegSnapshotCandidates(array $camera, bool $preferRtsp = false): array {
    $items = [];

    $push = static function (string $v) use (&$items): void {
        $v = trim($v);
        if ($v === '' || !isFfmpegMediaSnapshotUrl($v)) {
            return;
        }
        $items[] = $v;
    };

    $ext = $camera['ext'] ?? null;
    if (is_object($ext)) {
        foreach (['hlsUrl', 'ffmpegSnapshotUrl', 'rtspUrl'] as $ek) {
            if (isset($ext->$ek) && is_string($ext->$ek)) {
                $push($ext->$ek);
            }
        }
    }

    foreach (['dvrStream', 'stream', 'url'] as $key) {
        if (!empty($camera[$key])) {
            $push((string)$camera[$key]);
        }
    }

    $seen = [];
    $unique = [];
    foreach ($items as $u) {
        if (isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $unique[] = $u;
    }

    $hls = [];
    $rtsp = [];
    foreach ($unique as $u) {
        $k = ffmpegMediaUrlKind($u);
        if ($k === 'hls') {
            $hls[] = $u;
        } elseif ($k === 'rtsp') {
            $rtsp[] = $u;
        }
    }

    $ordered = $preferRtsp ? array_merge($rtsp, $hls) : array_merge($hls, $rtsp);

    return $ordered;
}

/**
 * Первый успешный кадр по списку URL.
 *
 * @param list<string> $urls
 */
function ffmpegTrySnapshotUrls(array $urls, ?int $timeoutSec = null): ?string {
    foreach ($urls as $u) {
        $jpeg = ffmpegUrlSnapshotToJpeg($u, $timeoutSec);
        if ($jpeg !== null) {
            return $jpeg;
        }
    }

    return null;
}

/**
 * @deprecated используйте cameraFfmpegSnapshotCandidates + ffmpegTrySnapshotUrls
 */
function cameraPickFfmpegSnapshotUrl(array $camera): ?string {
    $a = cameraFfmpegSnapshotCandidates($camera, false);

    return $a[0] ?? null;
}

/** @deprecated */
function cameraPickRtspUrl(array $camera): ?string {
    return cameraPickFfmpegSnapshotUrl($camera);
}

/**
 * Похоже ли тело ответа на растровое изображение (снимок с камеры).
 */
function snapshotBytesLookLikeImage(string $data): bool {
    if (strlen($data) < 100) {
        return false;
    }
    if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) {
        return true;
    }
    if (strncmp($data, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return true;
    }
    if (strncmp($data, "GIF87a", 6) === 0 || strncmp($data, "GIF89a", 6) === 0) {
        return true;
    }
    if (strncmp($data, "RIFF", 4) === 0 && strlen($data) > 12 && substr($data, 8, 4) === "WEBP") {
        return true;
    }

    return false;
}

function isFakeCameraModel(string $model): bool {
    return $model === 'fake.json';
}

/**
 * Один кадр JPEG из URL потока (RTSP или HLS).
 * FFmpeg 6.x: для RTSP используется -timeout (мкс), не stimeout.
 */
function ffmpegUrlSnapshotToJpeg(string $mediaUrl, ?int $timeoutSec = null): ?string {
    $mediaUrl = trim($mediaUrl);
    if ($mediaUrl === '') {
        return null;
    }

    $scheme = strtolower((string)parse_url($mediaUrl, PHP_URL_SCHEME));
    $allowed = ['rtsp', 'rtsps', 'http', 'https'];
    if (!in_array($scheme, $allowed, true)) {
        return null;
    }
    if (($scheme === 'http' || $scheme === 'https') && !str_contains(strtolower($mediaUrl), '.m3u8')
        && !str_contains(strtolower($mediaUrl), '/hls/')) {
        return null;
    }

    $ffmpeg = getenv('RBT_FFMPEG_PATH');
    if (!is_string($ffmpeg) || $ffmpeg === '') {
        $ffmpeg = 'ffmpeg';
    }

    if ($timeoutSec === null) {
        $envT = getenv('RBT_RTSP_SNAPSHOT_TIMEOUT');
        $timeoutSec = (is_string($envT) && $envT !== '' && ctype_digit($envT)) ? (int)$envT : 15;
    }
    $timeoutSec = max(3, min(90, $timeoutSec));
    $timeoutUs = $timeoutSec * 1000000;

    $base = tempnam(sys_get_temp_dir(), 'rbt_ff');
    if ($base === false) {
        return null;
    }
    $jpgPath = $base . '.jpg';
    @unlink($base);

    if ($scheme === 'rtsp' || $scheme === 'rtsps') {
        $inputOpts = sprintf('-rtsp_transport tcp -timeout %d', $timeoutUs);
    } else {
        $inputOpts = sprintf('-rw_timeout %d', $timeoutUs);
    }

    $cmd = sprintf(
        '%s -hide_banner -loglevel error %s -i %s -frames:v 1 -q:v 3 -y %s 2>&1',
        escapeshellcmd($ffmpeg),
        $inputOpts,
        escapeshellarg($mediaUrl),
        escapeshellarg($jpgPath)
    );

    $outLines = [];
    $code = 0;
    exec($cmd, $outLines, $code);

    $data = @file_get_contents($jpgPath);
    @unlink($jpgPath);

    if ($code !== 0 || $data === false || strlen($data) < 100) {
        if ($code !== 0 && $outLines !== []) {
            error_log('ffmpegUrlSnapshotToJpeg: code=' . $code . ' ' . implode(' | ', array_slice($outLines, 0, 3)));
        }

        return null;
    }

    if (strncmp($data, "\xFF\xD8\xFF", 3) !== 0) {
        return null;
    }

    return $data;
}

/** @deprecated */
function rtspSnapshotToJpeg(string $rtspUrl, ?int $timeoutSec = null): ?string {
    return ffmpegUrlSnapshotToJpeg($rtspUrl, $timeoutSec);
}

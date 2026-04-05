<?php

/**
 * Список прокси для DiagnosticsTelegramNotifier: загрузка с URL, проверка TCP (аналог «пинга» порта) и опционально HTTP-проба через прокси.
 */
class DiagnosticsTelegramProxy {

    public const REDIS_LIST = 'DIAG:TG:proxy_list_json';
    public const REDIS_FETCH_AT = 'DIAG:TG:proxy_fetch_at';

    /**
     * Кешированный список проверенных прокси.
     *
     * @return array<int, array{proxy:string, type:int, fingerprint:string}>
     */
    public static function verifiedProxyList($redis, array $tc, bool $forceRefresh = false): array {
        if (empty($tc['use_proxy'])) {
            return [];
        }
        $url = isset($tc['proxy_config_url']) && is_string($tc['proxy_config_url']) ? trim($tc['proxy_config_url']) : '';
        $staticLines = isset($tc['proxy_static_lines']) && is_array($tc['proxy_static_lines']) ? $tc['proxy_static_lines'] : [];
        $hasStatic = false;
        foreach ($staticLines as $line) {
            if (is_string($line) && trim($line) !== '') {
                $hasStatic = true;
                break;
            }
        }
        if ($url === '' && !$hasStatic) {
            return [];
        }
        $interval = max(60, (int)($tc['proxy_fetch_interval_sec'] ?? 300));
        $now = time();
        if (!$forceRefresh && $redis) {
            try {
                $at = (int)$redis->get(self::REDIS_FETCH_AT);
                $raw = $redis->get(self::REDIS_LIST);
                if ($at > 0 && ($now - $at) < $interval && $raw !== false && $raw !== null && $raw !== '') {
                    $dec = json_decode((string)$raw, true);
                    if (is_array($dec) && $dec !== []) {
                        return self::normalizeDecArray($dec);
                    }
                }
            } catch (\Throwable $e) {
                error_log('DiagnosticsTelegramProxy redis read: ' . $e->getMessage());
            }
        }
        $fileCache = self::fileCachePath();
        if (!$forceRefresh && $fileCache !== null && is_readable($fileCache)) {
            $raw = @file_get_contents($fileCache);
            if ($raw !== false && $raw !== '') {
                $meta = json_decode($raw, true);
                if (is_array($meta) && isset($meta['at'], $meta['list']) && ($now - (int)$meta['at']) < $interval) {
                    return self::normalizeDecArray($meta['list']);
                }
            }
        }
        $verified = self::fetchAndVerify($tc);
        if ($redis && $verified !== []) {
            try {
                $redis->setex(self::REDIS_LIST, 86400, json_encode($verified, JSON_UNESCAPED_UNICODE));
                $redis->setex(self::REDIS_FETCH_AT, 86400, (string)$now);
            } catch (\Throwable $e) {
                error_log('DiagnosticsTelegramProxy redis write: ' . $e->getMessage());
            }
        }
        if ($fileCache !== null && $verified !== []) {
            $dir = dirname($fileCache);
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($fileCache, json_encode(['at' => $now, 'list' => $verified], JSON_UNESCAPED_UNICODE));
        }
        return $verified;
    }

    public static function invalidateCache($redis): void {
        if (!$redis) {
            return;
        }
        try {
            $redis->del(self::REDIS_LIST);
            $redis->del(self::REDIS_FETCH_AT);
        } catch (\Throwable $e) {
            error_log('DiagnosticsTelegramProxy invalidate: ' . $e->getMessage());
        }
        $p = self::fileCachePath();
        if ($p !== null && is_file($p)) {
            @unlink($p);
        }
    }

    private static function fileCachePath(): ?string {
        $path = dirname(__DIR__) . '/config/.diag_tg_proxy_cache.json';
        $dir = dirname($path);
        if (is_writable($dir)) {
            return $path;
        }
        return sys_get_temp_dir() . '/rbt_diag_tg_proxy_cache.json';
    }

    /**
     * @param array<mixed> $dec
     * @return array<int, array{proxy:string, type:int, fingerprint:string}>
     */
    private static function normalizeDecArray(array $dec): array {
        $out = [];
        foreach ($dec as $row) {
            if (!is_array($row)) {
                continue;
            }
            $p = isset($row['proxy']) && is_string($row['proxy']) ? $row['proxy'] : '';
            $t = isset($row['type']) ? (int)$row['type'] : CURLPROXY_HTTP;
            if ($p === '') {
                continue;
            }
            $fp = isset($row['fingerprint']) && is_string($row['fingerprint']) ? $row['fingerprint'] : hash('sha256', $p . '|' . $t);
            $out[] = ['proxy' => $p, 'type' => $t, 'fingerprint' => $fp];
        }
        return $out;
    }

    /**
     * @return array<int, array{proxy:string, type:int, fingerprint:string}>
     */
    private static function fetchAndVerify(array $tc): array {
        $url = isset($tc['proxy_config_url']) && is_string($tc['proxy_config_url']) ? trim($tc['proxy_config_url']) : '';
        $candidates = [];
        if ($url !== '') {
            $ft = max(5, (int)($tc['proxy_fetch_timeout_sec'] ?? 15));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $ft,
                CURLOPT_CONNECTTIMEOUT => min(10, $ft),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            if ($body === false || $body === '') {
                error_log('DiagnosticsTelegramProxy: пустой ответ config_url');
            } else {
                $candidates = self::parseRawBody((string)$body);
            }
        }
        foreach ($tc['proxy_static_lines'] ?? [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            self::parseLineToCandidate($line, $candidates);
        }
        if ($candidates === []) {
            return [];
        }
        $verified = [];
        $tcp = max(1, (int)($tc['proxy_tcp_timeout_sec'] ?? 3));
        $probe = !empty($tc['proxy_probe_http']);
        $probeUrl = isset($tc['proxy_probe_url']) && is_string($tc['proxy_probe_url']) ? trim($tc['proxy_probe_url']) : 'https://api.telegram.org/';
        foreach ($candidates as $c) {
            $host = $c['host'];
            $port = $c['port'];
            if (!self::tcpConnectOk($host, $port, (float)$tcp)) {
                continue;
            }
            if ($probe && !self::httpProbe($c['proxy'], $c['type'], $probeUrl, min(12, $tcp + 8))) {
                continue;
            }
            $fp = hash('sha256', $c['proxy'] . '|' . $c['type']);
            $verified[] = ['proxy' => $c['proxy'], 'type' => $c['type'], 'fingerprint' => $fp];
        }
        return $verified;
    }

    /**
     * @return array<int, array{proxy:string, type:int, host:string, port:int}>
     */
    private static function parseRawBody(string $raw): array {
        $raw = trim($raw);
        $out = [];
        if ($raw === '') {
            return $out;
        }
        if ($raw[0] === '{' || $raw[0] === '[') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                if (isset($j['detail']) && !isset($j['proxies'])) {
                    return [];
                }
                $lines = [];
                if (isset($j['proxies']) && is_array($j['proxies'])) {
                    foreach ($j['proxies'] as $p) {
                        if (is_string($p)) {
                            $lines[] = $p;
                        }
                    }
                } else {
                    foreach ($j as $k => $v) {
                        if (is_string($v) && (is_int($k) || (is_string($k) && ctype_digit((string)$k)))) {
                            $lines[] = $v;
                        }
                    }
                }
                foreach ($lines as $line) {
                    self::parseLineToCandidate((string)$line, $out);
                }
                return $out;
            }
        }
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            self::parseLineToCandidate($line, $out);
        }
        return $out;
    }

    /**
     * @param array<int, array{proxy:string, type:int, host:string, port:int}> $out
     */
    private static function parseLineToCandidate(string $line, array &$out): void {
        if (preg_match('/^socks5:\/\//i', $line)) {
            $parts = parse_url($line);
            if ($parts === false || empty($parts['host'])) {
                return;
            }
            $host = (string)$parts['host'];
            $port = (int)($parts['port'] ?? 1080);
            if ($port < 1 || $port > 65535) {
                return;
            }
            $out[] = ['proxy' => $line, 'type' => CURLPROXY_SOCKS5_HOSTNAME, 'host' => $host, 'port' => $port];
            return;
        }
        if (preg_match('#^https?://#i', $line)) {
            $parts = parse_url($line);
            if ($parts === false || empty($parts['host'])) {
                return;
            }
            $host = (string)$parts['host'];
            $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
            $port = (int)($parts['port'] ?? 0);
            if ($port <= 0) {
                $port = ($scheme === 'https') ? 443 : 80;
            }
            if ($port < 1 || $port > 65535) {
                return;
            }
            $out[] = ['proxy' => $line, 'type' => CURLPROXY_HTTP, 'host' => $host, 'port' => $port];
            return;
        }
        if (preg_match('/^([^\s:]+):(\d{1,5})$/', $line, $m)) {
            $host = $m[1];
            $port = (int)$m[2];
            if ($port < 1 || $port > 65535) {
                return;
            }
            $proxy = 'http://' . $host . ':' . $port;
            $out[] = ['proxy' => $proxy, 'type' => CURLPROXY_HTTP, 'host' => $host, 'port' => $port];
        }
    }

    /**
     * @param array<string, mixed> $opts
     * @param array{proxy:string, type:int, fingerprint:string}|null $spec
     */
    public static function applyCurlProxy(array &$opts, array $tc, bool $useProxy, ?array $spec): void {
        if (!$useProxy || $spec === null) {
            return;
        }
        $opts[CURLOPT_PROXY] = $spec['proxy'];
        $opts[CURLOPT_PROXYTYPE] = (int)$spec['type'];
    }

    public static function tcpConnectOk(string $host, int $port, float $timeout): bool {
        $errno = 0;
        $errstr = '';
        $t = max(0.5, $timeout);
        $fp = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $t
        );
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static function httpProbe(string $proxy, int $proxyType, string $probeUrl, int $timeoutSec): bool {
        $ch = curl_init($probeUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => $proxyType,
            CURLOPT_HTTPGET => true,
            CURLOPT_NOBODY => true,
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        return $ok !== false && $errno === 0;
    }
}

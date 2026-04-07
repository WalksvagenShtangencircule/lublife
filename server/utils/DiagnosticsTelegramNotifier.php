<?php

require_once __DIR__ . '/DiagnosticsTelegramProxy.php';

/**
 * Уведомления о результатах диагностики в Telegram через HTTP(S)/SOCKS-прокси.
 * Список прокси: URL (config_url) и/или static_proxies в diagnostics.telegram.proxy; проверка TCP и опционально HTTP.
 */
class DiagnosticsTelegramNotifier {

    /** @var mixed|null Redis для verifiedProxyList на время запроса. */
    private static $tgProxyRedis = null;

    public const REDIS_KEY_LAST_SENT = 'DIAG:TG:last_sent';
    public const REDIS_KEY_LAST_FP = 'DIAG:TG:last_fp';
    public const REDIS_KEY_WAIT_UNTIL = 'DIAG:TG:WAIT_UNTIL';
    public const REDIS_KEY_ARM_AT = 'DIAG:TG:ARM_AT';
    public const REDIS_KEY_POLL_OFFSET = 'DIAG:TG:POLL_OFFSET';
    public const REDIS_KEY_PENDING_DELETE_WEBHOOK = 'DIAG:TG:PENDING_DEL_WH';
    public const REDIS_KEY_DELETE_WEBHOOK_FAILS = 'DIAG:TG:DEL_WH_FAILS';
    public const REDIS_KEY_DELETE_WEBHOOK_SKIP_UNTIL = 'DIAG:TG:DEL_WH_SKIP';

    private const SCENARIO_HINTS_RU = [
        'DVR/медиасервер отвечает десятки секунд → растёт tasks_changes, долго живут строки в core_running_processes (cron minutely ждёт детей).',
        'ClickHouse «тупит» или таймаут → подвисают отчёты analytics, plog, tt (в коде часто таймаут curl ~5 с).',
        'Заполненный диск / высокий load → смарт-конфиг и ffmpeg могут не успевать, очередь устройств копится.',
        'Пустой ответ Bot API без прокси → задайте diagnostics.telegram.proxy (config_url и/или static_proxies) или fallback_direct.',
    ];

    public static function telegramNotifyConfig(array $config): array {
        $root = $config['diagnostics']['telegram'] ?? [];
        $px = is_array($root['proxy'] ?? null) ? $root['proxy'] : [];
        $alert = is_array($root['alert'] ?? null) ? $root['alert'] : [];
        $chats = [];
        if (!empty($root['chats']) && is_array($root['chats'])) {
            foreach ($root['chats'] as $c) {
                $c = is_string($c) ? trim($c) : (is_int($c) ? (string)$c : '');
                if ($c !== '') {
                    $chats[] = $c;
                }
            }
        }
        $allow = [];
        if (!empty($root['allowed_usernames']) && is_array($root['allowed_usernames'])) {
            foreach ($root['allowed_usernames'] as $u) {
                $u = is_string($u) ? strtolower(trim(ltrim($u, '@'))) : '';
                if ($u !== '') {
                    $allow[$u] = true;
                }
            }
        }
        return [
            'enabled' => !empty($root['enabled']),
            'bot_token' => isset($root['bot_token']) && is_string($root['bot_token']) ? trim($root['bot_token']) : '',
            'chats' => $chats,
            'allowed_usernames' => $allow,
            'proxy_config_url' => (array_key_exists('config_url', $px) && is_string($px['config_url']))
                ? trim($px['config_url'])
                : '',
            'proxy_fetch_interval_sec' => isset($px['fetch_interval_sec']) ? max(60, (int)$px['fetch_interval_sec']) : 300,
            'proxy_tcp_timeout_sec' => isset($px['tcp_connect_timeout_sec']) ? max(1, (int)$px['tcp_connect_timeout_sec']) : 3,
            'proxy_probe_http' => !array_key_exists('probe_http', $px) || !empty($px['probe_http']),
            'proxy_probe_url' => isset($px['probe_url']) && is_string($px['probe_url']) ? trim($px['probe_url']) : 'https://api.telegram.org/',
            'proxy_fetch_timeout_sec' => isset($px['fetch_timeout_sec']) ? max(5, (int)$px['fetch_timeout_sec']) : 15,
            'http_timeout' => isset($px['http_timeout_sec']) ? max(10, (int)$px['http_timeout_sec']) : 45,
            'on_fail' => array_key_exists('on_fail', $alert) ? (bool)$alert['on_fail'] : true,
            'on_warn' => !empty($alert['on_warn']),
            'cooldown_sec' => isset($alert['cooldown_sec']) ? max(60, (int)$alert['cooldown_sec']) : 3600,
            'only_notify_on_change' => !array_key_exists('only_notify_on_change', $alert) ? true : (bool)$alert['only_notify_on_change'],
            'max_lines' => isset($alert['max_lines']) ? max(5, min(80, (int)$alert['max_lines'])) : 35,
            'append_scenario_hints' => !empty($root['append_scenario_hints']),
            'use_proxy' => !array_key_exists('use_proxy', $px) || !empty($px['use_proxy']),
            'fallback_direct' => !array_key_exists('fallback_direct', $px) || !empty($px['fallback_direct']),
            'delete_webhook_direct_first' => array_key_exists('delete_webhook_direct_first', $px) && !empty($px['delete_webhook_direct_first']),
            'proxy_static_lines' => (function () use ($px) {
                $out = [];
                if (!empty($px['static_proxies']) && is_array($px['static_proxies'])) {
                    foreach ($px['static_proxies'] as $s) {
                        if (is_string($s) && trim($s) !== '') {
                            $out[] = trim($s);
                        }
                    }
                }
                return $out;
            })(),
        ];
    }

    /** Ошибки curl: нет соединения с SOCKS / прокси */
    private static function isProxyUnreachable(int $curlErrno): bool {
        return in_array($curlErrno, [5, 7, 96, 97], true);
    }

    /** Таймаут или обрыв до api.telegram.org (в т.ч. через прокси) — пробуем другой маршрут */
    private static function isTelegramTransportRetryErrno(int $curlErrno): bool {
        return in_array($curlErrno, [7, 28, 35, 56], true);
    }

    /** Нужен ли выход к Telegram через прокси (config_url и/или static_proxies). */
    private static function wantProxyEnabled(array $tc): bool {
        if (empty($tc['use_proxy'])) {
            return false;
        }
        if (!empty($tc['proxy_static_lines']) && is_array($tc['proxy_static_lines'])) {
            return true;
        }
        $u = isset($tc['proxy_config_url']) && is_string($tc['proxy_config_url']) ? trim($tc['proxy_config_url']) : '';
        return $u !== '';
    }

    /**
     * Таймауты SOCKS к Telegram. В одном GET telegramWait вызываются deleteWebhook и getUpdates;
     * их сумма должна быть меньше типичного nginx fastcgi_read_timeout (часто 60 с), иначе upstream
     * обрывает запрос без JSON — в UI «таймаут /start», хотя PHP ещё ждёт curl.
     *
     * @return array{0:int,1:int} [CURLOPT_TIMEOUT, CURLOPT_CONNECTTIMEOUT]
     */
    private static function telegramProxyCurlTimeouts(array $tc, bool $forDeleteWebhook): array {
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            // Один HTTP GET /diagnostics/telegramWait: deleteWebhook + getUpdates; суммарно < ~55 с, иначе nginx 504.
            return [10, 8];
        }
        // [CURLOPT_TIMEOUT, CURLOPT_CONNECTTIMEOUT] — под медленный прокси.
        // deleteWebhook + getUpdates подряд: держите nginx fastcgi_read_timeout >= 120 с.
        if ($forDeleteWebhook) {
            return [45, 30];
        }
        return [55, 30];
    }

    /** Прямой HTTPS — коротко (в паре delete+get в одном запросе тоже лимит nginx). */
    private static function telegramDirectCurlTimeouts(array $tc): array {
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            return [10, 7];
        }
        $t = min(18, max(12, min((int)$tc['http_timeout'], 16)));
        $c = min(10, max(6, (int)round($t * 0.45)));
        return [$t, $c];
    }

    public static function maybeNotifyAfterRun($redis, array $checks, array $summary, array $config): void {
        $tc = self::telegramNotifyConfig($config);
        if (!$tc['enabled'] || $tc['bot_token'] === '' || !$tc['chats']) {
            return;
        }
        $counts = $summary['counts'] ?? [];
        $fail = (int)($counts['fail'] ?? 0);
        $warn = (int)($counts['warn'] ?? 0);
        $need = false;
        if ($fail > 0 && $tc['on_fail']) {
            $need = true;
        }
        if ($warn > 0 && $tc['on_warn']) {
            $need = true;
        }
        if (!$need) {
            if ($redis) {
                try {
                    $redis->del(self::REDIS_KEY_LAST_FP);
                    $redis->del(self::REDIS_KEY_LAST_SENT);
                } catch (\Throwable $e) {
                    error_log('diagnostics tg clear on ok: ' . $e->getMessage());
                }
            }
            return;
        }
        $fp = self::fingerprint($checks, $fail, $warn);
        $now = time();
        if ($redis) {
            try {
                $lastFp = (string)$redis->get(self::REDIS_KEY_LAST_FP);
                if (!empty($tc['only_notify_on_change']) && $lastFp !== '' && hash_equals($lastFp, $fp)) {
                    return;
                }
                if (empty($tc['only_notify_on_change'])) {
                    $lastT = (int)$redis->get(self::REDIS_KEY_LAST_SENT);
                    if ($lastT > 0 && ($now - $lastT) < $tc['cooldown_sec'] && hash_equals($lastFp, $fp)) {
                        return;
                    }
                }
            } catch (\Throwable $e) {
                error_log('diagnostics tg dedupe: ' . $e->getMessage());
            }
        }
        $text = self::buildMessage($checks, $summary, $tc['max_lines'], $tc['append_scenario_hints']);
        self::$tgProxyRedis = $redis;
        $ok = self::broadcast($tc, $text);
        self::$tgProxyRedis = null;
        if ($ok && $redis) {
            try {
                $redis->setex(self::REDIS_KEY_LAST_SENT, 86400 * 2, (string)$now);
                $redis->setex(self::REDIS_KEY_LAST_FP, 86400 * 2, $fp);
            } catch (\Throwable $e) {
                error_log('diagnostics tg redis: ' . $e->getMessage());
            }
        }
    }

    public static function configJsonPath(): string {
        return dirname(__DIR__) . '/config/config.json';
    }

    /**
     * Добавить chat_id и username в diagnostics.telegram (файл config.json).
     *
     * @return array{ok:bool, error?:string}
     */
    public static function appendRecipientToConfigFile(string $chatId, ?string $username): array {
        $path = self::configJsonPath();
        if (!is_readable($path)) {
            return ['ok' => false, 'error' => 'config_unreadable'];
        }
        $fh = @fopen($path, 'c+');
        if (!$fh) {
            return ['ok' => false, 'error' => 'config_open_failed'];
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return ['ok' => false, 'error' => 'config_lock_failed'];
            }
            $raw = stream_get_contents($fh);
            if ($raw === false || $raw === '') {
                flock($fh, LOCK_UN);
                return ['ok' => false, 'error' => 'config_empty'];
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                flock($fh, LOCK_UN);
                return ['ok' => false, 'error' => 'config_json_invalid'];
            }
            if (!isset($data['diagnostics']) || !is_array($data['diagnostics'])) {
                $data['diagnostics'] = [];
            }
            if (!isset($data['diagnostics']['telegram']) || !is_array($data['diagnostics']['telegram'])) {
                $data['diagnostics']['telegram'] = [];
            }
            $tg = &$data['diagnostics']['telegram'];
            if (!isset($tg['chats']) || !is_array($tg['chats'])) {
                $tg['chats'] = [];
            }
            if (!isset($tg['allowed_usernames']) || !is_array($tg['allowed_usernames'])) {
                $tg['allowed_usernames'] = [];
            }
            $cid = trim($chatId);
            if ($cid !== '' && !in_array($cid, $tg['chats'], true)) {
                $tg['chats'][] = $cid;
            }
            if ($username !== null && $username !== '') {
                $u = strtolower(trim(ltrim($username, '@')));
                if ($u !== '' && !in_array($u, $tg['allowed_usernames'], true)) {
                    $tg['allowed_usernames'][] = $u;
                }
            }
            $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($out === false) {
                flock($fh, LOCK_UN);
                return ['ok' => false, 'error' => 'encode_failed'];
            }
            if (!ftruncate($fh, 0) || rewind($fh) === false) {
                flock($fh, LOCK_UN);
                return ['ok' => false, 'error' => 'config_truncate_failed'];
            }
            $w = fwrite($fh, $out);
            fflush($fh);
            flock($fh, LOCK_UN);
            if ($w === false || $w !== strlen($out)) {
                return ['ok' => false, 'error' => 'config_write_incomplete'];
            }
            return ['ok' => true];
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array{ok:bool, result?:array, error?:string, description?:string}
     */
    public static function getUpdates(array $tc, ?int $offset): array {
        $token = $tc['bot_token'];
        if ($token === '') {
            return ['ok' => false, 'error' => 'no_token'];
        }
        $query = ['timeout' => 0, 'limit' => 100];
        if ($offset !== null && $offset > 0) {
            $query['offset'] = $offset;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/getUpdates?' . http_build_query($query);
        $raw = self::curlTelegramGet($url, $tc);
        if ($raw['ok'] === false) {
            return $raw;
        }
        $j = $raw['data'];
        if (!is_array($j) || empty($j['ok'])) {
            $d = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
            return ['ok' => false, 'error' => 'telegram_api', 'description' => $d];
        }
        return ['ok' => true, 'result' => is_array($j['result'] ?? null) ? $j['result'] : []];
    }

    /**
     * deleteWebhook для UI-опроса: прокси (или список) → при fallback прямой, без длинных цепочек curl.
     *
     * @return array{ok:bool, error?:string, description?:string, curl_errno?:int}
     */
    private static function deleteWebhookForLongPollingUiFast(array $tc): array {
        $url = 'https://api.telegram.org/bot' . rawurlencode($tc['bot_token']) . '/deleteWebhook';
        $body = http_build_query(['drop_pending_updates' => '1']);
        $wantProxy = self::wantProxyEnabled($tc);
        list($tDir, $cDir) = self::telegramDirectCurlTimeouts($tc);
        list($tSocks, $cSocks) = self::telegramProxyCurlTimeouts($tc, true);
        $directFirst = !empty($tc['delete_webhook_direct_first']);
        if (!empty($tc['fallback_direct']) && $directFirst && $wantProxy) {
            $r = self::telegramDeleteWebhookRequest($url, $body, $tc, false, $tDir, $cDir, false);
            if (!empty($r['ok'])) {
                return ['ok' => true];
            }
            $r2 = self::telegramDeleteWebhookRequest($url, $body, $tc, true, $tSocks, $cSocks, false);
            if (!empty($r2['ok'])) {
                return ['ok' => true];
            }
            return [
                'ok' => false,
                'error' => $r2['error'] ?? $r['error'] ?? 'delete_webhook_failed',
                'description' => $r2['description'] ?? $r['description'] ?? '',
                'curl_errno' => (int)($r2['curl_errno'] ?? $r['curl_errno'] ?? 0),
            ];
        }
        $r = self::telegramDeleteWebhookRequest(
            $url,
            $body,
            $tc,
            $wantProxy,
            $wantProxy ? $tSocks : $tDir,
            $wantProxy ? $cSocks : $cDir,
            false
        );
        if (!empty($r['ok'])) {
            return ['ok' => true];
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy && (self::isProxyUnreachable($e) || self::isTelegramTransportRetryErrno($e))) {
            $r2 = self::telegramDeleteWebhookRequest($url, $body, $tc, false, $tDir, $cDir, false);
            if (!empty($r2['ok'])) {
                return ['ok' => true];
            }
            return [
                'ok' => false,
                'error' => $r2['error'] ?? 'delete_webhook_failed',
                'description' => $r2['description'] ?? '',
                'curl_errno' => (int)($r2['curl_errno'] ?? 0),
            ];
        }
        return [
            'ok' => false,
            'error' => $r['error'] ?? 'delete_webhook_failed',
            'description' => $r['description'] ?? '',
            'curl_errno' => $e,
        ];
    }

    /**
     * getUpdates для UI-опроса: максимум две попытки (как выше), без лишних задержек (см. telegramGetUpdatesRequest).
     *
     * @return array{ok:bool, result?:array, error?:string, description?:string}
     */
    private static function getUpdatesForWaitChatUiFast(array $tc, ?int $offset): array {
        $query = ['timeout' => 0, 'limit' => 100];
        if ($offset !== null && $offset > 0) {
            $query['offset'] = $offset;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($tc['bot_token']) . '/getUpdates?' . http_build_query($query);
        $wantProxy = self::wantProxyEnabled($tc);
        list($tDir, $cDir) = self::telegramDirectCurlTimeouts($tc);
        list($tSocks, $cSocks) = self::telegramProxyCurlTimeouts($tc, false);
        $directFirst = !empty($tc['delete_webhook_direct_first']);
        if (!empty($tc['fallback_direct']) && $directFirst && $wantProxy) {
            $r = self::telegramGetUpdatesRequest($url, $tc, false, $tDir, $cDir, false);
            if ($r['ok'] === false) {
                $r = self::telegramGetUpdatesRequest($url, $tc, true, $tSocks, $cSocks, false);
            }
        } else {
            $r = self::telegramGetUpdatesRequest(
                $url,
                $tc,
                $wantProxy,
                $wantProxy ? $tSocks : $tDir,
                $wantProxy ? $cSocks : $cDir,
                false
            );
            if ($r['ok'] === false) {
                $e = (int)($r['curl_errno'] ?? 0);
                if (!empty($tc['fallback_direct']) && $wantProxy && (self::isProxyUnreachable($e) || self::isTelegramTransportRetryErrno($e))) {
                    $r = self::telegramGetUpdatesRequest($url, $tc, false, $tDir, $cDir, false);
                } elseif (!empty($tc['fallback_direct']) && !$wantProxy) {
                    $canProxy = self::wantProxyEnabled($tc);
                    if ($canProxy && self::isTelegramTransportRetryErrno($e)) {
                        $r = self::telegramGetUpdatesRequest($url, $tc, true, $tSocks, $cSocks, false);
                    }
                }
            }
        }
        if ($r['ok'] === false) {
            return [
                'ok' => false,
                'error' => $r['error'] ?? 'getUpdates_failed',
                'description' => $r['description'] ?? '',
            ];
        }
        $j = $r['data'];
        if (!is_array($j) || empty($j['ok'])) {
            $d = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
            return ['ok' => false, 'error' => 'telegram_api', 'description' => $d];
        }
        return ['ok' => true, 'result' => is_array($j['result'] ?? null) ? $j['result'] : []];
    }

    /**
     * getUpdates для опроса «Ждать чат» — укладывается в таймаут прокси/php-fpm (без минутных повторов curl).
     *
     * @return array{ok:bool, result?:array, error?:string, description?:string}
     */
    public static function getUpdatesForWaitChat(array $tc, ?int $offset, $redis = null): array {
        self::$tgProxyRedis = $redis;
        try {
        $token = $tc['bot_token'];
        if ($token === '') {
            return ['ok' => false, 'error' => 'no_token'];
        }
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            return self::getUpdatesForWaitChatUiFast($tc, $offset);
        }
        $query = ['timeout' => 0, 'limit' => 100];
        if ($offset !== null && $offset > 0) {
            $query['offset'] = $offset;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/getUpdates?' . http_build_query($query);
        $wantProxy = self::wantProxyEnabled($tc);
        list($tDir, $cDir) = self::telegramDirectCurlTimeouts($tc);
        list($tSocks, $cSocks) = self::telegramProxyCurlTimeouts($tc, false);
        $directFirst = !empty($tc['delete_webhook_direct_first']);
        if (!empty($tc['fallback_direct']) && $directFirst && $wantProxy) {
            $r = self::telegramGetUpdatesRequest($url, $tc, false, $tDir, $cDir);
            if ($r['ok'] === false) {
                $r = self::telegramGetUpdatesRequest($url, $tc, true, $tSocks, $cSocks);
            }
        } else {
            $r = self::telegramGetUpdatesRequest(
                $url,
                $tc,
                $wantProxy,
                $wantProxy ? $tSocks : $tDir,
                $wantProxy ? $cSocks : $cDir
            );
            if ($r['ok'] === false) {
                $e = (int)($r['curl_errno'] ?? 0);
                if (!empty($tc['fallback_direct']) && $wantProxy && (self::isProxyUnreachable($e) || self::isTelegramTransportRetryErrno($e))) {
                    $r = self::telegramGetUpdatesRequest($url, $tc, false, $tDir, $cDir);
                } elseif (!empty($tc['fallback_direct']) && !$wantProxy) {
                    $canProxy = self::wantProxyEnabled($tc);
                    if ($canProxy && self::isTelegramTransportRetryErrno($e)) {
                        $r = self::telegramGetUpdatesRequest($url, $tc, true, $tSocks, $cSocks);
                    }
                }
            }
        }
        if ($r['ok'] === false) {
            return [
                'ok' => false,
                'error' => $r['error'] ?? 'getUpdates_failed',
                'description' => $r['description'] ?? '',
            ];
        }
        $j = $r['data'];
        if (!is_array($j) || empty($j['ok'])) {
            $d = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
            return ['ok' => false, 'error' => 'telegram_api', 'description' => $d];
        }
        return ['ok' => true, 'result' => is_array($j['result'] ?? null) ? $j['result'] : []];
        } finally {
            self::$tgProxyRedis = null;
        }
    }

    /**
     * Одна попытка getUpdates. $proxySpec = null — прямой HTTPS.
     *
     * @param array{proxy:string,type:int,fingerprint:string}|null $proxySpec
     * @return array{ok:bool, data?:array, error?:string, description?:string, curl_errno?:int}
     */
    private static function telegramGetUpdatesRequestOnce(string $url, array $tc, int $timeoutSec, int $connectSec, ?array $proxySpec): array {
        $opts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $connectSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURL_HTTP_VERSION_1_1')) {
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        if ($proxySpec === null && defined('CURL_IPRESOLVE_V4')) {
            $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if ($proxySpec !== null) {
            DiagnosticsTelegramProxy::applyCurlProxy($opts, $tc, true, $proxySpec);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $errno !== 0) {
            return ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
        }
        $j = json_decode((string)$body, true);
        return ['ok' => true, 'data' => is_array($j) ? $j : []];
    }

    /**
     * @return array{ok:bool, data?:array, error?:string, description?:string, curl_errno?:int}
     */
    private static function telegramGetUpdatesRequest(string $url, array $tc, bool $useProxy, int $timeoutSec, int $connectSec, bool $allowNewnymRecycle = true): array {
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            $allowNewnymRecycle = false;
        }
        if ($useProxy) {
            $list = DiagnosticsTelegramProxy::verifiedProxyList(self::$tgProxyRedis, $tc);
            if ($list === []) {
                error_log('DiagnosticsTelegram: список прокси пуст — проверьте diagnostics.telegram.proxy.config_url и сеть');
            } else {
                $last = ['ok' => false, 'error' => 'curl_0', 'description' => '', 'curl_errno' => 0];
                foreach ($list as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $last = self::telegramGetUpdatesRequestOnce($url, $tc, $timeoutSec, $connectSec, $spec);
                    if ($last['ok'] === true) {
                        return $last;
                    }
                    $e = (int)($last['curl_errno'] ?? 0);
                    if (!self::isProxyUnreachable($e) && !self::isTelegramTransportRetryErrno($e)) {
                        return $last;
                    }
                }
                return $last;
            }
        }
        return self::telegramGetUpdatesRequestOnce($url, $tc, $timeoutSec, $connectSec, null);
    }

    /**
     * Снять webhook перед getUpdates. Через прокси CONNECT+TLS до api.telegram.org часто 10–60 с — отдельные таймауты;
     * прямой канал остаётся коротким при fallback_direct + delete_webhook_direct_first.
     *
     * @return array{ok:bool, error?:string, description?:string, curl_errno?:int}
     */
    public static function deleteWebhookForLongPolling(array $tc): array {
        if ($tc['bot_token'] === '') {
            return ['ok' => false, 'error' => 'no_token'];
        }
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            return self::deleteWebhookForLongPollingUiFast($tc);
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($tc['bot_token']) . '/deleteWebhook';
        $body = http_build_query(['drop_pending_updates' => '1']);
        $wantProxy = self::wantProxyEnabled($tc);
        list($tDir, $cDir) = self::telegramDirectCurlTimeouts($tc);
        list($tSocks, $cSocks) = self::telegramProxyCurlTimeouts($tc, true);
        $directFirst = !empty($tc['delete_webhook_direct_first']);

        if (!empty($tc['fallback_direct']) && $directFirst && $wantProxy) {
            $r = self::telegramDeleteWebhookRequest($url, $body, $tc, false, $tDir, $cDir);
            if (!empty($r['ok'])) {
                return ['ok' => true];
            }
            $r2 = self::telegramDeleteWebhookRequest($url, $body, $tc, true, $tSocks, $cSocks);
            if (!empty($r2['ok'])) {
                return ['ok' => true];
            }
            return [
                'ok' => false,
                'error' => $r2['error'] ?? $r['error'] ?? 'delete_webhook_failed',
                'description' => $r2['description'] ?? $r['description'] ?? '',
                'curl_errno' => (int)($r2['curl_errno'] ?? $r['curl_errno'] ?? 0),
            ];
        }

        $r = self::telegramDeleteWebhookRequest(
            $url,
            $body,
            $tc,
            $wantProxy,
            $wantProxy ? $tSocks : $tDir,
            $wantProxy ? $cSocks : $cDir
        );
        if (!empty($r['ok'])) {
            return ['ok' => true];
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy) {
            if (self::isProxyUnreachable($e) || self::isTelegramTransportRetryErrno($e)) {
                $r2 = self::telegramDeleteWebhookRequest($url, $body, $tc, false, $tDir, $cDir);
                if (!empty($r2['ok'])) {
                    return ['ok' => true];
                }
                return [
                    'ok' => false,
                    'error' => $r2['error'] ?? 'delete_webhook_failed',
                    'description' => $r2['description'] ?? '',
                    'curl_errno' => (int)($r2['curl_errno'] ?? 0),
                ];
            }
        }
        $canProxy = self::wantProxyEnabled($tc);
        if (!empty($tc['fallback_direct']) && !$wantProxy && $canProxy && self::isTelegramTransportRetryErrno($e)) {
            $r2 = self::telegramDeleteWebhookRequest($url, $body, $tc, true, $tSocks, $cSocks);
            if (!empty($r2['ok'])) {
                return ['ok' => true];
            }
            return [
                'ok' => false,
                'error' => $r2['error'] ?? 'delete_webhook_failed',
                'description' => $r2['description'] ?? '',
                'curl_errno' => (int)($r2['curl_errno'] ?? 0),
            ];
        }
        return [
            'ok' => false,
            'error' => $r['error'] ?? 'delete_webhook_failed',
            'description' => $r['description'] ?? '',
            'curl_errno' => $e,
        ];
    }

    public static function markPendingDeleteWebhook($redis): void {
        if (!$redis) {
            return;
        }
        try {
            $redis->setex(self::REDIS_KEY_PENDING_DELETE_WEBHOOK, 300, '1');
            $redis->del(self::REDIS_KEY_DELETE_WEBHOOK_FAILS);
            $redis->del(self::REDIS_KEY_DELETE_WEBHOOK_SKIP_UNTIL);
        } catch (\Throwable $e) {
            error_log('markPendingDeleteWebhook: ' . $e->getMessage());
        }
    }

    /**
     * Снятие webhook при «Ждать чат» — вызывается из GET telegramWait, не из POST (чтобы кнопка не ждала Telegram).
     *
     * @return array{ok:bool, error?:string, description?:string}|null null если флаг не выставлен
     */
    public static function runPendingDeleteWebhookIfAny($redis, array $tc): ?array {
        if (!$redis) {
            return null;
        }
        try {
            $p = $redis->get(self::REDIS_KEY_PENDING_DELETE_WEBHOOK);
        } catch (\Throwable $e) {
            error_log('runPendingDeleteWebhookIfAny get: ' . $e->getMessage());
            return null;
        }
        if ($p !== '1' && $p !== 1) {
            return null;
        }
        try {
            $skipUntil = (int)$redis->get(self::REDIS_KEY_DELETE_WEBHOOK_SKIP_UNTIL);
        } catch (\Throwable $e) {
            $skipUntil = 0;
        }
        if ($skipUntil > time()) {
            return [
                'ok' => false,
                'error' => 'delete_webhook_throttled',
                'description' => 'Повторная попытка deleteWebhook через ' . (string)max(1, $skipUntil - time()) . ' с',
            ];
        }
        $fails = 0;
        try {
            $fails = (int)$redis->get(self::REDIS_KEY_DELETE_WEBHOOK_FAILS);
        } catch (\Throwable $e) {
            $fails = 0;
        }
        if ($fails >= 8) {
            try {
                $redis->del(self::REDIS_KEY_PENDING_DELETE_WEBHOOK);
                $redis->del(self::REDIS_KEY_DELETE_WEBHOOK_FAILS);
            } catch (\Throwable $e) {
                //
            }
            return [
                'ok' => false,
                'error' => 'aborted',
                'description' => 'deleteWebhook: слишком много неудачных попыток; если был webhook, снимите вручную',
            ];
        }
        $prev = self::$tgProxyRedis;
        self::$tgProxyRedis = $redis;
        try {
            $wh = self::deleteWebhookForLongPolling($tc);
        } finally {
            self::$tgProxyRedis = $prev;
        }
        if (!empty($wh['ok'])) {
            try {
                $redis->del(self::REDIS_KEY_PENDING_DELETE_WEBHOOK);
                $redis->del(self::REDIS_KEY_DELETE_WEBHOOK_FAILS);
            } catch (\Throwable $e) {
                //
            }
            return $wh;
        }
        try {
            $redis->incr(self::REDIS_KEY_DELETE_WEBHOOK_FAILS);
            $redis->expire(self::REDIS_KEY_DELETE_WEBHOOK_FAILS, 300);
            $redis->setex(self::REDIS_KEY_DELETE_WEBHOOK_SKIP_UNTIL, 300, (string)(time() + 30));
        } catch (\Throwable $e) {
            error_log('runPendingDeleteWebhookIfAny incr: ' . $e->getMessage());
        }
        return $wh;
    }

    /**
     * Одна попытка deleteWebhook. $proxySpec = null — прямой HTTPS без прокси.
     *
     * @param array{proxy:string,type:int,fingerprint:string}|null $proxySpec
     * @return array{ok:bool, error?:string, description?:string, curl_errno?:int}
     */
    private static function telegramDeleteWebhookRequestOnce(string $url, string $body, array $tc, int $timeoutSec, int $connectSec, ?array $proxySpec): array {
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $connectSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURL_HTTP_VERSION_1_1')) {
            $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        if ($proxySpec === null && defined('CURL_IPRESOLVE_V4')) {
            $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if ($proxySpec !== null) {
            DiagnosticsTelegramProxy::applyCurlProxy($opts, $tc, true, $proxySpec);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j) || empty($j['ok'])) {
            $desc = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
            return ['ok' => false, 'error' => 'telegram_api', 'description' => $desc];
        }
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool, error?:string, description?:string, curl_errno?:int}
     */
    private static function telegramDeleteWebhookRequest(string $url, string $body, array $tc, bool $useProxy, int $timeoutSec, int $connectSec, bool $allowNewnymRecycle = true): array {
        if (!empty($tc['_telegram_wait_ui_fast'])) {
            $allowNewnymRecycle = false;
        }
        if ($useProxy) {
            $list = DiagnosticsTelegramProxy::verifiedProxyList(self::$tgProxyRedis, $tc);
            if ($list === []) {
                error_log('DiagnosticsTelegram: список прокси пуст — проверьте diagnostics.telegram.proxy.config_url и сеть');
            } else {
                $last = ['ok' => false, 'error' => 'curl_0', 'description' => '', 'curl_errno' => 0];
                foreach ($list as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $last = self::telegramDeleteWebhookRequestOnce($url, $body, $tc, $timeoutSec, $connectSec, $spec);
                    if (!empty($last['ok'])) {
                        return $last;
                    }
                    if (($last['error'] ?? '') === 'telegram_api') {
                        return $last;
                    }
                    $e = (int)($last['curl_errno'] ?? 0);
                    if (!self::isProxyUnreachable($e) && !self::isTelegramTransportRetryErrno($e)) {
                        return $last;
                    }
                }
                return $last;
            }
        }
        return self::telegramDeleteWebhookRequestOnce($url, $body, $tc, $timeoutSec, $connectSec, null);
    }

    /**
     * @return array{ok:bool, data?:array, error?:string, description?:string, curl_errno?:int}
     */
    private static function curlTelegramGet(string $url, array $tc): array {
        $wantProxy = self::wantProxyEnabled($tc);
        $r = self::curlTelegramGetOnce($url, $tc, $wantProxy, null);
        if ($r['ok']) {
            return $r;
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isProxyUnreachable($e)) {
            error_log('DiagnosticsTelegram: прокси недоступен (' . ($r['description'] ?? '') . '), повтор без прокси');
            $r = self::curlTelegramGetOnce($url, $tc, false, null);
            if ($r['ok']) {
                return $r;
            }
            $e = (int)($r['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: GET через прокси — таймаут/сеть, повтор без прокси');
            $r2 = self::curlTelegramGetOnce($url, $tc, false, null);
            if ($r2['ok']) {
                return $r2;
            }
            $r = $r2;
            $e = (int)($r2['curl_errno'] ?? 0);
        }
        $canProxy = self::wantProxyEnabled($tc);
        if (!empty($tc['fallback_direct']) && !$wantProxy && $canProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: GET напрямую не прошёл, повтор через прокси');
            $r3 = self::curlTelegramGetOnce($url, $tc, true, null);
            if ($r3['ok']) {
                return $r3;
            }
            $r = $r3;
            $e = (int)($r3['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && self::isTelegramTransportRetryErrno($e)) {
            $longT = max(120, (int)$tc['http_timeout']);
            $r4 = self::curlTelegramGetOnce($url, $tc, $wantProxy, $longT);
            if ($r4['ok']) {
                return $r4;
            }
            $r5 = self::curlTelegramGetOnce($url, $tc, !$wantProxy, $longT);
            return $r5;
        }
        return $r;
    }

    private static function curlTelegramGetOnce(string $url, array $tc, bool $useProxy, ?int $timeoutOverride): array {
        $t = $timeoutOverride ?? $tc['http_timeout'];
        $conn = min(90, max(10, (int)($t / 2)));
        if ($useProxy) {
            $conn = max(30, $conn);
        }
        $baseOpts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $t,
            CURLOPT_CONNECTTIMEOUT => $conn,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($useProxy) {
            $list = DiagnosticsTelegramProxy::verifiedProxyList(self::$tgProxyRedis, $tc);
            if ($list === []) {
                error_log('DiagnosticsTelegram: список прокси пуст — проверьте diagnostics.telegram.proxy.config_url и сеть');
            } else {
                $last = ['ok' => false, 'error' => 'curl_0', 'description' => '', 'curl_errno' => 0];
                foreach ($list as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $opts = $baseOpts;
                    DiagnosticsTelegramProxy::applyCurlProxy($opts, $tc, true, $spec);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, $opts);
                    $body = curl_exec($ch);
                    $errno = curl_errno($ch);
                    $err = curl_error($ch);
                    curl_close($ch);
                    if ($body === false || $errno !== 0) {
                        $last = ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
                        if (self::isProxyUnreachable($errno) || self::isTelegramTransportRetryErrno($errno)) {
                            continue;
                        }
                        return $last;
                    }
                    $j = json_decode((string)$body, true);
                    return ['ok' => true, 'data' => is_array($j) ? $j : []];
                }
                return $last;
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $baseOpts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $errno !== 0) {
            return ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
        }
        $j = json_decode((string)$body, true);
        return ['ok' => true, 'data' => is_array($j) ? $j : []];
    }

    public static function sendTestMessage(array $config): array {
        $tc = self::telegramNotifyConfig($config);
        if (!$tc['enabled']) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }
        if ($tc['bot_token'] === '' || !$tc['chats']) {
            return ['ok' => false, 'error' => 'no_token_or_chats'];
        }
        $host = php_uname('n') ?: 'server';
        $text = "<b>RBT диагностика — тест</b>\nСообщение через прокси из diagnostics.telegram.proxy (если настроено).\nХост: " . self::h($host) . "\nВремя: " . self::h(date('c'));
        $ok = self::broadcast($tc, $text);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'send_failed'];
    }

    /**
     * Учебное сообщение в том же формате, что алерт после прогона, но только по строкам симуляции.
     * Не трогает Redis cooldown реальных алертов.
     */
    public static function sendSimulatedDiagnosticAlert(array $config): array {
        require_once __DIR__ . '/DiagnosticsService.php';
        $checks = DiagnosticsService::simulationChecks($config);
        if ($checks === []) {
            return ['ok' => false, 'error' => 'simulate_disabled'];
        }
        $tc = self::telegramNotifyConfig($config);
        if (!$tc['enabled']) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }
        if ($tc['bot_token'] === '' || !$tc['chats']) {
            return ['ok' => false, 'error' => 'no_token_or_chats'];
        }
        $summary = DiagnosticsService::summarizeChecks($checks);
        $body = self::buildMessage($checks, $summary, $tc['max_lines'], $tc['append_scenario_hints']);
        $text = "<b>⚠️ УЧЕБНЫЙ АЛЕРТ (симуляция диагностики)</b>\n<i>Кнопка в UI при включённом diagnostics.simulate.enabled</i>\n\n" . $body;
        $ok = self::broadcast($tc, $text);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'send_failed'];
    }

    private static function fingerprint(array $checks, int $fail, int $warn): string {
        $ids = [];
        foreach ($checks as $c) {
            if (($c['status'] ?? '') === 'fail') {
                $ids[] = (string)($c['id'] ?? '');
            }
        }
        sort($ids);
        return hash('sha256', $fail . '|' . $warn . '|' . implode(',', $ids));
    }

    private static function buildMessage(array $checks, array $summary, int $maxLines, bool $appendHints): string {
        $counts = $summary['counts'] ?? [];
        $fail = (int)($counts['fail'] ?? 0);
        $warn = (int)($counts['warn'] ?? 0);
        $lines = [];
        $lines[] = '<b>RBT — диагностика сервера</b>';
        $lines[] = 'Ошибки: ' . $fail . ', предупреждения: ' . $warn . ', пропуски: ' . (int)($counts['skip'] ?? 0);
        $lines[] = '';
        $n = 0;
        foreach ($checks as $c) {
            $st = $c['status'] ?? '';
            if ($st !== 'fail' && $st !== 'warn') {
                continue;
            }
            if ($n >= $maxLines) {
                $lines[] = '… ещё строки опущены';
                break;
            }
            $title = self::h((string)($c['title'] ?? $c['id'] ?? '?'));
            $hint = isset($c['hint']) ? self::h((string)$c['hint']) : '';
            $val = isset($c['value']) ? self::h((string)$c['value']) : '';
            $lat = isset($c['latencyMs']) ? (' ' . (int)$c['latencyMs'] . ' мс') : '';
            $lines[] = ($st === 'fail' ? '🔴 ' : '🟡 ') . $title;
            if ($val !== '') {
                $lines[] = '   ' . $val . $lat;
            }
            if ($hint !== '') {
                $lines[] = '   <i>' . $hint . '</i>';
            }
            $n++;
        }
        if ($appendHints) {
            $lines[] = '';
            $lines[] = '<b>Типичные сценарии в этой системе</b>';
            foreach (self::SCENARIO_HINTS_RU as $h) {
                $lines[] = '• ' . self::h($h);
            }
        }
        return implode("\n", $lines);
    }

    private static function h(string $s): string {
        return htmlspecialchars($s, ENT_COMPAT | ENT_HTML5, 'UTF-8');
    }

    private static function broadcast(array $tc, string $text): bool {
        $allOk = true;
        foreach ($tc['chats'] as $chat) {
            $r = self::telegramSendMessage($tc['bot_token'], $chat, $text, $tc);
            if (!$r['ok']) {
                $allOk = false;
                error_log('diagnostics telegram send fail chat=' . $chat . ' err=' . ($r['error'] ?? '?'));
            }
        }
        return $allOk;
    }

    public static function telegramSendMessage(string $token, string $chatId, string $text, array $tc): array {
        $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
        $parts = self::splitUtf8Telegram($text, 3800);
        $last = ['ok' => true];
        foreach ($parts as $chunk) {
            $post = [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => '1',
            ];
            $last = self::curlTelegramPost($url, $post, $tc);
            if (!$last['ok']) {
                return $last;
            }
            usleep(150000);
        }
        return $last;
    }

    private static function splitUtf8Telegram(string $text, int $maxBytes): array {
        if (strlen($text) <= $maxBytes) {
            return [$text];
        }
        $out = [];
        $buf = '';
        $len = strlen($text);
        for ($i = 0; $i < $len;) {
            $ch = $text[$i];
            $ord = ord($ch);
            $cl = 1;
            if (($ord & 0xE0) === 0xC0) {
                $cl = 2;
            } elseif (($ord & 0xF0) === 0xE0) {
                $cl = 3;
            } elseif (($ord & 0xF8) === 0xF0) {
                $cl = 4;
            }
            $piece = substr($text, $i, $cl);
            if (strlen($buf) + strlen($piece) > $maxBytes) {
                $out[] = $buf;
                $buf = $piece;
            } else {
                $buf .= $piece;
            }
            $i += $cl;
        }
        if ($buf !== '') {
            $out[] = $buf;
        }
        return $out ?: [''];
    }

    /**
     * @param array<string,string>|string $post тело: массив (как раньше) или строка x-www-form-urlencoded
     */
    private static function curlTelegramPost(string $url, $post, array $tc): array {
        $wantProxy = self::wantProxyEnabled($tc);
        $r = self::curlTelegramPostOnce($url, $post, $tc, $wantProxy, null);
        if ($r['ok']) {
            return $r;
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isProxyUnreachable($e)) {
            error_log('DiagnosticsTelegram: прокси недоступен при POST, повтор без прокси');
            $r = self::curlTelegramPostOnce($url, $post, $tc, false, null);
            if ($r['ok']) {
                return $r;
            }
            $e = (int)($r['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: POST через прокси — таймаут/сеть, повтор без прокси');
            $r2 = self::curlTelegramPostOnce($url, $post, $tc, false, null);
            if ($r2['ok']) {
                return $r2;
            }
            $r = $r2;
            $e = (int)($r2['curl_errno'] ?? 0);
        }
        $canProxy = self::wantProxyEnabled($tc);
        if (!empty($tc['fallback_direct']) && !$wantProxy && $canProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: POST напрямую не прошёл, повтор через прокси');
            $r3 = self::curlTelegramPostOnce($url, $post, $tc, true, null);
            if ($r3['ok']) {
                return $r3;
            }
            $r = $r3;
            $e = (int)($r3['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && self::isTelegramTransportRetryErrno($e)) {
            $longT = max(120, (int)$tc['http_timeout']);
            $r4 = self::curlTelegramPostOnce($url, $post, $tc, $wantProxy, $longT);
            if ($r4['ok']) {
                return $r4;
            }
            $r5 = self::curlTelegramPostOnce($url, $post, $tc, !$wantProxy, $longT);
            return $r5;
        }
        return $r;
    }

    /**
     * @param array<string,string>|string $post
     */
    private static function curlTelegramPostOnce(string $url, $post, array $tc, bool $useProxy, ?int $timeoutOverride): array {
        $t = $timeoutOverride ?? $tc['http_timeout'];
        $conn = min(90, max(10, (int)($t / 2)));
        if ($useProxy) {
            $conn = max(30, $conn);
        }
        $baseOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $t,
            CURLOPT_CONNECTTIMEOUT => $conn,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($useProxy) {
            $list = DiagnosticsTelegramProxy::verifiedProxyList(self::$tgProxyRedis, $tc);
            if ($list === []) {
                error_log('DiagnosticsTelegram: список прокси пуст — проверьте diagnostics.telegram.proxy.config_url и сеть');
            } else {
                $last = ['ok' => false, 'error' => 'curl_0', 'description' => '', 'curl_errno' => 0];
                foreach ($list as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $opts = $baseOpts;
                    DiagnosticsTelegramProxy::applyCurlProxy($opts, $tc, true, $spec);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, $opts);
                    $raw = curl_exec($ch);
                    $errno = curl_errno($ch);
                    $err = curl_error($ch);
                    curl_close($ch);
                    if ($raw === false || $errno !== 0) {
                        $last = ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
                        if (self::isProxyUnreachable($errno) || self::isTelegramTransportRetryErrno($errno)) {
                            continue;
                        }
                        return $last;
                    }
                    $j = json_decode((string)$raw, true);
                    if (!is_array($j) || empty($j['ok'])) {
                        $desc = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
                        return ['ok' => false, 'error' => 'telegram_api', 'description' => $desc];
                    }
                    return ['ok' => true];
                }
                return $last;
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $baseOpts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'error' => 'curl_' . $errno, 'description' => $err, 'curl_errno' => $errno];
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j) || empty($j['ok'])) {
            $desc = is_array($j) ? (string)($j['description'] ?? '') : 'bad_json';
            return ['ok' => false, 'error' => 'telegram_api', 'description' => $desc];
        }
        return ['ok' => true];
    }

}

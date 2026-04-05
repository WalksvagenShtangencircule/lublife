<?php

/**
 * Уведомления о результатах диагностики в Telegram через Tor (SOCKS5).
 * Опционально: SIGNAL NEWNYM перед запросом для смены выходного IP.
 */
class DiagnosticsTelegramNotifier {

    public const REDIS_KEY_LAST_SENT = 'DIAG:TG:last_sent';
    public const REDIS_KEY_LAST_FP = 'DIAG:TG:last_fp';
    public const REDIS_KEY_WAIT_UNTIL = 'DIAG:TG:WAIT_UNTIL';
    public const REDIS_KEY_ARM_AT = 'DIAG:TG:ARM_AT';
    public const REDIS_KEY_POLL_OFFSET = 'DIAG:TG:POLL_OFFSET';

    private const SCENARIO_HINTS_RU = [
        'DVR/медиасервер отвечает десятки секунд → растёт tasks_changes, долго живут строки в core_running_processes (cron minutely ждёт детей).',
        'ClickHouse «тупит» или таймаут → подвисают отчёты analytics, plog, tt (в коде часто таймаут curl ~5 с).',
        'Заполненный диск / высокий load → смарт-конфиг и ffmpeg могут не успевать, очередь устройств копится.',
        'Пустой ответ Bot API без туннеля → Tor SOCKS, http_proxy (Clash/sing-box) или свой api_base_url (nginx → api.telegram.org).',
    ];

    public static function telegramNotifyConfig(array $config): array {
        $root = $config['diagnostics']['telegram'] ?? [];
        $tor = is_array($root['tor'] ?? null) ? $root['tor'] : [];
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
            'tor_socks_host' => isset($tor['socks_host']) && is_string($tor['socks_host']) ? $tor['socks_host'] : '127.0.0.1',
            'tor_socks_port' => isset($tor['socks_port']) ? max(1, (int)$tor['socks_port']) : 9050,
            'tor_control_host' => isset($tor['control_host']) && is_string($tor['control_host']) ? $tor['control_host'] : '127.0.0.1',
            'tor_control_port' => isset($tor['control_port']) ? max(1, (int)$tor['control_port']) : 9051,
            'tor_control_password' => isset($tor['control_password']) && is_string($tor['control_password']) ? $tor['control_password'] : '',
            'tor_newnym_before_send' => !empty($tor['newnym_before_send']),
            'http_timeout' => isset($tor['http_timeout_sec']) ? max(10, (int)$tor['http_timeout_sec']) : 45,
            'on_fail' => array_key_exists('on_fail', $alert) ? (bool)$alert['on_fail'] : true,
            'on_warn' => !empty($alert['on_warn']),
            'cooldown_sec' => isset($alert['cooldown_sec']) ? max(60, (int)$alert['cooldown_sec']) : 3600,
            /** true (по умолчанию): Telegram только при смене набора fail/warn или счётчиков; не повторять каждый час одно и то же */
            'only_notify_on_change' => !array_key_exists('only_notify_on_change', $alert) ? true : (bool)$alert['only_notify_on_change'],
            'max_lines' => isset($alert['max_lines']) ? max(5, min(80, (int)$alert['max_lines'])) : 35,
            'append_scenario_hints' => !empty($root['append_scenario_hints']),
            'use_tor_proxy' => !array_key_exists('use_proxy', $tor) || !empty($tor['use_proxy']),
            // Tor недоступен (9050 закрыт) — один повтор к api.telegram.org без прокси; false = только Tor
            'fallback_direct' => !array_key_exists('fallback_direct', $tor) || !empty($tor['fallback_direct']),
        ];
    }

    /** Ошибки curl: нет соединения с SOCKS / прокси */
    private static function isTorSocksUnreachable(int $curlErrno): bool {
        return in_array($curlErrno, [5, 7, 96, 97], true);
    }

    /** Таймаут или обрыв до api.telegram.org (в т.ч. через Tor) — пробуем другой маршрут */
    private static function isTelegramTransportRetryErrno(int $curlErrno): bool {
        return in_array($curlErrno, [7, 28, 35, 56], true);
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
        $ok = self::broadcast($tc, $text);
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
     * Снять webhook — иначе getUpdates не получает апдейты (типичная причина «/start не доходит»).
     *
     * @return array{ok:bool, error?:string, description?:string}
     */
    public static function deleteWebhookForLongPolling(array $tc): array {
        if ($tc['bot_token'] === '') {
            return ['ok' => false, 'error' => 'no_token'];
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($tc['bot_token']) . '/deleteWebhook';
        $r = self::curlTelegramPost($url, http_build_query(['drop_pending_updates' => '0']), $tc);
        if (!empty($r['ok'])) {
            return ['ok' => true];
        }
        return [
            'ok' => false,
            'error' => $r['error'] ?? 'delete_webhook_failed',
            'description' => $r['description'] ?? '',
        ];
    }

    /**
     * @return array{ok:bool, data?:array, error?:string, description?:string, curl_errno?:int}
     */
    private static function curlTelegramGet(string $url, array $tc): array {
        $wantProxy = !empty($tc['use_tor_proxy']) && $tc['tor_socks_host'] !== '' && $tc['tor_socks_port'] > 0;
        $r = self::curlTelegramGetOnce($url, $tc, $wantProxy, null);
        if ($r['ok']) {
            return $r;
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTorSocksUnreachable($e)) {
            error_log('DiagnosticsTelegram: Tor SOCKS недоступен (' . ($r['description'] ?? '') . '), повтор без прокси');
            $r = self::curlTelegramGetOnce($url, $tc, false, null);
            if ($r['ok']) {
                return $r;
            }
            $e = (int)($r['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: GET через Tor — таймаут/сеть, повтор без прокси');
            $r2 = self::curlTelegramGetOnce($url, $tc, false, null);
            if ($r2['ok']) {
                return $r2;
            }
            $r = $r2;
            $e = (int)($r2['curl_errno'] ?? 0);
        }
        $canTor = !empty($tc['use_tor_proxy']) && $tc['tor_socks_host'] !== '' && $tc['tor_socks_port'] > 0;
        if (!empty($tc['fallback_direct']) && !$wantProxy && $canTor && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: GET напрямую не прошёл, повтор через Tor');
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
        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $t,
            CURLOPT_CONNECTTIMEOUT => min(90, max(10, (int)($t / 2))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($useProxy) {
            $opts[CURLOPT_PROXY] = $tc['tor_socks_host'] . ':' . $tc['tor_socks_port'];
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        }
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

    public static function sendTestMessage(array $config): array {
        $tc = self::telegramNotifyConfig($config);
        if (!$tc['enabled']) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }
        if ($tc['bot_token'] === '' || !$tc['chats']) {
            return ['ok' => false, 'error' => 'no_token_or_chats'];
        }
        $host = php_uname('n') ?: 'server';
        $text = "<b>RBT диагностика — тест</b>\nСообщение через Tor (если настроен).\nХост: " . self::h($host) . "\nВремя: " . self::h(date('c'));
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
        $failIds = [];
        $warnIds = [];
        foreach ($checks as $c) {
            $st = $c['status'] ?? '';
            $id = (string)($c['id'] ?? '');
            if ($st === 'fail') {
                $failIds[] = $id;
            } elseif ($st === 'warn') {
                $warnIds[] = $id;
            }
        }
        sort($failIds);
        sort($warnIds);
        return hash('sha256', $fail . '|' . $warn . '|f:' . implode(',', $failIds) . '|w:' . implode(',', $warnIds));
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
        if ($tc['tor_newnym_before_send']) {
            self::torNewnym(
                $tc['tor_control_host'],
                $tc['tor_control_port'],
                $tc['tor_control_password']
            );
            usleep(800000);
        }
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
        $wantProxy = !empty($tc['use_tor_proxy']) && $tc['tor_socks_host'] !== '' && $tc['tor_socks_port'] > 0;
        $r = self::curlTelegramPostOnce($url, $post, $tc, $wantProxy, null);
        if ($r['ok']) {
            return $r;
        }
        $e = (int)($r['curl_errno'] ?? 0);
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTorSocksUnreachable($e)) {
            error_log('DiagnosticsTelegram: Tor SOCKS недоступен при POST, повтор без прокси');
            $r = self::curlTelegramPostOnce($url, $post, $tc, false, null);
            if ($r['ok']) {
                return $r;
            }
            $e = (int)($r['curl_errno'] ?? 0);
        }
        if (!empty($tc['fallback_direct']) && $wantProxy && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: POST через Tor — таймаут/сеть, повтор без прокси');
            $r2 = self::curlTelegramPostOnce($url, $post, $tc, false, null);
            if ($r2['ok']) {
                return $r2;
            }
            $r = $r2;
            $e = (int)($r2['curl_errno'] ?? 0);
        }
        $canTor = !empty($tc['use_tor_proxy']) && $tc['tor_socks_host'] !== '' && $tc['tor_socks_port'] > 0;
        if (!empty($tc['fallback_direct']) && !$wantProxy && $canTor && self::isTelegramTransportRetryErrno($e)) {
            error_log('DiagnosticsTelegram: POST напрямую не прошёл, повтор через Tor');
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
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $t,
            CURLOPT_CONNECTTIMEOUT => min(90, max(10, (int)($t / 2))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($useProxy) {
            $opts[CURLOPT_PROXY] = $tc['tor_socks_host'] . ':' . $tc['tor_socks_port'];
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        }
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

    private static function torNewnym(string $host, int $port, string $password): bool {
        $fp = @fsockopen($host, $port, $errno, $errstr, 4);
        if (!$fp) {
            error_log('diagnostics tor control connect: ' . ($errstr ?: (string)$errno));
            return false;
        }
        stream_set_timeout($fp, 4);
        if ($password !== '') {
            $safe = str_replace(["\r", "\n"], '', $password);
            $safe = str_replace('"', '\\"', $safe);
            fwrite($fp, 'AUTHENTICATE "' . $safe . '"' . "\r\n");
        } else {
            fwrite($fp, "AUTHENTICATE \"\"\r\n");
        }
        self::readTorControl($fp);
        fwrite($fp, "SIGNAL NEWNYM\r\n");
        self::readTorControl($fp);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }

    private static function readTorControl($fp): void {
        $guard = 0;
        while (!feof($fp) && $guard++ < 50) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                break;
            }
            if (preg_match('/^(250|515|250 )/', $line)) {
                break;
            }
        }
    }
}

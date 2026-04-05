<?php

/**
 * Сборка проверок для модуля «Диагностика»: ОС, БД, интеграции, SSL/TLS.
 */
class DiagnosticsService {

    private static function startsWith(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public const CACHE_KEY_SUMMARY = 'DIAG:SUMMARY';
    public const CACHE_KEY_HISTORY = 'DIAG:HISTORY';

    /** Только для опасных действий (например clearFrontCache); GET-диагностика идёт по матрице прав API. */
    public static function assertDiagnosticsAllowed(array $params): bool {
        $uid = (int)($params['_uid'] ?? -1);
        if ($uid === 0) {
            return true;
        }
        return ($params['_login'] ?? '') === 'admin';
    }

    public static function diagConfig(array $config): array {
        $d = $config['diagnostics'] ?? [];
        return [
            'allowOsProbe' => !empty($d['allowOsProbe']),
            'allowSystemd' => !empty($d['allowSystemd']),
            'allowExec' => !empty($d['allowExec']),
            'summaryCacheTtl' => isset($d['summaryCacheTtl']) ? max(10, (int)$d['summaryCacheTtl']) : 45,
            'historyMaxPoints' => isset($d['historyMaxPoints']) ? max(5, min(500, (int)$d['historyMaxPoints'])) : 48,
            'sslVerifyStrict' => !empty($d['sslVerifyStrict']),
            'sslExtraHosts' => isset($d['sslExtraHosts']) && is_array($d['sslExtraHosts']) ? $d['sslExtraHosts'] : [],
            'httpTimeout' => isset($d['httpTimeout']) ? max(1, (int)$d['httpTimeout']) : 4,
            /** Порог «медленного» HTTP (мс): интеграции и DVR — типичный признак заторов очереди */
            'slowHttpWarnMs' => isset($d['slowHttpWarnMs']) ? max(500, (int)$d['slowHttpWarnMs']) : 8000,
            /** ClickHouse: отклик SELECT 1 дольше — риск для аналитики / plog / tt */
            'clickhouseSlowMs' => isset($d['clickhouseSlowMs']) ? max(200, (int)$d['clickhouseSlowMs']) : 2000,
            /** Возраст незавершённой записи core_running_processes (сек), предупреждение */
            'bgProcessWarnSec' => isset($d['bgProcessWarnSec']) ? max(15, (int)$d['bgProcessWarnSec']) : 90,
            /** То же, статус fail — вероятный зависший воркер */
            'bgProcessFailSec' => isset($d['bgProcessFailSec']) ? max(30, (int)$d['bgProcessFailSec']) : 600,
        ];
    }

    public static function runAll(array $params, ?string $groupFilter, bool $heavy): array {
        global $config;
        $cfg = self::diagConfig($config);
        $checks = [];
        $want = static function (?string $g, string $cat): bool {
            if ($g === null || $g === '') {
                return true;
            }
            return strcasecmp($g, $cat) === 0;
        };
        if ($want($groupFilter, 'config')) {
            $checks = array_merge($checks, self::checksConfigPhp($config));
        }
        if ($want($groupFilter, 'datastores')) {
            $checks = array_merge($checks, self::checksDatastores($params, $config));
        }
        if ($want($groupFilter, 'os') && $cfg['allowOsProbe']) {
            $checks = array_merge($checks, self::checksOs($cfg));
        } elseif ($want($groupFilter, 'os')) {
            $checks[] = self::item('os_skipped', 'ОС: зонд отключён', 'os', 'skip', [
                'hint' => 'Включите diagnostics.allowOsProbe в config.json',
            ]);
        }
        if ($want($groupFilter, 'integrations')) {
            $checks = array_merge($checks, self::checksHttpIntegrations($config, $cfg));
            $checks = array_merge($checks, self::checksDvrHttp($config, $cfg));
            $checks = array_merge($checks, self::checksClickhouseDeep($config, $cfg));
            $checks = array_merge($checks, self::checksZabbix($config, $cfg['httpTimeout']));
        }
        if ($want($groupFilter, 'ssl')) {
            $checks = array_merge($checks, self::checksSslCertificates($config, $cfg));
        }
        if ($want($groupFilter, 'media') && $heavy) {
            $checks = array_merge($checks, self::checksFfmpeg($cfg));
        } elseif ($want($groupFilter, 'media')) {
            $checks[] = self::item('ffmpeg_skipped', 'FFmpeg: пропуск (лёгкий режим)', 'media', 'skip', [
                'hint' => 'Запустите полную диагностику с тяжёлыми проверками',
            ]);
        }
        if ($want($groupFilter, 'background')) {
            $checks = array_merge($checks, self::checksQueue($params, $cfg));
            $checks = array_merge($checks, self::checksCronHint($cfg));
        }
        if ($want($groupFilter, 'systemd') && $cfg['allowSystemd'] && $cfg['allowExec']) {
            $checks = array_merge($checks, self::checksSystemd($config, $cfg));
        } elseif ($want($groupFilter, 'systemd')) {
            $checks[] = self::item('systemd_skipped', 'systemd: проверка отключена', 'systemd', 'skip', [
                'hint' => 'Включите diagnostics.allowSystemd и allowExec',
            ]);
        }
        if ($groupFilter === null || $groupFilter === '') {
            $checks = array_merge(self::simulationChecks($config), $checks);
        }
        return $checks;
    }

    /**
     * Включается diagnostics.simulate.enabled — искусственные warn/fail для проверки UI и Telegram без поломки сервера.
     * Публичный метод: нужен для кнопки «тест алерта» и флага simulateActive в API.
     */
    public static function simulationChecks(array $config): array {
        if (empty($config['diagnostics']['simulate']['enabled'])) {
            return [];
        }
        return [
            self::item('sim_clickhouse_slow', '[Симуляция] ClickHouse: очень медленный отклик', 'integrations', 'warn', [
                'value' => '8500 мс',
                'latencyMs' => 8500,
                'hint' => 'Настоящая проверка ниже; эта строка — учебная имитация типичного сбоя.',
                'explain' => 'Отключите diagnostics.simulate.enabled в config.json после тестов.',
            ]),
            self::item('sim_dvr_timeout', '[Симуляция] Медиасервер: таймаут', 'integrations', 'fail', [
                'value' => 'таймаут',
                'hint' => 'Имитация заторов очереди tasks_changes из-за долгих ответов DVR.',
            ]),
            self::item('sim_queue_insight', '[Симуляция] Вероятная причина заторов', 'insights', 'warn', [
                'value' => 'см. симуляции выше',
                'hint' => 'Так в интерфейсе выглядит блок «Подсказки» при реальных проблемах.',
            ]),
        ];
    }

    public static function runOne(array $params, string $id): array {
        foreach (self::runAll($params, null, true) as $c) {
            if (($c['id'] ?? '') === $id) {
                return [$c];
            }
        }
        return [self::item('unknown', 'Неизвестная проверка', 'config', 'fail', [
            'hint' => 'Неверный идентификатор',
            'details' => ['id' => $id],
        ])];
    }

    public static function summarizeChecks(array $checks): array {
        $ok = $warn = $fail = $skip = 0;
        foreach ($checks as $c) {
            switch ($c['status'] ?? '') {
                case 'ok': $ok++; break;
                case 'warn': $warn++; break;
                case 'fail': $fail++; break;
                default: $skip++;
            }
        }
        $diskPercent = null;
        $loadPercent = null;
        foreach ($checks as $c) {
            if (($c['id'] ?? '') === 'memory' && isset($c['percent'])) {
                // optional
            }
            if (self::startsWith((string)($c['id'] ?? ''), 'disk_') && isset($c['percent']) && $diskPercent === null) {
                $diskPercent = $c['percent'];
            }
            if (($c['id'] ?? '') === 'loadavg' && isset($c['percent'])) {
                $loadPercent = $c['percent'];
            }
        }
        return [
            'counts' => compact('ok', 'warn', 'fail', 'skip'),
            'diskPercent' => $diskPercent,
            'loadPercent' => $loadPercent,
            'generatedAt' => time(),
        ];
    }

    public static function pushHistory($redis, array $diagConfig, array $summaryBlob): void {
        if (!$redis) {
            return;
        }
        $max = (int)($diagConfig['historyMaxPoints'] ?? 48);
        $key = self::CACHE_KEY_HISTORY;
        $payload = json_encode([
            't' => time(),
            'disk' => $summaryBlob['diskPercent'] ?? null,
            'load' => $summaryBlob['loadPercent'] ?? null,
            'fail' => $summaryBlob['counts']['fail'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
        try {
            $redis->rPush($key, $payload);
            $redis->lTrim($key, -$max, -1);
            $redis->expire($key, 86400 * 2);
        } catch (Throwable $e) {
            error_log('DiagnosticsService::pushHistory: ' . $e->getMessage());
        }
    }

    private static function checksConfigPhp(array $config): array {
        $out = [];
        $out[] = self::item('php_version', 'Версия PHP', 'config', 'ok', [
            'value' => PHP_VERSION,
            'unit' => '',
        ]);
        $need = ['pdo_pgsql', 'redis', 'curl', 'mbstring', 'openssl', 'json'];
        $loaded = get_loaded_extensions();
        $missing = array_values(array_diff($need, $loaded));
        $out[] = self::item('php_extensions', 'Расширения PHP', 'config', $missing ? 'warn' : 'ok', [
            'value' => implode(', ', $need),
            'details' => ['missing' => $missing],
            'hint' => $missing ? 'Установите: ' . implode(', ', $missing) : null,
        ]);
        $disp = ini_get('display_errors');
        $badDisp = ($disp === '1' || strtolower((string)$disp) === 'on');
        $out[] = self::item('php_display_errors', 'display_errors (php.ini)', 'config', $badDisp ? 'warn' : 'ok', [
            'value' => (string)$disp,
            'hint' => $badDisp ? 'В продакшене отключите display_errors' : null,
        ]);
        $df = ini_get('disable_functions');
        $out[] = self::item('php_disable_functions', 'disable_functions', 'config', 'ok', [
            'value' => $df ? 'задано' : 'нет',
            'details' => ['snippet' => $df ? substr((string)$df, 0, 200) : ''],
        ]);
        $out[] = self::item('config_keys', 'Ключи config.json', 'config',
            (isset($config['db'], $config['redis'], $config['backends'])) ? 'ok' : 'fail', [
                'hint' => (isset($config['db'], $config['redis'], $config['backends'])) ? null : 'Отсутствуют db/redis/backends',
            ]);
        return $out;
    }

    private static function checksDatastores(array $params, array $config): array {
        $out = [];
        $db = $params['_db'] ?? null;
        $redis = $params['_redis'] ?? null;
        if ($db) {
            $t0 = microtime(true);
            try {
                $db->query('select 1');
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $out[] = self::item('pgsql_ping', 'PostgreSQL', 'datastores', 'ok', [
                    'latencyMs' => $ms,
                    'value' => 'OK',
                ]);
            } catch (Throwable $e) {
                $out[] = self::item('pgsql_ping', 'PostgreSQL', 'datastores', 'fail', [
                    'hint' => 'Проверьте PostgreSQL и DSN',
                    'details' => ['error' => $e->getMessage()],
                ]);
            }
        } else {
            $out[] = self::item('pgsql_ping', 'PostgreSQL', 'datastores', 'skip', ['hint' => 'Нет PDO']);
        }
        if ($redis) {
            $t0 = microtime(true);
            try {
                $pong = $redis->ping();
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $out[] = self::item('redis_ping', 'Redis', 'datastores', 'ok', [
                    'latencyMs' => $ms,
                    'value' => is_string($pong) ? $pong : 'PONG',
                ]);
            } catch (Throwable $e) {
                $out[] = self::item('redis_ping', 'Redis', 'datastores', 'fail', [
                    'hint' => 'Проверьте redis-server',
                    'details' => ['error' => $e->getMessage()],
                ]);
            }
        }
        return $out;
    }

    private static function checksOs(array $cfg): array {
        $out = [];
        foreach (['/', '/var', '/tmp'] as $p) {
            if (!@is_dir($p)) {
                continue;
            }
            $free = @disk_free_space($p);
            $total = @disk_total_space($p);
            if ($free === false || !$total) {
                continue;
            }
            $usedPct = (int)round(100 * (1 - $free / $total));
            $st = $usedPct >= 95 ? 'fail' : ($usedPct >= 85 ? 'warn' : 'ok');
            $out[] = self::item('disk_' . md5($p), "Диск {$p} (занято)", 'os', $st, [
                'percent' => $usedPct,
                'value' => $usedPct,
                'unit' => '%',
                'hint' => $st !== 'ok' ? 'Освободите место' : null,
            ]);
        }
        if (is_readable('/proc/loadavg')) {
            $la = explode(' ', trim((string)@file_get_contents('/proc/loadavg')));
            $ncpu = (int)trim((string)@shell_exec('nproc'));
            if ($ncpu < 1) {
                $ncpu = 1;
            }
            $load1 = (float)($la[0] ?? 0);
            $pct = (int)min(100, round(100 * $load1 / $ncpu));
            $st = $pct >= 90 ? 'warn' : 'ok';
            $out[] = self::item('loadavg', 'Нагрузка (load/CPU)', 'os', $st, [
                'percent' => $pct,
                'value' => round($load1, 2),
                'unit' => 'load1',
                'details' => ['ncpu' => $ncpu],
            ]);
        }
        if (is_readable('/proc/meminfo')) {
            $mem = @file_get_contents('/proc/meminfo');
            if ($mem) {
                $parse = static function (string $k) use ($mem): int {
                    if (preg_match('/^' . preg_quote($k, '/') . ':\s+(\d+)/m', $mem, $m)) {
                        return (int)$m[1];
                    }
                    return 0;
                };
                $total = $parse('MemTotal');
                $avail = $parse('MemAvailable');
                if ($total > 0) {
                    $usedPct = (int)round(100 * (1 - $avail / $total));
                    $st = $usedPct >= 92 ? 'fail' : ($usedPct >= 85 ? 'warn' : 'ok');
                    $out[] = self::item('memory', 'Память (занято, оценка)', 'os', $st, [
                        'percent' => $usedPct,
                        'value' => $usedPct,
                        'unit' => '%',
                        'hint' => $st !== 'ok' ? 'Проверьте swap и процессы' : null,
                    ]);
                }
            }
        }
        return $out;
    }

    private static function checksHttpIntegrations(array $config, array $cfg): array {
        $timeoutSec = $cfg['httpTimeout'];
        $slowMs = (int)$cfg['slowHttpWarnMs'];
        $out = [];
        $slowHint = 'Долгий ответ внешнего URL — интерфейс и фоновые сценарии могут казаться «зависшими».';
        foreach (['api', 'frontend', 'mobile', 'asterisk', 'internal', 'kamailio'] as $k) {
            if (!empty($config['api'][$k])) {
                $out[] = self::httpProbe('http_api_' . $k, 'HTTP: api.' . $k, 'integrations', $config['api'][$k], $timeoutSec, $slowMs, $slowHint);
            }
        }
        $mqttAgent = ($config['backends']['mqtt'] ?? [])['agent'] ?? null;
        if (!empty($mqttAgent)) {
            $out[] = self::httpProbe('mqtt_agent', 'HTTP: MQTT agent', 'integrations', (string)$mqttAgent, $timeoutSec, $slowMs, $slowHint);
        }
        return $out;
    }

    private static function checksDvrHttp(array $config, array $cfg): array {
        $servers = (($config['backends']['dvr'] ?? [])['servers'] ?? null);
        if (!is_array($servers) || !$servers) {
            return [];
        }
        $timeoutSec = $cfg['httpTimeout'];
        $slowMs = (int)$cfg['slowHttpWarnMs'];
        $explain = 'В RBT очередь autoconfigure (cron minutely, backend queue) для камер/домофонов ждёт ответы медиасервера. Если запросы идут по 30–60 с, таблица tasks_changes растёт: родительский процесс в wait() не разбирает следующие 25 задач, пока не завершатся дочерние PHP (см. core_running_processes).';
        $out = [];
        foreach ($servers as $i => $srv) {
            if (!is_array($srv)) {
                continue;
            }
            $url = $srv['url'] ?? '';
            if (!is_string($url) || $url === '') {
                continue;
            }
            $title = isset($srv['title']) ? (string)$srv['title'] : ('сервер #' . (int)$i);
            $out[] = self::httpProbe(
                'dvr_http_' . (int)$i,
                'Медиасервер (DVR): ' . $title,
                'integrations',
                $url,
                $timeoutSec,
                $slowMs,
                $explain
            );
        }
        return $out;
    }

    private static function checksClickhouseDeep(array $config, array $cfg): array {
        $ch = $config['clickhouse'] ?? [];
        if (empty($ch['host'])) {
            return [];
        }
        $host = $ch['host'];
        $port = (string)($ch['port'] ?? '8123');
        $user = $ch['username'] ?? 'default';
        $pass = $ch['password'] ?? '';
        $database = $ch['database'] ?? 'default';
        $q = rawurlencode('SELECT 1');
        $url = 'http://' . $host . ':' . $port . '/?user=' . rawurlencode($user) . '&database=' . rawurlencode($database) . '&query=' . $q;
        if ($pass !== '') {
            $url .= '&password=' . rawurlencode($pass);
        }
        $timeoutSec = (int)$cfg['httpTimeout'];
        $slowMs = (int)$cfg['clickhouseSlowMs'];
        $t0 = microtime(true);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeoutSec)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($curl);
        $errNo = curl_errno($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $explainBase = 'В проекте к ClickHouse ходят analytics, plog, tt и др. (см. utils/clickhouse.php, таймаут curl часто 5 с). «Подвисание» — долгие SELECT/INSERT, диск, merges; в UI это похоже на вечную загрузку отчётов.';
        if ($errNo === CURLE_OPERATION_TIMEDOUT) {
            return [self::item('clickhouse_http', 'ClickHouse: отклик (SELECT 1)', 'integrations', 'fail', [
                'latencyMs' => $ms,
                'value' => 'таймаут',
                'hint' => 'HTTP-запрос не уложился в таймаут — сервер перегружен или недоступен.',
                'explain' => $explainBase,
                'details' => ['curlErrno' => $errNo, 'curlError' => $err],
            ])];
        }
        if ($code >= 200 && $code < 400) {
            $st = 'ok';
            $hint = null;
            $explain = null;
            if ($ms >= $slowMs) {
                $st = 'warn';
                $hint = 'Ответ есть, но медленный — при пиках нагрузки запросы могут «висеть» дольше таймаута клиента.';
                $explain = $explainBase;
            }
            return [self::item('clickhouse_http', 'ClickHouse: отклик (SELECT 1)', 'integrations', $st, [
                'latencyMs' => $ms,
                'value' => $ms . ' мс, HTTP ' . $code,
                'hint' => $hint,
                'explain' => $explain,
            ])];
        }
        if ($code >= 400) {
            return [self::item('clickhouse_http', 'ClickHouse: отклик (SELECT 1)', 'integrations', 'fail', [
                'latencyMs' => $ms,
                'value' => 'HTTP ' . $code,
                'hint' => 'Ошибка HTTP — проверьте user/password, сеть и логи ClickHouse.',
                'explain' => $explainBase,
            ])];
        }
        return [self::item('clickhouse_http', 'ClickHouse: отклик (SELECT 1)', 'integrations', 'fail', [
            'latencyMs' => $ms,
            'value' => $err ?: 'нет ответа',
            'hint' => 'Нет корректного HTTP-ответа — сеть, firewall или CH не слушает порт.',
            'explain' => $explainBase,
            'details' => ['curlErrno' => $errNo],
        ])];
    }

    private static function checksZabbix(array $config, int $timeoutSec): array {
        $mon = $config['backends']['monitoring'] ?? [];
        if (($mon['backend'] ?? '') !== 'zabbix' || empty($mon['zbx_api_url']) || empty($mon['zbx_token'])) {
            return [self::item('zabbix_skip', 'Zabbix API', 'integrations', 'skip', [
                'hint' => 'Мониторинг не zabbix или нет URL/токена',
            ])];
        }
        $url = $mon['zbx_api_url'];
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'apiinfo.version',
            'params' => [],
            'id' => 1,
        ]);
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $mon['zbx_token'],
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $ok = $code >= 200 && $code < 300 && $res && str_contains((string)$res, 'result');
        return [self::item('zabbix_api', 'Zabbix API', 'integrations', $ok ? 'ok' : 'fail', [
            'latencyMs' => $ms,
            'value' => $ok ? 'OK' : ('HTTP ' . $code),
            'hint' => $ok ? null : 'Проверьте zbx_api_url и токен',
        ])];
    }

    private static function checksSslCertificates(array $config, array $cfg): array {
        $hosts = self::collectSslTargets($config, $cfg['sslExtraHosts'] ?? []);
        $out = [];
        $seen = [];
        foreach ($hosts as $h) {
            $key = $h['host'] . ':' . $h['port'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = self::probeTlsCertificate($h['host'], $h['port'], $h['label'], !empty($cfg['sslVerifyStrict']));
        }
        if (!$out) {
            $out[] = self::item('ssl_none', 'SSL/TLS: нет HTTPS-целей', 'ssl', 'skip', [
                'hint' => 'Добавьте URL в config или diagnostics.sslExtraHosts',
            ]);
        }
        return $out;
    }

    private static function collectSslTargets(array $config, array $extraHosts): array {
        $targets = [];
        $addUrl = static function (string $label, ?string $url) use (&$targets): void {
            if (!$url || !is_string($url)) {
                return;
            }
            $p = @parse_url($url);
            if (!$p || empty($p['scheme']) || strtolower($p['scheme']) !== 'https' || empty($p['host'])) {
                return;
            }
            $port = isset($p['port']) ? (int)$p['port'] : 443;
            $targets[] = ['host' => $p['host'], 'port' => $port, 'label' => $label];
        };
        foreach (['api', 'frontend', 'mobile'] as $k) {
            $addUrl('api.' . $k, $config['api'][$k] ?? null);
        }
        foreach ($config['backends']['dvr']['servers'] ?? [] as $i => $srv) {
            $addUrl('dvr.' . $i, $srv['url'] ?? null);
        }
        $addUrl('zabbix', $config['backends']['monitoring']['zbx_api_url'] ?? null);
        $addUrl('isdn', $config['backends']['isdn']['api_host'] ?? null);
        foreach ($config['syslog_servers'] ?? [] as $vendor => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $j => $entry) {
                if (is_string($entry) && self::startsWith($entry, 'https://')) {
                    $addUrl('syslog.' . $vendor . '.' . $j, $entry);
                }
            }
        }
        foreach ($extraHosts as $row) {
            if (is_string($row)) {
                $targets[] = ['host' => $row, 'port' => 443, 'label' => 'extra:' . $row];
            } elseif (is_array($row) && !empty($row['host'])) {
                $targets[] = [
                    'host' => (string)$row['host'],
                    'port' => (int)($row['port'] ?? 443),
                    'label' => (string)($row['label'] ?? 'extra'),
                ];
            }
        }
        return $targets;
    }

    private static function probeTlsCertificate(string $host, int $port, string $label, bool $strictVerify): array {
        $id = 'ssl_' . md5($label . $host . $port);
        $t0 = microtime(true);
        $tryConnect = static function (bool $verify) use ($host, $port): array {
            $ctx = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => $verify,
                    'verify_peer_name' => $verify,
                    'allow_self_signed' => !$verify,
                    'peer_name' => $host,
                    'SNI_enabled' => true,
                ],
            ]);
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                12,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            $params = $socket ? stream_context_get_params($socket) : [];
            if (is_resource($socket)) {
                fclose($socket);
            }
            return ['ok' => (bool)$socket, 'errno' => $errno, 'errstr' => $errstr, 'params' => $params];
        };
        $verified = false;
        $first = $tryConnect($strictVerify);
        if ($first['ok']) {
            $verified = true;
        } else {
            $first = $tryConnect(false);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (!$first['ok']) {
            return self::item($id, 'TLS: ' . $label . ' (' . $host . ':' . $port . ')', 'ssl', 'fail', [
                'latencyMs' => $ms,
                'hint' => 'Нет TLS или порт закрыт',
                'details' => ['errno' => $first['errno'], 'error' => $first['errstr']],
            ]);
        }
        $cert = $first['params']['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return self::item($id, 'TLS: ' . $label, 'ssl', 'warn', [
                'latencyMs' => $ms,
                'hint' => 'Сертификат не получен',
            ]);
        }
        $parsed = openssl_x509_parse($cert);
        if (!$parsed) {
            return self::item($id, 'TLS: ' . $label, 'ssl', 'warn', [
                'latencyMs' => $ms,
                'hint' => 'Не удалось разобрать сертификат',
            ]);
        }
        $to = $parsed['validTo_time_t'] ?? 0;
        $days = (int)floor(($to - time()) / 86400);
        $cn = $parsed['subject']['CN'] ?? '';
        $st = 'ok';
        $hint = null;
        if ($days < 0) {
            $st = 'fail';
            $hint = 'Сертификат истёк';
        } elseif ($days < 14) {
            $st = 'fail';
            $hint = 'Мало дней до окончания';
        } elseif ($days < 30) {
            $st = 'warn';
            $hint = 'Менее 30 дней до окончания';
        }
        if (!$verified) {
            if ($st === 'ok') {
                $st = 'warn';
            }
            $hint = ($hint ? $hint . ' ' : '') . 'Цепочка не прошла строгую проверку.';
        }
        return self::item($id, 'TLS: ' . $label . ' (' . $host . ')', 'ssl', $st, [
            'latencyMs' => $ms,
            'value' => $days,
            'unit' => 'дн.',
            'hint' => $hint,
            'details' => [
                'validTo' => isset($parsed['validTo']) ? (string)$parsed['validTo'] : '',
                'subject' => is_string($cn) ? $cn : '',
                'verifiedChain' => $verified,
            ],
        ]);
    }

    private static function httpProbe(string $id, string $title, string $group, string $url, int $timeoutSec, ?int $slowWarnMs = null, ?string $slowExplain = null): array {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeoutSec)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $errNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($errNo === CURLE_OPERATION_TIMEDOUT) {
            return self::item($id, $title, $group, 'fail', [
                'latencyMs' => $ms,
                'value' => 'таймаут',
                'hint' => 'Сервер не ответил в срок — похоже на «подвисание» или сетевую проблему.',
                'explain' => $slowExplain,
                'details' => ['curlErrno' => $errNo],
            ]);
        }
        if ($code >= 200 && $code < 400) {
            $st = 'ok';
            $hint = null;
            $explain = null;
            if ($slowWarnMs !== null && $ms >= $slowWarnMs) {
                $st = 'warn';
                $hint = 'Очень долгий ответ (' . $ms . ' мс) при обычной проверке — под нагрузкой будет ещё хуже.';
                $explain = $slowExplain;
            }
            return self::item($id, $title, $group, $st, [
                'latencyMs' => $ms,
                'value' => 'HTTP ' . $code,
                'hint' => $hint,
                'explain' => $explain,
            ]);
        }
        if ($code >= 400 && $code < 500) {
            return self::item($id, $title, $group, 'warn', [
                'latencyMs' => $ms,
                'value' => 'HTTP ' . $code,
                'hint' => 'Клиентская ошибка или нужна авторизация',
            ]);
        }
        if ($code >= 500) {
            return self::item($id, $title, $group, 'fail', [
                'latencyMs' => $ms,
                'value' => 'HTTP ' . $code,
                'hint' => 'Ошибка на стороне сервиса',
            ]);
        }
        return self::item($id, $title, $group, 'fail', [
            'latencyMs' => $ms,
            'value' => $err ?: 'no response',
            'hint' => 'Проверьте DNS и firewall',
            'explain' => $slowExplain,
        ]);
    }

    private static function checksFfmpeg(array $cfg): array {
        if (empty($cfg['allowExec'])) {
            return [self::item('ffmpeg', 'FFmpeg', 'media', 'skip', ['hint' => 'Включите diagnostics.allowExec'])];
        }
        $bin = getenv('RBT_FFMPEG_PATH');
        if (!is_string($bin) || $bin === '') {
            $bin = 'ffmpeg';
        }
        $out = [];
        $code = 0;
        @exec(escapeshellcmd($bin) . ' -version 2>&1', $out, $code);
        $line = $out[0] ?? '';
        return [self::item('ffmpeg', 'FFmpeg', 'media', $code === 0 ? 'ok' : 'fail', [
            'value' => $code === 0 ? 'OK' : 'нет',
            'details' => ['versionLine' => substr($line, 0, 120)],
            'hint' => $code === 0 ? null : 'Установите ffmpeg',
        ])];
    }

    private static function checksQueue(array $params, array $cfg): array {
        $db = $params['_db'] ?? null;
        if (!$db) {
            return [];
        }
        try {
            $out = [];
            $row = $db->get('select count(*) as c from tasks_changes', [], false, ['singlify']);
            $n = (int)($row['c'] ?? 0);
            $byType = $db->get(
                'select object_type, count(*)::int as c from tasks_changes group by object_type order by c desc',
                [],
                false,
                []
            );
            $parts = [];
            if (is_array($byType)) {
                foreach ($byType as $br) {
                    if (isset($br['object_type'], $br['c'])) {
                        $parts[] = $br['object_type'] . ':' . $br['c'];
                    }
                }
            }
            $breakdown = $parts ? implode(', ', $parts) : '—';
            $stTasks = 'ok';
            if ($n > 5000) {
                $stTasks = 'fail';
            } elseif ($n > 500) {
                $stTasks = 'warn';
            }
            $out[] = self::item('queue_tasks_changes', 'Очередь tasks_changes (ожидают autoconfigure)', 'background', $stTasks, [
                'value' => $n . ($breakdown !== '—' ? ' — ' . $breakdown : ''),
                'unit' => 'записей',
                'hint' => $n > 500
                    ? 'Много накопилось — cron minutely снимает по 25 domophone + 25 camera за проход; при медленном медиасервере хвост не успевает разбираться.'
                    : null,
                'explain' => 'Таблица без времени создания: мы видим только «сколько сейчас ждёт». Рост при пустых ошибках в UI часто связан с долгими HTTP к медиасерверу и блокировкой в core_running_processes.',
                'details' => ['byObjectType' => $breakdown],
            ]);

            $activeRow = $db->get(
                'select count(*)::int as c, min(start) as oldest_start from core_running_processes where (done is null or done = 0)',
                [],
                false,
                ['singlify']
            );
            if (!is_array($activeRow)) {
                $activeRow = ['c' => 0, 'oldest_start' => null];
            }
            $active = (int)($activeRow['c'] ?? 0);
            $oldestStart = isset($activeRow['oldest_start']) ? (int)$activeRow['oldest_start'] : 0;
            $ageSec = ($oldestStart > 0) ? max(0, time() - $oldestStart) : 0;
            $warnSec = (int)$cfg['bgProcessWarnSec'];
            $failSec = (int)$cfg['bgProcessFailSec'];
            $stBg = 'ok';
            if ($active > 0 && $ageSec >= $failSec) {
                $stBg = 'fail';
            } elseif ($active > 0 && $ageSec >= $warnSec) {
                $stBg = 'warn';
            } elseif ($active > 15) {
                $stBg = 'warn';
            }
            $sample = $db->get(
                'select process, pid, start from core_running_processes where (done is null or done = 0) order by start asc nulls last limit 8',
                [],
                false,
                []
            );
            if (!is_array($sample)) {
                $sample = [];
            }
            $out[] = self::item('queue_core_running', 'Фоновые процессы (core_running_processes)', 'background', $stBg, [
                'value' => $active ? ($active . ' активн., старейш. ~' . $ageSec . ' с') : 'нет активных',
                'hint' => $active > 0 && $ageSec >= $warnSec
                    ? 'Родительский cron ждёт завершения детей (см. queue wait()). Пока PID «висит», новая порция tasks_changes не обрабатывается.'
                    : null,
                'explain' => 'Это прямой индикатор «заморозки» очереди: CLI-задачи autoconfigure устройств пишутся сюда из cli.php; при минутном ответе DVR вы увидите рост age и tasks_changes одновременно.',
                'details' => ['sample' => is_array($sample) ? $sample : []],
            ]);

            if ($n > 80 && ($ageSec >= 60 || $active >= 8)) {
                $out[] = self::item('insight_queue_dvr', 'Вероятная причина заторов', 'insights', 'warn', [
                    'value' => 'см. выше',
                    'hint' => 'Сочетание большой tasks_changes и долгоживущих core_running_processes типично при медленном/недоступном медиасервере или зависании PHP-воркеров.',
                    'explain' => 'Проверьте строки «Медиасервер (DVR)» и время ответа (мс). Дальше: логи nginx/php-fpm, сетевой пинг до DVR, нагрузку на хост с потоками.',
                ]);
            }
            if ($n > 200 && $active === 0) {
                $out[] = self::item('insight_queue_no_workers', 'Очередь есть, активных воркеров не видно', 'insights', 'warn', [
                    'value' => '—',
                    'hint' => 'Возможно, сейчас между запусками minutely, или cron не отрабатывает, или процессы под другим пользователем.',
                    'explain' => 'Убедитесь, что crontab содержит RBT minutely и что нет ошибок в syslog при запуске cli.php.',
                ]);
            }

            return $out;
        } catch (\Throwable $e) {
            return [self::item('queue_tasks_changes', 'Очередь / фон (ошибка SQL)', 'background', 'skip', [
                'details' => ['error' => $e->getMessage()],
            ])];
        }
    }

    private static function checksCronHint(array $cfg): array {
        if (empty($cfg['allowExec'])) {
            return [self::item('cron', 'Cron RBT', 'background', 'skip', [
                'hint' => 'Включите allowExec',
            ])];
        }
        $out = [];
        @exec('crontab -l 2>/dev/null', $out, $code);
        $text = implode("\n", $out);
        $ok = str_contains($text, 'RBT crons start');
        return [self::item('cron_rbt', 'Cron: секция RBT', 'background', $ok ? 'ok' : 'warn', [
            'value' => $ok ? 'найдена' : 'не найдена',
            'hint' => $ok ? null : 'Установите crontab (installCrontabs)',
        ])];
    }

    private static function checksSystemd(array $config, array $cfg): array {
        $services = ['nginx', 'php8.3-fpm', 'postgresql', 'redis-server', 'clickhouse-server'];
        if (!empty($config['backends']['mqtt']['mqtt'])) {
            $services[] = 'mosquitto';
        }
        $out = [];
        foreach ($services as $svc) {
            $lines = [];
            @exec('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null', $lines, $code);
            $state = trim($lines[0] ?? '');
            $active = $state === 'active';
            $out[] = self::item('systemd_' . preg_replace('/\W/', '_', $svc), 'systemd: ' . $svc, 'systemd', $active ? 'ok' : 'warn', [
                'value' => $state ?: 'unknown',
                'hint' => $active ? null : ('systemctl status ' . $svc),
            ]);
        }
        return $out;
    }

    private static function item(string $id, string $title, string $group, string $status, array $extra = []): array {
        return array_merge([
            'id' => $id,
            'title' => $title,
            'group' => $group,
            'status' => $status,
        ], $extra);
    }
}

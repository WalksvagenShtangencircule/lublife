#!/usr/bin/env php
<?php
/**
 * AMI → DTMF → открытие URL для виртуальной QR-панели (Redis vdom_dtmf_ctx:*).
 * Конфиг: config.json → vdom_dtmf.ami (host, port, username, secret)
 * Лог успеха: server/logs/vdom_dtmf_door.log
 * Отладка:   server/logs/vdom_ami_listener.log (подробности только при vdom_dtmf.ami.verbose_log)
 */
declare(strict_types=1);

chdir(__DIR__ . '/..');

set_time_limit(0);
ini_set('max_execution_time', '0');

function vdom_log(string $file, string $line): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . $file, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Подробный лог в vdom_ami_listener.log (startup, каждый DTMF, reconnect). */
function vdom_verbose_log(array $config): bool {
    $ami = ($config['vdom_dtmf']['ami'] ?? []);
    return !empty($ami['verbose_log']);
}

function vdom_log_debug(array $config, string $file, string $line): void {
    if (vdom_verbose_log($config)) {
        vdom_log($file, $line);
    }
}

/**
 * Один процесс на хост: защита от второго запуска (cron + systemd, лишний @reboot и т.п.).
 *
 * @return resource|false
 */
function vdom_acquire_singleton_lock() {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/.vdom_ami_dtmf_listener.lock';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        fwrite(STDERR, date('c') . " vdom_ami: не удалось открыть lock-файл $path\n");
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        fwrite(STDERR, date('c') . " vdom_ami: слушатель уже запущен (см. flock $path), повторный запуск пропущен.\n");
        exit(0);
    }
    ftruncate($fh, 0);
    fwrite($fh, (string)getmypid() . "\n");
    fflush($fh);
    return $fh;
}

function vdom_load_config(): array {
    $path = __DIR__ . '/../config/config.json';
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        fwrite(STDERR, "vdom_ami: нет config.json\n");
        exit(1);
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function vdom_http_trigger(string $url): bool {
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 6, 'method' => 'GET', 'ignore_errors' => true],
        'https' => ['timeout' => 6, 'method' => 'GET', 'ignore_errors' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false;
}

function vdom_parse_ami_block(string $block): array {
    $vars = [];
    foreach (preg_split("/\r?\n/", trim($block)) as $line) {
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $vars[trim($k)] = trim($v);
    }
    return $vars;
}

function vdom_ami_write($sock, array $fields): void {
    $s = '';
    foreach ($fields as $k => $v) {
        $s .= "$k: $v\r\n";
    }
    $s .= "\r\n";
    fwrite($sock, $s);
}

/** Не запускать демон при require из cli.php — только при прямом вызове этого файла. */
if (PHP_SAPI !== 'cli' || !isset($_SERVER['argv'][0]) || realpath((string) $_SERVER['argv'][0]) !== realpath(__FILE__)) {
    return;
}

$config = vdom_load_config();
$vd = $config['vdom_dtmf'] ?? [];
$ami = $vd['ami'] ?? [];
$host = (string)($ami['host'] ?? '127.0.0.1');
$port = (int)($ami['port'] ?? 5038);
$user = (string)($ami['username'] ?? 'rbtdom');
$secret = (string)($ami['secret'] ?? '');

if ($secret === '') {
    fwrite(STDERR, "vdom_ami: пустой vdom_dtmf.ami.secret\n");
    exit(1);
}

$lockFh = vdom_acquire_singleton_lock();
if ($lockFh === false) {
    exit(1);
}

$rch = $config['redis']['host'] ?? '127.0.0.1';
$rpo = (int)($config['redis']['port'] ?? 6379);
$rpa = $config['redis']['password'] ?? null;

$redis = new Redis();
$redis->connect($rch, $rpo, 2.0);
if ($rpa !== null && $rpa !== '') {
    $redis->auth((string)$rpa);
}

date_default_timezone_set($config['mobile']['time_zone'] ?? 'Europe/Moscow');

vdom_log_debug($config, 'vdom_ami_listener.log', date('c') . "\tstartup\tpid=" . getmypid());
fwrite(STDERR, date('c') . " vdom_ami: слушатель AMI/DTMF запущен pid=" . getmypid() . " verbose_log=" . (vdom_verbose_log($config) ? '1' : '0') . "\n");

while (true) {
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$sock) {
        vdom_log('vdom_ami_listener.log', date('c') . "\tami_connect_fail\t$errstr");
        sleep(5);
        continue;
    }
    stream_set_timeout($sock, 86400);

    vdom_ami_write($sock, [
        'Action' => 'Login',
        'Username' => $user,
        'Secret' => $secret,
    ]);

    $loggedIn = false;
    $buf = '';

    while (!feof($sock)) {
        $chunk = fread($sock, 8192);
        if ($chunk === false || $chunk === '') {
            if (feof($sock)) {
                break;
            }
            usleep(50000);
            continue;
        }
        $buf .= $chunk;
        while (($p = strpos($buf, "\r\n\r\n")) !== false) {
            $packet = substr($buf, 0, $p);
            $buf = substr($buf, $p + 4);
            $vars = vdom_parse_ami_block($packet);
            if ($vars === []) {
                continue;
            }

            if (($vars['Response'] ?? '') === 'Success'
                && in_array($vars['Message'] ?? '', ['Authentication accepted', 'Successfully logged in'], true)
            ) {
                $loggedIn = true;
                vdom_ami_write($sock, ['Action' => 'Events', 'EventMask' => 'on',]);
                vdom_log_debug($config, 'vdom_ami_listener.log', date('c') . "\tami_login_ok");
                continue;
            }

            if (($vars['Response'] ?? '') === 'Error') {
                vdom_log('vdom_ami_listener.log', date('c') . "\tami_response_error\t" . json_encode($vars, JSON_UNESCAPED_UNICODE));
                fclose($sock);
                sleep(5);
                continue 2;
            }

            if (!$loggedIn) {
                continue;
            }

            $ev = $vars['Event'] ?? '';
            if ($ev !== 'DTMF' && $ev !== 'DTMFEnd') {
                continue;
            }

            $digit = (string)($vars['Digit'] ?? '');
            if ($digit === '') {
                continue;
            }
            $digit = $digit[0];

            $linked = trim((string)($vars['Linkedid'] ?? ''));
            if ($linked === '') {
                $linked = trim((string)($vars['Uniqueid'] ?? ''));
            }

            vdom_log_debug($config, 'vdom_ami_listener.log', date('c') . "\tdtmf_event\tdigit=$digit\tlinkedid=$linked\tchan=" . ($vars['Channel'] ?? ''));

            $ctxKey = null;
            if ($linked !== '') {
                $k = 'vdom_dtmf_ctx:' . $linked;
                if ($redis->exists($k)) {
                    $ctxKey = $k;
                }
            }

            if ($ctxKey === null) {
                $keys = $redis->keys('vdom_dtmf_ctx:*');
                if (is_array($keys) && count($keys) === 1) {
                    $ctxKey = $keys[0];
                    vdom_log_debug($config, 'vdom_ami_listener.log', date('c') . "\tdtmf_fallback_single_ctx\t" . $ctxKey);
                }
            }

            if ($ctxKey === null) {
                continue;
            }

            $raw = $redis->get($ctxKey);
            if (!$raw) {
                continue;
            }
            $ctx = json_decode((string)$raw, true);
            if (!is_array($ctx)) {
                continue;
            }

            $expected = (string)($ctx['dtmf'] ?? '1');
            $bufKey = 'vdom_dtmf_buf:' . $ctxKey;

            $prev = (string)$redis->get($bufKey);
            $next = $prev . $digit;

            if ($expected !== '' && !str_starts_with($expected, $next)) {
                $next = $digit;
                if (!str_starts_with($expected, $next)) {
                    $redis->del($bufKey);
                    continue;
                }
            }

            $redis->setex($bufKey, 120, $next);

            if ($next !== $expected) {
                continue;
            }

            $doorUrl = (string)($ctx['doorUrl'] ?? '');
            if ($doorUrl === '') {
                vdom_log('vdom_ami_listener.log', date('c') . "\tdtmf_match_no_url\tdomophone=" . ($ctx['domophoneId'] ?? ''));
                $redis->del($bufKey);
                $redis->del($ctxKey);
                continue;
            }

            $ok = vdom_http_trigger($doorUrl);
            $domophoneId = (int)($ctx['domophoneId'] ?? 0);
            $flatId = (int)($ctx['flatId'] ?? 0);
            $flatNum = (int)($ctx['flatNumber'] ?? 0);

            $line = sprintf(
                "%s\tdtmf\t%d\t%d\t%d\tdoor0\t%s",
                date('c'),
                $domophoneId,
                $flatId,
                $flatNum,
                $ok ? 'ok' : 'fail'
            );
            vdom_log('vdom_dtmf_door.log', $line);

            $redis->del($bufKey);
            $redis->del($ctxKey);

            vdom_log('vdom_ami_listener.log', date('c') . "\tdtmf_open_done\t" . ($ok ? 'ok' : 'fail') . "\t" . $line);
        }
    }

    fclose($sock);
    vdom_log_debug($config, 'vdom_ami_listener.log', date('c') . "\tami_socket_closed_resleep");
    sleep(3);
}

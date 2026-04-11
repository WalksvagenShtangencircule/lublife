#!/usr/bin/env php
<?php

    /**
     * Слушатель AMI Asterisk: DTMF во время звонка с виртуальной панели → HTTP doorOpeningUrls.
     *
     * Требования:
     *   1) В manager.conf пользователь AMI с read на события DTMF (часто хватает all).
     *   2) В config.json блок vdom_dtmf (см. пример ниже).
     *   3) extensions.lua уже пишет redis vdom_call_ctx:<linkedid> перед Dial с виртуальной панелью.
     *
     * Запуск (systemd / screen):
     *   php /opt/rbt/server/cli/vdom_ami_dtmf_listener.php
     *
     * Пример config.json (без коммита секретов в git):
     *   "vdom_dtmf": {
     *       "open_api_key": "случайная-длинная-строка",
     *       "ami": { "host": "127.0.0.1", "port": 5038, "username": "rbtdom", "secret": "…" }
     *   }
     *
     * Ручной тест без AMI (подставьте свои id и секрет):
     *   curl -sS -X POST 'http://127.0.0.1/asterisk/extensions/vdomDtmfDoor' \
     *     -H 'Content-Type: application/json' \
     *     -d '{"auth":"…","domophoneId":1,"flatId":2,"digit":"1"}'
     */

    declare(strict_types=1);

    $configPath = dirname(__DIR__) . "/config/config.json";
    $raw = @file_get_contents($configPath);
    $config = $raw ? json_decode($raw, true) : null;
    if (!is_array($config)) {
        fwrite(STDERR, "Не удалось прочитать config.json\n");
        exit(1);
    }

    $ami = @$config["vdom_dtmf"]["ami"];
    $apiKey = @$config["vdom_dtmf"]["open_api_key"];
    $asteriskApi = @$config["api"]["asterisk"];
    if (!is_array($ami) || !is_string($apiKey) || $apiKey === "" || !is_string($asteriskApi) || $asteriskApi === "") {
        fwrite(STDERR, "Задайте vdom_dtmf.ami и vdom_dtmf.open_api_key и api.asterisk в config.json — выход.\n");
        exit(0);
    }

    $host = (string)($ami["host"] ?? "127.0.0.1");
    $port = (int)($ami["port"] ?? 5038);
    $user = (string)($ami["username"] ?? $ami["user"] ?? "");
    $secret = (string)($ami["secret"] ?? "");
    if ($user === "" || $secret === "") {
        fwrite(STDERR, "vdom_dtmf.ami: нужны username и secret\n");
        exit(1);
    }

    $redisCfg = @$config["redis"];
    if (!is_array($redisCfg)) {
        fwrite(STDERR, "Нет блока redis в config\n");
        exit(1);
    }

    $postUrl = rtrim($asteriskApi, "/") . "/extensions/vdomDtmfDoor";
    $logUnmatched = !isset($config["vdom_dtmf"]["log_unmatched_dtmf"]) || $config["vdom_dtmf"]["log_unmatched_dtmf"] !== false;

    $redis = new Redis();
    $redis->connect((string)$redisCfg["host"], (int)($redisCfg["port"] ?? 6379));
    if (!empty($redisCfg["password"])) {
        $redis->auth((string)$redisCfg["password"]);
    }

    /**
     * @return resource|false
     */
    function vdom_ami_connect(string $host, int $port) {
        $errno = 0;
        $errstr = "";
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10.0);
        if ($fp === false) {
            fwrite(STDERR, "AMI connect failed: {$errstr} ({$errno})\n");

            return false;
        }
        stream_set_timeout($fp, 3600);

        return $fp;
    }

    /**
     * Читает блоки AMI до ответа Action (Response: Success/Error), пропуская события вроде FullyBooted.
     *
     * @param resource $fp
     */
    function vdom_ami_wait_response($fp): ?array {
        while (true) {
            $msg = vdom_ami_read_block($fp);
            if ($msg === null) {
                return null;
            }
            if ($msg === []) {
                continue;
            }
            if (isset($msg["Response"])) {
                return $msg;
            }
        }
    }

    /**
     * @param resource $fp
     */
    function vdom_ami_read_block($fp): ?array {
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === "") {
                break;
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            return feof($fp) ? null : [];
        }
        $ev = [];
        foreach ($lines as $line) {
            $p = strpos($line, ":");
            if ($p === false) {
                continue;
            }
            $k = trim(substr($line, 0, $p));
            $v = trim(substr($line, $p + 1));
            $ev[$k] = $v;
        }

        return $ev;
    }

    /**
     * @param resource $fp
     */
    function vdom_ami_write($fp, array $lines): void {
        $buf = implode("\r\n", $lines) . "\r\n\r\n";
        fwrite($fp, $buf);
    }

    /**
     * Ищет контекст звонка в Redis по полям AMI (разные ноги моста дают разные Linkedid/Uniqueid).
     *
     * @return array<string, mixed>|null
     */
    function vdom_lookup_call_ctx(Redis $redis, array $msg): ?array {
        $candidates = [];
        foreach (["Linkedid", "Uniqueid", "BridgeUniqueid"] as $f) {
            $v = isset($msg[$f]) ? trim((string)$msg[$f]) : "";
            if ($v !== "") {
                $candidates[] = $v;
            }
        }
        foreach ($candidates as $k) {
            $raw = $redis->get("vdom_call_ctx:" . $k);
            if ($raw !== false && $raw !== null && $raw !== "") {
                $ctx = json_decode((string)$raw, true);
                if (is_array($ctx)) {
                    return $ctx;
                }
            }
        }

        return null;
    }

    function vdom_post_door(string $url, string $auth, int $domophoneId, int $flatId, string $digit): void {
        $payload = json_encode([
            "auth" => $auth,
            "domophoneId" => $domophoneId,
            "flatId" => $flatId,
            "digit" => $digit,
        ], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/json\r\n",
                "content" => $payload,
                "timeout" => 12,
                "ignore_errors" => true,
            ],
        ]);
        @file_get_contents($url, false, $ctx);
    }

    while (true) {
        $fp = vdom_ami_connect($host, $port);
        if ($fp === false) {
            sleep(5);
            continue;
        }

        vdom_ami_write($fp, [
            "Action: Login",
            "Username: {$user}",
            "Secret: {$secret}",
        ]);

        $loginAck = vdom_ami_wait_response($fp);
        if ($loginAck === null || ($loginAck["Response"] ?? "") !== "Success") {
            fwrite(STDERR, "AMI login error: " . ($loginAck["Message"] ?? json_encode($loginAck, JSON_UNESCAPED_UNICODE)) . "\n");
            fclose($fp);
            sleep(5);
            continue;
        }

        vdom_ami_write($fp, [
            "Action: Events",
            "EventMask: on",
        ]);
        $evAck = vdom_ami_wait_response($fp);
        if ($evAck === null || ($evAck["Response"] ?? "") !== "Success") {
            fwrite(STDERR, "AMI Events: " . json_encode($evAck, JSON_UNESCAPED_UNICODE) . "\n");
            fclose($fp);
            sleep(5);
            continue;
        }

        fwrite(STDERR, date("c") . " AMI vdom DTMF listener: подключено\n");

        while (!feof($fp)) {
            $msg = vdom_ami_read_block($fp);
            if ($msg === null) {
                break;
            }
            if ($msg === []) {
                continue;
            }

            $evName = $msg["Event"] ?? "";
            if ($evName !== "DTMF" && $evName !== "DTMFEnd") {
                continue;
            }

            $digit = trim((string)($msg["Digit"] ?? ""));
            if ($digit === "") {
                continue;
            }

            $ctx = vdom_lookup_call_ctx($redis, $msg);
            if ($ctx === null) {
                if ($logUnmatched) {
                    $ch = (string)($msg["Channel"] ?? "");
                    $lid = trim((string)($msg["Linkedid"] ?? ""));
                    $uid = trim((string)($msg["Uniqueid"] ?? ""));
                    $bid = trim((string)($msg["BridgeUniqueid"] ?? ""));
                    fwrite(STDERR, date("c") . " DTMF unmatched digit={$digit} channel={$ch} Linkedid={$lid} Uniqueid={$uid} BridgeUniqueid={$bid}\n");
                }

                continue;
            }

            $domophoneId = (int)($ctx["domophoneId"] ?? 0);
            $flatId = (int)($ctx["flatId"] ?? 0);
            if ($domophoneId <= 0 || $flatId <= 0) {
                continue;
            }

            $debKey = "vdom_dtmf_db:" . $domophoneId . ":" . $flatId . ":" . $digit;
            if (!$redis->setnx($debKey, "1")) {
                continue;
            }
            $redis->expire($debKey, 4);

            fwrite(STDERR, date("c") . " DTMF matched digit={$digit} domophone={$domophoneId} flat={$flatId}\n");
            vdom_post_door($postUrl, $apiKey, $domophoneId, $flatId, $digit);
        }

        fclose($fp);
        fwrite(STDERR, date("c") . " AMI: соединение потеряно, переподключение через 3 с\n");
        sleep(3);
    }

<?php

/**
 * Плановый прогон диагностики из cli.php --cron=… (кеш сводки, история, Telegram).
 * Настройка: diagnostics.scheduledRun в config.json.
 */
class DiagnosticsCron {

    public static function runIfScheduled(string $part, array $config, $redis): void {
        $sch = $config['diagnostics']['scheduledRun'] ?? null;
        if (!is_array($sch) || empty($sch['enabled'])) {
            return;
        }
        $want = isset($sch['cronPart']) ? trim((string)$sch['cronPart']) : 'hourly';
        if ($want === '' || $want !== $part) {
            return;
        }
        if (!in_array($want, ['minutely', '5min', 'hourly', 'daily', 'monthly'], true)) {
            error_log('DiagnosticsCron: неверный scheduledRun.cronPart в config.json');
            return;
        }
        if (!$redis) {
            return;
        }

        $lockKey = 'CRON:LOCK:diagnostics:' . $part;
        $pid = (int)$redis->get($lockKey);
        if ($pid > 0 && @file_exists("/proc/$pid")) {
            return;
        }
        if ($pid > 0) {
            try {
                $redis->del($lockKey);
            } catch (\Throwable $e) {
                error_log('DiagnosticsCron lock del: ' . $e->getMessage());
            }
        }

        try {
            $redis->set($lockKey, (string)getmypid());
        } catch (\Throwable $e) {
            error_log('DiagnosticsCron lock set: ' . $e->getMessage());
            return;
        }

        try {
            self::execute($config, $redis, $sch);
        } catch (\Throwable $e) {
            error_log('DiagnosticsCron: ' . $e->getMessage());
        } finally {
            try {
                $redis->del($lockKey);
            } catch (\Throwable $e) {
                error_log('DiagnosticsCron lock finally: ' . $e->getMessage());
            }
        }
    }

    private static function execute(array $config, $redis, array $sch): void {
        require_once __DIR__ . '/DiagnosticsService.php';
        require_once __DIR__ . '/DiagnosticsTelegramNotifier.php';

        $heavy = !empty($sch['heavy']);
        $params = [
            '_uid' => 0,
            '_login' => 'cron',
            '_redis' => $redis,
        ];

        $checks = DiagnosticsService::runAll($params, null, $heavy);
        $summary = DiagnosticsService::summarizeChecks($checks);
        $diagCfg = DiagnosticsService::diagConfig($config);

        $payload = [
            'checks' => $checks,
            'summary' => $summary,
        ];

        try {
            $redis->setex(
                DiagnosticsService::CACHE_KEY_SUMMARY,
                $diagCfg['summaryCacheTtl'],
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
            DiagnosticsService::pushHistory($redis, $diagCfg, $summary);
        } catch (\Throwable $e) {
            error_log('diagnostics cron cache: ' . $e->getMessage());
        }

        DiagnosticsTelegramNotifier::maybeNotifyAfterRun($redis, $checks, $summary, $config);
    }

    /** Ручной запуск: php cli.php --diagnostics-run [--heavy] */
    public static function runOnceManual(array $config, $redis, bool $heavy): void {
        self::execute($config, $redis, ['heavy' => $heavy]);
    }

}

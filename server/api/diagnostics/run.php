<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;
        use DiagnosticsTelegramNotifier;

        class run extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';
                global $config;

                $group = isset($params['group']) ? trim((string)$params['group']) : null;
                if ($group === '') {
                    $group = null;
                }
                $heavy = !empty($params['heavy']);
                $checks = DiagnosticsService::runAll($params, $group, $heavy);
                $summary = DiagnosticsService::summarizeChecks($checks);
                $diagCfg = DiagnosticsService::diagConfig($config);

                $payload = [
                    'checks' => $checks,
                    'summary' => $summary,
                    'simulateActive' => !empty($config['diagnostics']['simulate']['enabled']),
                ];

                if (!empty($params['_redis'])) {
                    try {
                        $params['_redis']->setex(
                            DiagnosticsService::CACHE_KEY_SUMMARY,
                            $diagCfg['summaryCacheTtl'],
                            json_encode($payload, JSON_UNESCAPED_UNICODE)
                        );
                        DiagnosticsService::pushHistory($params['_redis'], $diagCfg, $summary);
                    } catch (\Throwable $e) {
                        error_log('diagnostics run cache: ' . $e->getMessage());
                    }
                }

                $sn = $params['skip_notify'] ?? null;
                $skipNotify = $sn === '1' || $sn === 1 || $sn === true;
                if (!$skipNotify) {
                    require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';
                    DiagnosticsTelegramNotifier::maybeNotifyAfterRun($params['_redis'] ?? null, $checks, $summary, $config);
                }

                return api::ANSWER($payload, 'diagnostics');
            }

            public static function index() {
                return [
                    'GET' => '#same(analytics,stats,GET)',
                ];
            }
        }
    }

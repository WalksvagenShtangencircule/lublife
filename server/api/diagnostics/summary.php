<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;

        class summary extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';
                global $config;

                $diagCfg = DiagnosticsService::diagConfig($config);

                if (!empty($params['_redis'])) {
                    try {
                        $raw = $params['_redis']->get(DiagnosticsService::CACHE_KEY_SUMMARY);
                        if (is_string($raw) && $raw !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                return api::ANSWER($decoded, 'diagnostics');
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('diagnostics summary cache: ' . $e->getMessage());
                    }
                }

                $checks = array_merge(
                    DiagnosticsService::runAll($params, 'config', false),
                    DiagnosticsService::runAll($params, 'datastores', false)
                );
                $summary = DiagnosticsService::summarizeChecks($checks);

                return api::ANSWER([
                    'checks' => $checks,
                    'summary' => $summary,
                    'partial' => true,
                ], 'diagnostics');
            }

            public static function index() {
                return [
                    'GET' => '#same(analytics,stats,GET)',
                ];
            }
        }
    }

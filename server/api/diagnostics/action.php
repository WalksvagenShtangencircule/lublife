<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;

        class action extends api {

            public static function POST($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';

                $action = $params['action'] ?? '';
                if (!is_string($action) || $action === '') {
                    return api::ANSWER(false, 'badRequest');
                }

                if ($action === 'clearFrontCache') {
                    clearCache($params['_uid'] ?? 0);
                    return api::ANSWER(['ok' => true, 'did' => 'clearFrontCache'], 'diagnosticsAction');
                }

                if ($action === 'bumpDiagnosticsCache' && !empty($params['_redis'])) {
                    try {
                        $params['_redis']->del(DiagnosticsService::CACHE_KEY_SUMMARY);
                    } catch (\Throwable $e) {
                        error_log('diagnostics bump: ' . $e->getMessage());
                    }
                    return api::ANSWER(['ok' => true, 'did' => 'bumpDiagnosticsCache'], 'diagnosticsAction');
                }

                return api::ANSWER(false, 'badRequest');
            }

            public static function index() {
                return [
                    'POST' => '#same(diagnostics,run,GET)',
                ];
            }
        }
    }

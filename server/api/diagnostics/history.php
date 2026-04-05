<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;

        class history extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';

                $points = [];
                if (!empty($params['_redis'])) {
                    try {
                        $raw = $params['_redis']->lRange(DiagnosticsService::CACHE_KEY_HISTORY, 0, 199);
                        if (is_array($raw)) {
                            foreach ($raw as $line) {
                                $j = json_decode($line, true);
                                if (is_array($j)) {
                                    $points[] = $j;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('diagnostics history: ' . $e->getMessage());
                    }
                }

                return api::ANSWER(['points' => $points], 'diagnosticsHistory');
            }

            public static function index() {
                return [
                    'GET' => '#same(analytics,stats,GET)',
                ];
            }
        }
    }

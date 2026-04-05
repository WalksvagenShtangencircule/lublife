<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;

        class check extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';

                $id = isset($params['_id']) ? trim((string)$params['_id']) : '';
                if ($id === '') {
                    return api::ANSWER(false, 'badRequest');
                }

                $checks = DiagnosticsService::runOne($params, $id);

                return api::ANSWER(['checks' => $checks], 'diagnostics');
            }

            public static function index() {
                return [
                    'GET' => '#same(analytics,stats,GET)',
                ];
            }
        }
    }

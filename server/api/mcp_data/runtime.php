<?php

    namespace api\mcp_data {

        use api\api;

        /**
         * @api {get} /api/mcp_data/runtime среда PHP и процесса
         */

        class runtime extends api {

            public static function GET($params) {
                $ext = get_loaded_extensions();
                sort($ext);

                $payload = [
                    'phpVersion' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                    'os' => PHP_OS,
                    'timezone' => @date_default_timezone_get(),
                    'memoryLimit' => ini_get('memory_limit'),
                    'maxExecutionTime' => ini_get('max_execution_time'),
                    'serverApiRoot' => realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..',
                    'extensions' => $ext,
                    'extensionsCount' => count($ext),
                ];

                if (isset($params['_redis'])) {
                    try {
                        $payload['redisPing'] = $params['_redis']->ping() ? true : false;
                    } catch (\Throwable $e) {
                        $payload['redisPing'] = false;
                        $payload['redisError'] = $e->getMessage();
                    }
                }

                return api::ANSWER($payload, 'mcpData');
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,check,GET)',
                ];
            }
        }
    }

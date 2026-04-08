<?php

    namespace api\mcp_data {

        use api\api;

        /**
         * @api {get} /api/mcp_data/redisInfo вывод INFO Redis (без KEYS)
         */

        class redisInfo extends api {

            public static function GET($params) {
                if (empty($params['_redis'])) {
                    return api::ANSWER(['error' => 'redis_not_available'], 'mcpData');
                }
                $r = $params['_redis'];
                try {
                    $info = $r->info();
                    if (!is_array($info)) {
                        $info = ['raw' => (string) $info];
                    }
                    return api::ANSWER(['info' => $info], 'mcpData');
                } catch (\Throwable $e) {
                    return api::ANSWER(['error' => 'redis_info_failed', 'message' => $e->getMessage()], 'mcpData');
                }
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,check,GET)',
                ];
            }
        }
    }

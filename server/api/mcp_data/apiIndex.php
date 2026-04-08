<?php

    namespace api\mcp_data {

        use api\api;

        /**
         * @api {get} /api/mcp_data/apiIndex индекс методов API из core_api_methods
         */

        class apiIndex extends api {

            public static function GET($params) {
                $db = $params['_db'];

                $methods = $db->get(
                    'SELECT api, method, request_method
                     FROM core_api_methods
                     ORDER BY api, method, request_method'
                );

                if ($methods === false) {
                    return api::ANSWER(false, 'badRequest');
                }

                return api::ANSWER([
                    'methods' => $methods,
                    'count' => count($methods),
                ], 'mcpData');
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,check,GET)',
                ];
            }
        }
    }

<?php

    namespace api\mcp_data {

        use api\api;
        use McpDataService;

        /**
         * @api {get} /api/mcp_data/configSnapshot server/config.json с редактированием секретов
         */

        class configSnapshot extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/McpDataService.php';

                $raw = @json_decode(file_get_contents(__DIR__ . '/../../config/config.json'), true);
                if (!is_array($raw)) {
                    return api::ANSWER(['error' => 'config_not_readable'], 'mcpData');
                }

                $redacted = McpDataService::redactConfig($raw);

                return api::ANSWER([
                    'configRedacted' => $redacted,
                    'note' => 'Секретные поля заменены на <redacted>. Полный конфиг только на сервере.',
                ], 'mcpData');
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,check,GET)',
                ];
            }
        }
    }

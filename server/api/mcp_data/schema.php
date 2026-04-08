<?php

    namespace api\mcp_data {

        use api\api;
        use McpDataService;

        /**
         * @api {get} /api/mcp_data/schema список таблиц и колонок (PG information_schema)
         */

        class schema extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/McpDataService.php';

                $db = $params['_db'];
                $tableFilter = isset($params['tableLike']) ? trim((string) $params['tableLike']) : '';
                if ($tableFilter !== '' && !preg_match('/^[a-zA-Z0-9_%]+$/', $tableFilter)) {
                    return api::ANSWER(false, 'badRequest');
                }

                $condTables = "table_schema NOT IN ('pg_catalog','information_schema') AND table_type = 'BASE TABLE'";
                $bind = [];
                if ($tableFilter !== '') {
                    $condTables .= ' AND table_name LIKE :tl';
                    $bind['tl'] = str_replace('*', '%', $tableFilter);
                }

                $tables = $db->get(
                    "SELECT table_schema, table_name
                     FROM information_schema.tables
                     WHERE $condTables
                     ORDER BY table_schema, table_name",
                    $bind
                );
                if ($tables === false) {
                    return api::ANSWER(false, 'badRequest');
                }

                $condCols = "table_schema NOT IN ('pg_catalog','information_schema')";
                if ($tableFilter !== '') {
                    $condCols .= ' AND table_name LIKE :tl2';
                    $bind['tl2'] = str_replace('*', '%', $tableFilter);
                }

                $columns = $db->get(
                    "SELECT table_schema, table_name, column_name, data_type, is_nullable, ordinal_position
                     FROM information_schema.columns
                     WHERE $condCols
                     ORDER BY table_schema, table_name, ordinal_position",
                    $bind
                );
                if ($columns === false) {
                    return api::ANSWER(false, 'badRequest');
                }

                return api::ANSWER([
                    'tables' => $tables,
                    'columns' => $columns,
                    'limits' => [
                        'pgSelectMaxRows' => McpDataService::PG_MAX_ROWS,
                    ],
                ], 'mcpData');
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,check,GET)',
                ];
            }
        }
    }

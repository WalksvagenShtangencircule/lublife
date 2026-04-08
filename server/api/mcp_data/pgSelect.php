<?php

    namespace api\mcp_data {

        use api\api;
        use McpDataService;

        /**
         * @api {post} /api/mcp_data/pgSelect только SELECT / WITH; лимит строк; read-only транзакция
         */

        class pgSelect extends api {

            public static function POST($params) {
                require_once __DIR__ . '/../../utils/McpDataService.php';

                $sql = isset($params['sql']) ? trim((string) $params['sql']) : '';
                $err = McpDataService::validateSelectOnly($sql, 'pg');
                if ($err !== null) {
                    return api::ANSWER(['error' => $err], 'mcpData');
                }

                $wrapped = McpDataService::wrapPgLimit($sql);
                /** @var \PDOExt $db */
                $db = $params['_db'];

                try {
                    $db->exec('BEGIN READ ONLY');
                    $db->exec("SET LOCAL statement_timeout TO '45000'");
                    $sth = $db->prepare($wrapped);
                    if (!$sth->execute()) {
                        $db->exec('ROLLBACK');
                        return api::ANSWER(['error' => 'execute_failed'], 'mcpData');
                    }
                    $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
                    $db->exec('ROLLBACK');
                } catch (\Throwable $e) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (\Throwable $e2) {
                    }
                    return api::ANSWER([
                        'error' => 'query_failed',
                        'message' => $e->getMessage(),
                    ], 'mcpData');
                }

                if (!is_array($rows)) {
                    return api::ANSWER(['error' => 'no_result', 'rows' => []], 'mcpData');
                }

                $truncated = count($rows) > McpDataService::PG_MAX_ROWS;
                if ($truncated) {
                    $rows = array_slice($rows, 0, McpDataService::PG_MAX_ROWS);
                }

                return api::ANSWER([
                    'rows' => $rows,
                    'rowCount' => count($rows),
                    'truncated' => $truncated,
                    'maxRows' => McpDataService::PG_MAX_ROWS,
                ], 'mcpData');
            }

            public static function index() {
                return [
                    'POST' => '#same(diagnostics,action,POST)',
                ];
            }
        }
    }

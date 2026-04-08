<?php

    namespace api\mcp_data {

        use api\api;
        use McpDataService;

        /**
         * @api {post} /api/mcp_data/clickhouseSelect только SELECT/WITH; лимит строк
         */

        class clickhouseSelect extends api {

            public static function POST($params) {
                require_once __DIR__ . '/../../utils/McpDataService.php';

                $sql = isset($params['sql']) ? trim((string) $params['sql']) : '';
                $err = McpDataService::validateSelectOnly($sql, 'ch');
                if ($err !== null) {
                    return api::ANSWER(['error' => $err], 'mcpData');
                }

                $config = $params['_config'];
                $c = @$config['clickhouse'];
                if (!$c || !@$c['host']) {
                    return api::ANSWER(['error' => 'clickhouse_not_configured'], 'mcpData');
                }

                $host = $c['host'];
                $port = @$c['port'] ?: 8123;
                $user = @$c['username'] ?: 'default';
                $pass = @$c['password'] ?: '';

                $qbody = $sql . ' FORMAT JSON';
                $mr = (string) (McpDataService::CH_MAX_ROWS + 1);
                $chUrl = 'http://' . $host . ':' . $port . '/?max_result_rows=' . rawurlencode($mr) . '&max_rows_to_read=200000000';

                $curl = curl_init();
                $headers = [];
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: text/plain; charset=UTF-8',
                    'X-ClickHouse-User: ' . $user,
                    'X-ClickHouse-Key: ' . $pass,
                ]);
                curl_setopt($curl, CURLOPT_HEADERFUNCTION,
                    function ($curl, $header) use (&$headers) {
                        $len = strlen($header);
                        $header = explode(':', $header, 2);
                        if (count($header) < 2) {
                            return $len;
                        }
                        $headers[strtolower(trim($header[0]))][] = trim($header[1]);
                        return $len;
                    }
                );
                curl_setopt($curl, CURLOPT_POSTFIELDS, $qbody);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_URL, $chUrl);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                $raw = curl_exec($curl);
                curl_close($curl);

                if (@$headers['x-clickhouse-exception-code']) {
                    return api::ANSWER([
                        'error' => 'clickhouse_error',
                        'body' => is_string($raw) ? substr($raw, 0, 2000) : '',
                    ], 'mcpData');
                }

                $j = json_decode($raw, true);
                if (!is_array($j) || !isset($j['data'])) {
                    return api::ANSWER(['error' => 'bad_response', 'raw' => substr((string) $raw, 0, 500)], 'mcpData');
                }

                $rows = $j['data'];
                $truncated = count($rows) > McpDataService::CH_MAX_ROWS;
                if ($truncated) {
                    $rows = array_slice($rows, 0, McpDataService::CH_MAX_ROWS);
                }

                return api::ANSWER([
                    'rows' => $rows,
                    'rowCount' => count($rows),
                    'truncated' => $truncated,
                    'maxRows' => McpDataService::CH_MAX_ROWS,
                ], 'mcpData');
            }

            public static function index() {
                return [
                    'POST' => '#same(diagnostics,action,POST)',
                ];
            }
        }
    }

<?php

    /**
     * @api {post} /api/assistant/chat чат с DeepSeek (инструменты статистики по БД)
     *
     * @apiBody {Object[]} messages [{role, content}]
     */

    namespace api\assistant {

        use api\api;

        class chat extends api {

            public static function POST($params) {
                require_once __DIR__ . "/../../utils/AssistantTools.php";

                $cfg = @$params["_config"]["assistant"];
                if (!is_array($cfg)) {
                    $cfg = [];
                }
                $apiKey = isset($cfg["deepseekApiKey"]) ? trim((string) $cfg["deepseekApiKey"]) : "";
                if ($apiKey === "") {
                    return api::ANSWER(false, "badRequest");
                }

                $baseUrl = isset($cfg["deepseekBaseUrl"]) ? rtrim(trim((string) $cfg["deepseekBaseUrl"]), "/") : "https://api.deepseek.com";
                $model = isset($cfg["model"]) ? trim((string) $cfg["model"]) : "deepseek-chat";
                $maxIter = isset($cfg["maxToolIterations"]) ? (int) $cfg["maxToolIterations"] : 5;
                if ($maxIter < 1) {
                    $maxIter = 1;
                }
                if ($maxIter > 12) {
                    $maxIter = 12;
                }

                $messages = @$params["messages"];
                if (!is_array($messages) || !count($messages)) {
                    $one = @$params["message"];
                    if (is_string($one) && trim($one) !== "") {
                        $messages = [
                            ["role" => "user", "content" => trim($one)],
                        ];
                    } else {
                        return api::ANSWER(false, "badRequest");
                    }
                }

                array_unshift($messages, [
                    "role" => "system",
                    "content" => "Ты аналитический помощник SmartAccess. Отвечай по-русски, кратко. " .
                        "Для адресов сначала вызывай resolve_house, затем используй house_id. " .
                        "Время в инструментах rfid_events_in_period и flat_activity_in_house — unix-секунды (UTC эпоха).",
                ]);

                $messages = self::sanitizeMessages($messages);
                if (!count($messages)) {
                    return api::ANSWER(false, "badRequest");
                }

                $tools = self::toolDefinitions();
                $db = $params["_db"];
                $config = $params["_config"];

                $iter = 0;
                $assistantMeta = [];

                while ($iter < $maxIter) {
                    $iter++;
                    $payload = [
                        "model" => $model,
                        "messages" => $messages,
                        "tools" => $tools,
                        "tool_choice" => "auto",
                        "temperature" => 0.2,
                    ];

                    $raw = self::httpJson("POST", $baseUrl . "/chat/completions", $apiKey, $payload);
                    if ($raw === null) {
                        return api::ANSWER([
                            "reply" => "",
                            "error" => "deepseek_unreachable",
                            "iterations" => $iter,
                        ], "assistantChat");
                    }

                    $choice = @$raw["choices"][0];
                    if (!$choice) {
                        return api::ANSWER([
                            "error" => "no_choice",
                            "raw" => $raw,
                        ], "assistantChat");
                    }

                    $msg = @$choice["message"];
                    if (!is_array($msg)) {
                        return api::ANSWER(["error" => "bad_message_shape"], "assistantChat");
                    }

                    $finish = @$choice["finish_reason"];
                    $toolCalls = @$msg["tool_calls"];

                    if (is_array($toolCalls) && count($toolCalls) && ($finish === "tool_calls" || isset($msg["tool_calls"]))) {
                        $messages[] = $msg;
                        foreach ($toolCalls as $tc) {
                            $id = @$tc["id"];
                            $fn = @$tc["function"]["name"];
                            $argsJson = @$tc["function"]["arguments"];
                            $args = [];
                            if (is_string($argsJson)) {
                                $args = json_decode($argsJson, true);
                                if (!is_array($args)) {
                                    $args = [];
                                }
                            }
                            $result = assistant_tools_run((string) $fn, $args, $db, $config);
                            $assistantMeta[] = ["tool" => $fn, "args" => $args, "result" => $result];
                            $messages[] = [
                                "role" => "tool",
                                "tool_call_id" => (string) $id,
                                "content" => json_encode($result, JSON_UNESCAPED_UNICODE),
                            ];
                        }
                        continue;
                    }

                    $content = isset($msg["content"]) ? (string) $msg["content"] : "";
                    return api::ANSWER([
                        "reply" => $content,
                        "iterations" => $iter,
                        "meta" => $assistantMeta,
                    ], "assistantChat");
                }

                return api::ANSWER(["error" => "max_iterations", "meta" => $assistantMeta], "assistantChat");
            }

            /**
             * @param array<int, array<string, mixed>> $messages
             * @return array<int, array<string, mixed>>
             */
            private static function sanitizeMessages(array $messages): array {
                $out = [];
                $maxChars = 12000;
                $total = 0;
                foreach ($messages as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $role = isset($m["role"]) ? (string) $m["role"] : "";
                    if (!in_array($role, ["user", "assistant", "system"], true)) {
                        continue;
                    }
                    $content = isset($m["content"]) ? (string) $m["content"] : "";
                    if ($content === "") {
                        continue;
                    }
                    if (mb_strlen($content) > 8000) {
                        $content = mb_substr($content, 0, 8000) . "…";
                    }
                    $total += mb_strlen($content);
                    if ($total > $maxChars) {
                        break;
                    }
                    $out[] = ["role" => $role, "content" => $content];
                }
                return $out;
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            private static function toolDefinitions(): array {
                return [
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "resolve_house",
                            "description" => "Найти дома по подстроке адреса (house_full). Вернуть houseId для других инструментов.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "search" => ["type" => "string", "description" => "Фрагмент адреса, например улица и номер дома"],
                                ],
                                "required" => ["search"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "count_keys_for_flat",
                            "description" => "Сколько домофонных ключей (RFID) привязано к квартире в доме.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "flat_number" => ["type" => "string", "description" => "Номер квартиры как в справочнике"],
                                ],
                                "required" => ["house_id", "flat_number"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "device_user_agents_for_house",
                            "description" => "Агрегация User-Agent мобильных приложений по дому (косвенно модели/клиенты).",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "limit" => ["type" => "integer", "description" => "Макс. строк, по умолчанию 25"],
                                ],
                                "required" => ["house_id"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "rfid_events_in_period",
                            "description" => "Сколько событий в журнале plog (ClickHouse) по ключу RFID за период unix-времени для дома.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "rfid" => ["type" => "string"],
                                    "since_unix" => ["type" => "integer"],
                                    "until_unix" => ["type" => "integer"],
                                ],
                                "required" => ["house_id", "rfid", "since_unix", "until_unix"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "flat_activity_in_house",
                            "description" => "Топ квартир по числу событий plog за период (unix-время).",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "since_unix" => ["type" => "integer"],
                                    "until_unix" => ["type" => "integer"],
                                ],
                                "required" => ["house_id", "since_unix", "until_unix"],
                            ],
                        ],
                    ],
                ];
            }

            /**
             * @param array<string, mixed> $body
             * @return array<string, mixed>|null
             */
            private static function httpJson(string $method, string $url, string $apiKey, array $body) {
                $json = json_encode($body, JSON_UNESCAPED_UNICODE);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, $method === "POST");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $apiKey,
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp === false || $code < 200 || $code >= 300) {
                    error_log("assistant deepseek http " . $code);
                    return null;
                }
                $dec = json_decode($resp, true);
                return is_array($dec) ? $dec : null;
            }

            public static function index() {
                return [
                    "POST" => false,
                ];
            }
        }
    }

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
                $maxIter = isset($cfg["maxToolIterations"]) ? (int) $cfg["maxToolIterations"] : 8;
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
                    "content" => "Ты встроенный аналитический помощник **этого конкретного сервера SmartAccess**. " .
                        "Отвечай **только** на основе данных, полученных через инструменты (БД PostgreSQL, журнал plog в ClickHouse, конфигурация этого экземпляра). " .
                        "Если вопрос выходит за пределы доступных данных или не относится к содержимому этого сервера — прямо скажи, что можешь отвечать лишь по фактам из системы, и не выдумывай. " .
                        "Отвечай по-русски, структурированно. Дома ищи через resolve_house, затем используй house_id. " .
                        "Время since_unix/until_unix — **unix-секунды** (эпоха). Для «за последнюю неделю»: until=текущее время, since=until-7*86400. " .
                        "Типы событий plog: 1 пропущенный звонок, 2 ответ, 3 открыто ключом RFID, 4 открыто из приложения, 5 лицо, 6 код, 7 ворота по звонку, 9 транспорт. " .
                        "Счёт квартир: flats_count. Мобильные учётки и активность приложения в plog: mobile_users_stats. " .
                        "Карточка абонента (квартиры, устройства, ключи): subscriber_lookup. " .
                        "Что открывалось и когда: plog_events_list (по дому; узкий фильтр по абоненту — house_subscriber_id).",
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
                $toolCallLoop = [];

                while ($iter < $maxIter) {
                    $iter++;
                    $forceNoTools = ($iter >= $maxIter - 1);
                    $payload = [
                        "model" => $model,
                        "messages" => $messages,
                        "temperature" => 0.2,
                    ];
                    if (!$forceNoTools) {
                        $payload["tools"] = $tools;
                        $payload["tool_choice"] = "auto";
                    } else {
                        // Последний проход: принудительно без новых инструментов, чтобы получить финальный ответ.
                        $payload["tool_choice"] = "none";
                    }

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
                    if ((!is_array($toolCalls) || !count($toolCalls)) && isset($msg["content"]) && is_string($msg["content"])) {
                        $toolCalls = self::parseDsmlToolCalls($msg["content"]);
                    }

                    if (is_array($toolCalls) && count($toolCalls) && ($finish === "tool_calls" || isset($msg["tool_calls"]) || strpos((string)@$msg["content"], "DSML") !== false)) {
                        if ($forceNoTools) {
                            // Модель продолжает просить инструменты даже на финальном проходе.
                            // Добавляем жёсткую подсказку и делаем ещё одну попытку без tools.
                            $messages[] = [
                                "role" => "system",
                                "content" => "Новых вызовов инструментов больше делать нельзя. Ответь пользователю итогом на основании уже полученных результатов инструментов.",
                            ];
                            continue;
                        }
                        $messages[] = $msg;
                        foreach ($toolCalls as $tc) {
                            $id = @$tc["id"];
                            $fn = "";
                            $args = [];
                            if (isset($tc["function"])) {
                                $fn = (string) (@$tc["function"]["name"] ?: "");
                                $argsJson = @$tc["function"]["arguments"];
                                if (is_string($argsJson)) {
                                    $args = json_decode($argsJson, true);
                                    if (!is_array($args)) {
                                        $args = [];
                                    }
                                }
                            } else {
                                $fn = (string) (@$tc["name"] ?: "");
                                $args = is_array(@$tc["arguments"]) ? $tc["arguments"] : [];
                            }
                            $sig = md5((string)$fn . "|" . json_encode($args, JSON_UNESCAPED_UNICODE));
                            $toolCallLoop[$sig] = isset($toolCallLoop[$sig]) ? ($toolCallLoop[$sig] + 1) : 1;
                            if ($toolCallLoop[$sig] > 2) {
                                $messages[] = [
                                    "role" => "system",
                                    "content" => "Одинаковый вызов инструмента уже повторялся. Не повторяй его снова, а сформируй ответ по уже полученным данным.",
                                ];
                                continue;
                            }
                            $result = assistant_tools_run((string) $fn, $args, $db, $config);
                            $assistantMeta[] = ["tool" => $fn, "args" => $args, "result" => $result];
                            $messages[] = [
                                "role" => "tool",
                                "tool_call_id" => (string) ($id ?: ("dsml_" . md5($fn . json_encode($args)))),
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
                /** Контекст до 10 реплик user/assistant + system; бюджет символов под длинные ответы */
                $maxChars = 36000;
                $maxPerMessage = 6000;
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
                    if (mb_strlen($content) > $maxPerMessage) {
                        $content = mb_substr($content, 0, $maxPerMessage) . "…";
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
                            "name" => "flats_count",
                            "description" => "Сколько квартир в доме или сводка по всем домам: total_flats и топ домов по числу квартир.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer", "description" => "0 = все дома (сводка), иначе id дома"],
                                    "top_houses_limit" => ["type" => "integer", "description" => "При house_id=0: сколько домов в топе, по умолчанию 35"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "mobile_users_stats",
                            "description" => "Учётные записи мобильного приложения в PostgreSQL (по дому или глобально) и оценка активных пользователей приложения по журналу plog (event=4, уникальные телефоны в JSON phones) за период unix-времени.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer", "description" => "0 = по всей базе, иначе только абоненты с квартирами в этом доме"],
                                    "since_unix" => ["type" => "integer"],
                                    "until_unix" => ["type" => "integer"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "subscriber_lookup",
                            "description" => "Данные абонента: ФИО/телефон, привязанные квартиры и дома, мобильные устройства (UA, last_seen), привязанные RFID-ключи.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "phone" => ["type" => "string", "description" => "Телефон как в БД или фрагмент с цифрами"],
                                    "house_subscriber_id" => ["type" => "integer", "description" => "id из houses_subscribers_mobile"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "plog_events_list",
                            "description" => "Журнал проходов/открытий из ClickHouse plog по квартирам дома за период: тип события, RFID, код, flat_id, domophone, телефон из приложения. Можно сузить до квартир конкретного house_subscriber_id в этом доме.",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "since_unix" => ["type" => "integer"],
                                    "until_unix" => ["type" => "integer"],
                                    "house_subscriber_id" => ["type" => "integer", "description" => "Если указан — только квартиры этого абонента в этом доме"],
                                    "phone" => ["type" => "string", "description" => "Опционально: только события с этим user_phone в plog"],
                                    "rfid" => ["type" => "string", "description" => "Опционально: фильтр по ключу"],
                                    "limit" => ["type" => "integer", "description" => "Макс. строк 5..80, по умолчанию 40"],
                                ],
                                "required" => ["house_id", "since_unix", "until_unix"],
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

            /**
             * DeepSeek иногда возвращает function call не в tool_calls JSON, а DSML-текстом.
             * Конвертируем в унифицированный массив вызовов.
             *
             * @return array<int, array{name:string,arguments:array<string,mixed>}>
             */
            private static function parseDsmlToolCalls(string $content): array {
                if (strpos($content, "invoke name=") === false || strpos($content, "DSML") === false) {
                    return [];
                }
                $calls = [];
                if (!preg_match_all('/<\\|\\s*DSML\\s*\\|\\s*invoke\\s+name=\"([a-zA-Z0-9_]+)\"\\s*>(.*?)<\\|\\s*\\/\\s*DSML\\s*\\|\\s*invoke\\s*>/su', $content, $invokes, PREG_SET_ORDER)) {
                    return [];
                }
                foreach ($invokes as $inv) {
                    $name = (string) $inv[1];
                    $body = (string) $inv[2];
                    $args = [];
                    if (preg_match_all('/<\\|\\s*DSML\\s*\\|\\s*parameter\\s+name=\"([a-zA-Z0-9_]+)\"([^>]*)>(.*?)<\\|\\s*\\/\\s*DSML\\s*\\|\\s*parameter\\s*>/su', $body, $params, PREG_SET_ORDER)) {
                        foreach ($params as $p) {
                            $k = (string) $p[1];
                            $attrs = (string) $p[2];
                            $vRaw = trim(html_entity_decode((string) $p[3], ENT_QUOTES | ENT_HTML5));
                            $isString = false;
                            if (preg_match('/\\bstring=\"(true|false)\"/i', $attrs, $m)) {
                                $isString = (strtolower((string) $m[1]) === "true");
                            }
                            if ($isString) {
                                $args[$k] = $vRaw;
                            } else {
                                if ($vRaw === "true") {
                                    $args[$k] = true;
                                } elseif ($vRaw === "false") {
                                    $args[$k] = false;
                                } elseif (preg_match('/^-?\\d+$/', $vRaw)) {
                                    $args[$k] = (int) $vRaw;
                                } elseif (preg_match('/^-?\\d+\\.\\d+$/', $vRaw)) {
                                    $args[$k] = (float) $vRaw;
                                } else {
                                    $args[$k] = $vRaw;
                                }
                            }
                        }
                    }
                    $calls[] = [
                        "name" => $name,
                        "arguments" => $args,
                    ];
                }
                return $calls;
            }

            public static function index() {
                return [
                    "POST" => false,
                ];
            }
        }
    }

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
                set_time_limit(120);
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
                $maxIter = isset($cfg["maxToolIterations"]) ? (int) $cfg["maxToolIterations"] : 6;
                if ($maxIter < 1) {
                    $maxIter = 1;
                }
                if ($maxIter > 8) {
                    $maxIter = 8;
                }
                $maxToolExecTotal = isset($cfg["maxToolCallsPerRequest"]) ? (int) $cfg["maxToolCallsPerRequest"] : 6;
                if ($maxToolExecTotal < 1) {
                    $maxToolExecTotal = 1;
                }
                if ($maxToolExecTotal > 12) {
                    $maxToolExecTotal = 12;
                }
                $maxResponseTokens = isset($cfg["maxResponseTokens"]) ? (int) $cfg["maxResponseTokens"] : 1200;
                if ($maxResponseTokens < 200) {
                    $maxResponseTokens = 200;
                }
                if ($maxResponseTokens > 2000) {
                    $maxResponseTokens = 2000;
                }
                /** Абсолютный дедлайн wall-clock для всего POST (сек), по умолчанию ~1 минута. */
                $wallDeadline = microtime(true) + self::assistantMaxWallSeconds($cfg);

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
                        "Отвечай **только** на основе данных, полученных через инструменты (БД PostgreSQL, журнал plog в ClickHouse). " .
                        "Если вопрос выходит за пределы доступных данных — прямо скажи об этом, не выдумывай. " .
                        "Отвечай по-русски, структурированно, используй Markdown (заголовки, таблицы, списки). " .

                        "**ССЫЛКИ**: Когда инструмент возвращает поля _url, flat_url, owner_url, domophone_url, camera_url, house_url — " .
                        "всегда вставляй их в ответ как Markdown-ссылки. Примеры форматирования: " .
                        "[Иванов И.И.](?#addresses.subscriberDevices&subscriberId=42), " .
                        "[кв. 15](?#addresses.subscribers&flatId=100&houseId=5), " .
                        "[домофон Beward](?#addresses.domophones&id=3), " .
                        "[камера](?#addresses.cameras&id=7), " .
                        "[дом](?#addresses.houses&houseId=5). " .
                        "Если данных для ссылки нет — просто выводи текст без ссылки. " .

                        "**ИНСТРУМЕНТЫ**: " .
                        "Дома ищи через resolve_house, затем используй house_id. " .
                        "Список всех домов: all_houses_list. " .
                        "Счёт квартир: flats_count. Детали квартиры (код, блокировки, абоненты): flat_info. " .
                        "Заблокированные квартиры дома: blocked_flats. " .
                        "Подъезды дома: house_entrances_list. Домофоны дома: house_domophones_list. " .
                        "Карточка абонента (квартиры, устройства, ключи): subscriber_lookup. " .
                        "Список активных абонентов дома: active_subscribers_for_house. " .
                        "Мобильные учётки и plog-активность: mobile_users_stats. " .
                        "Воронка за несколько периодов одним вызовом: mobile_access_funnel(house_id, periods_days:[7,30]). " .
                        "Журнал событий (проходы/открытия): plog_events_list. " .
                        "Ключи квартиры: count_keys_for_flat. Поиск ключа RFID: rfid_lookup. " .
                        "События по ключу RFID: rfid_events_in_period. Топ активных квартир: flat_activity_in_house. " .

                        "**ВРЕМЯ**: since_unix/until_unix — unix-секунды. Для «за X дней» передавай days_back=X. " .
                        "Типы событий plog: 1=пропущенный_звонок, 2=ответ, 3=ключ_RFID, 4=мобильное_приложение, 5=лицо, 6=код, 7=ворота_по_звонку, 9=транспорт. " .

                        "**ПАСПОРТ ДОМА** (7 дней): flats_count → mobile_access_funnel(periods_days:[7]) → plog_events_list(limit:30). Не дублируй вызовы.",
                ]);

                $messages = self::sanitizeMessages($messages);
                if (!count($messages)) {
                    return api::ANSWER(false, "badRequest");
                }

                $db = $params["_db"];
                $config = $params["_config"];

                $fast = self::tryFastHousePassport($messages, $db, $config);
                if (is_array($fast)) {
                    return api::ANSWER($fast, "assistantChat");
                }

                $tools = self::toolDefinitions();

                $iter = 0;
                $assistantMeta = [];
                $toolCallLoop = [];
                $rawMarkupLoops = 0;
                $toolExecTotal = 0;

                while ($iter < $maxIter) {
                    $iter++;
                    // Только на последней итерации отключаем инструменты. Раньше было ($iter >= $maxIter - 1) — тогда
                    // при maxIter=5 инструменты работали лишь на шагах 1–3, а «паспорт дома» не успевал собраться.
                    $forceNoTools = ($iter >= $maxIter);
                    $payload = [
                        "model" => $model,
                        "messages" => $messages,
                        "temperature" => 0.2,
                        "max_tokens" => $maxResponseTokens,
                    ];
                    if (!$forceNoTools) {
                        $payload["tools"] = $tools;
                        $payload["tool_choice"] = "auto";
                    } else {
                        // Последний проход: принудительно без новых инструментов, чтобы получить финальный ответ.
                        $payload["tool_choice"] = "none";
                    }

                    $bud = self::assistantBudgetSeconds($wallDeadline);
                    $curlT = self::nextCurlTimeout($bud, 5, 16);
                    if ($curlT <= 0) {
                        return self::answerTimeBudget($assistantMeta, $iter);
                    }
                    $raw = self::httpJson("POST", $baseUrl . "/chat/completions", $apiKey, $payload, $curlT);
                    if ($raw === null) {
                        if (self::assistantBudgetSeconds($wallDeadline) < 2.0) {
                            return self::answerTimeBudget($assistantMeta, $iter);
                        }
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

                    $toolCalls = @$msg["tool_calls"];
                    $msgContent = isset($msg["content"]) && is_string($msg["content"]) ? (string)$msg["content"] : "";
                    if ((!is_array($toolCalls) || !count($toolCalls)) && $msgContent !== "") {
                        $toolCalls = self::parseDsmlToolCalls($msgContent);
                    }

                    // Важно: DeepSeek иногда кладёт вызовы в текст DSML при finish_reason=stop/stop_generation.
                    // Раньше мы требовали подстроку "DSML" или finish=tool_calls — из-за этого инструменты не выполнялись.
                    if (is_array($toolCalls) && count($toolCalls)) {
                        if ($forceNoTools) {
                            // Раньше здесь был continue — на последней итерации ($iter === $maxIter) цикл while
                            // уже не выполняется снова (iter < maxIter ложно), и ответ терялся → buildFallbackFromMeta.
                            $messages[] = [
                                "role" => "system",
                                "content" => "Новых вызовов инструментов больше делать нельзя. Ответь пользователю итогом на основании уже полученных результатов инструментов.",
                            ];
                            return api::ANSWER([
                                "reply" => self::buildFallbackFromMeta($assistantMeta),
                                "iterations" => $iter,
                                "meta" => $assistantMeta,
                                "fallback" => true,
                            ], "assistantChat");
                        }
                        $messages[] = $msg;
                        foreach ($toolCalls as $tc) {
                            if ($toolExecTotal >= $maxToolExecTotal) {
                                return api::ANSWER([
                                    "reply" => self::buildFallbackFromMeta($assistantMeta) .
                                        "\n\n— Лимит быстрых инструментов достигнут. Уточните запрос (дом, абонент, период), чтобы ответ пришёл быстрее.",
                                    "iterations" => $iter,
                                    "meta" => $assistantMeta,
                                    "fallback" => true,
                                    "tool_limit" => true,
                                ], "assistantChat");
                            }
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
                            if (self::assistantBudgetSeconds($wallDeadline) < 4.0) {
                                $result = [
                                    "error" => "server_time_budget",
                                    "tool" => $fn,
                                    "message" => "Лимит времени ответа сервера: инструмент не выполнен.",
                                ];
                            } else {
                                $result = assistant_tools_run((string) $fn, $args, $db, $config);
                            }
                            $toolExecTotal++;
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
                    if (self::containsRawToolMarkup($content)) {
                        $rawMarkupLoops++;
                        if ($rawMarkupLoops >= 2) {
                            $fallback = self::buildFallbackFromMeta($assistantMeta);
                            return api::ANSWER([
                                "reply" => $fallback,
                                "iterations" => $iter,
                                "meta" => $assistantMeta,
                                "fallback" => true,
                            ], "assistantChat");
                        }
                        // Модель вернула сырые теги function_calls/function_results как "ответ".
                        // Просим переформулировать обычным текстом по уже полученным данным.
                        $messages[] = [
                            "role" => "assistant",
                            "content" => $content,
                        ];
                        $messages[] = [
                            "role" => "system",
                            "content" => "Не выводи DSML/XML/служебные теги (function_calls/function_results/result/parameter). " .
                                "Дай только финальный ответ пользователю обычным русским текстом, используя уже полученные результаты инструментов.",
                        ];
                        if ($iter >= $maxIter) {
                            return api::ANSWER([
                                "reply" => self::buildFallbackFromMeta($assistantMeta),
                                "iterations" => $iter,
                                "meta" => $assistantMeta,
                                "fallback" => true,
                            ], "assistantChat");
                        }
                        continue;
                    }
                    $content = self::stripDsmlMarkup($content);
                    if (trim($content) === "") {
                        $content = "Не удалось корректно сформировать ответ в текстовом виде. Повторите запрос, пожалуйста.";
                    }
                    return api::ANSWER([
                        "reply" => $content,
                        "iterations" => $iter,
                        "meta" => $assistantMeta,
                    ], "assistantChat");
                }

                if (count($assistantMeta)) {
                    return api::ANSWER([
                        "reply" => self::buildFallbackFromMeta($assistantMeta),
                        "iterations" => $iter,
                        "meta" => $assistantMeta,
                        "fallback" => true,
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
                /** Контекст короче для снижения задержки ответа модели. */
                $maxChars = 18000;
                $maxPerMessage = 3500;
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
                            "name" => "mobile_access_funnel",
                            "description" => "Воронка мобильного доступа за **несколько окон** (например 7 и 30 дней) за один вызов: для каждого окна — учётки (distinct), устройства, уникальные телефоны в plog при event=4. Правая граница until_unix по умолчанию «сейчас».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer", "description" => "0 = вся база, иначе дом"],
                                    "periods_days" => [
                                        "type" => "array",
                                        "items" => ["type" => "integer"],
                                        "description" => "Длины окон в днях, напр. [7, 30]. Максимум 6 значений.",
                                    ],
                                    "until_unix" => ["type" => "integer", "description" => "Правая граница периода (эпоха), по умолчанию time()"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "active_subscribers_for_house",
                            "description" => "Список абонентов дома с активностью в мобильном приложении: телефон, ФИО, квартира, платформа (iOS/Android), дата последнего входа, роль. Используй для отчётов «активные абоненты», «кто пользуется приложением», «список жильцов».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer", "description" => "ID дома"],
                                    "days_back" => ["type" => "integer", "description" => "За сколько дней считать активным (по last_seen устройства). По умолчанию 30"],
                                    "limit" => ["type" => "integer", "description" => "Максимум записей, 10–500. По умолчанию 100"],
                                    "only_with_device" => ["type" => "boolean", "description" => "true — только те у кого есть зарегистрированное устройство за период"],
                                ],
                                "required" => ["house_id"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "subscriber_lookup",
                            "description" => "Данные одного абонента: ФИО/телефон, привязанные квартиры и дома, мобильные устройства (UA, last_seen), привязанные RFID-ключи.",
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
                            "description" => "Журнал проходов/открытий из ClickHouse plog по квартирам дома за период: тип события, RFID, код, flat_id, domophone, телефон из приложения. Можно сузить до квартир конкретного house_subscriber_id в этом доме. Если since_unix/until_unix не известны — передай days_back (количество дней назад от сейчас).",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "since_unix" => ["type" => "integer", "description" => "Начало периода (unix). Если не указан — используется days_back или последние 2 дня"],
                                    "until_unix" => ["type" => "integer", "description" => "Конец периода (unix). Если не указан — используется текущее время"],
                                    "days_back" => ["type" => "integer", "description" => "Альтернатива since_unix: сколько дней назад от сейчас (1–90). Используй когда пользователь говорит 'за X дней'"],
                                    "house_subscriber_id" => ["type" => "integer", "description" => "Если указан — только квартиры этого абонента в этом доме"],
                                    "phone" => ["type" => "string", "description" => "Опционально: только события с этим user_phone в plog"],
                                    "rfid" => ["type" => "string", "description" => "Опционально: фильтр по ключу"],
                                    "limit" => ["type" => "integer", "description" => "Макс. строк 5..200, по умолчанию 40"],
                                ],
                                "required" => ["house_id"],
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
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "flat_info",
                            "description" => "Детальная карточка квартиры: код открытия, блокировки (ручная/авто/административная), SIP, количество ключей RFID, список абонентов со ссылками. Используй для «покажи квартиру X в доме Y», «заблокирована ли», «кто живёт».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer", "description" => "ID дома"],
                                    "flat_number" => ["type" => "string", "description" => "Номер квартиры как в справочнике"],
                                    "flat_id" => ["type" => "integer", "description" => "house_flat_id — альтернатива house_id+flat_number"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "house_entrances_list",
                            "description" => "Список подъездов дома: тип, номер, привязанный домофон (модель, IP), камера со ссылками. Используй для «покажи подъезды», «какие домофоны в доме», «есть ли камеры на входе».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                ],
                                "required" => ["house_id"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "house_domophones_list",
                            "description" => "Список домофонов дома с моделью, IP, статусом (активен/отключён), ссылками. Используй для «список домофонов», «IP домофона», «модели оборудования».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                ],
                                "required" => ["house_id"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "blocked_flats",
                            "description" => "Список заблокированных квартир дома (ручная, авто или административная блокировка) со ссылками. Используй для «покажи заблокированные квартиры», «у кого блокировка».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "house_id" => ["type" => "integer"],
                                    "block_type" => [
                                        "type" => "string",
                                        "enum" => ["any", "manual", "auto", "admin"],
                                        "description" => "Фильтр по типу блокировки: any = все виды (по умолчанию)",
                                    ],
                                    "limit" => ["type" => "integer", "description" => "Максимум записей, 5–500. По умолчанию 100"],
                                ],
                                "required" => ["house_id"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "rfid_lookup",
                            "description" => "Найти ключ RFID: кому принадлежит (абонент или квартира), дата последнего использования, ссылки на владельца и квартиру. Используй для «кому принадлежит ключ X», «найди карту RFID», «история ключа».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "rfid" => ["type" => "string", "description" => "Полный или частичный HEX-код ключа"],
                                ],
                                "required" => ["rfid"],
                            ],
                        ],
                    ],
                    [
                        "type" => "function",
                        "function" => [
                            "name" => "all_houses_list",
                            "description" => "Полный список домов в системе с количеством квартир и абонентов, ссылками на страницы домов. Используй для «список всех домов», «сколько домов в системе», «покажи все адреса».",
                            "parameters" => [
                                "type" => "object",
                                "properties" => [
                                    "search" => ["type" => "string", "description" => "Фильтр по адресу (необязательно)"],
                                    "limit" => ["type" => "integer", "description" => "Максимум строк, 10–500. По умолчанию 100"],
                                ],
                                "required" => [],
                            ],
                        ],
                    ],
                ];
            }

            /** Общий лимит времени на весь запрос assistant/chat (сек). */
            private static function assistantMaxWallSeconds(array $cfg): float {
                $v = isset($cfg["maxTotalSeconds"]) ? (float) $cfg["maxTotalSeconds"] : 90.0;
                if ($v < 10.0) {
                    $v = 10.0;
                }
                if ($v > 110.0) {
                    $v = 110.0;
                }
                return $v;
            }

            private static function assistantBudgetSeconds(float $deadline): float {
                $b = $deadline - microtime(true);
                return $b > 0 ? $b : 0.0;
            }

            /**
             * Сколько секунд отдать одному HTTP к DeepSeek (остаток бюджета минус запас).
             *
             * @return int 0 — бюджета не хватает, вызывать API не нужно
             */
            private static function nextCurlTimeout(float $budgetSeconds, int $minimum, int $cap): int {
                if ($budgetSeconds < (float) $minimum + 1.0) {
                    return 0;
                }
                $t = (int) floor($budgetSeconds - 1.0);
                if ($t < $minimum) {
                    return 0;
                }
                return min($cap, $t);
            }

            /**
             * @param array<int, array<string, mixed>> $assistantMeta
             */
            private static function answerTimeBudget(array $assistantMeta, int $iter): array {
                if (count($assistantMeta)) {
                    return api::ANSWER([
                        "reply" => self::buildFallbackFromMeta($assistantMeta) .
                            "\n\n— Ответ обрезан по лимиту времени сервера (~1 мин). Сузьте запрос (конкретный house_id, короткий период, меньше пунктов).",
                        "iterations" => $iter,
                        "meta" => $assistantMeta,
                        "fallback" => true,
                        "time_budget" => true,
                    ], "assistantChat");
                }
                return api::ANSWER([
                    "reply" => "Превышено время ожидания: сеть или модель отвечают слишком долго. Повторите с более коротким периодом.",
                    "error" => "assistant_time_budget",
                    "iterations" => $iter,
                ], "assistantChat");
            }

            /**
             * @param array<string, mixed> $body
             * @return array<string, mixed>|null
             */
            private static function httpJson(string $method, string $url, string $apiKey, array $body, int $timeoutSec = 40, int $connectTimeoutSec = 8) {
                $json = json_encode($body, JSON_UNESCAPED_UNICODE);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, $method === "POST");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $apiKey,
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeoutSec));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(3, $connectTimeoutSec));
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
             * Один запрос к модели без инструментов (итог после инструментов или «залипания» на последнем шаге цикла).
             *
             * @param array<int, array<string, mixed>> $messages
             */
            private static function requestTextOnlyCompletion(string $baseUrl, string $apiKey, string $model, array $messages, float $wallDeadline): ?string {
                // Без поля tools — модель не сможет вызвать функции; tool_choice без списка tools у части API даёт ошибку.
                $payload = [
                    "model" => $model,
                    "messages" => $messages,
                    "temperature" => 0.2,
                ];
                $bud = self::assistantBudgetSeconds($wallDeadline);
                        $curlT = self::nextCurlTimeout($bud, 4, 12);
                if ($curlT <= 0) {
                    return null;
                }
                $raw = self::httpJson("POST", $baseUrl . "/chat/completions", $apiKey, $payload, $curlT);
                if ($raw === null) {
                    return null;
                }
                $choice = @$raw["choices"][0];
                if (!$choice || !is_array(@$choice["message"])) {
                    return null;
                }
                $msg = $choice["message"];
                $text = isset($msg["content"]) && is_string($msg["content"]) ? (string) $msg["content"] : "";
                $text = self::stripDsmlMarkup($text);
                $trim = trim($text);
                if ($trim !== "") {
                    return $trim;
                }
                return null;
            }

            /**
             * DeepSeek иногда возвращает function call не в tool_calls JSON, а DSML-текстом.
             * Конвертируем в унифицированный массив вызовов.
             *
             * @return array<int, array{name:string,arguments:array<string,mixed>}>
             */
            private static function parseDsmlToolCalls(string $content): array {
                if (strpos($content, "invoke name=") === false) {
                    return [];
                }
                $calls = [];
                // Универсальный разбор: ищем каждый invoke-блок и параметры внутри него, не опираясь жёстко на spacing DSML.
                if (!preg_match_all('/<\\s*invoke\\s+name=\"([a-zA-Z0-9_]+)\"\\s*>(.*?)<\\s*\\/\\s*invoke\\s*>/isu', $content, $invokes, PREG_SET_ORDER)) {
                    // Совместимость со старым DSML-представлением без угловой скобки в начале.
                    if (!preg_match_all('/invoke\\s+name=\"([a-zA-Z0-9_]+)\"\\s*>(.*?)\\/\\s*invoke\\s*>/isu', $content, $invokes, PREG_SET_ORDER)) {
                        return [];
                    }
                }
                foreach ($invokes as $inv) {
                    $name = (string) $inv[1];
                    $body = (string) $inv[2];
                    $args = [];
                    if (!preg_match_all('/<\\s*parameter\\s+name=\"([a-zA-Z0-9_]+)\"([^>]*)>(.*?)<\\s*\\/\\s*parameter\\s*>/isu', $body, $params, PREG_SET_ORDER)) {
                        preg_match_all('/parameter\\s+name=\"([a-zA-Z0-9_]+)\"([^>]*)>(.*?)\\/\\s*parameter\\s*>/isu', $body, $params, PREG_SET_ORDER);
                    }
                    if (count($params)) {
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

            private static function containsDsmlInvoke(string $text): bool {
                return (bool) preg_match('/invoke\\s+name=\"[a-zA-Z0-9_]+\"/i', $text);
            }

            private static function containsRawToolMarkup(string $text): bool {
                if ($text === "") {
                    return false;
                }
                if (self::containsDsmlInvoke($text)) {
                    return true;
                }
                if (preg_match('/<\\s*function_results\\s*>/i', $text)) {
                    return true;
                }
                if (preg_match('/<\\s*result\\s*>/i', $text)) {
                    return true;
                }
                if (preg_match('/DSML\\s*\\|\\s*function_calls/i', $text)) {
                    return true;
                }
                return false;
            }

            private static function stripDsmlMarkup(string $text): string {
                $out = $text;
                // Удаляем DSML-блоки целиком, если вдруг модель добавила их в финальный ответ.
                $out = preg_replace('/<\\|\\s*DSML\\s*\\|\\s*function_calls\\s*>.*?<\\|\\s*\\/\\s*DSML\\s*\\|\\s*function_calls\\s*>/isu', '', $out);
                // Подстраховка на "голые" invoke/parameter без внешних тегов.
                $out = preg_replace('/<\\s*invoke\\s+name=\"[a-zA-Z0-9_]+\"\\s*>.*?<\\s*\\/\\s*invoke\\s*>/isu', '', $out);
                // Удаляем function_results/result XML-like блоки.
                $out = preg_replace('/<\\s*function_results\\s*>.*?<\\s*\\/\\s*function_results\\s*>/isu', '', $out);
                $out = preg_replace('/<\\s*result\\s*>.*?<\\s*\\/\\s*result\\s*>/isu', '', $out);
                // На случай фрагментированных тегов — прибираем одиночные.
                $out = preg_replace('/<\\s*\\/??\\s*(function_results|result|parameter|invoke)\\b[^>]*>/isu', '', $out);
                return trim((string) $out);
            }

            /**
             * Формирует безопасный итог, если модель зациклилась/вернула сырой markup.
             *
             * @param array<int, array<string,mixed>> $meta
             */
            private static function buildFallbackFromMeta(array $meta): string {
                if (!count($meta)) {
                    return "Не удалось получить итог модели. Повторите запрос, пожалуйста.";
                }
                $parts = [];
                $parts[] = "Не удалось завершить формулировку модели, но вот что уже подтверждено по данным сервера:";

                $scan = array_slice($meta, -25);
                $period = null;
                $houseId = null;
                $flatsCount = null;
                $eventsReturned = null;
                $errors = [];
                $mobileLines = [];
                $funnelWindows = [];
                $subscriberFound = false;

                foreach ($scan as $m) {
                    $tool = isset($m["tool"]) ? (string) $m["tool"] : "unknown_tool";
                    $result = isset($m["result"]) && is_array($m["result"]) ? $m["result"] : [];
                    if (isset($result["house_id"]) && (int) $result["house_id"] > 0) {
                        $houseId = (int) $result["house_id"];
                    }
                    if (isset($result["since_unix"], $result["until_unix"])) {
                        $period = [
                            "since" => (int) $result["since_unix"],
                            "until" => (int) $result["until_unix"],
                        ];
                    }
                    if (isset($result["flats_count"])) {
                        $flatsCount = (int) $result["flats_count"];
                    }
                    if ($tool === "plog_events_list" && isset($result["returned"])) {
                        $eventsReturned = (int) $result["returned"];
                    }
                    if (isset($result["error"])) {
                        $errors[] = $tool . ": " . (string) $result["error"];
                    }

                    if ($tool === "mobile_users_stats" && !isset($result["error"])) {
                        $hid = isset($result["house_id"]) && $result["house_id"] !== null ? (int) $result["house_id"] : 0;
                        $pgM = isset($result["pg_mobile_subscribers_distinct"]) ? (int) $result["pg_mobile_subscribers_distinct"] : null;
                        $pgD = isset($result["pg_subscribers_with_device_row"]) ? (int) $result["pg_subscribers_with_device_row"] : null;
                        $pl = array_key_exists("plog_distinct_app_phones_event4", $result) ? $result["plog_distinct_app_phones_event4"] : null;
                        $plStr = $pl === null ? "н/д" : (string) (int) $pl;
                        $su = isset($result["since_unix"]) ? (int) $result["since_unix"] : 0;
                        $uu = isset($result["until_unix"]) ? (int) $result["until_unix"] : 0;
                        $scope = $hid > 0 ? "дом " . $hid : "вся база";
                        $mobileLines[] = "• Мобильная воронка (" . $scope . ", " . self::fmtUnix($su) . " — " . self::fmtUnix($uu) .
                            "): учёток " . ($pgM !== null ? $pgM : "—") . ", с устройствами " . ($pgD !== null ? $pgD : "—") .
                            ", активных приложений (plog event=4, uniq телефонов) " . $plStr . ".";
                    }

                    if ($tool === "mobile_access_funnel" && !isset($result["error"]) && isset($result["windows"]) && is_array($result["windows"])) {
                        foreach ($result["windows"] as $w) {
                            if (!is_array($w)) {
                                continue;
                            }
                            $days = isset($w["days"]) ? (int) $w["days"] : 0;
                            $pgM = isset($w["pg_mobile_subscribers_distinct"]) && $w["pg_mobile_subscribers_distinct"] !== null
                                ? (int) $w["pg_mobile_subscribers_distinct"] : null;
                            $pgD = isset($w["pg_subscribers_with_device_row"]) && $w["pg_subscribers_with_device_row"] !== null
                                ? (int) $w["pg_subscribers_with_device_row"] : null;
                            $pl = array_key_exists("plog_distinct_app_phones_event4", $w) ? $w["plog_distinct_app_phones_event4"] : null;
                            $plStr = $pl === null ? "н/д" : (string) (int) $pl;
                            $su = isset($w["since_unix"]) ? (int) $w["since_unix"] : 0;
                            $uu = isset($w["until_unix"]) ? (int) $w["until_unix"] : 0;
                            $line = "• Окно " . ($days > 0 ? $days . " дн." : "?") . " (" . self::fmtUnix($su) . " — " . self::fmtUnix($uu) .
                                "): учёток " . ($pgM !== null ? $pgM : "—") . ", устройств " . ($pgD !== null ? $pgD : "—") .
                                ", активных (event=4) " . $plStr . ".";
                            if (!empty($w["error"])) {
                                $line .= " Ошибка: " . (string) $w["error"] . ".";
                            }
                            $funnelWindows[] = $line;
                        }
                    }

                    if ($tool === "subscriber_lookup" && !isset($result["error"]) && isset($result["subscriber"]) && is_array($result["subscriber"])) {
                        $subscriberFound = true;
                    }
                }

                if ($houseId !== null) {
                    $parts[] = "• Дом: house_id=" . $houseId . ".";
                }
                if ($period !== null) {
                    $parts[] = "• Последний зафиксированный период в инструментах: " . self::fmtUnix($period["since"]) . " — " . self::fmtUnix($period["until"]) .
                        " (unix: " . $period["since"] . "…" . $period["until"] . ").";
                }
                if ($flatsCount !== null) {
                    $parts[] = "• Количество квартир в доме: " . $flatsCount . ".";
                }
                if (count($funnelWindows)) {
                    $parts[] = "Сводка воронки мобильного доступа:";
                    foreach ($funnelWindows as $ln) {
                        $parts[] = $ln;
                    }
                } elseif (count($mobileLines)) {
                    $parts[] = "Сводка по мобильной аналитике:";
                    foreach ($mobileLines as $ln) {
                        $parts[] = $ln;
                    }
                }
                if ($subscriberFound) {
                    $parts[] = "• Запрос по абоненту (subscriber_lookup) выполнялся — при необходимости повторите узкий вопрос только по нему.";
                }
                if ($eventsReturned !== null) {
                    if ($eventsReturned > 0) {
                        $parts[] = "• Найдено событий в журнале plog: " . $eventsReturned . ".";
                    } else {
                        $parts[] = "• В журнале plog за указанный период событий не найдено (0).";
                        $parts[] = "  Возможные причины: выбран не тот период, дом без активности в plog, либо фильтр слишком узкий.";
                    }
                }
                if (count($errors)) {
                    $parts[] = "• Ошибки инструментов: " . implode("; ", $errors) . ".";
                }

                $parts[] = "Если данных в ответе мало — повторите запрос; для сравнения 7 и 30 дней достаточно одного вызова mobile_access_funnel.";
                return implode("\n", $parts);
            }

            private static function fmtUnix(int $ts): string {
                if ($ts <= 0) {
                    return "—";
                }
                return gmdate("Y-m-d H:i", $ts) . " UTC";
            }

            /**
             * Быстрый путь для сценария "паспорт дома" (house_id + период):
             * без обращения к LLM, чтобы не ждать несколько итераций/сетевых round-trip.
             *
             * @param array<int, array<string, mixed>> $messages
             * @return array<string, mixed>|null
             */
            private static function tryFastHousePassport(array $messages, $db, array $config): ?array {
                $userText = "";
                for ($i = count($messages) - 1; $i >= 0; $i--) {
                    $m = $messages[$i];
                    if (!is_array($m)) {
                        continue;
                    }
                    if ((string) (@$m["role"]) !== "user") {
                        continue;
                    }
                    $userText = trim((string) (@$m["content"] ?: ""));
                    if ($userText !== "") {
                        break;
                    }
                }
                if ($userText === "") {
                    return null;
                }
                if (!preg_match('/паспорт\\s+дома/iu', $userText)) {
                    return null;
                }
                if (!preg_match('/house_id\\s*=\\s*(\\d+)/i', $userText, $hm)) {
                    return null;
                }

                $houseId = (int) $hm[1];
                if ($houseId <= 0) {
                    return null;
                }

                $days = 7;
                if (preg_match('/период\\s*[:=]?\\s*(?:последние\\s*)?(\\d+)\\s*д/iu', $userText, $dm)) {
                    $days = (int) $dm[1];
                }
                if ($days < 1) {
                    $days = 1;
                }
                if ($days > 60) {
                    $days = 60;
                }

                $until = time();
                $since = $until - $days * 86400;

                $flats = assistant_tools_run("flats_count", [
                    "house_id" => $houseId,
                ], $db, $config);
                $funnel = assistant_tools_run("mobile_access_funnel", [
                    "house_id" => $houseId,
                    "periods_days" => [$days],
                    "until_unix" => $until,
                ], $db, $config);
                $events = assistant_tools_run("plog_events_list", [
                    "house_id" => $houseId,
                    "since_unix" => $since,
                    "until_unix" => $until,
                    "limit" => 25,
                ], $db, $config);

                $meta = [
                    ["tool" => "flats_count", "args" => ["house_id" => $houseId], "result" => $flats],
                    ["tool" => "mobile_access_funnel", "args" => ["house_id" => $houseId, "periods_days" => [$days], "until_unix" => $until], "result" => $funnel],
                    ["tool" => "plog_events_list", "args" => ["house_id" => $houseId, "since_unix" => $since, "until_unix" => $until, "limit" => 25], "result" => $events],
                ];

                $lines = [];
                $lines[] = "Паспорт дома #" . $houseId . " за " . $days . " дн. (" . self::fmtUnix($since) . " — " . self::fmtUnix($until) . ")";
                $lines[] = "";

                if (!isset($flats["error"])) {
                    $fc = isset($flats["flats_count"]) ? (int) $flats["flats_count"] : 0;
                    $lines[] = "1) Квартиры";
                    $lines[] = "• Всего квартир: " . $fc . ".";
                } else {
                    $lines[] = "1) Квартиры";
                    $lines[] = "• Ошибка: " . (string) $flats["error"] . ".";
                }

                $lines[] = "";
                $lines[] = "2) Мобильная воронка";
                $window = null;
                if (isset($funnel["windows"]) && is_array($funnel["windows"]) && count($funnel["windows"])) {
                    $window = $funnel["windows"][0];
                }
                if (is_array($window) && !isset($window["error"])) {
                    $acc = isset($window["pg_mobile_subscribers_distinct"]) ? (int) $window["pg_mobile_subscribers_distinct"] : 0;
                    $dev = isset($window["pg_subscribers_with_device_row"]) ? (int) $window["pg_subscribers_with_device_row"] : 0;
                    $act = isset($window["plog_distinct_app_phones_event4"]) && $window["plog_distinct_app_phones_event4"] !== null
                        ? (int) $window["plog_distinct_app_phones_event4"] : null;
                    $lines[] = "• Учёток: " . $acc . ".";
                    $lines[] = "• С устройствами: " . $dev . ".";
                    $lines[] = "• Активных в plog (event=4): " . ($act === null ? "н/д" : (string) $act) . ".";
                } else {
                    $lines[] = "• Ошибка: " . (is_array($window) && isset($window["error"]) ? (string) $window["error"] : "данные недоступны") . ".";
                }

                $lines[] = "";
                $lines[] = "3) События доступа (plog)";
                if (!isset($events["error"])) {
                    $ret = isset($events["returned"]) ? (int) $events["returned"] : 0;
                    $lines[] = "• Найдено событий: " . $ret . ".";
                    if ($ret > 0 && isset($events["events"][0]) && is_array($events["events"][0])) {
                        $e0 = $events["events"][0];
                        $lines[] = "• Последнее событие: " .
                            (isset($e0["event_name_ru"]) ? (string) $e0["event_name_ru"] : "событие") .
                            ", flat_id=" . (isset($e0["flat_id"]) ? (int) $e0["flat_id"] : 0) .
                            ", ts=" . (isset($e0["timestamp_unix"]) ? self::fmtUnix((int) $e0["timestamp_unix"]) : "—") . ".";
                    }
                } else {
                    $lines[] = "• Ошибка: " . (string) $events["error"] . ".";
                }

                $lines[] = "";
                $lines[] = "Источник: быстрый профиль отчёта (без LLM), чтобы не ждать длинный цикл модели.";

                return [
                    "reply" => implode("\n", $lines),
                    "iterations" => 0,
                    "meta" => $meta,
                    "fast_path" => "house_passport",
                ];
            }

            public static function index() {
                return [
                    "POST" => false,
                ];
            }
        }
    }

<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;
        use DiagnosticsTelegramNotifier;

        /**
         * Опрос getUpdates после «Ждать чат»: /start → запись chat_id и username в config.json.
         */
        class telegramWait extends api {

            public static function GET($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';

                global $config;
                $tc = DiagnosticsTelegramNotifier::telegramNotifyConfig($config);
                if ($tc['bot_token'] === '') {
                    return api::ANSWER(false, 'badRequest');
                }
                // Короткие таймауты curl + без длинных цепочек — иначе nginx отдаёт 504 (fastcgi_read_timeout).
                $tc['_telegram_wait_ui_fast'] = true;

                $redis = $params['_redis'] ?? null;
                if (!$redis) {
                    return api::ANSWER(['active' => false, 'error' => 'no_redis'], 'telegramWait');
                }

                $now = time();
                $until = (int)$redis->get(DiagnosticsTelegramNotifier::REDIS_KEY_WAIT_UNTIL);
                if ($until < $now) {
                    return api::ANSWER([
                        'active' => false,
                        'secondsLeft' => 0,
                    ], 'telegramWait');
                }

                $armAt = (int)$redis->get(DiagnosticsTelegramNotifier::REDIS_KEY_ARM_AT);
                if ($armAt <= 0) {
                    $armAt = $now;
                }

                $offRaw = $redis->get(DiagnosticsTelegramNotifier::REDIS_KEY_POLL_OFFSET);
                $offset = ($offRaw !== false && $offRaw !== null && $offRaw !== '') ? (int)$offRaw : null;

                /** POST telegramArmWait только ставит флаг — без этого webhook не снимается и getUpdates пустой. */
                $whTry = DiagnosticsTelegramNotifier::runPendingDeleteWebhookIfAny($redis, $tc);
                $pendingWebhookDeleted = is_array($whTry) && !empty($whTry['ok']);
                $pendingWebhookError = '';
                if (is_array($whTry) && empty($whTry['ok'])) {
                    $pendingWebhookError = (string)(($whTry['description'] ?? '') !== '' ? $whTry['description'] : ($whTry['error'] ?? ''));
                }

                $gu = DiagnosticsTelegramNotifier::getUpdatesForWaitChat($tc, $offset, $redis);
                if (empty($gu['ok'])) {
                    return api::ANSWER([
                        'active' => true,
                        'secondsLeft' => max(0, $until - $now),
                        'pollError' => $gu['error'] ?? 'getUpdates_failed',
                        'pollDescription' => $gu['description'] ?? '',
                        'pendingWebhookDeleted' => $pendingWebhookDeleted,
                        'pendingWebhookError' => $pendingWebhookError,
                    ], 'telegramWait');
                }

                $results = $gu['result'] ?? [];
                usort($results, static function ($a, $b) {
                    return ((int)($a['update_id'] ?? 0)) <=> ((int)($b['update_id'] ?? 0));
                });

                $added = [];
                $configWriteErrors = [];
                /** Последний update_id, который можно подтвердить в Telegram (не сдвигаем offset при ошибке записи config). */
                $lastAckedId = null;
                foreach ($results as $u) {
                    $uid = (int)($u['update_id'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    $msg = $u['message'] ?? ($u['edited_message'] ?? null);
                    if (!is_array($msg)) {
                        $lastAckedId = $uid;
                        continue;
                    }
                    $text = trim((string)($msg['text'] ?? ''));
                    // /start, /start@BotName — без учёта регистра; не цепляемся к strpos из‑за редких префиксов/пробелов в клиентах
                    if ($text === '' || !preg_match('/^\/start(?:@[^\s@]+)?(?:\s|$|\r|\n)/iu', $text)) {
                        $lastAckedId = $uid;
                        continue;
                    }
                    $date = (int)($msg['date'] ?? 0);
                    // Только явно «старые» апдейты (до вооружения); ±2 мин на рассинхрон часов сервера и Telegram
                    if ($date > 0 && $date < ($armAt - 120)) {
                        $lastAckedId = $uid;
                        continue;
                    }
                    $chatId = isset($msg['chat']['id']) ? (string)$msg['chat']['id'] : '';
                    if ($chatId === '') {
                        $lastAckedId = $uid;
                        continue;
                    }
                    $un = null;
                    if (!empty($msg['from']['username']) && is_string($msg['from']['username'])) {
                        $un = $msg['from']['username'];
                    }
                    $wr = DiagnosticsTelegramNotifier::appendRecipientToConfigFile($chatId, $un);
                    if (!empty($wr['ok'])) {
                        $added[] = [
                            'chat_id' => $chatId,
                            'username' => $un,
                        ];
                        $lastAckedId = $uid;
                        continue;
                    }
                    $configWriteErrors[] = [
                        'chat_id' => $chatId,
                        'error' => (string)($wr['error'] ?? 'config_write_failed'),
                    ];
                    break;
                }

                if ($lastAckedId !== null && $lastAckedId > 0) {
                    try {
                        $redis->setex(
                            DiagnosticsTelegramNotifier::REDIS_KEY_POLL_OFFSET,
                            300,
                            (string)($lastAckedId + 1)
                        );
                    } catch (\Throwable $e) {
                        error_log('telegramWait offset: ' . $e->getMessage());
                    }
                }

                return api::ANSWER([
                    'active' => true,
                    'secondsLeft' => max(0, $until - $now),
                    'added' => $added,
                    'configWriteErrors' => $configWriteErrors,
                    'pendingWebhookDeleted' => $pendingWebhookDeleted,
                    'pendingWebhookError' => $pendingWebhookError,
                ], 'telegramWait');
            }

            public static function index() {
                return [
                    'GET' => '#same(diagnostics,run,GET)',
                ];
            }
        }
    }

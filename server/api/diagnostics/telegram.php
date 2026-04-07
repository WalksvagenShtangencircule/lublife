<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsTelegramNotifier;

        /**
         * Действия с Telegram (тест, ожидание чата, учебный алерт) — отдельное право в матрице API.
         */
        class telegram extends api {

            public static function POST($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';
                global $config;

                $action = $params['action'] ?? '';
                if ($action !== null && !is_string($action)) {
                    $action = (string)$action;
                }
                if ($action === '' && isset($_GET['action'])) {
                    $action = (string)$_GET['action'];
                }
                if (!is_string($action) || $action === '') {
                    return api::ANSWER(false, 'badRequest');
                }

                if ($action === 'telegramArmWait') {
                    $tc = DiagnosticsTelegramNotifier::telegramNotifyConfig($config);
                    if ($tc['bot_token'] === '') {
                        return api::ANSWER([
                            'ok' => false,
                            'did' => 'telegramArmWait',
                            'telegramError' => 'no_bot_token',
                        ], 'diagnosticsAction');
                    }
                    if (!isset($params['_redis']) || !is_object($params['_redis'])) {
                        return api::ANSWER([
                            'ok' => false,
                            'did' => 'telegramArmWait',
                            'telegramError' => 'redis_unavailable',
                        ], 'diagnosticsAction');
                    }
                    $now = time();
                    try {
                        $params['_redis']->setex(DiagnosticsTelegramNotifier::REDIS_KEY_WAIT_UNTIL, 300, (string)($now + 180));
                        $params['_redis']->setex(DiagnosticsTelegramNotifier::REDIS_KEY_ARM_AT, 300, (string)$now);
                        $params['_redis']->del(DiagnosticsTelegramNotifier::REDIS_KEY_POLL_OFFSET);
                    } catch (\Throwable $e) {
                        error_log('telegramArmWait: ' . $e->getMessage());
                        return api::ANSWER([
                            'ok' => false,
                            'did' => 'telegramArmWait',
                            'telegramError' => 'redis_write_failed',
                        ], 'diagnosticsAction');
                    }
                    DiagnosticsTelegramNotifier::markPendingDeleteWebhook($params['_redis']);
                    return api::ANSWER([
                        'ok' => true,
                        'did' => 'telegramArmWait',
                        'until' => $now + 180,
                        'telegramWebhookDeleted' => false,
                        'telegramWebhookError' => '',
                        'telegramWebhookDeferred' => true,
                    ], 'diagnosticsAction');
                }

                if ($action === 'testTelegram') {
                    $r = DiagnosticsTelegramNotifier::sendTestMessage($config);
                    if (!empty($r['ok'])) {
                        return api::ANSWER(['ok' => true, 'did' => 'testTelegram'], 'diagnosticsAction');
                    }
                    $payload = [
                        'ok' => false,
                        'did' => 'testTelegram',
                        'telegramError' => $r['error'] ?? 'send_failed',
                    ];
                    if (!empty($r['telegram_description'])) {
                        $payload['telegramApiDescription'] = $r['telegram_description'];
                    }
                    return api::ANSWER($payload, 'diagnosticsAction');
                }

                if ($action === 'telegramSimulateAlert') {
                    $r = DiagnosticsTelegramNotifier::sendSimulatedDiagnosticAlert($config);
                    if (!empty($r['ok'])) {
                        return api::ANSWER(['ok' => true, 'did' => 'telegramSimulateAlert'], 'diagnosticsAction');
                    }
                    return api::ANSWER([
                        'ok' => false,
                        'did' => 'telegramSimulateAlert',
                        'telegramError' => $r['error'] ?? 'send_failed',
                    ], 'diagnosticsAction');
                }

                return api::ANSWER(false, 'badRequest');
            }

            public static function index() {
                return [
                    'POST' => '#same(diagnostics,run,GET)',
                ];
            }
        }
    }

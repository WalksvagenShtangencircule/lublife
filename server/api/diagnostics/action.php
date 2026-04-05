<?php

    namespace api\diagnostics {

        use api\api;
        use DiagnosticsService;
        use DiagnosticsTelegramNotifier;

        class action extends api {

            public static function POST($params) {
                require_once __DIR__ . '/../../utils/DiagnosticsService.php';

                $action = $params['action'] ?? '';
                if (!is_string($action) || $action === '') {
                    return api::ANSWER(false, 'badRequest');
                }

                if ($action === 'clearFrontCache') {
                    if (!DiagnosticsService::assertDiagnosticsAllowed($params)) {
                        return api::ANSWER(false, 'forbidden');
                    }
                    clearCache($params['_uid'] ?? 0);
                    return api::ANSWER(['ok' => true, 'did' => 'clearFrontCache'], 'diagnosticsAction');
                }

                if ($action === 'bumpDiagnosticsCache' && !empty($params['_redis'])) {
                    try {
                        $params['_redis']->del(DiagnosticsService::CACHE_KEY_SUMMARY);
                    } catch (\Throwable $e) {
                        error_log('diagnostics bump: ' . $e->getMessage());
                    }
                    return api::ANSWER(['ok' => true, 'did' => 'bumpDiagnosticsCache'], 'diagnosticsAction');
                }

                if ($action === 'telegramArmWait') {
                    if (!DiagnosticsService::assertDiagnosticsAllowed($params)) {
                        return api::ANSWER(false, 'forbidden');
                    }
                    require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';
                    global $config;
                    $tc = DiagnosticsTelegramNotifier::telegramNotifyConfig($config);
                    if ($tc['bot_token'] === '') {
                        return api::ANSWER(false, 'badRequest');
                    }
                    if (empty($params['_redis'])) {
                        return api::ANSWER(false, 'badRequest');
                    }
                    $now = time();
                    try {
                        $params['_redis']->setex(DiagnosticsTelegramNotifier::REDIS_KEY_WAIT_UNTIL, 300, (string)($now + 180));
                        $params['_redis']->setex(DiagnosticsTelegramNotifier::REDIS_KEY_ARM_AT, 300, (string)$now);
                        $params['_redis']->del(DiagnosticsTelegramNotifier::REDIS_KEY_POLL_OFFSET);
                    } catch (\Throwable $e) {
                        error_log('telegramArmWait: ' . $e->getMessage());
                        return api::ANSWER(false, 'badRequest');
                    }
                    // deleteWebhook не вызываем здесь: запрос к Telegram через прокси может длиться долго — спиннер в UI не снимается.
                    // Снятие webhook — в GET telegramWait (runPendingDeleteWebhookIfAny).
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
                    if (!DiagnosticsService::assertDiagnosticsAllowed($params)) {
                        return api::ANSWER(false, 'forbidden');
                    }
                    require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';
                    global $config;
                    $r = DiagnosticsTelegramNotifier::sendTestMessage($config);
                    if (!empty($r['ok'])) {
                        return api::ANSWER(['ok' => true, 'did' => 'testTelegram'], 'diagnosticsAction');
                    }
                    return api::ANSWER([
                        'ok' => false,
                        'did' => 'testTelegram',
                        'telegramError' => $r['error'] ?? 'send_failed',
                    ], 'diagnosticsAction');
                }

                if ($action === 'telegramSimulateAlert') {
                    if (!DiagnosticsService::assertDiagnosticsAllowed($params)) {
                        return api::ANSWER(false, 'forbidden');
                    }
                    require_once __DIR__ . '/../../utils/DiagnosticsTelegramNotifier.php';
                    global $config;
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
                    'POST' => '#same(analytics,stats,GET)',
                ];
            }
        }
    }

<?php
/**
 * Быстрые проверки порядка маршрутов deleteWebhook / конфига Telegram-диагностики.
 * Запуск: php server/tests/diagnostics_telegram_delete_webhook_test.php
 * С реальным токеном (опционально): TG_TEST_TOKEN=123:abc php ...
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/utils/DiagnosticsTelegramNotifier.php';

function fail(string $msg): void {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

$base = [
    'diagnostics' => [
        'telegram' => [
            'enabled' => true,
            'bot_token' => '0:INVALID',
            'chats' => ['1'],
            // use_proxy false — smoke-тест без загрузки списка прокси
            'proxy' => [
                'use_proxy' => false,
                'config_url' => '',
                'fallback_direct' => true,
            ],
        ],
    ],
];

$tc = DiagnosticsTelegramNotifier::telegramNotifyConfig($base);
if (!array_key_exists('delete_webhook_direct_first', $tc)) {
    fail('нет ключа delete_webhook_direct_first в telegramNotifyConfig');
}
if (!empty($tc['delete_webhook_direct_first'])) {
    fail('delete_webhook_direct_first по умолчанию должен быть false (прокси первым при блокировках)');
}

$on = $base;
$on['diagnostics']['telegram']['proxy']['delete_webhook_direct_first'] = true;
$tcOn = DiagnosticsTelegramNotifier::telegramNotifyConfig($on);
if (empty($tcOn['delete_webhook_direct_first'])) {
    fail('delete_webhook_direct_first: true в конфиге не применился');
}

$r = DiagnosticsTelegramNotifier::deleteWebhookForLongPolling($tc);
if (!is_array($r) || !array_key_exists('ok', $r)) {
    fail('deleteWebhookForLongPolling: неверная структура ответа');
}
// Невалидный токен при доступной сети → telegram_api; без маршрута до API — curl_* (тоже норма для CI)
if (!empty($r['ok'])) {
    fail('ожидали ok=false для заведомо неверного токена');
}
$err = (string)($r['error'] ?? '');
if ($err !== 'telegram_api' && !preg_match('/^curl_\d+$/', $err)) {
    fail('ожидали error=telegram_api или curl_* , получили: ' . $err);
}

$token = getenv('TG_TEST_TOKEN');
if (is_string($token) && $token !== '') {
    $real = $base;
    $real['diagnostics']['telegram']['bot_token'] = trim($token);
    $tcR = DiagnosticsTelegramNotifier::telegramNotifyConfig($real);
    $r2 = DiagnosticsTelegramNotifier::deleteWebhookForLongPolling($tcR);
    if (empty($r2['ok'])) {
        fail('TG_TEST_TOKEN: deleteWebhook не прошёл: ' . json_encode($r2, JSON_UNESCAPED_UNICODE));
    }
    echo "OK (в т.ч. live deleteWebhook с TG_TEST_TOKEN)\n";
} else {
    echo "OK (без сети кроме короткого запроса с неверным токеном; задайте TG_TEST_TOKEN для полного deleteWebhook)\n";
}

exit(0);

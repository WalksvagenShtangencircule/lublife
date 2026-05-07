#!/usr/bin/env php
<?php
/**
 * Экспорт бизнес-данных RBT в JSON для переноса на другой сервер (без слияния по ID).
 * Запуск: php rbt_export_migration_json.php [путь_к_файлу.json]
 */

declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/config/config.json';
if (!is_readable($configPath)) {
    fwrite(STDERR, "Не найден config: {$configPath}\n");
    exit(1);
}

$config = json_decode((string)file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$dsn = $config['db']['dsn'];
$user = $config['db']['username'] ?? 'rbt';
$pass = $config['db']['password'] ?? '';

$outFile = $argv[1] ?? (dirname(__DIR__) . '/migration/rbt_migration_' . date('Y-m-d_His') . '.json');

/** Таблицы с данными домов/абонентов/адресов (без core_* и tt_* — учётные записи и заявки целевого сервера свои). */
$tables = [
    'addresses_regions', 'addresses_areas', 'addresses_cities', 'addresses_settlements',
    'addresses_streets', 'addresses_houses', 'addresses_favorites',
    'houses_domophones', 'houses_entrances', 'houses_houses_entrances',
    'houses_entrances_cmses', 'houses_entrances_flats',
    'houses_flats', 'houses_flats_subscribers', 'houses_rfids', 'houses_paths',
    'houses_cameras_houses', 'houses_cameras_flats', 'houses_cameras_subscribers',
    'houses_subscribers_mobile', 'houses_subscribers_devices', 'houses_flats_devices',
    'houses_watchers', 'houses_devices_tree', 'houses_subscribers_messages',
    'cameras', 'camera_records',
    'companies', 'providers',
    'custom_fields', 'custom_fields_options', 'custom_fields_values',
    'frs_faces', 'frs_links_faces',
    'notes',
    'subscriber_purchased_rfids', 'vendor_rfids_whitelist', 'mobile_legal_acceptances',
    'mediaserver_audit',
    'inbox',
    'plog_call_done', 'plog_door_open',
];

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$dbVersion = null;
try {
    $dbVersion = $pdo->query("SELECT var_value FROM core_vars WHERE var_name = 'dbVersion'")->fetchColumn();
} catch (Throwable $e) {
    $dbVersion = null;
}

$payload = [
    'meta' => [
        'format' => 'rbt_migration_json_v1',
        'exported_at' => gmdate('c'),
        'hostname' => php_uname('n'),
        'dbVersion' => $dbVersion,
        'notes' => [
            'Импорт должен пересоздать serial/id и перепривязать внешние ключи.',
            'Не включены таблицы core_* и tt_* — пользователи API и заявки остаются на целевом сервере.',
        ],
    ],
    'tables' => [],
];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query('SELECT * FROM ' . '"' . str_replace('"', '', $table) . '"');
        $rows = $stmt->fetchAll();
        $payload['tables'][$table] = $rows;
    } catch (Throwable $e) {
        fwrite(STDERR, "Пропуск таблицы {$table}: " . $e->getMessage() . "\n");
    }
}

$json = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT
);

if ($json === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

if (file_put_contents($outFile, $json) === false) {
    fwrite(STDERR, "Не удалось записать {$outFile}\n");
    exit(1);
}

echo "OK: {$outFile} (" . strlen($json) . " bytes)\n";

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/db.php';
$name = 'lynchstore_teste_completion';
$pdo = db();
$server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$name]);
if ($exists->fetchColumn()) { throw new RuntimeException('Banco de teste já existe. Preservado sem alterações.'); }
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$name`");
$server->exec('SET FOREIGN_KEY_CHECKS=0');
$counts = [];
foreach (['admin_users', 'categories', 'products', 'product_images', 'site_settings', 'checkout_logs'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $server->exec($ddl);
    $columns = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    $list = implode(',', array_map(static fn($c) => "`$c`", $columns));
    $rows = $pdo->query("SELECT $list FROM `$table`")->fetchAll();
    $insert = $server->prepare("INSERT INTO `$table` ($list) VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach ($rows as $row) { $insert->execute(array_values($row)); }
    $counts[$table] = count($rows);
}
$server->exec('SET FOREIGN_KEY_CHECKS=1');
$env = file_get_contents(APP_ROOT . '/.env');
$env = preg_replace('/^DB_NAME=.*$/m', 'DB_NAME=' . $name, $env);
$env = preg_replace('/^APP_BASE_URL=.*$/m', 'APP_BASE_URL=http://localhost:8000', $env);
file_put_contents(APP_ROOT . '/.env.test.local', $env);
echo json_encode(['database' => $name, 'counts' => $counts], JSON_PRETTY_PRINT) . PHP_EOL;

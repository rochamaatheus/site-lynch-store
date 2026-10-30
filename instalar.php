<?php
declare(strict_types=1);

$root = __DIR__;
if (is_file($root . '/.env') || is_file($root . '/.installed')) {
    http_response_code(403);
    exit('A loja já está configurada. O instalador está bloqueado.');
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header_remove('X-Powered-By');
session_name('LYNCHINSTALL');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'use_strict_mode' => true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$requirements = [];
if (PHP_VERSION_ID < 80300) { $requirements[] = 'PHP 8.3 ou superior'; }
foreach (['pdo_mysql', 'mbstring', 'gd', 'fileinfo', 'zip', 'xml', 'dom', 'xmlreader', 'xmlwriter', 'simplexml'] as $ext) {
    if (!extension_loaded($ext)) { $requirements[] = 'Extensão ' . $ext; }
}
if (!is_writable($root) || !is_writable($root . '/uploads')) { $requirements[] = 'Permissão de escrita na raiz e em uploads'; }
if (!is_file($root . '/install-secret.php')) { $requirements[] = 'Pacote gerado por scripts/build.php'; }
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$requirements) {
    $lock = fopen($root . '/.install-lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $error = 'Existe outra instalação em andamento. Aguarde.'; }
    else {
        try {
            if (is_file($root . '/.env')) { throw new RuntimeException('A loja já está instalada.'); }
            if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) { throw new RuntimeException('Formulário expirado. Atualize a página.'); }
            $secret = require $root . '/install-secret.php';
            if (!password_verify((string) ($_POST['key'] ?? ''), $secret)) { throw new RuntimeException('Chave de instalação inválida. Consulte o arquivo de instruções do build.'); }
            $fields = [];
            foreach (['host', 'database', 'user', 'db_password', 'url', 'admin_user', 'admin_password', 'whatsapp'] as $field) {
                $fields[$field] = (string) ($_POST[$field] ?? '');
                if (preg_match('/[\r\n\x00]/', $fields[$field])) { throw new RuntimeException('Há caracteres inválidos em um dos campos.'); }
            }
            $url = rtrim(trim($fields['url']), '/');
            $parts = parse_url($url);
            $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
            if (!filter_var($url, FILTER_VALIDATE_URL) || (!$local && ($parts['scheme'] ?? '') !== 'https') || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
                throw new RuntimeException('Use a URL HTTPS completa da loja, sem parâmetros.');
            }
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $fields['database']) || !preg_match('/^[a-zA-Z0-9_.-]{1,253}$/D', $fields['host'])) { throw new RuntimeException('Nome do banco ou servidor inválido.'); }
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $fields['admin_user']) || strlen($fields['admin_password']) < 12 || strlen($fields['admin_password']) > 72) {
                throw new RuntimeException('Use login com 3 a 50 caracteres e senha de 12 a 72 bytes.');
            }
            $whatsapp = preg_replace('/\D/', '', $fields['whatsapp']);
            if (strlen($whatsapp) === 10 || strlen($whatsapp) === 11) { $whatsapp = '55' . $whatsapp; }
            if ($whatsapp !== '' && !preg_match('/^[1-9]\d{9,14}$/D', $whatsapp)) { throw new RuntimeException('WhatsApp inválido. Use DDI e DDD ou deixe vazio para configurar depois.'); }
            $pdo = new PDO('mysql:host=' . $fields['host'] . ';dbname=' . $fields['database'] . ';charset=utf8mb4', $fields['user'], $fields['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if ($pdo->query('SHOW TABLES')->fetch()) { throw new RuntimeException('O banco precisa estar vazio. Crie um banco exclusivo para esta instalação. Nenhum dado existente foi alterado.'); }
            $schema = file_get_contents($root . '/db/schema.sql');
            $pdo->exec($schema);
            $pdo->beginTransaction();
            $catalog = json_decode(file_get_contents($root . '/db/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
            $allowed = [
                'categories' => ['id','name','slug','description','sort_order','active'],
                'products' => ['id','category_id','name','slug','short_description','description','price_cents','price_on_request','availability','active','featured','sort_order','stock_qty','show_stock','sold_out','sale_price_cents','sale_ends_on'],
                'product_images' => ['id','product_id','file_path','source_path','focal_y','alt_text','is_primary','sort_order'],
                'site_settings' => ['setting_key','setting_value'],
            ];
            // Catalogo de um build antigo (sem estoque/promocao): colunas NOT NULL
            // recebem o mesmo padrao do schema em vez de NULL.
            $defaults = ['products' => ['show_stock' => 0, 'sold_out' => 0, 'price_on_request' => 1, 'active' => 1, 'featured' => 0, 'sort_order' => 0], 'categories' => ['sort_order' => 0, 'active' => 1], 'product_images' => ['focal_y' => 15, 'is_primary' => 0, 'sort_order' => 0]];
            foreach ($allowed as $table => $columns) {
                $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                foreach ($catalog[$table] ?? [] as $row) {
                    $stmt->execute(array_map(static fn($c) => $row[$c] ?? $defaults[$table][$c] ?? null, $columns));
                }
            }
            $pdo->prepare('INSERT INTO admin_users (username,password_hash,display_name) VALUES (?,?,?)')->execute([$fields['admin_user'], password_hash($fields['admin_password'], PASSWORD_DEFAULT), $fields['admin_user']]);
            $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['whatsapp_number', $whatsapp]);
            $pdo->commit();
            $quote = static fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
            $env = "APP_ENV=production\nAPP_BASE_URL=" . $quote($url) . "\nAPP_TIMEZONE=America/Sao_Paulo\n";
            foreach (['DB_HOST' => 'host','DB_NAME' => 'database','DB_USER' => 'user','DB_PASS' => 'db_password'] as $name => $field) { $env .= $name . '=' . $quote($fields[$field]) . "\n"; }
            $handle = fopen($root . '/.env', 'x');
            if (!$handle) { throw new RuntimeException('Não foi possível salvar a configuração. Preserve o banco e contate o suporte.'); }
            @chmod($root . '/.env', 0600);
            if (fwrite($handle, $env) !== strlen($env)) {
                fclose($handle);
                // Um .env pela metade travaria o instalador sem consertar a loja: remove.
                @unlink($root . '/.env');
                throw new RuntimeException('Falha ao gravar configuração. Preserve o banco para recuperação.');
            }
            fclose($handle);
            file_put_contents($root . '/.installed', date(DATE_ATOM));
            $_SESSION = [];
            session_destroy();
            $success = true;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[Lynch install] ' . $e->getMessage());
            $error = $e instanceof PDOException ? 'Não foi possível instalar no banco. Confira os dados no hPanel. Se as tabelas já foram criadas, preserve esse banco e use outro vazio para tentar novamente.' : $e->getMessage();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar Lynch Store</title><link rel="stylesheet" href="assets/css/admin.css"></head><body>
<main class="container narrow-form"><div class="page-head"><div><p class="eyebrow">Lynch Store</p><h1><?= $success ? 'Sua loja está instalada' : 'Vamos abrir a loja?' ?></h1><p>Configure uma vez. Depois, cuide de tudo pelo painel.</p></div></div>
<?php if ($success): ?><div class="flash success">Instalação concluída e bloqueada contra reutilização.</div><a class="btn primary" href="<?= $escape($url) ?>/admin/login.php">Entrar no painel</a><p>Revise os produtos, preços e o WhatsApp antes de divulgar a loja.</p>
<?php else: ?>
<?php foreach ($requirements as $requirement): ?><div class="flash error">Necessário: <?= $escape($requirement) ?></div><?php endforeach; ?>
<?php if ($error): ?><div class="flash error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if (!$requirements): ?><form method="post" class="card">
<input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
<?php foreach ([
    'key' => ['Chave de instalação (arquivo LEIA-ME do build)', 'password', ''],
    'url' => ['URL da loja', 'url', 'https://sualoja.com.br'],
    'host' => ['Servidor MySQL', 'text', 'localhost'],
    'database' => ['Nome do banco vazio', 'text', ''],
    'user' => ['Usuário do banco', 'text', ''],
    'db_password' => ['Senha do banco', 'password', ''],
    'admin_user' => ['Seu login no painel', 'text', ''],
    'admin_password' => ['Sua senha do painel (12 a 72 bytes)', 'password', ''],
    'whatsapp' => ['WhatsApp com DDD (opcional)', 'tel', ''],
] as $name => [$label,$type,$default]): ?>
<div class="field"><label for="<?= $name ?>"><?= $label ?></label><input id="<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= $type === 'password' ? '' : $escape($_POST[$name] ?? $default) ?>" <?= in_array($name, ['whatsapp','db_password'], true) ? '' : 'required' ?> autocomplete="<?= $type === 'password' ? 'new-password' : 'off' ?>"></div>
<?php endforeach; ?>
<button class="btn primary" type="submit">Instalar loja</button></form><?php endif; endif; ?>
</main></body></html>

<?php
/**
 * Apoio do tests/security.cjs: prepara dados e le resultados no banco de TESTE.
 * Recusa qualquer banco sem _teste_ no nome. Saida sempre em JSON.
 *
 *   php tests/security-helper.php setup <sufixo>
 *   php tests/security-helper.php read <coupon_code> <stock_product_id> <order_id_desde>
 *   php tests/security-helper.php clear-limits
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('LYNCH_ENV_FILE')) { putenv('LYNCH_ENV_FILE=' . dirname(__DIR__) . '/.env.test.local'); }
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/Coupon.php';
if (!str_contains(DB_NAME, '_teste_')) { fwrite(STDERR, "Somente banco _teste_.\n"); exit(1); }

$cmd = $argv[1] ?? '';
$pdo = db();
$out = [];
if ($cmd === 'setup') {
    $s = preg_replace('/[^a-z0-9]/', '', strtolower($argv[2] ?? bin2hex(random_bytes(3))));
    $mk = static fn (string $slug, array $extra = []): int => ProductRepository::create($extra + ['name' => 'QA seg ' . $slug, 'slug' => "qa-seg-$s-$slug", 'active' => true, 'price_on_request' => false, 'price_cents' => 10000, 'availability' => 'pronta_entrega']);
    $out['product'] = $mk('preco');
    $out['stock_product'] = $mk('ultimo', ['stock_qty' => 1, 'show_stock' => true]);
    $payload = "<script>alert(1)</script>\"><img src=x onerror=alert(2)>'</script><svg onload=alert(3)>";
    $out['xss_slug'] = "qa-seg-$s-xss";
    ProductRepository::create(['name' => 'QA ' . $payload, 'slug' => $out['xss_slug'], 'active' => true, 'price_on_request' => false, 'price_cents' => 990, 'availability' => 'pronta_entrega', 'short_description' => $payload, 'description' => $payload . "\njavascript:alert(4)"]);
    $out['payload'] = $payload;
    $code = 'QAUNI' . strtoupper($s);
    CouponRepository::create(CouponRepository::parseForm(['code' => $code, 'discount_type' => 'percent', 'discount_value' => '10', 'max_uses' => '1']));
    $out['coupon'] = $code;
    $out['last_order'] = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM checkout_logs')->fetchColumn();
    $wa = (string) $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'whatsapp_number'")->fetchColumn();
    if ($wa === '') { $pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('whatsapp_number', '5511999999999') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"); }
} elseif ($cmd === 'read') {
    $stmt = $pdo->prepare('SELECT uses FROM coupons WHERE code = ?');
    $stmt->execute([(string) ($argv[2] ?? '')]);
    $out['coupon_uses'] = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ?');
    $stmt->execute([(int) ($argv[3] ?? 0)]);
    $out['stock'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM checkout_logs WHERE id > ?');
    $stmt->execute([(int) ($argv[4] ?? 0)]);
    $out['orders'] = (int) $stmt->fetchColumn();
    $out['audit'] = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE event IN ('login_falha','login_bloqueado','login_desconhecido')")->fetchColumn();
} elseif ($cmd === 'clear-limits') {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'lynch-rl-' . substr(hash('sha256', APP_ROOT . '|' . DB_NAME), 0, 12);
    $n = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) { if (@unlink($f)) { $n++; } }
    $pdo->exec("UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE username = 'qa_runner'");
    $out['cleared'] = $n;
} else {
    fwrite(STDERR, "Comando desconhecido.\n"); exit(1);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

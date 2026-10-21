<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
putenv('LYNCH_ENV_FILE=' . dirname(__DIR__) . '/.env.test.local');
require dirname(__DIR__) . '/includes/ProductImport.php';
require dirname(__DIR__) . '/includes/ProductRepository.php';
require dirname(__DIR__) . '/includes/Cart.php';
require dirname(__DIR__) . '/includes/WhatsAppMessage.php';
require dirname(__DIR__) . '/includes/OrderRepository.php';
if (!str_contains(DB_NAME, '_teste_')) { throw new RuntimeException('Testes exigem banco _teste_.'); }
$passed = [];
$check = static function (bool $ok, string $label) use (&$passed): void { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } $passed[] = $label; };
$throws = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable) { return true; } };
foreach (['129,90' => 12990, '1.299,90' => 129990, '129.90' => 12990, '0,01' => 1, '1.234' => 123400, 'R$ 20' => 2000] as $input => $expected) {
    $check(price_to_cents($input) === $expected, 'Preço exato: ' . $input);
}
foreach (['-10', 'abc12', '1,2,3', '1.2345', '9999999999', ''] as $input) { $check(price_to_cents($input) === null, 'Preço rejeitado: ' . $input); }
$check(slugify('Camiseta de Algodão!') === 'camiseta-de-algodao', 'Slug com acentos');
$check(safe_local_redirect('https://evil.test') === null, 'Redirect externo recusado');
$check(safe_admin_redirect('//evil.test') === null, 'Redirect admin externo recusado');
$check(!csrf_check('invalido'), 'CSRF inválido recusado');
$token = csrf_token();
$check(csrf_check($token), 'CSRF válido aceito');
$suffix = bin2hex(random_bytes(5));
$row = ['Teste QA ' . $suffix, 'qa-' . $suffix, 'QA temporário', '129,90', 'pronta_entrega', 'Resumo', 'Detalhes', '0', '1'];
$parsed = ProductImport::normalize([ProductImport::HEADERS, $row]);
$check(!$parsed['errors'] && $parsed['products'][0]['price_cents'] === 12990, 'Planilha normalizada');
$invalid = ProductImport::normalize([ProductImport::HEADERS, $row, $row]);
$check(count($invalid['errors']) === 1, 'Duplicidade na planilha detectada');
$bad = $row; $bad[0] = '=HYPERLINK("https://evil.test")';
$check(count(ProductImport::normalize([ProductImport::HEADERS, $bad])['errors']) === 1, 'Fórmula recusada');
$bad = $row; $bad[3] = '-2';
$check(count(ProductImport::normalize([ProductImport::HEADERS, $bad])['errors']) === 1, 'Preço negativo de importação recusado');
$result = ProductImport::apply($parsed['products'], 'create', []);
$check($result['created'] === 1, 'Importação cria produto');
$expected = ProductImport::existing($parsed['products']);
$parsed['products'][0]['name'] .= ' atualizado';
$result = ProductImport::apply($parsed['products'], 'upsert', $expected);
$check($result['updated'] === 1, 'Importação atualiza por slug');
$check($throws(fn() => ProductImport::apply($parsed['products'], 'upsert', $expected)), 'Prévia antiga recusada');
$check($throws(fn() => ProductImport::apply($parsed['products'], 'create', ProductImport::existing($parsed['products']))), 'Modo criar não sobrescreve produto');
$before = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$many = [];
for ($i = 0; $i < 51; $i++) {
    $p = $parsed['products'][0]; $p['slug'] = "qa-rollback-$suffix-$i"; $many[] = $p;
}
$many[50]['name'] = str_repeat('x', 181);
db()->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$check($throws(fn() => ProductImport::apply($many, 'create', [])), 'Falha no segundo lote recusa importação');
$check((int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn() === $before, 'Rollback desfaz primeiro lote');

$book = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$book->getActiveSheet()->fromArray([ProductImport::HEADERS, $row], null, 'A1', true);
if (!is_dir(__DIR__ . '/fixtures')) { mkdir(__DIR__ . '/fixtures', 0700, true); }
$xlsx = __DIR__ . '/fixtures/import.xlsx';
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($xlsx);
$check(count(ProductImport::read($xlsx, 'xlsx')['products']) === 1, 'Leitura real de Excel XLSX');
file_put_contents(__DIR__ . '/fixtures/import.csv', "\xEF\xBB\xBF" . implode(';', ProductImport::HEADERS) . "\n" . implode(';', $row) . "\n");
$check(count(ProductImport::read(__DIR__ . '/fixtures/import.csv', 'csv')['products']) === 1, 'CSV com BOM e ponto e vírgula');
$book->disconnectWorksheets();

$pdo = db();
$pdo->beginTransaction();
try {
    $cat = $pdo->prepare('INSERT INTO categories (name,slug) VALUES (?,?)');
    $cat->execute(['QA paginação', 'qa-paginacao-' . $suffix]);
    $category = (int) $pdo->lastInsertId();
    for ($i = 0; $i < 110; $i++) {
        ProductRepository::create(['name' => 'QA paginação ' . $i, 'slug' => "qa-page-$suffix-$i", 'category_id' => $category, 'active' => true, 'price_on_request' => false, 'price_cents' => 1990, 'availability' => 'pronta_entrega']);
    }
    $seen = []; $cursor = '';
    do {
        $page = ProductRepository::listActive(['category_id' => $category, 'cursor' => $cursor, 'limit' => 8]);
        foreach ($page as $product) { $seen[] = $product['id']; }
        if ($page) { $last = end($page); $cursor = base64_encode(json_encode([$last['sort_order'], $last['id']])); }
    } while ($page);
    $check(count($seen) === 110 && count(array_unique($seen)) === 110, 'Paginação percorre 110 produtos sem duplicar ou perder');
    cart_store(array_fill_keys(array_slice($seen, 0, 8), 2));
    $q0 = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $items = cart_items();
    $q1 = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $check($q1 - $q0 - 1 === 1, 'Carrinho de 8 produtos usa uma consulta');
    $check(count($items) === 8 && cart_total_cents($items) === 31840, 'Total do carrinho em centavos');
    ProductRepository::softDelete($seen[0]);
    cart_flush_cache();
    $check(count(cart_items()) === 7, 'Produto desativado sai do carrinho');
    $message = WhatsAppMessage::build(cart_items());
    $check(str_contains($message, 'QA paginação'), 'Mensagem usa os produtos reais');
    $check(WhatsAppMessage::url($items, ['number' => '']) === null, 'Checkout não gera WhatsApp vazio');
    $check(str_starts_with(WhatsAppMessage::url($items, ['number' => '5511999999999']), 'https://wa.me/5511999999999?text='), 'URL WhatsApp válida sem enviar mensagem');
} finally { $pdo->rollBack(); cart_clear(); }

$password = bin2hex(random_bytes(14));
$pdo->prepare('INSERT INTO admin_users (username,password_hash,display_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),active=1,failed_attempts=0,locked_until=NULL')->execute(['qa_runner', password_hash($password, PASSWORD_DEFAULT), 'QA']);
file_put_contents(dirname(__DIR__) . '/.qa-credentials.json', json_encode(['username' => 'qa_runner', 'password' => $password]));
$img = imagecreatetruecolor(400, 500);
imagefill($img, 0, 0, imagecolorallocate($img, 166, 78, 46));
imagepng($img, __DIR__ . '/fixtures/upload.png');
imagedestroy($img);
$plans = [];
$queries = [
    'cart_before_single' => 'SELECT id,name,slug FROM products WHERE active=1 AND id=1',
    'cart_after_batch' => 'SELECT id,name,slug FROM products WHERE active=1 AND id IN (1,2,3,4,5,6,7,8)',
    'catalog_cursor' => 'SELECT id,sort_order FROM products WHERE active=1 AND (sort_order>0 OR (sort_order=0 AND id>8)) ORDER BY sort_order,id LIMIT 9',
    'import_slug_lookup' => "SELECT slug FROM products WHERE slug IN ('peca-de-cosplay-01','camiseta-geek-20')",
    'orders_cursor' => 'SELECT id,item_count,total_cents,status FROM checkout_logs WHERE id<100 ORDER BY id DESC LIMIT 31',
    'dashboard_missing_photos' => 'SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)',
];
foreach ($queries as $label => $sql) { $plans[$label] = $pdo->query('EXPLAIN ' . $sql)->fetchAll(); }
file_put_contents(dirname(__DIR__) . '/docs/explain.json', json_encode($plans, JSON_PRETTY_PRINT));
file_put_contents(dirname(__DIR__) . '/docs/php-tests.json', json_encode(['database' => DB_NAME, 'passed' => $passed, 'count' => count($passed), 'at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($passed) . " verificações PHP passaram.\n";

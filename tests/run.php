<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
// Banco de teste: o do LYNCH_ENV_FILE informado, ou o padrao .env.test.local.
if (!getenv('LYNCH_ENV_FILE')) { putenv('LYNCH_ENV_FILE=' . dirname(__DIR__) . '/.env.test.local'); }
require_once dirname(__DIR__) . '/includes/ProductImport.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/Cart.php';
require_once dirname(__DIR__) . '/includes/WhatsAppMessage.php';
require_once dirname(__DIR__) . '/includes/OrderRepository.php';
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

// ---------------------------------------------------------------------------
// Estoque, promocao, destaque e esgotado
// ---------------------------------------------------------------------------
$check(ProductRepository::stockReady(), 'Banco de teste com colunas de estoque e promoção');
$check(sale_percent(10000, 7990) === 20 && parse_date_br('30/11/2026') === '2026-11-30' && parse_date_br('31/02/2026') === null, 'Desconto e datas calculados');
$pdo->beginTransaction();
try {
    $mk = static fn (string $slug, array $extra = []): int => ProductRepository::create($extra + ['name' => 'QA estoque ' . $slug, 'slug' => "qa-st-$suffix-$slug", 'active' => true, 'price_on_request' => false, 'price_cents' => 10000, 'availability' => 'pronta_entrega']);
    $promo = $mk('promo');
    [$cents] = ProductRepository::parseSale(['price_cents' => 10000, 'price_on_request' => false], '', '25', '');
    $check($cents === 7500, 'Promoção por porcentagem calcula o preço (25% de R$ 100,00 = R$ 75,00)');
    $p = ProductRepository::quickAction($promo, 'sale_set', ['sale_price' => '79,90', 'sale_ends_on' => date('Y-m-d', strtotime('+7 days'))]);
    $view = product_price_view($p);
    $check($p['sale_active'] && $p['current_price_cents'] === 7990 && $view['old'] === 'R$ 100,00' && $view['now'] === 'R$ 79,90' && $view['percent'] === 20, 'Promoção vigente: preço novo, antigo riscado e 20%');
    $check($throws(fn() => ProductRepository::quickAction($promo, 'sale_set', ['sale_price' => '100,00'])), 'Promoção igual ou acima do preço recusada');
    $check($throws(fn() => ProductRepository::quickAction($promo, 'sale_set', ['sale_price' => '50', 'sale_ends_on' => '2020-01-01'])), 'Data final no passado recusada');
    $onReq = $mk('consulta', ['price_on_request' => true, 'price_cents' => null]);
    try { ProductRepository::quickAction($onReq, 'sale_set', ['sale_percent' => '10']); $msg = ''; } catch (DomainException $e) { $msg = $e->getMessage(); }
    $check(str_contains($msg, 'Consultar valor'), 'Produto sob consulta não entra em promoção, com mensagem clara');
    $check($throws(fn() => $pdo->exec("UPDATE products SET sale_price_cents = 20000 WHERE id = $promo")), 'CHECK do banco recusa promoção acima do preço');
    $pdo->prepare('UPDATE products SET sale_ends_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $promo]);
    $p = ProductRepository::findAnyById($promo);
    $check(!$p['sale_active'] && $p['sale_expired'] && $p['current_price_cents'] === 10000, 'Promoção vencida volta ao preço normal sozinha');
    $p = ProductRepository::quickAction($promo, 'sale_off');
    $check($p['sale_price_cents'] === null && $p['sale_ends_on'] === null, 'Tirar promoção limpa preço e data');

    $stock = $mk('estoque', ['stock_qty' => 1, 'show_stock' => true]);
    $p = ProductRepository::quickAction($stock, 'stock_dec');
    $check($p['stock_qty'] === 0 && $p['is_sold_out'], 'Estoque em 0 vira esgotado automaticamente');
    $p = ProductRepository::quickAction($stock, 'stock_inc');
    $check($p['stock_qty'] === 1 && !$p['is_sold_out'], 'Repor estoque devolve à venda');
    $p = ProductRepository::quickAction($stock, 'sold_out');
    $check($p['is_sold_out'] && $p['stock_qty'] === 1, 'Marcar como esgotado sem perder o número do estoque');
    ProductRepository::quickAction($stock, 'stock_set', ['stock' => '0']);
    $p = ProductRepository::quickAction($stock, 'reactivate');
    $check(!$p['is_sold_out'] && $p['stock_qty'] === 1, 'Reativar peça zerada volta com 1 no estoque');
    $p = ProductRepository::quickAction($stock, 'stock_set', ['stock' => '']);
    $check($p['stock_qty'] === null, 'Estoque vazio = sem controle');
    $check($throws(fn() => ProductRepository::quickAction($stock, 'stock_set', ['stock' => '-3'])), 'Estoque negativo recusado');
    $p = ProductRepository::quickAction($stock, 'featured', ['value' => 1]);
    $check($p['featured'] === true, 'Destaque ligado pela ação rápida');

    // Sacola e checkout validados no servidor
    ProductRepository::quickAction($stock, 'stock_set', ['stock' => '2']);
    $out = $mk('esgotado', ['sold_out' => true]);
    $sale = $mk('sacola');
    ProductRepository::quickAction($sale, 'sale_set', ['sale_percent' => '10']);
    cart_store([$stock => 5, $out => 1, $sale => 2]);
    $items = array_column(cart_items(), null, 'id');
    $check($items[$stock]['qty'] === 2 && $items[$stock]['qty_adjusted'], 'Sacola ajusta a quantidade ao estoque');
    $check($items[$out]['sold_out'] && $items[$out]['subtotal_cents'] === null && cart_sold_out_items() !== [], 'Esgotado na sacola não soma e bloqueia o envio');
    $check($items[$sale]['price_cents'] === 9000 && $items[$sale]['subtotal_cents'] === 18000 && cart_total_cents() === 38000, 'Sacola usa o preço promocional vigente');
    $msg = WhatsAppMessage::build(cart_items());
    $check(!str_contains($msg, 'QA estoque esgotado') && str_contains($msg, 'R$ 90,00 (promoção, de R$ 100,00)'), 'Mensagem do WhatsApp sem esgotado e com preço promocional');
    $check(cart_room_for(ProductRepository::findById($out)) === 0 && cart_room_for(ProductRepository::findById($stock)) === 0, 'Servidor não deixa somar esgotado nem passar do estoque');

    // Abas e contagens
    $low = 3;
    $counts = ProductRepository::adminTabCounts(['search' => "qa-st-$suffix"], $low);
    $list = static fn (string $tab): int => count(ProductRepository::listAll(['search' => "qa-st-$suffix", 'tab' => $tab, 'low' => $low]));
    $check($counts['todos'] === 5 && $counts['esgotado'] === $list('esgotado') && $counts['promocao'] === $list('promocao') && $counts['baixo'] === $list('baixo') && $counts['destaque'] === $list('destaque') && $counts['visivel'] === $list('visivel'), 'Contagem das abas bate com a lista filtrada');
    $check($counts['esgotado'] === 1 && $counts['promocao'] === 1 && $counts['baixo'] === 1 && $counts['visivel'] === 1, 'Abas Esgotados, Em promoção, Estoque baixo e Estoque visível');
} finally { $pdo->rollBack(); cart_clear(); }

// Importacao com as colunas novas (fora da transacao: apply abre a sua)
$headersNew = ProductImport::HEADERS;
$rowNew = ['QA promo ' . $suffix, 'qa-promo-' . $suffix, 'QA temporário', '100,00', 'pronta_entrega', '', '', '0', '1', '4', '1', '80,00', '30/12/2099', '0'];
$parsedNew = ProductImport::normalize([$headersNew, $rowNew]);
$check(!$parsedNew['errors'] && $parsedNew['products'][0]['stock_qty'] === 4 && $parsedNew['products'][0]['sale_price_cents'] === 8000 && $parsedNew['products'][0]['sale_ends_on'] === '2099-12-30', 'Planilha com estoque e promoção normalizada');
$bad = $rowNew; $bad[11] = '120,00';
$check(count(ProductImport::normalize([$headersNew, $bad])['errors']) === 1, 'Planilha com promoção acima do preço recusada');
$bad = $rowNew; $bad[3] = '';
$check(count(ProductImport::normalize([$headersNew, $bad])['errors']) === 1, 'Planilha com promoção sem preço recusada');
ProductImport::apply($parsedNew['products'], 'create', []);
$oldHeaders = array_slice($headersNew, 0, 9);
$parsedOld = ProductImport::normalize([$oldHeaders, array_slice($rowNew, 0, 9)]);
$check(ProductImport::stockColumns($parsedOld['products']) === [], 'Planilha antiga não traz colunas de estoque');
ProductImport::apply($parsedOld['products'], 'upsert', ProductImport::existing($parsedOld['products']));
$after = ProductRepository::findBySlug('qa-promo-' . $suffix) ?? ProductRepository::findAnyById((int) db()->query("SELECT id FROM products WHERE slug = 'qa-promo-$suffix'")->fetchColumn());
$check($after['stock_qty'] === 4 && $after['sale_price_cents'] === 8000 && $after['show_stock'], 'Planilha antiga atualiza sem apagar estoque e promoção');
$cheaper = $parsedOld['products']; $cheaper[0]['price_cents'] = 7000;
ProductImport::apply($cheaper, 'upsert', ProductImport::existing($cheaper));
$after = ProductRepository::findAnyById((int) $after['id']);
$check($after['price_cents'] === 7000 && $after['sale_price_cents'] === null, 'Preço novo abaixo da promoção antiga tira a promoção na importação');
$csvNew = __DIR__ . '/fixtures/import-estoque.csv';
file_put_contents($csvNew, implode(';', $headersNew) . "\n" . implode(';', array_replace($rowNew, [1 => 'qa-promo-csv-' . $suffix])) . "\n");
$check(ProductImport::read($csvNew, 'csv')['products'][0]['stock_qty'] === 4, 'CSV com colunas novas lido');

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
    'admin_tab_counts' => "SELECT COUNT(*), SUM(p.sale_price_cents IS NOT NULL AND (p.sale_ends_on IS NULL OR p.sale_ends_on >= '2026-10-30')), SUM(p.featured = 1), SUM(p.sold_out = 0 AND p.stock_qty BETWEEN 1 AND 3), SUM(p.sold_out = 1 OR p.stock_qty = 0), SUM(p.show_stock = 1 AND p.stock_qty IS NOT NULL), SUM(p.show_stock = 0 AND p.stock_qty IS NOT NULL) FROM products p WHERE 1=1",
    'admin_tab_promocao' => "SELECT p.id,p.name,p.sale_price_cents FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1 AND p.sale_price_cents IS NOT NULL AND (p.sale_ends_on IS NULL OR p.sale_ends_on >= '2026-10-30') ORDER BY p.sort_order ASC, p.id ASC LIMIT 31",
    'admin_tab_esgotado' => 'SELECT p.id,p.name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1 AND (p.sold_out = 1 OR p.stock_qty = 0) ORDER BY p.sort_order ASC, p.id ASC LIMIT 31',
    'home_destaques' => 'SELECT p.id,p.name,p.stock_qty,p.sold_out,p.sale_price_cents FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.active = 1 AND p.featured = 1 ORDER BY p.sort_order ASC, p.id ASC LIMIT 10',
    'dashboard_missing_photos' =>'SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)',
];
foreach ($queries as $label => $sql) { $plans[$label] = $pdo->query('EXPLAIN ' . $sql)->fetchAll(); }
file_put_contents(dirname(__DIR__) . '/docs/explain.json', json_encode($plans, JSON_PRETTY_PRINT));
file_put_contents(dirname(__DIR__) . '/docs/php-tests.json', json_encode(['database' => DB_NAME, 'passed' => $passed, 'count' => count($passed), 'at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($passed) . " verificações PHP passaram.\n";

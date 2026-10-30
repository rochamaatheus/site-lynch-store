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
require_once dirname(__DIR__) . '/includes/Upload.php';
require_once dirname(__DIR__) . '/includes/RateLimit.php';
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
    $check(!str_contains($msg, 'QA estoque esgotado') && str_contains($msg, 'Promoção: de R$ 100,00 por R$ 90,00'), 'Mensagem do WhatsApp sem esgotado e com preço promocional');
    $check(cart_room_for(ProductRepository::findById($out)) === 0 && cart_room_for(ProductRepository::findById($stock)) === 0, 'Servidor não deixa somar esgotado nem passar do estoque');

    // Abas e contagens
    $low = 3;
    $counts = ProductRepository::adminTabCounts(['search' => "qa-st-$suffix"], $low);
    $list = static fn (string $tab): int => count(ProductRepository::listAll(['search' => "qa-st-$suffix", 'tab' => $tab, 'low' => $low]));
    $check($counts['todos'] === 5 && $counts['esgotado'] === $list('esgotado') && $counts['promocao'] === $list('promocao') && $counts['baixo'] === $list('baixo') && $counts['destaque'] === $list('destaque') && $counts['visivel'] === $list('visivel'), 'Contagem das abas bate com a lista filtrada');
    $check($counts['esgotado'] === 1 && $counts['promocao'] === 1 && $counts['baixo'] === 1 && $counts['visivel'] === 1, 'Abas Esgotados, Em promoção, Estoque baixo e Estoque visível');
} finally { $pdo->rollBack(); cart_clear(); }

// ---------------------------------------------------------------------------
// Cupom, Comprar agora, mensagem do WhatsApp e dados da loja
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/StoreInfo.php';
CouponRepository::flush();
$S = strtoupper($suffix);
$check(CouponRepository::ready(), 'Banco de teste com a tabela de cupons');
$check(CouponRepository::normalizeCode(' lynch 10 ') === 'LYNCH10' && CouponRepository::normalizeCode('a!') === '' && preg_match(CouponRepository::CODE_PATTERN, CouponRepository::suggestCode()) === 1, 'Código de cupom normalizado (maiúsculo, sem espaço) e sugestão válida');
$check(CouponRepository::parseForm(['code' => 'x' . $suffix, 'discount_type' => 'fixed', 'discount_value' => '15,00', 'min_order' => '100,00', 'max_uses' => '3'])['discount_value'] === 1500
    && $throws(fn() => CouponRepository::parseForm(['code' => 'QA1', 'discount_type' => 'percent', 'discount_value' => '120']))
    && $throws(fn() => CouponRepository::parseForm(['code' => 'QA1', 'discount_type' => 'percent', 'discount_value' => '10', 'expires_on' => '2020-01-01'])),
    'Formulário do cupom: valor em reais vira centavos; 120% e validade passada recusados');
$pdo->beginTransaction();
try {
    $mkp = static fn (string $slug, array $extra = []): int => ProductRepository::create($extra + ['name' => 'QA cupom ' . $slug, 'slug' => "qa-cp-$suffix-$slug", 'active' => true, 'price_on_request' => false, 'price_cents' => 10000, 'availability' => 'pronta_entrega']);
    $a = $mkp('a');
    $b = $mkp('b');
    $c = $mkp('consulta', ['price_on_request' => true, 'price_cents' => null]);
    ProductRepository::quickAction($b, 'sale_set', ['sale_percent' => '10']);
    $pct  = CouponRepository::create(CouponRepository::parseForm(['code' => 'QAP' . $S, 'discount_type' => 'percent', 'discount_value' => '10']));
    $fix  = CouponRepository::create(CouponRepository::parseForm(['code' => 'QAF' . $S, 'discount_type' => 'fixed', 'discount_value' => '500,00']));
    $min  = CouponRepository::create(CouponRepository::parseForm(['code' => 'QAM' . $S, 'discount_type' => 'percent', 'discount_value' => '5', 'min_order' => '500,00']));
    $lim  = CouponRepository::create(CouponRepository::parseForm(['code' => 'QAL' . $S, 'discount_type' => 'percent', 'discount_value' => '5', 'max_uses' => '1']));
    $exp  = CouponRepository::create(CouponRepository::parseForm(['code' => 'QAE' . $S, 'discount_type' => 'percent', 'discount_value' => '5']));
    $pdo->prepare('UPDATE coupons SET expires_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $exp]);
    $check($throws(fn() => CouponRepository::create(CouponRepository::parseForm(['code' => 'qap' . $suffix, 'discount_type' => 'percent', 'discount_value' => '5']))), 'Código de cupom repetido recusado (UNIQUE, sem diferenciar maiúscula)');
    $check($throws(fn() => $pdo->exec("UPDATE coupons SET discount_value = 150 WHERE id = $pct")), 'CHECK do banco recusa cupom acima de 100%');

    cart_bag('cart');
    cart_store([$a => 1, $b => 1, $c => 2]);
    cart_coupon_set('QAP' . $S);
    $sum = cart_summary();
    $check($sum['subtotal_cents'] === 19000 && $sum['discount_cents'] === 1900 && $sum['total_cents'] === 17100, 'Cupom percentual sobre o subtotal já com preço promocional (R$ 100 + R$ 90, 10% = R$ 19,00)');
    $check($sum['on_request'] && $sum['on_request_count'] === 1, 'Item sob consulta fica fora do subtotal e do desconto');
    $msg = WhatsAppMessage::build(cart_items(), ['order_id' => 77, 'summary' => $sum]);
    $check(str_contains($msg, '*Pedido #77*') && str_contains($msg, 'Subtotal: R$ 190,00') && str_contains($msg, 'Cupom QAP' . $S . ' (10%): -R$ 19,00') && str_contains($msg, '*Total estimado: R$ 171,00*'), 'Mensagem do WhatsApp com pedido, subtotal, cupom e total');
    $check(str_contains($msg, '1. QA cupom a') && str_contains($msg, 'Qtd: 1 x R$ 90,00 = R$ 90,00') && str_contains($msg, 'Promoção: de R$ 100,00 por R$ 90,00') && str_contains($msg, 'Qtd: 2, valor a combinar'), 'Mensagem lista itens numerados com quantidade, promoção e valor a combinar');
    $check(str_contains($msg, 'Itens com valor a combinar (1) não entram no total nem no desconto.') && str_contains($msg, WhatsAppMessage::CLOSING) && !str_contains($msg, '—'), 'Mensagem avisa itens sob consulta, pede para combinar pagamento e entrega e não tem travessão');
    $wa = (string) WhatsAppMessage::url(cart_items(), ['number' => '5547991781461', 'order_id' => 77, 'summary' => $sum]);
    $check(str_starts_with($wa, 'https://wa.me/5547991781461?text=') && str_contains($wa, 'Promo%C3%A7%C3%A3o') && rawurldecode(substr($wa, strlen('https://wa.me/5547991781461?text='))) === $msg, 'Link do WhatsApp em UTF-8 com rawurlencode e texto íntegro');

    cart_coupon_set('QAF' . $S);
    $sum = cart_summary();
    $check($sum['discount_cents'] === 19000 && $sum['total_cents'] === 0, 'Cupom fixo maior que o subtotal zera o total sem ficar negativo');
    cart_coupon_set('QAM' . $S);
    $sum = cart_summary();
    $check($sum['coupon'] === null && str_contains((string) $sum['coupon_error'], 'Pedido mínimo de R$ 500,00') && !$sum['coupon_permanent'], 'Pedido mínimo: cupom não aplica e diz quanto falta');
    cart_coupon_set('QAE' . $S);
    $sum = cart_summary();
    $check($sum['coupon'] === null && $sum['coupon_error'] === 'Cupom expirado.' && $sum['coupon_permanent'], 'Cupom expirado recusado');
    CouponRepository::setActive($pct, false);
    $check(CouponRepository::evaluate(CouponRepository::findByCode('QAP' . $S), 19000, true)['ok'] === false, 'Cupom desativado não vale');
    CouponRepository::setActive($pct, true);
    $check(CouponRepository::claim($pdo, $lim) && !CouponRepository::claim($pdo, $lim), 'Uso contado de forma atômica e limite respeitado no banco');
    cart_coupon_set('QAL' . $S);
    $check(str_contains((string) cart_summary()['coupon_error'], 'esgotado') && cart_summary(null, true)['discount_cents'] === 950, 'Cupom esgotado recusado; a repetição do mesmo checkout ainda vale');
    $check(!CouponRepository::claim($pdo, $exp), 'Checkout não conta uso de cupom expirado');
    cart_store([$c => 1]);
    cart_coupon_set('QAP' . $S);
    $check(str_contains((string) cart_summary()['coupon_error'], 'valor a combinar'), 'Sacola só com itens sob consulta não recebe desconto');
    CouponRepository::softDelete($fix);
    $check(CouponRepository::findByCode('QAF' . $S) === null && CouponRepository::create(CouponRepository::parseForm(['code' => 'QAF' . $S, 'discount_type' => 'percent', 'discount_value' => '7'])) === $fix, 'Exclusão lógica; o código excluído pode ser criado de novo');

    // Comprar agora: sacola separada, a de sempre fica intacta
    cart_coupon_clear();
    cart_store([$a => 2]);
    cart_bag('cart_direct');
    cart_store([$b => 1]);
    cart_coupon_set('QAP' . $S);
    $direct = cart_items();
    cart_bag('cart');
    $check(count($direct) === 1 && $direct[0]['id'] === $b && cart_raw() === [$a => 2] && cart_count() === 2 && cart_coupon_code() === '', 'Comprar agora usa sacola própria e não mexe na sacola nem no cupom dela');

    // Limite de tamanho do link
    $many = [];
    for ($i = 0; $i < 60; $i++) {
        $many[] = ['name' => str_repeat('Peça com nome bem comprido ', 6) . $i, 'qty' => 1, 'price_cents' => 1000, 'price_on_request' => false, 'url' => 'http://localhost/produto.php?slug=x' . $i];
    }
    $longUrl = (string) WhatsAppMessage::url($many, ['number' => '5547991781461', 'order_id' => 1]);
    $check(strlen($longUrl) <= WhatsAppMessage::MAX_URL_LENGTH && str_contains(rawurldecode($longUrl), 'e mais') && str_contains(rawurldecode($longUrl), '*Total: R$ 600,00*'), 'Link do WhatsApp respeita o limite de tamanho e mantém o total');

    // Tentativas de cupom
    $_SESSION['coupon_fails'] = [];
    for ($i = 0; $i < CouponRepository::MAX_FAILS; $i++) { coupon_register_fail(); }
    $check(coupon_locked(), 'Cinco cupons errados seguidos bloqueiam novas tentativas por 10 minutos');
    $_SESSION['coupon_fails'] = [];
    RateLimit::clear('cupom');
    // Robo que descarta o cookie: a sessao nova nao zera o limite do endereco de rede.
    for ($i = 0; $i < CouponRepository::IP_MAX_FAILS; $i++) { RateLimit::hit('cupom', CouponRepository::FAIL_WINDOW); }
    $check(coupon_locked(), 'Cupom: sessão nova não escapa do limite por endereço de rede');
    RateLimit::clear('cupom');
    $check(!coupon_locked(), 'Cupom: limite por endereço some depois da janela (limpo no teste)');

    // Ordem por preco usa o preco vigente (promocao), o mesmo que o card mostra
    $cat->execute(['QA ordem', 'qa-ordem-' . $suffix]);
    $sortCat = (int) $pdo->lastInsertId();
    $promoId = ProductRepository::create(['name' => 'QA ordem promo', 'slug' => 'qa-ordem-promo-' . $suffix, 'category_id' => $sortCat, 'active' => true, 'price_on_request' => false, 'price_cents' => 10000, 'sale_price_cents' => 3000, 'availability' => 'pronta_entrega']);
    $plainId = ProductRepository::create(['name' => 'QA ordem normal', 'slug' => 'qa-ordem-normal-' . $suffix, 'category_id' => $sortCat, 'active' => true, 'price_on_request' => false, 'price_cents' => 5000, 'availability' => 'pronta_entrega']);
    $expiredId = ProductRepository::create(['name' => 'QA ordem vencida', 'slug' => 'qa-ordem-vencida-' . $suffix, 'category_id' => $sortCat, 'active' => true, 'price_on_request' => false, 'price_cents' => 8000, 'availability' => 'pronta_entrega']);
    $pdo->prepare('UPDATE products SET sale_price_cents = 1000, sale_ends_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-2 days')), $expiredId]);
    $asc = array_column(ProductRepository::listActive(['category_id' => $sortCat, 'sort' => 'preco_asc']), 'id');
    $desc = array_column(ProductRepository::listActive(['category_id' => $sortCat, 'sort' => 'preco_desc']), 'id');
    $check($asc === [$promoId, $plainId, $expiredId] && $desc === [$expiredId, $plainId, $promoId], 'Menor e maior preço seguem o preço vigente (promoção ativa conta, vencida não)');
} finally { $pdo->rollBack(); cart_bag('cart_direct'); cart_clear(); cart_bag('cart'); cart_clear(); }

SettingsRepository::flush();
$check(whatsapp_display('5547991781461') === '+55 47 99178-1461' && instagram_handle('https://instagram.com/lynchstore') === '@lynchstore', 'WhatsApp e Instagram exibidos como no aplicativo');
$check(store_hours_lines() === [['Segunda a sexta', '09:00 às 12:00 e 14:00 às 18:30'], ['Sábado', '09:00 às 12:00'], ['Domingo', 'Fechado']] && store_address_lines() === ['Avenida Júpiter, 146, Jardim Paraíso', 'Joinville - SC, CEP 89226-600'], 'Horário e endereço da Lynch lidos das configurações');
$ld = store_jsonld();
$check($ld['address']['postalCode'] === '89226-600' && count($ld['openingHoursSpecification']) === 3 && $ld['telephone'] === '+55 47 99178-1461' && !isset($ld['email']), 'JSON-LD com endereço, horário e telefone, sem e-mail');
$check(store_hours('{"seg_sex":[["12:00","09:00"],["xx","10:00"]]}')['seg_sex'] === [], 'Horário inválido no banco é ignorado na vitrine');

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

// --- Seguranca: limite por endereco de rede, memoria de upload, metadados ---
$rl = 'qa' . $suffix;
$check(!RateLimit::tooMany($rl, 3, 60, 'cliente-a'), 'Limite por IP começa livre');
for ($i = 0; $i < 3; $i++) { RateLimit::hit($rl, 60, 'cliente-a'); }
$check(RateLimit::tooMany($rl, 3, 60, 'cliente-a') && !RateLimit::tooMany($rl, 3, 60, 'cliente-b'), 'Limite por IP conta cada endereço separado');
RateLimit::clear($rl, 'cliente-a');
$check(!RateLimit::tooMany($rl, 3, 60, 'cliente-a'), 'Limite por IP pode ser limpo');
$check(RateLimit::clientId('2001:db8:1:2::1') === RateLimit::clientId('2001:db8:1:2:ffff::9') && RateLimit::clientId('2001:db8:1:3::1') !== RateLimit::clientId('2001:db8:1:2::1') && RateLimit::clientId('203.0.113.7') !== RateLimit::clientId('203.0.113.8'), 'IPv6 conta pelo /64 e IPv4 pelo endereço inteiro');
RateLimit::clear('login');
for ($i = 0; $i < AUTH_IP_MAX_ATTEMPTS; $i++) { RateLimit::hit('login', AUTH_LOCK_SECONDS); }
$blocked = attempt_login('qa_runner', $password);
$lockRow = $pdo->query("SELECT failed_attempts FROM admin_users WHERE username = 'qa_runner'")->fetch();
$check(!$blocked['ok'] && str_contains((string) $blocked['error'], 'Usuário ou senha inválidos') && (int) $lockRow['failed_attempts'] === 0, 'Login: IP com tentativas demais é recusado com a mensagem de sempre, sem travar a conta');
RateLimit::clear('login');
$check(attempt_login('qa_runner', $password)['ok'], 'Login: senha certa entra quando o IP está livre');
unset($_SESSION['admin_id']);
$check(Upload::ensureMemory(1048576, '128M') && !Upload::ensureMemory(6000 * 6000 * 8, '128M') && Upload::ensureMemory(PHP_INT_MAX >> 8, '-1'), 'Upload confere a memória antes de abrir a foto');

// Banner WEBP com EXIF (GPS): regravado sem metadado e com no máximo 1600px.
$gd = imagecreatetruecolor(2000, 1000);
imagefill($gd, 0, 0, imagecolorallocate($gd, 221, 174, 53));
ob_start(); imagewebp($gd, null, 80); $plain = (string) ob_get_clean();
imagedestroy($gd);
$chunk = static fn (string $id, string $data): string => $id . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
$vp8x = $chunk('VP8X', chr(0x08) . "\0\0\0" . substr(pack('V', 1999), 0, 3) . substr(pack('V', 999), 0, 3));
$body = 'WEBP' . $vp8x . substr($plain, 12) . $chunk('EXIF', "II*\0" . 'GPS-LYNCH-QA -26.3,-48.8');
$withExif = __DIR__ . '/fixtures/banner-exif.webp';
file_put_contents($withExif, 'RIFF' . pack('V', strlen($body)) . $body);
$check(str_contains((string) file_get_contents($withExif), 'GPS-LYNCH-QA') && @imagecreatefromwebp($withExif) instanceof GdImage, 'Fixture: WEBP com bloco EXIF legível pelo GD');
$bannerDir = 'uploads/qa-' . $suffix;
$saved = Upload::handle(['error' => UPLOAD_ERR_OK, 'tmp_name' => $withExif, 'name' => 'foto.webp', 'size' => filesize($withExif)], $bannerDir);
$savedAbs = $saved['ok'] ? dirname(__DIR__) . '/' . $saved['path'] : '';
$savedInfo = $savedAbs !== '' ? getimagesize($savedAbs) : false;
$check($saved['ok'] && !str_contains((string) file_get_contents($savedAbs), 'GPS-LYNCH-QA') && is_array($savedInfo) && max($savedInfo[0], $savedInfo[1]) === Upload::BANNER_MAX_SIDE && str_ends_with($saved['path'], '.webp'), 'Banner WEBP é regravado sem EXIF/GPS e reduzido para 1600px');
if ($savedAbs !== '') { @unlink($savedAbs); }
@rmdir(dirname(__DIR__) . '/' . $bannerDir);
@unlink($withExif);
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
    'coupon_by_code' => "SELECT id,code,discount_type,discount_value,min_order_cents,expires_on,max_uses,uses,active FROM coupons WHERE code = 'LYNCH10' AND deleted = 0 LIMIT 1",
    'coupon_claim' => "EXPLAIN UPDATE coupons SET uses = uses + 1 WHERE id = 1 AND active = 1 AND deleted = 0 AND (expires_on IS NULL OR expires_on >= '2026-10-30') AND (max_uses IS NULL OR uses < max_uses)",
    'coupon_admin_list' => 'SELECT id,code,discount_type,discount_value,min_order_cents,expires_on,max_uses,uses,active FROM coupons WHERE deleted = 0 ORDER BY id DESC LIMIT 200',
    'dashboard_missing_photos' =>'SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)',
];
foreach ($queries as $label => $sql) { $plans[$label] = $pdo->query(str_starts_with($sql, 'EXPLAIN') ? $sql : 'EXPLAIN ' . $sql)->fetchAll(); }
file_put_contents(dirname(__DIR__) . '/docs/explain.json', json_encode($plans, JSON_PRETTY_PRINT));
file_put_contents(dirname(__DIR__) . '/docs/php-tests.json', json_encode(['database' => DB_NAME, 'passed' => $passed, 'count' => count($passed), 'at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($passed) . " verificações PHP passaram.\n";

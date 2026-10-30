<?php
/**
 * Lynch Store - testes de SEO por HTTP contra um servidor de teste.
 *
 * Confere metatags, canonical, og:image (baixa e mede a imagem), JSON-LD com um
 * validador proprio (tipos, URLs absolutas, referencias @id, Product so com
 * Offer valido), sitemap (XML, lastmod, imagens, canonical de cada URL), robots
 * (simula a regra de precedencia do Google), noindex e manifest.
 *
 * Uso (o servidor e este script apontam para o MESMO banco _teste_):
 *   $env:LYNCH_ENV_FILE="<env de teste>"; php -S localhost:8030 scripts/serve.php
 *   $env:LYNCH_ENV_FILE="<env de teste>"; $env:QA_URL="http://localhost:8030"; php tests/seo.php
 * Loja vazia (opcional, outro banco _teste_ sem pecas e outro servidor):
 *   $env:QA_URL_VAZIO="http://localhost:8031"
 *
 * Cria um produto com preco e promocao no banco de teste e o tira da loja no fim.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('LYNCH_ENV_FILE')) { putenv('LYNCH_ENV_FILE=' . dirname(__DIR__) . '/.env.test.local'); }
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';
require_once dirname(__DIR__) . '/includes/Seo.php';
if (!str_contains(DB_NAME, '_teste_')) { throw new RuntimeException('Testes exigem banco _teste_.'); }

$base = rtrim((string) (getenv('QA_URL') ?: APP_BASE_URL), '/');
$baseEmpty = rtrim((string) getenv('QA_URL_VAZIO'), '/');
$passed = [];
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $passed[] = $label;
};

/** @return array{status:int,headers:array<string,string>,body:string} */
function http_get(string $url): array
{
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20, 'header' => "User-Agent: LynchSeoTest\r\n"]]);
    $body = (string) @file_get_contents($url, false, $ctx);
    $headers = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) { $status = (int) $m[1]; continue; }
        [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($k))] = trim($v);
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/** Metatags, links, titulo, h1, imagens e JSON-LD de uma pagina. */
function page_info(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $meta = [];
    foreach ($xp->query('//meta[@property or @name]') as $m) {
        $key = $m->getAttribute('property') ?: $m->getAttribute('name');
        $meta[$key][] = $m->getAttribute('content');
    }
    $ld = [];
    foreach ($xp->query('//script[@type="application/ld+json"]') as $s) { $ld[] = $s->textContent; }
    $imgsNoAlt = 0;
    foreach ($xp->query('//img') as $img) { if (!$img->hasAttribute('alt')) { $imgsNoAlt++; } }
    $emptyLinks = 0;
    foreach ($xp->query('//a[@href]') as $a) {
        if (trim($a->textContent) === '' && $a->getAttribute('aria-label') === '' && $xp->query('.//img[@alt!=""]', $a)->length === 0) { $emptyLinks++; }
    }
    $canon = $xp->query('//link[@rel="canonical"]');
    return [
        'titles'    => $xp->query('//title')->length,
        'title'     => trim((string) ($xp->query('//title')->item(0)?->textContent)),
        'meta'      => $meta,
        'canonical' => $canon->length ? $canon->item(0)->getAttribute('href') : null,
        'canonicals'=> $canon->length,
        'h1'        => $xp->query('//h1')->length,
        'headings'  => array_map(static fn (DOMNode $n): int => (int) substr($n->nodeName, 1), iterator_to_array($xp->query('//h1|//h2|//h3|//h4'))),
        'ld'        => $ld,
        'imgsNoAlt' => $imgsNoAlt,
        'emptyLinks'=> $emptyLinks,
        'manifest'  => $xp->query('//link[@rel="manifest"]')->item(0)?->getAttribute('href'),
        'icons'     => array_map(static fn (DOMElement $l): string => $l->getAttribute('href'), iterator_to_array($xp->query('//link[@rel="icon" or @rel="apple-touch-icon"]'))),
    ];
}

const SCHEMA_AVAILABILITY = ['BackOrder', 'Discontinued', 'InStock', 'InStoreOnly', 'LimitedAvailability', 'MadeToOrder', 'OnlineOnly', 'OutOfStock', 'PreOrder', 'PreSale', 'Reserved', 'SoldOut'];
const SCHEMA_TYPES = ['Store', 'WebSite', 'WebPage', 'CollectionPage', 'ItemPage', 'BreadcrumbList', 'Product'];

/**
 * Validador de JSON-LD (o que o Rich Results Test cobraria para estes tipos).
 * Devolve a lista de erros; vazia = valido.
 *
 * @return array<int,string>
 */
function jsonld_errors(string $raw, string $canonical): array
{
    $err = [];
    $data = json_decode($raw, true);
    if (!is_array($data)) { return ['JSON invalido: ' . json_last_error_msg()]; }
    if (($data['@context'] ?? '') !== 'https://schema.org') { $err[] = '@context'; }
    $graph = $data['@graph'] ?? null;
    if (!is_array($graph) || $graph === []) { return array_merge($err, ['@graph vazio']); }
    $ids = [];
    foreach ($graph as $n) { if (isset($n['@id'])) { $ids[$n['@id']] = $n['@type'] ?? '?'; } }
    $abs = static fn ($u): bool => is_string($u) && preg_match('~^https?://[^/\s]+/~', $u) === 1 && !str_contains($u, ' ');
    // Toda URL e todo @id referenciado tem de existir/ser absoluto
    $walk = static function ($node, string $path) use (&$walk, &$err, $abs, $ids): void {
        if (!is_array($node)) { return; }
        foreach ($node as $k => $v) {
            if (in_array($k, ['url', 'item', 'logo', 'sameAs', 'image', 'urlTemplate'], true) && is_string($v)) {
                if (!$abs(str_replace('{search_term_string}', 'x', $v))) { $err[] = "URL relativa em $path.$k: $v"; }
            }
            if ($k === 'image' && is_array($v) && array_is_list($v)) { foreach ($v as $u) { if (!$abs($u)) { $err[] = "imagem relativa em $path"; } } }
            if ($k === 'sameAs' && is_array($v)) { foreach ($v as $u) { if (!$abs($u)) { $err[] = "sameAs relativo"; } } }
            if ($k === '@id' && is_string($v) && count($node) === 1 && !isset($ids[$v])) { $err[] = "referencia @id sem no: $v"; }
            if (is_string($v) && str_contains($v, "\u{2014}")) { $err[] = "travessao em $path.$k"; }
            if (is_array($v)) { $walk($v, $path . '.' . $k); }
        }
    };
    foreach ($graph as $i => $n) {
        $type = $n['@type'] ?? null;
        if (!in_array($type, SCHEMA_TYPES, true)) { $err[] = "tipo inesperado: " . json_encode($type); continue; }
        $walk($n, $type);
        switch ($type) {
            case 'Store':
                foreach (['name', 'url', 'logo', 'image'] as $f) { if (empty($n[$f])) { $err[] = "Store sem $f"; } }
                if (isset($n['address']) && (($n['address']['@type'] ?? '') !== 'PostalAddress' || empty($n['address']['addressLocality']) || ($n['address']['addressCountry'] ?? '') !== 'BR')) { $err[] = 'Store.address incompleto'; }
                foreach ($n['openingHoursSpecification'] ?? [] as $o) {
                    if (!preg_match('/^\d\d:\d\d$/', (string) ($o['opens'] ?? '')) || !preg_match('/^\d\d:\d\d$/', (string) ($o['closes'] ?? '')) || empty($o['dayOfWeek'])) { $err[] = 'horario invalido'; }
                }
                if (isset($n['telephone']) && !preg_match('/^\+\d[\d\s-]{7,}$/', (string) $n['telephone'])) { $err[] = 'telefone fora do formato'; }
                break;
            case 'WebSite':
                $t = $n['potentialAction']['target']['urlTemplate'] ?? '';
                if (($n['potentialAction']['@type'] ?? '') !== 'SearchAction' || !str_contains($t, '{search_term_string}') || ($n['potentialAction']['query-input'] ?? '') !== 'required name=search_term_string') { $err[] = 'SearchAction invalida'; }
                break;
            case 'BreadcrumbList':
                $items = $n['itemListElement'] ?? [];
                if (count($items) < 2) { $err[] = 'trilha curta'; }
                foreach ($items as $p => $li) {
                    if (($li['@type'] ?? '') !== 'ListItem' || ($li['position'] ?? 0) !== $p + 1 || empty($li['name'])) { $err[] = 'ListItem invalido na posicao ' . ($p + 1); }
                }
                if ($items !== [] && (end($items)['item'] ?? '') !== $canonical) { $err[] = 'ultimo item da trilha diferente do canonical'; }
                break;
            case 'Product':
                foreach (['name', 'image', 'offers', 'url'] as $f) { if (empty($n[$f])) { $err[] = "Product sem $f"; } }
                if (($n['url'] ?? '') !== $canonical) { $err[] = 'Product.url diferente do canonical'; }
                $o = $n['offers'] ?? [];
                if (($o['@type'] ?? '') !== 'Offer') { $err[] = 'offers sem Offer'; break; }
                if (($o['priceCurrency'] ?? '') !== 'BRL') { $err[] = 'priceCurrency'; }
                if (!preg_match('/^\d+\.\d{2}$/', (string) ($o['price'] ?? '')) || (float) $o['price'] <= 0) { $err[] = 'price invalido'; }
                if (!in_array(str_replace('https://schema.org/', '', (string) ($o['availability'] ?? '')), SCHEMA_AVAILABILITY, true)) { $err[] = 'availability invalida'; }
                if (isset($o['priceValidUntil']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $o['priceValidUntil'])) { $err[] = 'priceValidUntil'; }
                break;
            default: // paginas
                if (($n['url'] ?? '') !== $canonical || ($n['@id'] ?? '') !== $canonical) { $err[] = "$type fora do canonical"; }
        }
    }
    return $err;
}

/** Simula a precedencia do Google: regra mais longa vence; empate, Allow vence. */
function robots_allowed(string $robots, string $path): bool
{
    $best = null;
    foreach (preg_split('/\R/', $robots) as $line) {
        if (!preg_match('/^(Allow|Disallow):\s*(\S*)/i', trim($line), $m) || $m[2] === '') { continue; }
        $rule = $m[2];
        $re = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '~')) . '~';
        if (preg_match($re, $path) !== 1) { continue; }
        $allow = strtolower($m[1]) === 'allow';
        if ($best === null || strlen($rule) > $best[0] || (strlen($rule) === $best[0] && $allow)) { $best = [strlen($rule), $allow]; }
    }
    return $best === null || $best[1];
}

// ---------------------------------------------------------------------------
// Produto com preco e promocao (o acervo de teste so tem "Consultar valor")
// ---------------------------------------------------------------------------
$suffix = bin2hex(random_bytes(4));
$cat = array_values(array_filter(CategoryRepository::listActiveWithCounts(), static fn (array $c): bool => (int) $c['product_count'] > 0))[0] ?? null;
$pages = [];
$pricedSlug = 'seo-qa-preco-' . $suffix;
$pricedId = ProductRepository::create(['name' => 'Camiseta SEO QA ' . $suffix, 'slug' => $pricedSlug, 'category_id' => $cat['id'] ?? null, 'active' => true, 'price_on_request' => false, 'price_cents' => 12990, 'availability' => 'pronta_entrega', 'short_description' => 'Camiseta de teste com estampa geek.']);
if (ProductRepository::stockReady()) {
    db()->prepare('UPDATE products SET sale_price_cents = 9990, sale_ends_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('+10 days')), $pricedId]);
}
$onRequest = null;
foreach (ProductRepository::listActive(['limit' => 60]) as $p) { if ($p['current_price_cents'] === null) { $onRequest = $p; break; } }

try {
    $site = seo_site_url();
    $check(str_starts_with($site, 'http') && $site === rtrim(APP_BASE_URL, '/'), 'Domínio fixo do .env (nunca o Host da requisição)');

    // -----------------------------------------------------------------------
    // Paginas indexaveis
    // -----------------------------------------------------------------------
    $indexable = [
        'início'      => base_url(),
        'loja'        => url_catalog(),
        'informações' => base_url('informacoes.php'),
        'produto sob consulta' => $onRequest ? product_url($onRequest['slug']) : null,
        'produto com preço'    => product_url($pricedSlug),
    ];
    if ($cat) { $indexable['coleção'] = url_catalog(['categoria' => $cat['slug']]); }
    $titles = []; $descriptions = [];
    foreach (array_filter($indexable) as $label => $url) {
        $path = substr(seo_abs($url), strlen($site));
        $r = http_get($base . $path);
        $check($r['status'] === 200, "$label: 200");
        $i = page_info($r['body']);
        $desc = $i['meta']['description'][0] ?? '';
        $check($i['titles'] === 1 && mb_strlen($i['title']) >= 15 && mb_strlen($i['title']) <= 65, "$label: um <title> de 15 a 65 caracteres ({$i['title']})");
        $check(mb_strlen($desc) >= 50 && mb_strlen($desc) <= 160, "$label: description de 50 a 160 caracteres");
        $check(!str_contains($r['body'], "\u{2014}"), "$label: sem travessão no HTML");
        $check($i['canonicals'] === 1 && $i['canonical'] === seo_abs($url) && str_starts_with($i['canonical'], $site . '/'), "$label: canonical absoluto no domínio fixo");
        $check(($i['meta']['og:url'][0] ?? '') === $i['canonical'], "$label: og:url igual ao canonical");
        $check(str_starts_with($i['meta']['robots'][0] ?? '', 'index, follow'), "$label: indexável");
        foreach (['og:title', 'og:description', 'og:type', 'og:site_name', 'og:image', 'og:image:width', 'og:image:height', 'og:image:alt', 'twitter:card', 'twitter:image'] as $prop) {
            $check(($i['meta'][$prop][0] ?? '') !== '', "$label: $prop");
        }
        $check(($i['meta']['og:locale'][0] ?? '') === 'pt_BR' && ($i['meta']['twitter:card'][0] ?? '') === 'summary_large_image', "$label: og:locale pt_BR e summary_large_image");
        $check($i['h1'] === 1 && ($i['headings'][0] ?? 0) === 1, "$label: um h1, antes dos outros títulos");
        $jumps = 0; $prev = 1;
        foreach ($i['headings'] as $h) { if ($h > $prev + 1) { $jumps++; } $prev = $h; }
        $check($jumps === 0, "$label: títulos sem pular nível");
        $check($i['imgsNoAlt'] === 0 && $i['emptyLinks'] === 0, "$label: imagens com alt e links com texto");
        // og:image: absoluta, baixa, e as dimensoes declaradas batem com o arquivo
        $og = $i['meta']['og:image'][0];
        $img = http_get($base . substr($og, strlen($site)));
        $size = @getimagesizefromstring($img['body']);
        $check(str_starts_with($og, $site . '/') && $img['status'] === 200 && $size !== false && $size[0] === (int) $i['meta']['og:image:width'][0] && $size[1] === (int) $i['meta']['og:image:height'][0], "$label: og:image absoluta, existe e tem as dimensões declaradas");
        $check(strlen($img['body']) <= SEO_OG_IMAGE_MAX_BYTES, "$label: og:image leve para o WhatsApp (" . round(strlen($img['body']) / 1024) . ' KB)');
        // JSON-LD
        $check(count($i['ld']) === 1, "$label: um bloco JSON-LD");
        $errors = jsonld_errors($i['ld'][0], $i['canonical']);
        $check($errors === [], "$label: JSON-LD válido" . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));
        $graph = json_decode($i['ld'][0], true)['@graph'];
        $types = array_column($graph, '@type');
        $check(in_array('Store', $types, true) && in_array('WebSite', $types, true), "$label: JSON-LD com a loja e o site");
        if ($label !== 'início') { $check(in_array('BreadcrumbList', $types, true), "$label: trilha (BreadcrumbList)"); }
        $titles[] = $i['title']; $descriptions[] = $desc;
        $pages[$label] = ['info' => $i, 'graph' => $graph, 'types' => $types];
    }
    $check(count(array_unique($titles)) === count($titles) && count(array_unique($descriptions)) === count($descriptions), 'Títulos e descriptions únicos entre as páginas');
    $home = $pages['início'];
    $check(str_contains($home['info']['title'], 'Joinville') && str_contains($home['info']['meta']['description'][0], 'Joinville'), 'Início com intenção de busca local (Joinville) no título e na description');
    $check(str_ends_with(strtok($home['info']['meta']['og:image'][0], '?'), '/assets/capa-1200x630.jpg') && ($home['info']['meta']['og:image'][1] ?? '') !== '' && str_contains($home['info']['meta']['og:image'][1], 'capa-1200x1200.jpg'), 'Início usa a capa da loja (1200x630) e a quadrada como segunda imagem');
    $store = $home['graph'][array_search('Store', $home['types'], true)];
    $check(($store['address']['addressLocality'] ?? '') === 'Joinville' && !empty($store['openingHoursSpecification']) && ($store['sameAs'][0] ?? '') === setting('instagram_url') && ($store['logo']['width'] ?? 0) === 512, 'Loja com endereço, horário, Instagram e logo 512 no JSON-LD');

    // Produto sob consulta: sem Product (sem offers nao ha erro no Search Console)
    if ($onRequest) {
        $p = $pages['produto sob consulta'];
        $check(!in_array('Product', $p['types'], true) && !str_contains($p['info']['ld'][0], '"offers"') && in_array('ItemPage', $p['types'], true), 'Produto "Consultar valor" vira ItemPage, sem Product e sem offers');
        $expected = seo_product_image($onRequest['primary_image'] ?? null);
        $check($expected !== null && $p['info']['meta']['og:image'][0] === $expected['url'] && ($p['info']['meta']['og:type'][0] ?? '') === 'product', 'Produto com foto usa a própria foto como og:image');
    }
    // Produto com preco e promocao
    $p = $pages['produto com preço'];
    $product = $p['graph'][array_search('Product', $p['types'], true)] ?? null;
    $check($product !== null && $product['offers']['price'] === (ProductRepository::stockReady() ? '99.90' : '129.90') && $product['offers']['availability'] === 'https://schema.org/InStock', 'Produto com preço: Offer com preço vigente e InStock');
    if (ProductRepository::stockReady()) {
        $check(($product['offers']['priceValidUntil'] ?? '') === date('Y-m-d', strtotime('+10 days')), 'Promoção com data final vira priceValidUntil');
        db()->prepare('UPDATE products SET sold_out = 1 WHERE id = ?')->execute([$pricedId]);
        $out = page_info(http_get($base . substr(seo_abs(product_url($pricedSlug)), strlen($site)))['body']);
        $check(str_contains($out['ld'][0], '"availability":"https://schema.org/SoldOut"'), 'Esgotado vira SoldOut no Offer');
    }
    // Produto sem foto cai na capa padrao
    $check(str_contains($p['info']['meta']['og:image'][0], 'capa-1200x630.jpg'), 'Produto sem foto usa a capa padrão');

    // -----------------------------------------------------------------------
    // noindex: filtros, ordem, "ver mais", busca, sacola, favoritos e 404
    // -----------------------------------------------------------------------
    $noindex = ['/loja.php?busca=camiseta', '/loja.php?ordem=preco_asc', '/loja.php?mode=pronta_entrega', '/loja.php?mostrar=24', '/carrinho.php', '/favoritos.php'];
    if ($cat) { $noindex[] = '/loja.php?categoria=' . rawurlencode($cat['slug']) . '&ordem=preco_desc'; }
    foreach ($noindex as $path) {
        $i = page_info(http_get($base . $path)['body']);
        $check(str_starts_with($i['meta']['robots'][0] ?? '', 'noindex') && $i['canonicals'] === 0 && $i['ld'] === [], "noindex sem canonical nem JSON-LD: $path");
    }
    $r = http_get($base . '/loja.php?utm_source=whatsapp&fbclid=x');
    $check(page_info($r['body'])['canonical'] === seo_abs(url_catalog()), 'Parâmetros de campanha caem no canonical limpo');
    foreach (['/nao-existe', '/produto.php?slug=nao-existe-' . $suffix] as $path) {
        $r = http_get($base . $path);
        $check($r['status'] === 404 && str_starts_with(page_info($r['body'])['meta']['robots'][0] ?? '', 'noindex'), "404 de verdade e noindex: $path");
    }

    // -----------------------------------------------------------------------
    // robots.txt
    // -----------------------------------------------------------------------
    $robots = http_get($base . '/robots.txt');
    $check($robots['status'] === 200 && str_contains($robots['body'], 'Sitemap: ' . $site . '/sitemap.xml'), 'robots.txt aponta o sitemap absoluto');
    foreach (['/' => true, '/loja.php' => true, '/loja.php?categoria=cosplay' => true, '/loja.php?busca=' => true, '/produto.php?slug=x' => true, '/informacoes.php' => true, '/assets/capa-1200x630.jpg' => true,
              '/admin/' => false, '/admin/login.php' => false, '/carrinho.php' => false, '/favoritos.php' => false, '/finalizar.php' => false, '/busca.php?q=x' => false,
              '/loja.php?busca=camiseta' => false, '/loja.php?categoria=cosplay&busca=x' => false] as $path => $allowed) {
        $check(robots_allowed($robots['body'], $path) === $allowed, 'robots.txt ' . ($allowed ? 'libera ' : 'bloqueia ') . $path);
    }

    // -----------------------------------------------------------------------
    // sitemap.xml
    // -----------------------------------------------------------------------
    $sm = http_get($base . '/sitemap.xml');
    $doc = new DOMDocument();
    $check($sm['status'] === 200 && str_contains($sm['headers']['content-type'] ?? '', 'xml') && $doc->loadXML($sm['body']) === true, 'sitemap.xml é XML válido');
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xp->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
    $locs = array_map(static fn (DOMNode $n): string => $n->textContent, iterator_to_array($xp->query('//s:url/s:loc')));
    $activeProducts = (int) db()->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
    $filledCats = (int) db()->query('SELECT COUNT(*) FROM categories c WHERE c.active = 1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1)')->fetchColumn();
    $check(count($locs) === 3 + $filledCats + $activeProducts && count(array_unique($locs)) === count($locs), 'sitemap com início, loja, informações, ' . $filledCats . ' coleções e ' . $activeProducts . ' produtos ativos, sem repetir');
    $check(array_filter($locs, static fn (string $l): bool => !str_starts_with($l, $site . '/')) === [], 'sitemap só com URLs absolutas do domínio fixo');
    $check(array_filter($locs, static fn (string $l): bool => (bool) preg_match('~busca=|ordem=|mode=|mostrar=|carrinho|favoritos~', $l)) === [], 'sitemap sem páginas noindex');
    $lastmods = array_map(static fn (DOMNode $n): string => $n->textContent, iterator_to_array($xp->query('//s:url/s:lastmod')));
    $check(count($lastmods) === count($locs) && array_filter($lastmods, static fn (string $d): bool => !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $d)) === [], 'lastmod em todas as URLs, formato W3C');
    $imgLocs = array_map(static fn (DOMNode $n): string => $n->textContent, iterator_to_array($xp->query('//image:image/image:loc')));
    $withPhoto = (int) db()->query('SELECT COUNT(DISTINCT pi.product_id) FROM product_images pi JOIN products p ON p.id = pi.product_id AND p.active = 1')->fetchColumn();
    $check(($withPhoto === 0 || count($imgLocs) >= $withPhoto) && array_filter($imgLocs, static fn (string $l): bool => !str_starts_with($l, $site . '/')) === [], 'image:image absolutas para os produtos com foto');
    if ($imgLocs) { $check(http_get($base . substr($imgLocs[0], strlen($site)))['status'] === 200, 'Imagem do sitemap existe'); }
    $sample = array_merge(array_slice($locs, 0, 5), array_slice($locs, -3));
    foreach ($sample as $loc) {
        $i = page_info(http_get($base . substr($loc, strlen($site)))['body']);
        $check($i['canonical'] === $loc, 'URL do sitemap é canônica de si mesma: ' . substr($loc, strlen($site)));
    }

    // -----------------------------------------------------------------------
    // Icones e manifest
    // -----------------------------------------------------------------------
    $mf = http_get($base . '/site.webmanifest');
    $manifest = json_decode($mf['body'], true);
    $check($mf['status'] === 200 && is_array($manifest) && $manifest['name'] === 'Lynch Store' && $manifest['lang'] === 'pt-BR', 'manifest da loja pública válido');
    foreach ($manifest['icons'] as $icon) {
        [$w] = explode('x', $icon['sizes']);
        $file = http_get($base . '/' . $icon['src']);
        $size = @getimagesizefromstring($file['body']);
        $check($file['status'] === 200 && $size && $size[0] === (int) $w && $size[1] === (int) $w, 'Ícone ' . $icon['sizes'] . ' existe e tem o tamanho declarado');
    }
    $ico = http_get($base . '/favicon.ico');
    $check($ico['status'] === 200 && substr($ico['body'], 0, 4) === "\0\0\1\0", 'favicon.ico existe (formato ICO)');
    $check($home['info']['manifest'] !== null && count($home['info']['icons']) >= 3, 'Páginas apontam favicon, ícone PNG, apple-touch-icon e manifest');

    // Capa padrao: arquivos leves e com as dimensoes certas
    foreach (['assets/capa-1200x630.jpg' => [1200, 630], 'assets/capa-1200x630.webp' => [1200, 630], 'assets/capa-1200x1200.jpg' => [1200, 1200], 'assets/capa-1200x1200.webp' => [1200, 1200]] as $file => [$w, $h]) {
        $abs = dirname(__DIR__) . '/' . $file;
        $size = @getimagesize($abs);
        $check($size && $size[0] === $w && $size[1] === $h && filesize($abs) < 150 * 1024, "Capa $file: {$w}x{$h} e menos de 150 KB");
    }

    // -----------------------------------------------------------------------
    // Loja vazia (recem-publicada)
    // -----------------------------------------------------------------------
    if ($baseEmpty !== '') {
        $r = http_get($baseEmpty . '/');
        $i = page_info($r['body']);
        $check($r['status'] === 200 && str_contains($r['body'], 'A arara está sendo montada') && !str_contains($r['body'], 'Escolha seu universo') && $i['h1'] === 1, 'Loja vazia: início com aviso de preparo, sem vitrine vazia');
        $check(count($i['ld']) === 1 && jsonld_errors($i['ld'][0], (string) $i['canonical']) === [] && str_contains($i['meta']['og:image'][0], 'capa-1200x630.jpg'), 'Loja vazia: JSON-LD válido e capa padrão');
        $r = http_get($baseEmpty . '/loja.php');
        $check(str_contains($r['body'], 'A arara está sendo montada') && !str_contains($r['body'], 'Nenhuma peça com esse filtro') && !str_contains($r['body'], 'class="shop-count"') && !str_contains($r['body'], 'class="filters"'), 'Loja vazia: catálogo com aviso de preparo, sem filtro vazio');
        $sm = http_get($baseEmpty . '/sitemap.xml')['body'];
        $check(substr_count($sm, '<loc>') === 3, 'Loja vazia: sitemap com as 3 páginas fixas');
    }
} finally {
    db()->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$pricedId]);
}

foreach ($passed as $label) { echo "ok  $label\n"; }
echo count($passed) . " verificações de SEO aprovadas\n";

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['source:', 'base:']);
$source = rtrim($options['source'] ?? 'http://localhost:8090', '/');
$base = rtrim($options['base'] ?? 'https://rochamaatheus.github.io/site-lynch-store', '/');
if (!preg_match('~^http://(?:localhost|127\.0\.0\.1):[0-9]+$~D', $source)) { throw new RuntimeException('A origem precisa ser um servidor local.'); }
if (!filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('Informe uma URL HTTPS para a prévia.'); }
$prefix = rtrim(parse_url($base, PHP_URL_PATH) ?: '', '/') . '/';
$root = dirname(__DIR__);
$out = $root . '/dist/preview-' . date('Ymd-His');
mkdir($out, 0700, true);
$queue = ['index.php', 'informacoes.php', 'carrinho.php', '404.php'];
$seen = [];
$products = [];
$images = [];
$home = '';

function preview_route(string $url): ?string
{
    $parts = parse_url($url);
    $path = ltrim($parts['path'] ?? '', '/') ?: 'index.php';
    if (!in_array($path, ['index.php', 'produto.php', 'informacoes.php', 'carrinho.php', '404.php'], true)) { return null; }
    parse_str($parts['query'] ?? '', $query);
    $keys = $path === 'produto.php' ? ['slug'] : ($path === 'index.php' ? ['mode','categoria','mostrar_sob','mostrar_pronta'] : []);
    $query = array_intersect_key($query, array_flip($keys));
    foreach ($query as $key => $value) {
        if (!is_string($value) || strlen($value) > 180) { throw new RuntimeException('Parâmetro inválido no catálogo.'); }
        if ($value === '') { unset($query[$key]); }
    }
    ksort($query);
    return $path . ($query ? '?' . http_build_query($query) : '');
}
function preview_file(string $route): string
{
    $path = parse_url($route, PHP_URL_PATH);
    if ($path === 'produto.php') {
        parse_str(parse_url($route, PHP_URL_QUERY) ?? '', $query);
        $slug = $query['slug'] ?? '';
        return 'produto-' . (preg_match('/^[a-z0-9-]{1,150}$/D', $slug) ? $slug : substr(hash('sha256', $route), 0, 20)) . '.html';
    }
    if ($path === 'index.php' && str_contains($route, '?')) { return 'catalogo-' . substr(hash('sha256', $route), 0, 16) . '.html'; }
    return str_replace('.php', '.html', $path);
}
function preview_dom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    foreach (iterator_to_array($dom->childNodes) as $child) { if ($child->nodeType === XML_PI_NODE) { $dom->removeChild($child); } }
    return $dom;
}
while ($queue) {
    $route = array_shift($queue);
    if (isset($seen[$route])) { continue; }
    if (count($seen) >= 250) { throw new RuntimeException('Limite de páginas excedido.'); }
    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Connection: close\r\n"]]);
    $html = file_get_contents(str_replace('://localhost:', '://127.0.0.1:', $source) . '/' . $route, false, $context);
    $expected = $route === '404.php' ? '404' : '200';
    if ($html === false || !str_contains($http_response_header[0] ?? '', ' ' . $expected . ' ')) { throw new RuntimeException('Falha ao renderizar ' . $route); }
    $dom = preview_dom($html);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//input[@name="csrf" or @name="redirect_to"]|//script[@type="application/ld+json"]') as $private) { $private->parentNode->removeChild($private); }
    foreach ($xpath->query('//meta[@name="robots"]') as $meta) { $meta->setAttribute('content', 'noindex, nofollow'); }
    foreach ($xpath->query('//a[@href]') as $a) {
        $href = $a->getAttribute('href');
        if (str_starts_with($href, $source . '/')) {
            $local = substr($href, strlen($source) + 1);
            $next = preview_route($local);
            if ($next === null) { throw new RuntimeException('Link local não exportável: ' . $local); }
            $fragment = parse_url($local, PHP_URL_FRAGMENT);
            $a->setAttribute('href', $prefix . preview_file($next) . ($fragment ? '#' . $fragment : ''));
            $queue[] = $next;
        } elseif (preg_match('~^https?://(?:wa\.me|api\.whatsapp\.com)/~', $href)) {
            $a->setAttribute('href', '#preview-notice');
            $a->removeAttribute('target');
            $a->setAttribute('data-preview-checkout', '');
        }
    }
    foreach ($xpath->query('//form') as $form) {
        $action = $form->getAttribute('action');
        if (!str_starts_with($action, $source . '/')) { throw new RuntimeException('Formulário externo inesperado.'); }
        if (strtolower($form->getAttribute('method')) === 'post') {
            if (!$xpath->query('.//input[@name="product_id"]', $form)->length) { throw new RuntimeException('Formulário de escrita inesperado.'); }
            $form->setAttribute('action', $prefix . 'carrinho.html');
            $form->setAttribute('method', 'get');
            $form->setAttribute('data-preview-add', '');
        } else {
            $form->setAttribute('action', $prefix . 'busca.html');
        }
    }
    foreach ($xpath->query('//link[@rel="canonical"]|//meta[@property="og:url"]') as $canonical) {
        $canonical->setAttribute($canonical->tagName === 'link' ? 'href' : 'content', $base . '/' . preview_file($route));
    }
    $head = $dom->getElementsByTagName('head')->item(0);
    $style = $dom->createElement('link');
    $style->setAttribute('rel', 'stylesheet');
    $style->setAttribute('href', $prefix . 'preview.css');
    $head->appendChild($style);
    $body = $dom->getElementsByTagName('body')->item(0);
    $body->setAttribute('data-preview-base', $prefix);
    $body->setAttribute('data-preview-page', str_replace('.php', '', parse_url($route, PHP_URL_PATH)));
    $notice = $dom->createElement('aside');
    $notice->setAttribute('class', 'preview-notice');
    $notice->setAttribute('id', 'preview-notice');
    $notice->setAttribute('aria-label', 'Sobre esta prévia');
    $notice->appendChild($dom->createElement('strong', 'Prévia para a Ray'));
    $notice->appendChild($dom->createElement('span', 'Explore a loja e teste o carrinho. Pedidos e atendimento serão ativados na publicação definitiva.'));
    $main = $dom->getElementsByTagName('main')->item(0);
    $main->insertBefore($notice, $main->firstChild);
    $noscript = $dom->createElement('noscript', 'Ative o JavaScript para testar a busca e o carrinho desta prévia. As coleções e páginas de produto continuam disponíveis.');
    $main->insertBefore($noscript, $notice->nextSibling);
    $toast = $dom->createElement('div');
    $toast->setAttribute('class', 'preview-toast');
    $toast->setAttribute('role', 'status');
    $toast->setAttribute('aria-live', 'polite');
    $toast->setAttribute('aria-atomic', 'true');
    $body->appendChild($toast);
    $script = $dom->createElement('script');
    $script->setAttribute('src', $prefix . 'preview.js');
    $script->setAttribute('defer', '');
    $body->appendChild($script);
    $html = str_replace($source . '/', $prefix, $dom->saveHTML());
    if (str_contains($html, $source) || preg_match('~(?:localhost|127\.0\.0\.1):[0-9]+~', $html)) { throw new RuntimeException('Endereço local restante.'); }
    preg_match_all('~' . preg_quote($prefix, '~') . '(uploads/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g))~', $html, $matches);
    foreach ($matches[1] as $image) { $images[$image] = true; }
    file_put_contents($out . '/' . preview_file($route), $html);
    $seen[$route] = preview_file($route);
    if ($route === 'index.php') { $home = $html; }
    $cards = preview_dom($html);
    $cx = new DOMXPath($cards);
    foreach ($cx->query('//article[contains(concat(" ",normalize-space(@class)," ")," card ")]') as $card) {
        $input = $cx->query('.//input[@name="product_id"]', $card)->item(0);
        if (!$input) { continue; }
        $id = (int) $input->getAttribute('value');
        $title = $cx->query('.//h3/a', $card)->item(0);
        $photo = $cx->query('.//img', $card)->item(0);
        $price = trim($cx->query('.//*[contains(@class,"card-price")]', $card)->item(0)->textContent);
        $meta = trim($cx->query('.//*[contains(@class,"card-meta")]', $card)->item(0)->textContent);
        $cents = null;
        if (preg_match('/R\$\s*([0-9.]+),([0-9]{2})/u', $price, $money)) { $cents = (int) str_replace('.', '', $money[1]) * 100 + (int) $money[2]; }
        $products[$id] = ['id'=>$id,'name'=>trim($title->textContent),'url'=>$title->getAttribute('href'),'image'=>$photo->getAttribute('src'),'price'=>$price,'cents'=>$cents,'meta'=>$meta,'card'=>$cards->saveHTML($card)];
    }
}
$search = preview_dom($home);
$searchMain = $search->getElementsByTagName('main')->item(0);
while ($searchMain->lastChild) { $searchMain->removeChild($searchMain->lastChild); }
$searchMain->appendChild($search->importNode(preview_dom($home)->getElementById('preview-notice'), true));
$section = $search->createElement('section');
$section->setAttribute('class', 'container preview-search');
$section->appendChild($search->createElement('h1', 'Buscar peças'));
$status = $search->createElement('p', 'Carregando catálogo...');
$status->setAttribute('id', 'search-status');
$status->setAttribute('role', 'status');
$section->appendChild($status);
$grid = $search->createElement('div');
$grid->setAttribute('class', 'product-grid');
$grid->setAttribute('id', 'preview-results');
$section->appendChild($grid);
$searchMain->appendChild($section);
$search->getElementsByTagName('body')->item(0)->setAttribute('data-preview-page', 'busca');
$search->getElementsByTagName('title')->item(0)->textContent = 'Buscar peças | Lynch Store';
$searchXpath = new DOMXPath($search);
foreach ($searchXpath->query('//link[@rel="canonical"]|//meta[@property="og:url"]') as $node) { $node->setAttribute($node->tagName === 'link' ? 'href' : 'content', $base . '/busca.html'); }
file_put_contents($out . '/busca.html', $search->saveHTML());
foreach (['preview.js','preview.css'] as $file) { copy($root . '/scripts/preview/' . $file, $out . '/' . $file); }
$copy = static function (string $relative) use ($root, $out): void {
    $file = realpath($root . '/' . $relative);
    if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || is_link($root . '/' . $relative)) { throw new RuntimeException('Arquivo inválido: ' . $relative); }
    $target = $out . '/' . $relative;
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
    copy($file, $target);
};
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets', FilesystemIterator::SKIP_DOTS)) as $asset) {
    if ($asset->isFile() && in_array(strtolower($asset->getExtension()), ['css','js','webp','png','jpg','jpeg','svg','woff2'], true)) { $copy(str_replace('\\','/',substr($asset->getPathname(),strlen($root)+1))); }
}
foreach (array_keys($images) as $image) { $copy($image); }
ksort($products);
file_put_contents($out . '/products.json', json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($out . '/robots.txt', "User-agent: *\nDisallow: /\n");
file_put_contents($out . '/.nojekyll', '');
file_put_contents($out . '/README.md', "# Lynch Store: prévia para aprovação\n\nGerada das páginas PHP. Busca e carrinho são demonstrativos; não enviam pedidos nem armazenam dados de clientes no servidor. O painel e o checkout real fazem parte da instalação PHP na Hostinger.\n");
$report = ['output'=>$out,'base'=>$base,'pages'=>count($seen)+1,'products'=>count($products),'images'=>count($images),'routes'=>$seen];
file_put_contents($root . '/.cache/preview-build.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(array_diff_key($report, ['routes'=>true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Seo.php';
// Monta tudo em buffer: se o banco falhar no meio, o handler de erro responde 503
// em vez de entregar um XML pela metade com status 200.
// URLs absolutas no dominio fixo (seo_abs), lastmod real e fotos das pecas
// (image:image). So entra o que a loja mostra: pecas ativas e colecoes com peca.
ob_start();
$pdo = db();
$xml = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$stamp = static fn (?string $at): ?int => $at ? (strtotime($at) ?: null) : null;
$newest = static fn (?int ...$times): ?int => array_filter($times) ? max(array_filter($times)) : null;

$products = $pdo->query('SELECT id, slug, updated_at FROM products WHERE active = 1 ORDER BY id')->fetchAll();
$images = [];
$rows = $pdo->query('SELECT pi.product_id, pi.file_path, pi.created_at FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.active = 1 ORDER BY pi.product_id, pi.is_primary DESC, pi.sort_order, pi.id');
while ($row = $rows->fetch()) { $images[(int) $row['product_id']][] = $row; }
$categories = $pdo->query('SELECT c.slug, c.updated_at, MAX(p.updated_at) AS products_at FROM categories c JOIN products p ON p.category_id = c.id AND p.active = 1 WHERE c.active = 1 GROUP BY c.id, c.slug, c.updated_at ORDER BY c.id')->fetchAll();
$settingsAt = $stamp((string) $pdo->query('SELECT MAX(updated_at) FROM site_settings')->fetchColumn());

$productsAt = null;
foreach ($products as $p) { $productsAt = $newest($productsAt, $stamp($p['updated_at'])); }
$categoriesAt = null;
foreach ($categories as $c) { $categoriesAt = $newest($categoriesAt, $stamp($c['updated_at'])); }

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
$emit = static function (string $url, ?int $updated, array $imageUrls = []) use ($xml): void {
    echo '<url><loc>' . $xml(seo_abs($url)) . '</loc>';
    if ($updated) { echo '<lastmod>' . date('c', $updated) . '</lastmod>'; }
    foreach ($imageUrls as $img) { echo '<image:image><image:loc>' . $xml($img) . '</image:loc></image:image>'; }
    echo '</url>';
};
$emit(base_url(), $newest($productsAt, $categoriesAt, $settingsAt, filemtime(__DIR__ . '/index.php')));
$emit(url_catalog(), $newest($productsAt, $categoriesAt, filemtime(__DIR__ . '/loja.php')));
$emit(base_url('informacoes.php'), $newest($settingsAt, filemtime(__DIR__ . '/informacoes.php')));
foreach ($categories as $c) {
    $emit(url_catalog(['categoria' => $c['slug']]), $newest($stamp($c['updated_at']), $stamp($c['products_at'])));
}
foreach ($products as $p) {
    $urls = [];
    $at = $stamp($p['updated_at']);
    foreach ($images[(int) $p['id']] ?? [] as $img) {
        $at = $newest($at, $stamp($img['created_at']));
        $og = seo_product_image($img['file_path']);
        if ($og !== null) { $urls[] = $og['url']; }
    }
    $emit(product_url((string) $p['slug']), $at, $urls);
}
echo '</urlset>';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
ob_end_flush();

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
// Monta tudo em buffer: se o banco falhar no meio, o handler de erro responde 503
// em vez de entregar um XML pela metade com status 200.
ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
$emit = static function (string $url, ?string $updated = null): void {
    echo '<url><loc>' . htmlspecialchars($url, ENT_XML1, 'UTF-8') . '</loc>';
    if ($updated) { echo '<lastmod>' . date('c', strtotime($updated)) . '</lastmod>'; }
    echo '</url>';
};
$emit(base_url());
$emit(url_catalog());
$emit(base_url('informacoes.php'));
// Colecao sem peca ativa nao aparece no menu da loja, entao tambem fica fora daqui.
$stmt = db()->query('SELECT c.slug,c.updated_at FROM categories c WHERE c.active=1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id=c.id AND p.active=1) ORDER BY c.id');
while ($row = $stmt->fetch()) { $emit(url_catalog(['categoria' => $row['slug']]), $row['updated_at']); }
$stmt = db()->query('SELECT slug,updated_at FROM products WHERE active=1 ORDER BY id');
while ($row = $stmt->fetch()) { $emit(product_url($row['slug']), $row['updated_at']); }
echo '</urlset>';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
ob_end_flush();

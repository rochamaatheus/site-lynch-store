<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/ProductImport.php';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="produtos-lynch.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ProductImport::HEADERS, ';', '"', '');
if (isset($_GET['modelo'])) {
    fputcsv($out, ['Camiseta exemplo', 'camiseta-exemplo', 'Camisetas', '89,90', 'pronta_entrega', 'Descrição breve', 'Detalhes e medidas', '0', '0'], ';', '"', '');
} else {
    $stmt = db()->query('SELECT p.name,p.slug,c.name AS category,p.price_cents,p.price_on_request,p.availability,p.short_description,p.description,p.active,p.featured FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id');
    while ($p = $stmt->fetch()) {
        $row = [$p['name'], $p['slug'], $p['category'], $p['price_on_request'] ? '' : cents_to_input($p['price_cents']), $p['availability'], $p['short_description'], $p['description'], $p['active'], $p['featured']];
        $row = array_map(static function ($v) { $v = (string) $v; return preg_match('/^[\s]*[=+\-@]/u', $v) ? "'" . $v : $v; }, $row);
        fputcsv($out, $row, ';', '"', '');
    }
}
fclose($out);

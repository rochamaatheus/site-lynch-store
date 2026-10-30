<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/ProductImport.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="produtos-lynch.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$ready = ProductRepository::stockReady();
// Sem o banco atualizado, exporta so as colunas antigas (o arquivo continua importavel).
$headers = $ready ? ProductImport::HEADERS : array_values(array_diff(ProductImport::HEADERS, array_keys(ProductImport::STOCK_HEADERS)));
fputcsv($out, $headers, ';', '"', '');
if (isset($_GET['modelo'])) {
    $example = ['Camiseta exemplo', 'camiseta-exemplo', 'Camisetas', '89,90', 'pronta_entrega', 'Descrição breve', 'Detalhes e medidas', '0', '0'];
    if ($ready) { array_push($example, '5', '1', '79,90', '', '0'); }
    fputcsv($out, $example, ';', '"', '');
} else {
    $extra = $ready ? ',p.stock_qty,p.show_stock,p.sale_price_cents,p.sale_ends_on,p.sold_out' : '';
    $stmt = db()->query('SELECT p.name,p.slug,c.name AS category,p.price_cents,p.price_on_request,p.availability,p.short_description,p.description,p.active,p.featured' . $extra . ' FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id');
    while ($p = $stmt->fetch()) {
        $row = [$p['name'], $p['slug'], $p['category'], $p['price_on_request'] ? '' : cents_to_input($p['price_cents']), $p['availability'], $p['short_description'], $p['description'], $p['active'], $p['featured']];
        if ($ready) {
            array_push($row, $p['stock_qty'] ?? '', $p['show_stock'], $p['sale_price_cents'] === null ? '' : cents_to_input((int) $p['sale_price_cents']), format_date_br($p['sale_ends_on']), $p['sold_out']);
        }
        $row = array_map(static function ($v) { $v = (string) $v; return preg_match('/^[\s]*[=+\-@]/u', $v) ? "'" . $v : $v; }, $row);
        fputcsv($out, $row, ';', '"', '');
    }
}
fclose($out);

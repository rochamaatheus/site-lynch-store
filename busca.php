<?php
/** Sugestoes da busca instantanea: GET ?q=termo, ate 6 pecas em JSON. */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ProductRepository.php';

// Busca instantanea e chamada a cada letra: limite proprio, generoso, por IP.
RateLimit::guardPage(true);

$term = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$rows = mb_strlen($term) >= 2 ? ProductRepository::listActive(['search' => $term, 'limit' => 6]) : [];

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: private, no-store');
echo json_encode(array_map(static fn (array $p): array => [
    'name'  => (string) $p['name'],
    'url'   => product_url((string) $p['slug']),
    'image' => product_image_thumb_url($p['primary_image'] ?? null),
    'price' => !empty($p['is_sold_out']) ? 'Esgotado' : product_price_view($p)['now'],
], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

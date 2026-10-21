<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /instalar.php\nDisallow: /carrinho.php\nDisallow: /finalizar.php\nDisallow: /includes/\nDisallow: /db/\nDisallow: /vendor/\n";
echo 'Sitemap: ' . APP_BASE_URL . "/sitemap.xml\n";

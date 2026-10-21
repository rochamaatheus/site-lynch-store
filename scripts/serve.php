<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { exit; }
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.|includes/|db/|scripts/|tests/|docs/|dist/|storage/|vendor/|admin/partials/)|\.(?:json|md|sql|lock|log|ini|zip|ps1)$~i', $path)) {
    http_response_code(403); exit('Forbidden');
}
if ($path === '/robots.txt') { require $root . '/robots.php'; return true; }
if ($path === '/sitemap.xml') { require $root . '/sitemap.php'; return true; }
$file = realpath($root . $path);
if ($path === '/') { require $root . '/index.php'; return true; }
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
http_response_code(404);
require $root . '/404.php';

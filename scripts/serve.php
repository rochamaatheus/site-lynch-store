<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { exit; }
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
// Mesmas regras do .htaccess (php -S nao le .htaccess).
if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405); header('Allow: GET, HEAD, POST'); exit('Method Not Allowed');
}
if (preg_match('#(?:^|/)(?:\.(?!well-known/)|_old/|_lixeira/|includes/|db/|scripts/|tests/|docs/|dist/|storage/|vendor/|node_modules/|fixtures/|admin/partials/)|(?:~|\.(?:json|md|sql|lock|log|ini|zip|ps1|bak|old|orig|save|swp|tmp|gz|tar|csv|xlsx|yml|yaml|pem|key|sh))$|(?:^|/)(?:LEIA-ME|README|STATUS|composer|package)[^/]*$|^/uploads/(?!.*\.(?:webp|jpe?g|png)$)#i', $path) !== 0) {
    http_response_code(403); exit('Forbidden');
}
if ($path === '/robots.txt') { require $root . '/robots.php'; return true; }
if ($path === '/sitemap.xml') { require $root . '/sitemap.php'; return true; }
$file = realpath($root . $path);
if ($path === '/') { require $root . '/index.php'; return true; }
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
http_response_code(404);
require $root . '/404.php';

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/includes/db.php';
$name = 'lynchstore_teste_install_' . date('His');
db()->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$releases = glob(APP_ROOT . '/dist/*/lynch-hostinger.zip');
sort($releases);
$zipPath = end($releases);
if (!$zipPath) { throw new RuntimeException('Gere o build primeiro.'); }
$zip = new ZipArchive();
$zip->open($zipPath);
$dir = APP_ROOT . '/.cache/install-' . date('His');
mkdir($dir, 0700, true);
for ($i=0; $i<$zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    if (!str_starts_with($entry, 'public_html/') || str_contains($entry, '..') || str_contains($entry, '\\')) { throw new RuntimeException('Entrada inválida no ZIP.'); }
    if (preg_match('~(?:^|/)(?:\.env|\.git/|node_modules/|tests/|scripts/)~', $entry)) { throw new RuntimeException('Conteúdo privado no ZIP: ' . $entry); }
}
$zip->extractTo($dir);
$zip->close();
$readme = file_get_contents(dirname($zipPath) . '/LEIA-ME-INSTALACAO.txt');
preg_match('/\n([a-f0-9]{48})\n/', $readme, $matches);
$credentials = ['database' => $name, 'user' => DB_USER, 'db_password' => DB_PASS, 'key' => $matches[1], 'admin_user' => 'admin_install', 'admin_password' => bin2hex(random_bytes(16)), 'root' => $dir . '/public_html'];
file_put_contents(APP_ROOT . '/.cache/install-test.json', json_encode($credentials));
$docroot = str_replace('\\', '/', $dir . '/public_html');
$logs = str_replace('\\', '/', APP_ROOT . '/.cache');
$config = <<<APACHE
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:8082
ServerName localhost:8082
PidFile "$logs/apache-test.pid"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule filter_module modules/mod_filter.so
LoadModule expires_module modules/mod_expires.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
DocumentRoot "$docroot"
TypesConfig conf/mime.types
ErrorLog "$logs/apache-test-error.log"
LogLevel warn
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$docroot">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
ProxyFCGIBackendType GENERIC
ProxyFCGISetEnvIf "reqenv('SCRIPT_FILENAME') =~ m#^/(.*)$#" SCRIPT_FILENAME "$1"
<FilesMatch "\.php$">
    SetHandler "proxy:fcgi://127.0.0.1:9074/"
</FilesMatch>
APACHE;
file_put_contents(APP_ROOT . '/.cache/apache-test.conf', $config);
echo json_encode(['database' => $name, 'docroot' => $docroot, 'archive' => $zipPath]) . PHP_EOL;

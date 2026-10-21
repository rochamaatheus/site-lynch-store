<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/includes/db.php';
$root = dirname(__DIR__);
$release = $argv[1] ?? '';
if (!preg_match('~^dist/[0-9-]+$~', $release)) { throw new RuntimeException('Informe dist/ID do build.'); }
$manifest = json_decode(file_get_contents("$root/$release/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
$zipPath = "$root/$release/lynch-hostinger.zip";
if (!hash_equals($manifest['zip_sha256'], hash_file('sha256', $zipPath))) { throw new RuntimeException('Hash ZIP divergente.'); }
foreach ($manifest['files'] as $file => $hash) {
    if (!hash_equals($hash, hash_file('sha256', "$root/$file"))) { throw new RuntimeException('Código alterado depois do build: ' . $file); }
}
$zip = new ZipArchive();
$zip->open($zipPath);
for ($i=0; $i<$zip->numFiles; $i++) {
    $file = $zip->getNameIndex($i);
    if (preg_match('~(?:^|/)(?:\.env|\.git/|\.cache/|tests/|scripts/|node_modules/)|(?:STATUS\.json|\.log)$~', $file)) { throw new RuntimeException('Arquivo privado no ZIP: ' . $file); }
}
$catalog = json_decode($zip->getFromName('public_html/db/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
if (isset($catalog['admin_users']) || isset($catalog['checkout_logs'])) { throw new RuntimeException('Dados privados no catálogo.'); }
$imageFiles = [];
for ($i=0; $i<$zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    if (str_starts_with($entry, 'public_html/uploads/products/') && str_ends_with($entry, '.webp')) { $imageFiles[] = $entry; }
}
$counts = [];
foreach (['products','categories','product_images','admin_users','checkout_logs','site_settings'] as $table) { $counts[$table] = (int) db()->query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
$result = ['zip' => $release . '/lynch-hostinger.zip', 'sha256' => $manifest['zip_sha256'], 'files_verified' => count($manifest['files']), 'zip_bytes' => filesize($zipPath), 'catalog_counts' => array_map('count',$catalog), 'product_images_packaged' => count($imageFiles), 'original_database_counts' => $counts, 'verified_at' => date(DATE_ATOM)];
file_put_contents($root . '/docs/release-tests.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

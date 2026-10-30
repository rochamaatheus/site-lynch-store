<?php
/**
 * Cria o derivado -small (480x600) das fotos que ja existem em uploads/.
 * Fotos novas ja nascem com ele (ImageProcessor::SIZES). Seguro repetir: nao
 * sobrescreve o que existe e nao toca no banco.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root  = dirname(__DIR__) . '/uploads/products';
$made  = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!str_ends_with($path, '-detail.webp')) { continue; }
    $target = substr($path, 0, -strlen('-detail.webp')) . '-small.webp';
    if (is_file($target)) { continue; }
    $src = imagecreatefromwebp($path);
    if ($src === false) { continue; }
    $out = imagecreatetruecolor(480, 600);
    imagecopyresampled($out, $src, 0, 0, 0, 0, 480, 600, imagesx($src), imagesy($src));
    imagewebp($out, $target, 80);
    imagedestroy($src);
    imagedestroy($out);
    $made++;
}
echo "Derivados criados: $made\n";

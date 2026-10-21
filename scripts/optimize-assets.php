<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
foreach (glob($root . '/assets/hero/cutout-??.webp') as $path) {
    $source = imagecreatefromwebp($path);
    foreach ([360, 720] as $width) {
        $height = (int) round(imagesy($source) * $width / imagesx($source));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagewebp($image, substr($path, 0, -5) . '-' . $width . '.webp', 78);
        imagedestroy($image);
    }
    imagedestroy($source);
}
echo "Derivados responsivos do hero gerados; originais preservados.\n";

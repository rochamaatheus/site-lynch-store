<?php
/**
 * Lynch Store - gera a capa de compartilhamento e os icones da loja publica.
 *
 * Saidas (sobrescreve; rode de novo quando o nome, o slogan ou a cidade mudarem):
 *   assets/capa-1200x630.jpg e .webp     og:image e twitter:image (WhatsApp, Instagram, Google)
 *   assets/capa-1200x1200.jpg e .webp    capa quadrada (segunda og:image e JSON-LD)
 *   assets/icon-192.png, assets/icon-512.png   icones do manifest (any e maskable)
 *   favicon.ico                          16, 32 e 48 px (PNG dentro do ICO)
 *
 * So GD com FreeType e as fontes da propria loja (Shrikhand e Inter, convertidas
 * de assets/fonts/*.woff2 para TTF em scripts/fonts/, porque o GD nao le woff2).
 * As pecas da arara sao os recortes fixos de assets/hero/, nunca fotos do
 * catalogo: a capa funciona com a loja vazia. Nada aqui le ou grava o banco.
 *
 * Uso:
 *   php scripts/gerar-capa.php
 *   php scripts/gerar-capa.php --slogan="Um mundo sob medida para você" --cidade="Joinville, SC"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('imagettftext') || !function_exists('imagewebp')) {
    fwrite(STDERR, "Precisa da extensao GD com FreeType e WebP.\n");
    exit(1);
}

$root = dirname(__DIR__);
$opts = getopt('', ['nome::', 'slogan::', 'cidade::', 'linha::']);
$text = [
    'nome'   => (string) ($opts['nome'] ?? 'Lynch Store'),
    'slogan' => (string) ($opts['slogan'] ?? 'Um mundo sobre medida para você'),
    'cidade' => (string) ($opts['cidade'] ?? 'Joinville, SC'),
    'linha'  => (string) ($opts['linha'] ?? 'Moda geek, cosplay e camisetas'),
];
foreach ($text as $value) {
    if (str_contains($value, "\u{2014}")) { fwrite(STDERR, "Sem travessao nos textos da capa.\n"); exit(1); }
}

const SS = 2; // desenha em 2x e reduz: o GD nao suaviza poligonos
$F = [
    'display' => $root . '/scripts/fonts/Shrikhand-latin.ttf',
    'bold'    => $root . '/scripts/fonts/Inter-Loja-Bold.ttf',
    'medium'  => $root . '/scripts/fonts/Inter-Loja-Medium.ttf',
];
foreach ($F as $file) { if (!is_file($file)) { fwrite(STDERR, "Fonte ausente: $file\n"); exit(1); } }

// Paleta de assets/css/loja.css
const C = [
    'ink' => 0x171210, 'paper' => 0xF7EFE2, 'cream' => 0xFFF7EA, 'kraft' => 0xB98958,
    'wood' => 0x8D572A, 'cocoa' => 0x4A2815, 'sun' => 0xDDAE35, 'tape' => 0xF4D777,
    'rail' => 0xA56A38, 'petal' => 0xE5C56E, 'seed' => 0xC59A3A,
];

function col(GdImage $im, int $hex, int $alpha = 0): int
{
    return imagecolorallocatealpha($im, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255, $alpha);
}

function canvas(int $w, int $h, int $bg): GdImage
{
    $im = imagecreatetruecolor($w * SS, $h * SS);
    imagealphablending($im, true);
    imagefilledrectangle($im, 0, 0, $w * SS, $h * SS, col($im, $bg));
    return $im;
}

/** Ponto de uma cubica de Bezier. */
function bez(array $p0, array $p1, array $p2, array $p3, float $t): array
{
    $u = 1 - $t;
    return [
        $u ** 3 * $p0[0] + 3 * $u * $u * $t * $p1[0] + 3 * $u * $t * $t * $p2[0] + $t ** 3 * $p3[0],
        $u ** 3 * $p0[1] + 3 * $u * $u * $t * $p1[1] + 3 * $u * $t * $t * $p2[1] + $t ** 3 * $p3[1],
    ];
}

/** O girassol de sunflower() (12 petalas, viewBox 100) em (cx, cy) com raio r. */
function flower(GdImage $im, float $cx, float $cy, float $r, int $petal, int $seed, float $turn = 0): void
{
    $outline = [];
    foreach ([[[50, 34], [43, 26], [44, 11], [50, 3]], [[50, 3], [56, 11], [57, 26], [50, 34]]] as $seg) {
        for ($t = 0; $t <= 1.0001; $t += 1 / 14) { $outline[] = bez($seg[0], $seg[1], $seg[2], $seg[3], $t); }
    }
    $k = $r / 50 * SS;
    for ($i = 0; $i < 12; $i++) {
        $a = deg2rad($i * 30 + $turn);
        $pts = [];
        foreach ($outline as [$x, $y]) {
            $dx = $x - 50; $dy = $y - 50;
            $pts[] = (int) round($cx * SS + ($dx * cos($a) - $dy * sin($a)) * $k);
            $pts[] = (int) round($cy * SS + ($dx * sin($a) + $dy * cos($a)) * $k);
        }
        imagefilledpolygon($im, $pts, col($im, $petal));
    }
    imagefilledellipse($im, (int) ($cx * SS), (int) ($cy * SS), (int) (38 * $k), (int) (38 * $k), col($im, $seed));
}

/** Arara: barra de madeira com sombra, como .rail. */
function rail(GdImage $im, int $w, int $y, int $h = 16): void
{
    imagefilledrectangle($im, 0, ($y + $h) * SS, $w * SS, ($y + $h + 5) * SS, col($im, C['cocoa'], 100));
    for ($i = 0; $i < $h * SS; $i++) {
        $t = $i / ($h * SS);
        $from = $t < .55 ? C['rail'] : C['wood'];
        $to = $t < .55 ? C['wood'] : C['cocoa'];
        $f = $t < .55 ? $t / .55 : ($t - .55) / .45;
        $mix = 0;
        foreach ([16, 8, 0] as $sh) {
            $mix |= (int) round((($from >> $sh) & 255) * (1 - $f) + (($to >> $sh) & 255) * $f) << $sh;
        }
        imageline($im, 0, $y * SS + $i, $w * SS, $y * SS + $i, col($im, $mix));
    }
}

/** Cabide (o mesmo desenho do SVG .hanger) com a ponta do gancho em (cx, top). */
function hanger(GdImage $im, float $cx, float $top, float $width): void
{
    $k = $width / 120 * SS;
    $p = static fn (float $x, float $y): array => [(int) round(($cx - 60 * $width / 120) * SS + $x * $k), (int) round($top * SS + $y * $k)];
    imagesetthickness($im, (int) max(2, round(3.6 * $k)));
    $c = col($im, C['ink']);
    $hook = [];
    foreach ([[[60, 15], [60, 11], [67, 11], [67, 6]], [[67, 6], [67, 1], [63, 1], [60, 1]], [[60, 1], [57, 1], [54, 3], [54, 6]]] as $s) {
        for ($t = 0; $t <= 1.0001; $t += .1) { $hook[] = $p(...bez($s[0], $s[1], $s[2], $s[3], $t)); }
    }
    for ($i = 1, $n = count($hook); $i < $n; $i++) { imageline($im, $hook[$i - 1][0], $hook[$i - 1][1], $hook[$i][0], $hook[$i][1], $c); }
    $lines = [[[60, 15], [60, 26]], [[60, 26], [8, 50]], [[60, 26], [112, 50]], [[8, 50], [10, 55]], [[112, 50], [110, 55]], [[10, 55], [110, 55]]];
    foreach ($lines as [$a, $b]) { $pa = $p(...$a); $pb = $p(...$b); imageline($im, $pa[0], $pa[1], $pb[0], $pb[1], $c); }
    imagesetthickness($im, 1);
}

/** Peca recortada (assets/hero) com sombra suave, levemente inclinada. */
function garment(GdImage $im, string $file, float $x, float $y, float $w, float $deg): float
{
    $src = imagecreatefromwebp($file);
    $h = $w * imagesy($src) / imagesx($src);
    $pw = (int) round($w * SS); $ph = (int) round($h * SS);
    $piece = imagecreatetruecolor($pw, $ph);
    imagealphablending($piece, false); imagesavealpha($piece, true);
    imagefilledrectangle($piece, 0, 0, $pw, $ph, imagecolorallocatealpha($piece, 0, 0, 0, 127));
    imagecopyresampled($piece, $src, 0, 0, 0, 0, $pw, $ph, imagesx($src), imagesy($src));
    if ($deg != 0.0) {
        $piece = imagerotate($piece, $deg, imagecolorallocatealpha($piece, 0, 0, 0, 127));
        imagealphablending($piece, false); imagesavealpha($piece, true);
    }
    $rw = imagesx($piece); $rh = imagesy($piece);
    // Sombra: silhueta cacau reduzida e ampliada (desfoque barato), deslocada para baixo
    $small = imagecreatetruecolor(max(1, intdiv($rw, 10)), max(1, intdiv($rh, 10)));
    imagealphablending($small, false); imagesavealpha($small, true);
    imagecopyresampled($small, $piece, 0, 0, 0, 0, imagesx($small), imagesy($small), $rw, $rh);
    $sw = imagesx($small); $sh = imagesy($small);
    $cover = [];
    for ($i = 0; $i < $sw; $i++) {
        for ($j = 0; $j < $sh; $j++) { $cover[$i][$j] = (127 - ((imagecolorat($small, $i, $j) >> 24) & 127)) / 127; }
    }
    for ($pass = 0; $pass < 3; $pass++) { // desfoque de caixa 5x5, tres vezes (quase gaussiano)
        $next = [];
        for ($i = 0; $i < $sw; $i++) {
            for ($j = 0; $j < $sh; $j++) {
                $sum = 0; $n = 0;
                for ($di = -2; $di <= 2; $di++) {
                    for ($dj = -2; $dj <= 2; $dj++) {
                        if (isset($cover[$i + $di][$j + $dj])) { $sum += $cover[$i + $di][$j + $dj]; } $n++;
                    }
                }
                $next[$i][$j] = $sum / $n;
            }
        }
        $cover = $next;
    }
    for ($i = 0; $i < $sw; $i++) {
        for ($j = 0; $j < $sh; $j++) { imagesetpixel($small, $i, $j, col($small, C['cocoa'], (int) round(127 - $cover[$i][$j] * 127 * .4))); }
    }
    $shadow = imagescale($small, $rw, $rh, IMG_BICUBIC);
    imagealphablending($shadow, false); imagesavealpha($shadow, true);
    $ox = (int) round($x * SS - ($rw - $pw) / 2); $oy = (int) round($y * SS - ($rh - $ph) / 2);
    imagecopy($im, $shadow, $ox, $oy + 12 * SS, 0, 0, $rw, $rh);
    imagecopy($im, $piece, $ox, $oy, 0, 0, $rw, $rh);
    return $h;
}

/** Etiqueta kraft com furo e barbante preso na arara. */
function tag(GdImage $im, array $F, float $x, float $y, float $w, float $h, array $lines, ?float $railY): void
{
    $notch = 22;
    $cx = $x + $w / 2;
    // barbante: curva da arara ate o furo
    imagesetthickness($im, 2 * SS);
    $prev = null;
    for ($t = 0; $t <= 1.0001; $t += .05) {
        // Sem arara acima: so um laco curto de barbante saindo do furo
        $pt = $railY === null
            ? bez([$cx - 30, $y - 26], [$cx - 30, $y - 4], [$cx - 8, $y + 2], [$cx, $y + 13], $t)
            : bez([$cx - 34, $railY], [$cx - 40, $railY + 30], [$cx - 6, $y - 10], [$cx, $y + 13], $t);
        $pt = [(int) ($pt[0] * SS), (int) ($pt[1] * SS)];
        if ($prev) { imageline($im, $prev[0], $prev[1], $pt[0], $pt[1], col($im, C['cocoa'])); }
        $prev = $pt;
    }
    imagesetthickness($im, 1);
    $poly = static fn (float $d): array => array_map(static fn (float $v): int => (int) round($v * SS), [
        $x + $d, $y + $notch + $d, $cx, $y + $d, $x + $w + $d, $y + $notch + $d, $x + $w + $d, $y + $h + $d, $x + $d, $y + $h + $d,
    ]);
    imagefilledpolygon($im, $poly(5), col($im, C['cocoa'], 95));
    imagefilledpolygon($im, $poly(0), col($im, C['kraft']));
    imagefilledellipse($im, (int) ($cx * SS), (int) (($y + 13) * SS), 15 * SS, 15 * SS, col($im, C['cocoa']));
    imagefilledellipse($im, (int) ($cx * SS), (int) (($y + 13) * SS), 11 * SS, 11 * SS, col($im, C['paper']));
    $ty = $y + $notch + 20;
    foreach ($lines as [$font, $size, $str]) {
        $size = fit($F[$font], $str, $size, $w - 28);
        $box = imagettfbbox($size * SS, 0, $F[$font], $str);
        $tw = ($box[2] - $box[0]) / SS;
        $ty += $size * 1.33;
        imagettftext($im, $size * SS, 0, (int) (($cx - $tw / 2) * SS), (int) ($ty * SS), col($im, C['ink']), $F[$font], $str);
        $ty += $size * .45;
    }
}

/** Maior tamanho (em pontos) ate $max que cabe na largura. */
function fit(string $font, string $str, float $max, float $width): float
{
    for ($s = $max; $s > 8; $s -= .5) {
        $b = imagettfbbox($s * SS, 0, $font, $str);
        if (($b[2] - $b[0]) / SS <= $width) { return $s; }
    }
    return 8;
}

/** Texto com quebra de linha em ate $width; devolve o y final. */
function para(GdImage $im, string $font, float $size, string $str, float $x, float $y, float $width, int $color, float $lead = 1.25, bool $center = false, float $areaW = 0): float
{
    $words = explode(' ', $str); $line = ''; $lines = [];
    foreach ($words as $word) {
        $try = trim($line . ' ' . $word);
        $b = imagettfbbox($size * SS, 0, $font, $try);
        if ($line !== '' && ($b[2] - $b[0]) / SS > $width) { $lines[] = $line; $line = $word; } else { $line = $try; }
    }
    $lines[] = $line;
    foreach ($lines as $l) {
        $y += $size * 96 / 72 * $lead;
        $lx = $x;
        if ($center) { $b = imagettfbbox($size * SS, 0, $font, $l); $lx = $x + ($areaW - ($b[2] - $b[0]) / SS) / 2; }
        imagettftext($im, $size * SS, 0, (int) ($lx * SS), (int) ($y * SS), $color, $font, $l);
    }
    return $y;
}

/** Fita metrica no rodape (como .tape): faixa amarela, tracos a cada 0,1 e numeros. */
function tape(GdImage $im, int $w, int $y, int $h, string $font): void
{
    imagefilledrectangle($im, 0, $y * SS, $w * SS, ($y + $h) * SS, col($im, C['tape']));
    $c = col($im, C['cocoa']);
    imagefilledrectangle($im, 0, $y * SS, $w * SS, $y * SS + SS, $c);
    imagefilledrectangle($im, 0, ($y + $h) * SS - SS, $w * SS, ($y + $h) * SS, $c);
    $step = 64 / 10;
    for ($i = 0, $x = 0.0; $x < $w; $i++, $x += $step) {
        $len = $i % 10 === 0 ? $h * .62 : ($i % 5 === 0 ? 12 : 7);
        imagefilledrectangle($im, (int) ($x * SS), $y * SS, (int) ($x * SS) + SS - 1, (int) (($y + $len) * SS), $c);
        if ($i % 10 === 0 && $i > 0) {
            imagettftext($im, 10 * SS, 0, (int) (($x + 5) * SS), (int) (($y + $h - 9) * SS), $c, $font, (string) ($i / 10));
        }
    }
}

/** Fibras discretas de papel (semente fixa: a imagem sai igual a cada execucao). */
function grain(GdImage $im, int $w, int $h, int $n): void
{
    mt_srand(1461);
    for ($i = 0; $i < $n; $i++) {
        $x = mt_rand(0, $w * SS); $y = mt_rand(0, $h * SS); $len = mt_rand(6, 22);
        $a = mt_rand(0, 314) / 100;
        imageline($im, $x, $y, (int) ($x + cos($a) * $len), (int) ($y + sin($a) * $len), col($im, C['wood'], 112));
    }
}

/** Reduz de 2x para 1x e grava JPEG e WebP abaixo do limite (WhatsApp ignora capas pesadas). */
function save(GdImage $big, int $w, int $h, string $base, int $limit = 140_000): array
{
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $w, $h, imagesx($big), imagesy($big));
    $sizes = [];
    foreach (['jpg' => 'imagejpeg', 'webp' => 'imagewebp'] as $ext => $fn) {
        for ($q = 86; $q >= 50; $q -= 4) {
            if ($ext === 'jpg') { imageinterlace($out, true); }
            $fn($out, $base . '.' . $ext, $q);
            clearstatcache(true, $base . '.' . $ext);
            if (filesize($base . '.' . $ext) <= $limit) { break; }
        }
        $sizes[$ext] = [filesize($base . '.' . $ext), $q];
    }
    return $sizes;
}

$hero = $root . '/assets/hero/';
$report = [];

// ---------------------------------------------------------------------------
// 1200x630: texto a esquerda, arara com tres pecas a direita
// ---------------------------------------------------------------------------
$W = 1200; $H = 630;
$im = canvas($W, $H, C['sun']);
flower($im, 905, 300, 400, C['petal'], C['seed'], 8);
grain($im, $W, $H, 900);
rail($im, $W, 30);
$pieces = [['cutout-01-720.webp', 640, 222, -3.0], ['cutout-03-720.webp', 822, 250, 1.5], ['cutout-02-720.webp', 1000, 214, 3.5]];
foreach ($pieces as [$file, $px, $pw, $deg]) {
    hanger($im, $px + $pw / 2, 22, min(150, $pw * .78));
    garment($im, $hero . $file, $px, 74, $pw, $deg);
}
tag($im, $F, 64, 92, 218, 108, [['bold', 15, 'Ateliê em'], ['bold', 19, $text['cidade']]], 30);
$size = fit($F['display'], $text['nome'], 82, 560);
imagettftext($im, $size * SS, 0, 60 * SS, 340 * SS, col($im, C['ink']), $F['display'], $text['nome']);
$y = para($im, $F['bold'], fit($F['bold'], $text['slogan'], 25, 540), $text['slogan'], 64, 352, 540, col($im, C['ink']), 1.2);
$linha = $text['linha'] . '. Pedidos pelo WhatsApp.';
para($im, $F['medium'], fit($F['medium'], $linha, 17, 540), $linha, 64, $y + 12, 540, col($im, C['cocoa']), 1.3);
tape($im, $W, 578, 52, $F['bold']);
$report['assets/capa-1200x630'] = save($im, $W, $H, $root . '/assets/capa-1200x630');

// ---------------------------------------------------------------------------
// 1200x1200: arara em cima, nome e slogan centralizados embaixo
// ---------------------------------------------------------------------------
$W = 1200; $H = 1200;
$im = canvas($W, $H, C['sun']);
flower($im, 600, 380, 560, C['petal'], C['seed'], 8);
grain($im, $W, $H, 1500);
rail($im, $W, 40, 20);
$pieces = [['cutout-01-720.webp', 110, 300, -3.0], ['cutout-03-720.webp', 440, 310, 1.5], ['cutout-02-720.webp', 790, 296, 3.5]];
foreach ($pieces as [$file, $px, $pw, $deg]) {
    hanger($im, $px + $pw / 2, 30, min(190, $pw * .7));
    garment($im, $hero . $file, $px, 92, $pw, $deg);
}
// faixa de papel atras do texto, para leitura sobre as pecas
imagefilledrectangle($im, 0, 760 * SS, $W * SS, 1128 * SS, col($im, C['paper']));
grain($im, $W, $H, 0);
tag($im, $F, 916, 700, 230, 116, [['bold', 16, 'Ateliê em'], ['bold', 20, $text['cidade']]], null);
$size = fit($F['display'], $text['nome'], 118, 1000);
$b = imagettfbbox($size * SS, 0, $F['display'], $text['nome']);
imagettftext($im, $size * SS, 0, (int) ((($W - ($b[2] - $b[0]) / SS) / 2) * SS), 930 * SS, col($im, C['ink']), $F['display'], $text['nome']);
$y = para($im, $F['bold'], 34, $text['slogan'], 100, 950, 1000, col($im, C['ink']), 1.2, true, 1000);
para($im, $F['medium'], 22, $text['linha'], 100, $y + 10, 1000, col($im, C['cocoa']), 1.3, true, 1000);
tape($im, $W, 1128, 72, $F['bold']);
$report['assets/capa-1200x1200'] = save($im, $W, $H, $root . '/assets/capa-1200x1200');

// ---------------------------------------------------------------------------
// Icones: girassol da sacola sobre o amarelo da vitrine. O desenho fica dentro
// do circulo seguro de 80% (serve como "any" e "maskable").
// ---------------------------------------------------------------------------
$icon = static function (int $px): GdImage {
    $im = canvas($px, $px, C['sun']);
    flower($im, $px / 2, $px / 2, $px * .36, C['cream'], C['cocoa'], 0);
    $out = imagecreatetruecolor($px, $px);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $px, $px, $px * SS, $px * SS);
    return $out;
};
foreach ([192, 512] as $px) {
    imagepng($icon($px), $root . "/assets/icon-$px.png", 9);
    $report["assets/icon-$px.png"] = filesize($root . "/assets/icon-$px.png");
}
// favicon.ico com PNGs embutidos (aceito por todos os navegadores atuais)
$entries = [];
foreach ([16, 32, 48] as $px) {
    ob_start(); imagepng($icon($px), null, 9); $entries[$px] = ob_get_clean();
}
$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);
$data = '';
foreach ($entries as $px => $png) {
    $ico .= pack('CCCCvvVV', $px, $px, 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
    $data .= $png;
}
file_put_contents($root . '/favicon.ico', $ico . $data);
$report['favicon.ico'] = filesize($root . '/favicon.ico');

foreach ($report as $name => $info) {
    echo str_pad($name, 26) . (is_array($info)
        ? implode('  ', array_map(static fn ($ext, $v) => "$ext " . round($v[0] / 1024, 1) . " KB (q$v[1])", array_keys($info), $info))
        : round($info / 1024, 1) . ' KB') . "\n";
}

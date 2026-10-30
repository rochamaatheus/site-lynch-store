<?php
/**
 * Lynch Store - pipeline de imagem de produto (GD puro)
 *
 * Por que existe: as fotos da vitrine chegam em proporcoes muito diferentes
 * (o catalogo atual varia de 591x1280 a 1024x1280) e o CSS forcava todas numa
 * caixa 4:5 com object-fit:cover CENTRALIZADO - o recorte saia aleatorio em
 * cada foto (fatia de tecido, etiqueta, pescoco cortado). A correcao e recortar
 * no SERVIDOR, uma vez, num enquadramento controlado; ai o cover do CSS vira
 * um no-op seguro em vez de competir com o arquivo.
 *
 * Deliberadamente SO GD (sem Imagick): a hospedagem alvo e compartilhada e nao
 * garante Imagick. Nao ha fila/worker - roda dentro do request do upload.
 *
 * Este arquivo NAO valida seguranca de arquivo: isso e responsabilidade de
 * includes/Upload.php (7 camadas ja testadas). Aqui o arquivo ja chegou
 * aprovado; se for chamado com lixo, ele simplesmente falha com ok=false.
 *
 * Derivados gerados a partir do mesmo nome base (ex.: 'a1b2c3d4'):
 *   a1b2c3d4-src.webp      original normalizado, lado maior <= 2000px
 *   a1b2c3d4.webp          "card"   720x900   -> e o que vai em file_path
 *   a1b2c3d4-thumb.webp    miniatura 240x300
 *   a1b2c3d4-detail.webp   galeria 1080x1350
 *
 * Uso:
 *     require_once __DIR__ . '/ImageProcessor.php';
 *     $r = ImageProcessor::makeProductDerivatives($abs, 'image/jpeg',
 *                                                 'uploads/products/12', $base, 15);
 *     if ($r['ok']) { $card = $r['paths']['card']; }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class ImageProcessor
{
    /** Derivados publicos: sufixo => [largura, altura]. Todos 4:5. */
    public const SIZES = [
        'thumb'  => [240, 300],
        'small'  => [480, 600],
        'card'   => [720, 900],
        'detail' => [1080, 1350],
    ];

    /** Lado maior do arquivo-fonte guardado para permitir re-recorte. */
    public const SOURCE_MAX_SIDE = 2000;

    public const QUALITY_SOURCE = 86;
    public const QUALITY_CARD   = 82;
    public const QUALITY_THUMB  = 78;

    /** Padrao de foco vertical: 15% = vies pro topo (roupa em cabide/manequim). */
    public const FOCAL_TOP    = 15;
    public const FOCAL_CENTER = 50;
    public const FOCAL_BOTTOM = 85;

    /**
     * Rotulo legivel de um focal_y, para a UI do admin.
     * Faixas em vez de igualdade: um valor herdado/editado a mao continua
     * caindo num rotulo em vez de sumir do <select>.
     */
    public static function focalLabel(int $focalY): string
    {
        if ($focalY <= 33) {
            return 'Topo';
        }

        return $focalY <= 66 ? 'Centro' : 'Base';
    }

    /** Normaliza qualquer entrada para um dos 3 focos expostos na UI. */
    public static function normalizeFocal(mixed $value): int
    {
        $n = (int) $value;
        if ($n <= 33) {
            return self::FOCAL_TOP;
        }

        return $n <= 66 ? self::FOCAL_CENTER : self::FOCAL_BOTTOM;
    }

    /**
     * Gera os 4 arquivos a partir de uma imagem recem-validada.
     *
     * @param string $srcAbsPath caminho ABSOLUTO do arquivo de origem (tmp do
     *                           upload ou um arquivo ja em disco)
     * @param string $mime       MIME real ja detectado por Upload::detectMime()
     * @param string $destDir    caminho RELATIVO a raiz ('uploads/products/12')
     * @param string $baseName   nome sem extensao nem sufixo ('a1b2c3d4')
     * @return array{ok:bool,paths:array<string,string>,error:?string}
     *         paths tem as chaves src|card|thumb|detail, todas relativas a raiz.
     */
    public static function makeProductDerivatives(
        string $srcAbsPath,
        string $mime,
        string $destDir,
        string $baseName,
        int $focalY = self::FOCAL_TOP,
        int $focalX = self::FOCAL_CENTER
    ): array {
        if (!function_exists('imagewebp')) {
            return self::fail('O servidor nao tem suporte a WEBP no GD.');
        }

        $dir = self::resolveDir($destDir);
        if ($dir === null) {
            return self::fail('Pasta de destino invalida ou sem permissao de escrita.');
        }
        [$absDir, $relDir] = $dir;

        $baseName = self::sanitizeBase($baseName);
        if ($baseName === '') {
            return self::fail('Nome base invalido.');
        }

        $img = self::load($srcAbsPath, $mime);
        if ($img === null) {
            return self::fail('Nao foi possivel abrir a imagem para processamento.');
        }

        $img = self::applyExifRotation($img, $srcAbsPath, $mime);

        $written = [];
        $paths   = [];

        try {
            // --- fonte normalizada -------------------------------------------
            // Guardar o original e o que permite trocar o foco depois sem pedir
            // reupload. Nao se guarda o JPEG bruto de 8MB para sempre: 2000px de
            // lado maior sobra para qualquer recorte futuro.
            $source = self::downscaleToBox($img, self::SOURCE_MAX_SIDE);
            $srcRel = $relDir . '/' . $baseName . '-src.webp';
            if (!self::writeWebp($source, $absDir . DIRECTORY_SEPARATOR . $baseName . '-src.webp', self::QUALITY_SOURCE)) {
                self::destroy($source, $img);
                self::cleanup($written);

                return self::fail('Falha ao gravar a imagem de origem.');
            }
            $written[] = $absDir . DIRECTORY_SEPARATOR . $baseName . '-src.webp';
            $paths['src'] = $srcRel;

            // Os 3 derivados saem da imagem em RESOLUCAO CHEIA (nao da fonte ja
            // reduzida): um passo de reamostragem a menos = menos borrao.
            $result = self::renderDerivatives($img, $absDir, $relDir, $baseName, $focalY, $focalX);

            self::destroy($source, $img);

            if (!$result['ok']) {
                self::cleanup($written);

                return $result;
            }

            return ['ok' => true, 'paths' => $paths + $result['paths'], 'error' => null];
        } catch (Throwable $e) {
            self::destroy($img);
            self::cleanup($written);

            return self::fail('Erro ao processar a imagem: ' . $e->getMessage());
        }
    }

    /**
     * Regera SO os 3 derivados a partir de um arquivo "-src" ja gravado.
     * E o que a acao "Recortar: Topo/Centro/Base" do admin usa - sobrescreve os
     * mesmos nomes de arquivo, entao nenhuma URL do banco muda.
     *
     * @param string $sourceRelPath caminho relativo do -src.webp
     * @return array{ok:bool,paths:array<string,string>,error:?string}
     */
    public static function remakeDerivatives(
        string $sourceRelPath,
        int $focalY = self::FOCAL_TOP,
        int $focalX = self::FOCAL_CENTER
    ): array {
        $rel = trim(str_replace('\\', '/', $sourceRelPath), '/');
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'uploads/')) {
            return self::fail('Caminho de origem invalido.');
        }

        $abs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            return self::fail('Arquivo de origem nao encontrado no servidor.');
        }

        $relDir  = dirname($rel);
        $absDir  = dirname($abs);
        $fileName = basename($rel);

        // 'a1b2c3d4-src.webp' -> 'a1b2c3d4'
        $base = preg_replace('/-src\.[A-Za-z0-9]+$/', '', $fileName) ?? '';
        $base = self::sanitizeBase($base);
        if ($base === '' || $base === $fileName) {
            return self::fail('Nome do arquivo de origem fora do padrao esperado.');
        }

        $img = self::load($abs, null);
        if ($img === null) {
            return self::fail('Nao foi possivel abrir a imagem de origem.');
        }

        $result = self::renderDerivatives($img, $absDir, $relDir, $base, $focalY, $focalX);
        self::destroy($img);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------------

    /**
     * Grava os 3 derivados. Escreve primeiro num arquivo temporario e so entao
     * renomeia: um recorte que falhe no meio nao deixa a vitrine com meia foto.
     *
     * @return array{ok:bool,paths:array<string,string>,error:?string}
     */
    private static function renderDerivatives(
        GdImage $img,
        string $absDir,
        string $relDir,
        string $baseName,
        int $focalY,
        int $focalX
    ): array {
        $focalY = max(0, min(100, $focalY));
        $focalX = max(0, min(100, $focalX));

        $alpha   = self::hasAlpha($img);
        $pending = [];   // [tmpAbs, finalAbs]
        $paths   = [];

        foreach (self::SIZES as $suffix => [$w, $h]) {
            $out = self::cropCover($img, $w, $h, $focalY, $focalX, $alpha);
            if ($out === null) {
                self::rollbackTemp($pending);

                return self::fail('Falha ao redimensionar a imagem (' . $suffix . ').');
            }

            // O unsharp compensa o borrao do imagecopyresampled. Pulado quando
            // ha canal alfa: imageconvolution do GD estraga a transparencia.
            if (!$alpha) {
                self::sharpen($out);
            }

            // O "card" e o arquivo publico principal: fica sem sufixo, entao
            // tudo que ja lia file_path continua funcionando sem mudar codigo.
            $fileName = $suffix === 'card' ? $baseName . '.webp' : $baseName . '-' . $suffix . '.webp';
            $finalAbs = $absDir . DIRECTORY_SEPARATOR . $fileName;
            $tmpAbs   = $finalAbs . '.tmp';

            $quality = $suffix === 'thumb' ? self::QUALITY_THUMB : self::QUALITY_CARD;
            $ok      = self::writeWebp($out, $tmpAbs, $quality);
            imagedestroy($out);

            if (!$ok) {
                self::rollbackTemp($pending);

                return self::fail('Falha ao gravar o arquivo ' . $fileName . '.');
            }

            $pending[]       = [$tmpAbs, $finalAbs];
            $paths[$suffix]  = $relDir . '/' . $fileName;
        }

        foreach ($pending as [$tmpAbs, $finalAbs]) {
            // No Windows rename() nao sobrescreve destino existente.
            if (is_file($finalAbs)) {
                @unlink($finalAbs);
            }
            if (!@rename($tmpAbs, $finalAbs)) {
                self::rollbackTemp($pending);

                return self::fail('Falha ao publicar ' . basename($finalAbs) . '.');
            }
            @chmod($finalAbs, 0644);
        }

        return ['ok' => true, 'paths' => $paths, 'error' => null];
    }

    /**
     * Recorte "cover" com ponto de foco configuravel.
     *
     * O fator de escala e max(alvo_w/origem_w, alvo_h/origem_h) - a mesma conta
     * do object-fit:cover. A diferenca esta na janela: em vez de centralizar
     * sempre, ela desliza pelo eixo sobrando na proporcao focalY/focalX. Com
     * focalY=15 numa foto vertical, a janela encosta perto do topo - onde fica a
     * peca de roupa - em vez de pegar a barra/o chao.
     */
    private static function cropCover(
        GdImage $img,
        int $targetW,
        int $targetH,
        int $focalY,
        int $focalX,
        bool $alpha
    ): ?GdImage {
        $sw = imagesx($img);
        $sh = imagesy($img);
        if ($sw < 1 || $sh < 1) {
            return null;
        }

        $scale = max($targetW / $sw, $targetH / $sh);

        // Janela de recorte no espaco da ORIGEM (nunca maior que a origem).
        $winW = min($sw, max(1, (int) round($targetW / $scale)));
        $winH = min($sh, max(1, (int) round($targetH / $scale)));

        $srcX = (int) round(($sw - $winW) * ($focalX / 100));
        $srcY = (int) round(($sh - $winH) * ($focalY / 100));
        $srcX = max(0, min($sw - $winW, $srcX));
        $srcY = max(0, min($sh - $winH, $srcY));

        $dst = imagecreatetruecolor($targetW, $targetH);
        if (!$dst instanceof GdImage) {
            return null;
        }

        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, (int) $transparent);
        } else {
            // Fundo claro: se por algum motivo sobrar borda, ela some no creme
            // do site em vez de virar uma faixa preta.
            $bg = imagecolorallocate($dst, 255, 247, 234);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, (int) $bg);
        }

        if (!imagecopyresampled($dst, $img, 0, 0, $srcX, $srcY, $targetW, $targetH, $winW, $winH)) {
            imagedestroy($dst);

            return null;
        }

        return $dst;
    }

    /**
     * Unsharp leve. A matriz soma 16 com divisor 16 (ganho 1), entao ela nao
     * clareia nem escurece a foto - so realca a borda o suficiente para desfazer
     * o amaciamento do reamostramento, sem halo visivel.
     */
    private static function sharpen(GdImage $img): void
    {
        if (!function_exists('imageconvolution')) {
            return;
        }

        @imageconvolution($img, [
            [-1, -1, -1],
            [-1, 24, -1],
            [-1, -1, -1],
        ], 16, 0);
    }

    /**
     * Reduz proporcionalmente ate caber num quadrado de $maxSide.
     * Se a imagem ja for menor, devolve uma COPIA (o chamador destroi as duas
     * sem se preocupar com quem e quem).
     */
    private static function downscaleToBox(GdImage $img, int $maxSide): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $scale = min(1.0, $maxSide / max($w, $h));
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    /**
     * A imagem tem pixel translucido? Amostragem em grade (~2500 pontos): uma
     * varredura pixel a pixel numa foto de 6000x6000 custaria 36 milhoes de
     * chamadas so para responder uma pergunta booleana.
     */
    private static function hasAlpha(GdImage $img): bool
    {
        if (!imageistruecolor($img)) {
            return imagecolortransparent($img) >= 0;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $stepX = max(1, (int) floor($w / 50));
        $stepY = max(1, (int) floor($h / 50));

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                if ((imagecolorat($img, $x, $y) >> 24) & 0x7F) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Corrige a orientacao gravada no EXIF (foto de celular vem "deitada" com a
     * rotacao so no metadado). ext/exif nao e garantida em hospedagem
     * compartilhada - sem ela, segue sem corrigir em vez de recusar o upload.
     */
    private static function applyExifRotation(GdImage $img, string $absPath, ?string $mime): GdImage
    {
        if ($mime !== null && $mime !== 'image/jpeg') {
            return $img;   // so JPEG carrega EXIF de orientacao na pratica
        }
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($absPath);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        $angle = match ($orientation) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $img;
        }

        $rotated = @imagerotate($img, (float) $angle, 0);
        if (!$rotated instanceof GdImage) {
            return $img;
        }

        imagedestroy($img);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private static function load(string $absPath, ?string $mime): ?GdImage
    {
        if ($mime === null) {
            $info = @getimagesize($absPath);
            $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        }

        $img = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($absPath) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($absPath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false,
            default      => false,
        };

        if (!$img instanceof GdImage) {
            return null;
        }

        // PNG indexado com transparencia perde o alfa em qualquer resample se
        // nao virar truecolor antes.
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return $img;
    }

    private static function writeWebp(GdImage $img, string $absPath, int $quality): bool
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $ok = @imagewebp($img, $absPath, $quality);
        if (!$ok && is_file($absPath)) {
            @unlink($absPath);   // nunca deixa arquivo pela metade em disco
        }

        return (bool) $ok;
    }

    /**
     * @return array{0:string,1:string}|null [absDir, relDir]
     */
    private static function resolveDir(string $destDir): ?array
    {
        $relDir = trim(str_replace('\\', '/', $destDir), '/');
        if ($relDir === '' || str_contains($relDir, '..')) {
            return null;
        }

        $absDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return null;
        }
        if (!is_writable($absDir)) {
            return null;
        }

        return [$absDir, $relDir];
    }

    /** So [a-z0-9_-]: o nome vem de random_bytes, mas nada custa garantir. */
    private static function sanitizeBase(string $base): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $base);
    }

    /** @param array<int,array{0:string,1:string}> $pending */
    private static function rollbackTemp(array $pending): void
    {
        foreach ($pending as [$tmpAbs, ]) {
            if (is_file($tmpAbs)) {
                @unlink($tmpAbs);
            }
        }
    }

    /** @param array<int,string> $files */
    private static function cleanup(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function destroy(?GdImage ...$images): void
    {
        foreach ($images as $img) {
            if ($img instanceof GdImage) {
                imagedestroy($img);
            }
        }
    }

    /**
     * @return array{ok:bool,paths:array<string,string>,error:string}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'paths' => [], 'error' => $message];
    }
}

<?php
/**
 * Lynch Store - validacao e gravacao de imagens enviadas pelo admin (fase 6)
 *
 * Usado pelo gerenciador de fotos de produto e pelo banner do hero em
 * Configuracoes. E a unica porta de entrada de arquivo do projeto.
 *
 * Camadas de defesa (nesta ordem, todas obrigatorias):
 *   1. $_FILES['x']['error'] === UPLOAD_ERR_OK  (o resto so faz sentido depois);
 *   2. is_uploaded_file()  - o caminho tem mesmo que ter vindo de um POST;
 *   3. tamanho <= 5MB;
 *   4. MIME REAL via finfo_file() contra allowlist - NUNCA $_FILES['x']['type'],
 *      que e texto escolhido pelo navegador (e por qualquer script hostil);
 *   5. getimagesize() - segunda opiniao independente do finfo; derruba
 *      "polyglot" (arquivo que e imagem valida no cabecalho e PHP no fim);
 *   6. dimensoes <= 6000x6000 (uma foto de 30000x30000 e um decompression bomb:
 *      passa em tudo acima e estoura a memoria do PHP na conversao);
 *   7. nome de arquivo ALEATORIO (random_bytes) + extensao derivada do MIME
 *      detectado - o nome original do cliente nunca chega ao disco.
 *
 * Ainda assim o arquivo cai em uploads/, que tem .htaccess proprio removendo
 * qualquer handler de PHP - defesa em profundidade, nao alternativa ao acima.
 *
 * Uso:
 *     require_once __DIR__ . '/Upload.php';
 *     $r = Upload::handle($_FILES['foto'], 'uploads/products/12');
 *     if ($r['ok']) { $caminho = $r['path']; } else { echo $r['error']; }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ImageProcessor.php';

final class Upload
{
    /** Tamanho maximo aceito (5 MB). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Dimensoes maximas aceitas (anti decompression bomb). */
    public const MAX_WIDTH  = 6000;
    public const MAX_HEIGHT = 6000;

    /** MIME real => extensao gravada. Qualquer outro MIME e recusado. */
    private const ALLOWED = [
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    /** Qualidade usada na conversao para webp. */
    private const WEBP_QUALITY = 82;

    /**
     * Valida e grava UM arquivo.
     *
     * @param array<string,mixed> $file      uma entrada de $_FILES
     * @param string              $destDir   caminho RELATIVO a raiz do site
     *                                       (ex.: 'uploads/products/12')
     * @return array{ok:bool,path:?string,error:?string}
     *         path e relativo a raiz ('uploads/products/12/ab12cd.webp'), pronto
     *         para ir direto para product_images.file_path / site_settings.
     */
    public static function handle(array $file, string $destDir): array
    {
        $check = self::validate($file);
        if (!$check['ok']) {
            return self::fail((string) $check['error']);
        }

        $tmp  = (string) $check['tmp'];
        $mime = (string) $check['mime'];

        // --- 7. destino + nome aleatorio --------------------------------------
        $relDir = trim(str_replace('\\', '/', $destDir), '/');
        if ($relDir === '' || str_contains($relDir, '..')) {
            return self::fail('Pasta de destino invalida.');
        }

        $absDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return self::fail('Nao foi possivel criar a pasta de destino.');
        }
        if (!is_writable($absDir)) {
            return self::fail('Sem permissao de escrita na pasta de destino.');
        }

        $ext      = self::ALLOWED[$mime];
        $convert  = $ext !== 'webp' && self::canConvertToWebp($mime);
        $finalExt = $convert ? 'webp' : $ext;

        // random_bytes: o nome enviado pelo cliente e descartado por completo
        // (path traversal, ".php.webp", colisao e caractere exotico somem juntos).
        do {
            $name = bin2hex(random_bytes(8)) . '.' . $finalExt;
            $abs  = $absDir . DIRECTORY_SEPARATOR . $name;
        } while (file_exists($abs));

        // --- gravacao ----------------------------------------------------------
        if ($convert && self::convertToWebp($tmp, $abs, $mime)) {
            @chmod($abs, 0644);

            return ['ok' => true, 'path' => $relDir . '/' . $name, 'error' => null];
        }

        // Sem GD/webp (ou conversao falhou): grava no formato original. Nao vale
        // reprovar o upload da Ray por causa de uma extensao ausente na hospedagem.
        if ($convert) {
            $name = bin2hex(random_bytes(8)) . '.' . $ext;
            $abs  = $absDir . DIRECTORY_SEPARATOR . $name;
        }

        $moved = PHP_SAPI === 'cli'
            ? @rename($tmp, $abs)
            : @move_uploaded_file($tmp, $abs);

        if (!$moved) {
            return self::fail('Falha ao gravar o arquivo no servidor.');
        }

        @chmod($abs, 0644);

        return ['ok' => true, 'path' => $relDir . '/' . $name, 'error' => null];
    }

    /**
     * Foto de PRODUTO: mesma validacao de handle(), mas em vez de gravar o
     * arquivo como veio, gera os 4 derivados de ImageProcessor (src + card +
     * thumb + detail), todos ja recortados em 4:5.
     *
     * Existe separado de handle() de proposito: o banner do hero (Configuracoes)
     * NAO pode ser recortado em 4:5 - ninguem sabe em que proporcao ele sera
     * exibido. Quem sabe que a proporcao e 4:5 e a vitrine de produto.
     *
     * @param array<string,mixed> $file uma entrada de $_FILES
     * @param string $destDir  caminho RELATIVO ('uploads/products/12')
     * @return array{ok:bool,path:?string,source_path:?string,paths:array<string,string>,error:?string}
     *         path = o derivado "card" (720x900), que vai em file_path.
     */
    public static function handleProductPhoto(
        array $file,
        string $destDir,
        int $focalY = ImageProcessor::FOCAL_TOP
    ): array {
        $check = self::validate($file);
        if (!$check['ok']) {
            return self::failPhoto((string) $check['error']);
        }

        $tmp  = (string) $check['tmp'];
        $mime = (string) $check['mime'];

        // Hospedagem sem WEBP no GD: em vez de recusar a foto da Ray, cai no
        // fluxo antigo (valida + grava). Fica sem recorte, mas o site funciona.
        if (!function_exists('imagewebp')) {
            $legacy = self::handle($file, $destDir);

            return [
                'ok'          => $legacy['ok'],
                'path'        => $legacy['path'],
                'source_path' => null,
                'paths'       => [],
                'error'       => $legacy['error'],
            ];
        }

        $relDir = trim(str_replace('\\', '/', $destDir), '/');
        if ($relDir === '' || str_contains($relDir, '..')) {
            return self::failPhoto('Pasta de destino invalida.');
        }

        $absDir = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return self::failPhoto('Nao foi possivel criar a pasta de destino.');
        }

        // Mesma politica de nome de handle(): aleatorio, o nome do cliente morre
        // aqui. Os 4 derivados nascem desta unica base.
        do {
            $base = bin2hex(random_bytes(8));
        } while (file_exists($absDir . DIRECTORY_SEPARATOR . $base . '.webp'));

        $r = ImageProcessor::makeProductDerivatives($tmp, $mime, $relDir, $base, $focalY);
        if (!$r['ok']) {
            return self::failPhoto((string) $r['error']);
        }

        return [
            'ok'          => true,
            'path'        => $r['paths']['card'],
            'source_path' => $r['paths']['src'],
            'paths'       => $r['paths'],
            'error'       => null,
        ];
    }

    /**
     * As 6 primeiras camadas de defesa, compartilhadas por handle() e
     * handleProductPhoto(). Extraidas sem nenhuma mudanca de regra - so deixaram
     * de estar copiadas em dois lugares.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,tmp?:string,mime?:string,path?:null,error:?string}
     */
    private static function validate(array $file): array
    {
        // --- 1. erro do proprio upload ---------------------------------------
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail(self::uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return self::fail('Arquivo temporario nao encontrado no servidor.');
        }

        // --- 2. veio mesmo de um upload HTTP? --------------------------------
        // Sem isso, um bug em outro ponto do codigo que deixasse o atacante
        // controlar tmp_name viraria leitura de arquivo arbitrario do servidor.
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            return self::fail('Arquivo invalido (nao veio de um envio de formulario).');
        }

        // --- 3. tamanho -------------------------------------------------------
        $size = (int) (@filesize($tmp) ?: 0);
        if ($size <= 0) {
            return self::fail('Arquivo vazio.');
        }
        if ($size > self::MAX_BYTES) {
            return self::fail(sprintf(
                'Arquivo muito grande (%s). O limite e %s.',
                self::humanBytes($size),
                self::humanBytes(self::MAX_BYTES)
            ));
        }

        // --- 4. MIME REAL (nunca $file['type']) -------------------------------
        $mime = self::detectMime($tmp);
        if ($mime === null) {
            return self::fail('Nao foi possivel identificar o tipo do arquivo.');
        }
        if (!isset(self::ALLOWED[$mime])) {
            return self::fail('Formato nao aceito (' . $mime . '). Envie JPG, PNG ou WEBP.');
        }

        // --- 5. segunda opiniao: getimagesize() -------------------------------
        $info = @getimagesize($tmp);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return self::fail('O arquivo nao e uma imagem valida.');
        }

        // O MIME que o getimagesize deduz do CONTEUDO tem que bater com o do
        // finfo. Divergencia = arquivo hibrido/adulterado.
        $gdMime = strtolower((string) ($info['mime'] ?? ''));
        if ($gdMime !== $mime) {
            return self::fail('Arquivo de imagem inconsistente (recusado por seguranca).');
        }

        // --- 6. dimensoes -----------------------------------------------------
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            return self::fail(sprintf(
                'Imagem grande demais (%dx%d). O maximo e %dx%d pixels.',
                $width,
                $height,
                self::MAX_WIDTH,
                self::MAX_HEIGHT
            ));
        }

        return ['ok' => true, 'tmp' => $tmp, 'mime' => $mime, 'error' => null];
    }

    /**
     * Reorganiza $_FILES['campo'] de um <input multiple> (que o PHP entrega
     * "transposto": name[], type[], tmp_name[] em arrays paralelos) numa lista
     * de arrays no formato de arquivo unico, pronta para handle().
     *
     * Entradas UPLOAD_ERR_NO_FILE sao descartadas: o navegador manda uma
     * mesmo quando o usuario nao escolheu nada.
     *
     * @param array<string,mixed> $field
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeMultiple(array $field): array
    {
        if (!isset($field['name'])) {
            return [];
        }

        // Campo simples (input sem multiple)
        if (!is_array($field['name'])) {
            return ((int) ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
                ? []
                : [$field];
        }

        $out = [];
        foreach (array_keys($field['name']) as $i) {
            $one = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $one[$key] = $field[$key][$i] ?? null;
            }
            if ((int) ($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = $one;
        }

        return $out;
    }

    /**
     * Remove do disco um arquivo referenciado por caminho RELATIVO a raiz.
     * Recusa qualquer caminho fora de uploads/ (nunca deve dar para apagar um
     * .php do projeto a partir de uma linha do banco).
     *
     * Nao falha se o arquivo ja nao existir - apagar a linha do banco e o
     * objetivo, o arquivo orfao e um detalhe.
     */
    public static function deleteFile(?string $relativePath): bool
    {
        $rel = trim(str_replace('\\', '/', (string) $relativePath), '/');
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'uploads/')) {
            return false;
        }

        $abs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            return false;
        }

        return @unlink($abs);
    }

    /**
     * Apaga uma foto de produto INTEIRA: o card (file_path), os dois derivados
     * irmaos (-thumb/-detail) e o original (-src). Sem isso, excluir uma foto no
     * admin deixaria 3 arquivos orfaos ocupando espaco da hospedagem para sempre.
     *
     * @return int quantos arquivos sairam do disco
     */
    public static function deleteProductPhoto(?string $filePath, ?string $sourcePath = null): int
    {
        $alvos = [];

        $card = trim((string) $filePath);
        if ($card !== '') {
            $alvos[] = $card;

            $dot = strrpos($card, '.');
            if ($dot !== false) {
                foreach (array_keys(ImageProcessor::SIZES) as $suffix) {
                    if ($suffix === 'card') {
                        continue;   // ja e o proprio $card, sem sufixo
                    }
                    $alvos[] = substr($card, 0, $dot) . '-' . $suffix . substr($card, $dot);
                }
            }
        }

        $src = trim((string) $sourcePath);
        if ($src !== '') {
            $alvos[] = $src;
        }

        $removidos = 0;
        foreach (array_unique($alvos) as $alvo) {
            if (self::deleteFile($alvo)) {
                $removidos++;
            }
        }

        return $removidos;
    }

    // -----------------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------------

    /**
     * MIME real do conteudo do arquivo, ou null.
     */
    private static function detectMime(string $path): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower(trim(explode(';', $mime)[0]));
                }
            }
        }

        // Fallback (hospedagem sem ext/fileinfo): getimagesize le o conteudo
        // tambem, entao continua sendo deteccao real, nao a extensao do nome.
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return strtolower((string) $info['mime']);
        }

        return null;
    }

    private static function canConvertToWebp(string $mime): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png'  => function_exists('imagecreatefrompng'),
            default      => false,
        };
    }

    private static function convertToWebp(string $src, string $dest, string $mime): bool
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            default      => false,
        };

        if (!$img instanceof GdImage) {
            return false;
        }

        // PNG com transparencia perderia o canal alfa sem estas duas linhas.
        if ($mime === 'image/png') {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $ok = @imagewebp($img, $dest, self::WEBP_QUALITY);
        imagedestroy($img);

        if (!$ok && is_file($dest)) {
            @unlink($dest);   // nao deixa arquivo pela metade no disco
        }

        return (bool) $ok;
    }

    /**
     * @return array{ok:bool,path:?string,error:?string}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'path' => null, 'error' => $message];
    }

    /**
     * @return array{ok:bool,path:?string,source_path:?string,paths:array<string,string>,error:?string}
     */
    private static function failPhoto(string $message): array
    {
        return ['ok' => false, 'path' => null, 'source_path' => null, 'paths' => [], 'error' => $message];
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Arquivo maior que o limite do servidor (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido. Tente de novo.',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Servidor sem pasta temporaria configurada.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor nao conseguiu gravar o arquivo em disco.',
            UPLOAD_ERR_EXTENSION  => 'Envio bloqueado por uma extensao do PHP.',
            default               => 'Falha no envio do arquivo (codigo ' . $code . ').',
        };
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}

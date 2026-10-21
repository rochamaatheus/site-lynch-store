<?php
/**
 * Lynch Store - reprocessa as fotos ja cadastradas pelo pipeline de imagem
 *
 * Uso (linha de comando, a partir da raiz do projeto):
 *   php db/reprocess-images.php              processa o que ainda nao foi
 *   php db/reprocess-images.php --dry-run    so mostra o que faria
 *   php db/reprocess-images.php --force      refaz TAMBEM o que ja tem original
 *   php db/reprocess-images.php --focal=50   muda o ponto de recorte (0-100)
 *
 * Por que existe: as 46 fotos semeadas na fase 2 foram copiadas cruas para
 * uploads/ (591x1280 a 1024x1280, proporcoes diferentes entre si). Elas
 * precisam passar pelo mesmo pipeline que uma foto nova de upload, senao a
 * vitrine continua recortando cada uma de um jeito no CSS.
 *
 * IDEMPOTENTE: so toca linhas com source_path IS NULL. Rodar de novo nao
 * reprocessa nada. Com --force, refaz os 3 derivados a partir do -src que ja
 * existe (util para mudar o enquadramento de todo o catalogo de uma vez) - e
 * mesmo nesse modo NAO gera arquivo novo nem muda file_path.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ImageProcessor.php';
require_once __DIR__ . '/../includes/Upload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so pode ser executado pela linha de comando.');
}

/** Escreve uma linha no stdout (nunca no pipeline de retorno). */
function ri_out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/** Caminho absoluto de um caminho relativo a raiz do site. */
function ri_abs(string $relative): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
}

// ---------------------------------------------------------------------------
// Argumentos
// ---------------------------------------------------------------------------
$argvList = $argv ?? [];
$dryRun   = in_array('--dry-run', $argvList, true);
$force    = in_array('--force', $argvList, true);
$focal    = ImageProcessor::FOCAL_TOP;

foreach ($argvList as $arg) {
    if (str_starts_with((string) $arg, '--focal=')) {
        $focal = max(0, min(100, (int) substr((string) $arg, 8)));
    }
}

if (!function_exists('imagewebp')) {
    ri_out('');
    ri_out('[reprocess] ERRO: este PHP nao tem suporte a WEBP no GD.');
    ri_out('[reprocess] Sem isso o pipeline nao roda. Nada foi alterado.');
    ri_out('');
    exit(1);
}

$pdo = db();

// A coluna pode nao existir num banco criado antes deste ajuste - avisa com o
// comando exato em vez de estourar uma PDOException crua.
try {
    $pdo->query('SELECT source_path, focal_y FROM product_images LIMIT 1');
} catch (Throwable $e) {
    ri_out('');
    ri_out('[reprocess] ERRO: product_images ainda nao tem source_path/focal_y.');
    ri_out('[reprocess] Rode primeiro:');
    ri_out('');
    ri_out('  ALTER TABLE product_images');
    ri_out('    ADD COLUMN source_path VARCHAR(255) DEFAULT NULL AFTER file_path,');
    ri_out('    ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER source_path;');
    ri_out('');
    exit(1);
}

$where = $force ? '1 = 1' : 'source_path IS NULL';
$rows  = $pdo->query(
    'SELECT id, product_id, file_path, source_path, focal_y
       FROM product_images
      WHERE ' . $where . '
      ORDER BY product_id ASC, id ASC'
)->fetchAll();

$total = count($rows);

ri_out('');
ri_out('[reprocess] Lynch Store - pipeline de imagem');
ri_out('[reprocess] modo ....... ' . ($force ? 'FORCE (refaz tudo)' : 'pendentes (source_path IS NULL)'));
ri_out('[reprocess] foco ....... ' . $focal . ' (' . ImageProcessor::focalLabel($focal) . ')');
ri_out('[reprocess] alvos ...... ' . $total . ' foto(s)');
if ($dryRun) {
    ri_out('[reprocess] DRY-RUN: nada sera gravado.');
}
ri_out('');

if ($total === 0) {
    ri_out('[reprocess] Nada a fazer. Todas as fotos ja passaram pelo pipeline.');
    ri_out('');
    exit(0);
}

$update = $pdo->prepare(
    'UPDATE product_images SET file_path = ?, source_path = ?, focal_y = ? WHERE id = ?'
);

$feitos = 0;
$falhas = [];

foreach ($rows as $row) {
    $imageId   = (int) $row['id'];
    $productId = (int) $row['product_id'];
    $filePath  = (string) $row['file_path'];
    $srcPath   = trim((string) ($row['source_path'] ?? ''));
    $rotulo    = '#' . $imageId . ' (produto ' . $productId . ') ' . basename($filePath);

    // -----------------------------------------------------------------------
    // Ja tem original guardado: e so refazer os 3 derivados por cima. Nenhum
    // arquivo novo, nenhuma mudanca de file_path.
    // -----------------------------------------------------------------------
    if ($srcPath !== '') {
        if (!is_file(ri_abs($srcPath))) {
            $falhas[] = $rotulo . ': original ' . $srcPath . ' nao existe em disco';
            continue;
        }

        if ($dryRun) {
            ri_out('  [seria refeito] ' . $rotulo . ' <- ' . basename($srcPath));
            $feitos++;
            continue;
        }

        $r = ImageProcessor::remakeDerivatives($srcPath, $focal);
        if (!$r['ok']) {
            $falhas[] = $rotulo . ': ' . (string) $r['error'];
            continue;
        }

        $update->execute([$filePath, $srcPath, $focal, $imageId]);
        ri_out('  [refeito] ' . $rotulo);
        $feitos++;
        continue;
    }

    // -----------------------------------------------------------------------
    // Foto crua da fase 2: o proprio file_path e a origem.
    // -----------------------------------------------------------------------
    $origemAbs = ri_abs($filePath);
    if (!is_file($origemAbs)) {
        $falhas[] = $rotulo . ': arquivo nao existe em disco';
        continue;
    }

    $info = @getimagesize($origemAbs);
    if ($info === false || empty($info['mime'])) {
        $falhas[] = $rotulo . ': nao e uma imagem legivel';
        continue;
    }
    $mime = strtolower((string) $info['mime']);

    // Mesma pasta de sempre (uploads/products/{id}) e nome aleatorio novo, igual
    // ao de um upload. Nao reaproveita o nome antigo de proposito: assim o
    // arquivo cru continua intacto ate o banco confirmar, e o navegador de quem
    // ja visitou o site nao serve a versao velha do cache.
    $destDir = str_replace('\\', '/', dirname($filePath));

    if ($dryRun) {
        ri_out('  [seria processado] ' . $rotulo . ' ' . $info[0] . 'x' . $info[1] . ' ' . $mime);
        $feitos++;
        continue;
    }

    $absDir = ri_abs($destDir);
    do {
        $base = bin2hex(random_bytes(8));
    } while (file_exists($absDir . DIRECTORY_SEPARATOR . $base . '.webp'));

    $r = ImageProcessor::makeProductDerivatives($origemAbs, $mime, $destDir, $base, $focal);
    if (!$r['ok']) {
        $falhas[] = $rotulo . ': ' . (string) $r['error'];
        continue;
    }

    try {
        $update->execute([$r['paths']['card'], $r['paths']['src'], $focal, $imageId]);
    } catch (Throwable $e) {
        // Banco recusou: desfaz os 4 arquivos para nao deixar lixo orfao.
        Upload::deleteProductPhoto($r['paths']['card'], $r['paths']['src']);
        $falhas[] = $rotulo . ': falha ao gravar no banco - ' . $e->getMessage();
        continue;
    }

    // O arquivo antigo so sai depois que o banco ja aponta para o novo.
    if ($r['paths']['card'] !== $filePath) {
        Upload::deleteFile($filePath);
    }

    $dimCard = @getimagesize(ri_abs($r['paths']['card']));
    ri_out(sprintf(
        '  [ok] %s  %dx%d -> %dx%d',
        $rotulo,
        (int) $info[0],
        (int) $info[1],
        (int) ($dimCard[0] ?? 0),
        (int) ($dimCard[1] ?? 0)
    ));
    $feitos++;
}

// ---------------------------------------------------------------------------
// Resumo
// ---------------------------------------------------------------------------
ri_out('');
ri_out('---------------------------------------------------------------');
ri_out('[reprocess] ' . ($dryRun ? 'SIMULACAO CONCLUIDA' : 'CONCLUIDO'));
ri_out('---------------------------------------------------------------');
ri_out('  processadas ... ' . $feitos . ' de ' . $total);
ri_out('  falhas ........ ' . count($falhas));

foreach ($falhas as $falha) {
    ri_out('    - ' . $falha);
}
ri_out('');

exit($falhas === [] ? 0 : 1);

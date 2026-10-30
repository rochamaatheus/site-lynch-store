<?php
/**
 * Lynch Store - seed inicial do catalogo (fase 2)
 *
 * Uso (linha de comando, a partir da raiz do projeto):
 *   php db/seed.php
 *
 * O que faz:
 *   1. 4 categorias (Cosplay, Fantasia, Camisetas, Novidades)
 *   2. 46 produtos placeholder, replicando exatamente a logica que hoje roda em
 *      JavaScript no index.html (productImages.map(...)):
 *        cosplay    = id <= 12
 *        colecao    = cosplay ? (id<=6 ? Cosplay : Fantasia)
 *                             : (id%4===0 ? Novidades : Camisetas)
 *        nome       = "Peca de cosplay NN" / "Camiseta geek NN"
 *        preco      = sempre "Consultar valor" (price_on_request = 1)
 *   3. 1 imagem por produto: assets/products/product-NN.webp passa pelo pipeline
 *      de ImageProcessor (recorte 4:5 + 3 tamanhos + original) em
 *      uploads/products/{id}/ e o derivado "card" e registrado em product_images.
 *      Sem WEBP no GD, cai numa copia crua do arquivo (comportamento antigo).
 *   4. site_settings (textos do hero + intro da mensagem de WhatsApp)
 *   5. 1 usuario admin, com senha vinda do .env e gravada em password_hash()
 *
 * IDEMPOTENTE: se ja existir qualquer produto no banco, o script avisa e sai
 * sem alterar nada. As insercoes rodam todas dentro de uma unica transacao.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ImageProcessor.php';

// ---------------------------------------------------------------------------
// Este script e exclusivamente de linha de comando.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so pode ser executado pela linha de comando.');
}

const SEED_TOTAL_PRODUCTS = 46;

/**
 * Gera um slug ASCII a partir de um texto livre.
 * minusculas, acentos -> equivalente ASCII, nao-alfanumerico -> "-",
 * sem "-" duplicado nem nas pontas.
 */
function slugify(string $text): string
{
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Å' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'Ç' => 'c', 'Ñ' => 'n', 'Ý' => 'y',
        'ª' => 'a', 'º' => 'o', '&' => '-e-',
    ];

    $text = strtr($text, $map);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    // Qualquer coisa que nao seja [a-z0-9] vira separador.
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text;
}

/** Escreve uma linha no stdout (nunca no pipeline de retorno). */
function out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

// ---------------------------------------------------------------------------
// Guarda de idempotencia
// ---------------------------------------------------------------------------
$pdo = db();

$existingProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($existingProducts > 0) {
    out('');
    out('[seed] Banco JA semeado: ' . $existingProducts . ' produto(s) em products.');
    out('[seed] Nada foi inserido, alterado ou apagado.');
    out('[seed] Para semear do zero, esvazie as tabelas manualmente antes.');
    out('');
    exit(0);
}

// ---------------------------------------------------------------------------
// Dados fixos
// ---------------------------------------------------------------------------
$categories = [
    ['name' => 'Cosplay',   'slug' => 'cosplay',   'sort_order' => 0],
    ['name' => 'Fantasia',  'slug' => 'fantasia',  'sort_order' => 1],
    ['name' => 'Camisetas', 'slug' => 'camisetas', 'sort_order' => 2],
    ['name' => 'Novidades', 'slug' => 'novidades', 'sort_order' => 3],
];

// Dados reais da loja (WhatsApp, Instagram, horario, endereco): db/dados-lynch.php.
require_once __DIR__ . '/dados-lynch.php';
$settings = [
    'hero_title'              => 'Um mundo sob medida.',
    'hero_subtitle'           => 'Acessórios, moda kawaii geek e cosplay com clima artesanal, tons terrosos e atendimento direto pelo WhatsApp.',
    'hero_cta_label'          => 'Ver novidades',
    'whatsapp_message_intro'  => 'Olá! Quero fazer um pedido na Lynch Store:',
] + lynch_store_settings();

$sourceDir = APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'products';
$uploadsProductsDir = UPLOADS_PATH . DIRECTORY_SEPARATOR . 'products';

$copiedFiles     = [];  // para desfazer as copias caso a transacao caia
$createdDirs     = [];
$missingImages   = [];
$countCategories = 0;
$countProducts   = 0;
$countImages     = 0;
$countSettings   = 0;
$adminCreated    = false;
$adminUsername   = '';

out('');
out('[seed] Lynch Store - semeando o catalogo inicial...');

try {
    $pdo->beginTransaction();

    // -----------------------------------------------------------------------
    // 1. Categorias
    // -----------------------------------------------------------------------
    $selCat = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
    $insCat = $pdo->prepare(
        'INSERT INTO categories (name, slug, sort_order, active) VALUES (?, ?, ?, 1)'
    );

    $categoryIds = [];  // nome => id
    foreach ($categories as $cat) {
        $selCat->execute([$cat['slug']]);
        $id = $selCat->fetchColumn();

        if ($id === false) {
            $insCat->execute([$cat['name'], $cat['slug'], $cat['sort_order']]);
            $id = (int) $pdo->lastInsertId();
            $countCategories++;
        } else {
            $id = (int) $id;
        }

        $categoryIds[$cat['name']] = $id;
    }
    out('[seed] categorias: ' . $countCategories . ' inserida(s).');

    // -----------------------------------------------------------------------
    // 2. Produtos + 3. Imagens
    // -----------------------------------------------------------------------
    $insProduct = $pdo->prepare(
        'INSERT INTO products
            (category_id, name, slug, price_cents, price_on_request, availability, active, sort_order)
         VALUES (?, ?, ?, NULL, 1, ?, 1, ?)'
    );
    $insImage = $pdo->prepare(
        'INSERT INTO product_images (product_id, file_path, source_path, focal_y, alt_text, is_primary, sort_order)
         VALUES (?, ?, ?, ?, ?, 1, 0)'
    );

    for ($i = 1; $i <= SEED_TOTAL_PRODUCTS; $i++) {
        $nn      = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $cosplay = $i <= 12;

        // Mesma logica do index.html (products = productImages.map(...))
        $collection = $cosplay
            ? ($i <= 6 ? 'Cosplay' : 'Fantasia')
            : ($i % 4 === 0 ? 'Novidades' : 'Camisetas');

        $baseName     = $cosplay ? 'Peça de cosplay' : 'Camiseta geek';
        $name         = $baseName . ' ' . $nn;
        $slug         = slugify($baseName) . '-' . $nn;
        $availability = $cosplay ? 'sob_encomenda' : 'pronta_entrega';

        $insProduct->execute([
            $categoryIds[$collection],
            $name,
            $slug,
            $availability,
            $i,
        ]);

        $productId = (int) $pdo->lastInsertId();
        $countProducts++;

        // --- imagem de capa ---------------------------------------------------
        $source = $sourceDir . DIRECTORY_SEPARATOR . 'product-' . $nn . '.webp';

        if (!is_file($source)) {
            $missingImages[] = 'product-' . $nn . '.webp (produto #' . $productId . ')';
            out('[seed] AVISO: origem nao encontrada: ' . $source . ' - produto criado sem imagem.');
            continue;
        }

        $destDir = $uploadsProductsDir . DIRECTORY_SEPARATOR . $productId;
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $missingImages[] = 'product-' . $nn . '.webp (falha ao criar ' . $destDir . ')';
                out('[seed] AVISO: nao consegui criar o diretorio ' . $destDir . ' - produto sem imagem.');
                continue;
            }
            $createdDirs[] = $destDir;
        }

        // Caminho relativo a raiz do site, sempre com "/" (vai para o HTML).
        $relDestDir = 'uploads/products/' . $productId;

        // Pipeline completo: recorte 4:5 com foco no topo + card/thumb/detail +
        // o original guardado. E o mesmo caminho de um upload pelo admin, entao
        // uma instalacao nova ja nasce com as fotos bem enquadradas - sem
        // precisar rodar db/reprocess-images.php depois.
        $processed = ImageProcessor::makeProductDerivatives(
            $source,
            'image/webp',
            $relDestDir,
            (string) $productId,
            ImageProcessor::FOCAL_TOP
        );

        if ($processed['ok']) {
            $relativePath = $processed['paths']['card'];
            $relSourcePath = $processed['paths']['src'];
            foreach ($processed['paths'] as $rel) {
                $copiedFiles[] = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            }
        } else {
            // Hospedagem sem WEBP no GD (ou erro de processamento): copia crua,
            // como na versao original do seed. O site funciona; o recorte fica
            // por conta do CSS ate alguem rodar o reprocessamento.
            out('[seed] AVISO: pipeline de imagem falhou (' . (string) $processed['error'] . ') - copiando o arquivo cru.');

            $destFile = $destDir . DIRECTORY_SEPARATOR . $productId . '.webp';
            if (!copy($source, $destFile)) {
                $missingImages[] = 'product-' . $nn . '.webp (falha ao copiar)';
                out('[seed] AVISO: falha ao copiar ' . $source . ' - produto sem imagem.');
                continue;
            }
            $copiedFiles[] = $destFile;

            $relativePath  = $relDestDir . '/' . $productId . '.webp';
            $relSourcePath = null;
        }

        $insImage->execute([
            $productId,
            $relativePath,
            $relSourcePath,
            ImageProcessor::FOCAL_TOP,
            $name . ' - foto real da Lynch Store',
        ]);
        $countImages++;
    }

    out('[seed] produtos: ' . $countProducts . ' inserido(s).');
    out('[seed] imagens: ' . $countImages . ' copiada(s) e registrada(s).');

    // -----------------------------------------------------------------------
    // 4. site_settings
    //    ON DUPLICATE KEY sem alteracao: nunca sobrescreve valor ja configurado
    //    pela Ray no admin (ex: o numero real de WhatsApp).
    // -----------------------------------------------------------------------
    $insSetting = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );

    foreach ($settings as $key => $value) {
        $insSetting->execute([$key, $value]);
        if ($insSetting->rowCount() > 0) {
            $countSettings++;
        }
    }
    out('[seed] site_settings: ' . $countSettings . ' chave(s) inserida(s).');

    // -----------------------------------------------------------------------
    // 5. Usuario admin
    // -----------------------------------------------------------------------
    $adminUsername = trim((string) env('ADMIN_USERNAME', ''));
    $adminPassword = (string) env('ADMIN_PASSWORD', '');

    if ($adminUsername === '' || $adminPassword === '') {
        throw new RuntimeException(
            'ADMIN_USERNAME e/ou ADMIN_PASSWORD ausentes no .env - nao da para criar o usuario admin.'
        );
    }

    $selAdmin = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
    $selAdmin->execute([$adminUsername]);

    if ($selAdmin->fetchColumn() === false) {
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Falha ao gerar o hash da senha do admin.');
        }

        $insAdmin = $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, display_name, active)
             VALUES (?, ?, ?, 1)'
        );
        $insAdmin->execute([$adminUsername, $hash, $adminUsername]);
        $adminCreated = true;
    }
    unset($adminPassword);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Desfaz as copias de arquivo (que nao participam da transacao do banco).
    foreach ($copiedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    foreach (array_reverse($createdDirs) as $dir) {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    out('');
    out('[seed] ERRO: ' . $e->getMessage());
    out('[seed] Transacao revertida e arquivos copiados removidos. Nada foi gravado.');
    out('');
    exit(1);
}

// ---------------------------------------------------------------------------
// Resumo
// ---------------------------------------------------------------------------
out('');
out('---------------------------------------------------------------');
out('[seed] CONCLUIDO');
out('---------------------------------------------------------------');
out('  categorias inseridas ....... ' . $countCategories);
out('  produtos inseridos ......... ' . $countProducts);
out('  imagens copiadas/registradas ' . $countImages . ' de ' . SEED_TOTAL_PRODUCTS);
out('  settings inseridas ......... ' . $countSettings);

if ($adminCreated) {
    out('  admin ...................... criado');
    out('');
    out('Admin criado: usuario "' . $adminUsername . '" - troque a senha no primeiro login.');
} else {
    out('  admin ...................... ja existia (nao alterado)');
    out('');
    out('Usuario admin "' . $adminUsername . '" ja existia; senha mantida.');
}

if ($missingImages !== []) {
    out('');
    out('AVISOS de imagem (' . count($missingImages) . '):');
    foreach ($missingImages as $miss) {
        out('  - ' . $miss);
    }
}

out('');
exit(0);

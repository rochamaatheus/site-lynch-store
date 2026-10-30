<?php
/**
 * Lynch Store - grava um produto (fase 6)
 *
 * Handler POST puro: nao imprime HTML, sempre termina em redirect (Post/
 * Redirect/Get - F5 depois de salvar nao regrava nada).
 *
 * Filosofia de validacao: o unico campo que TRAVA o cadastro e o nome. Slug
 * vazio ou duplicado, preco mal digitado e categoria inexistente sao
 * CORRIGIDOS e avisados por flash - a Ray nao pode perder um texto longo de
 * descricao porque errou um detalhe num campo secundario.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';
require_once dirname(__DIR__) . '/includes/Upload.php';
require_once dirname(__DIR__) . '/includes/ImageProcessor.php';

/** Redireciona e encerra. */
function ps_redirect(string $path): never
{
    header('Location: ' . APP_BASE_URL . $path, true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// So POST, e so com CSRF valido
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Redireciona em vez de responder 405: quem cai aqui por GET e a Ray com um
    // link antigo/favorito, e o util para ela e voltar para a lista com um aviso.
    flash_set('error', 'Essa página só responde ao envio de um formulário. Abra o produto pela lista e salve por lá.');
    ps_redirect('/admin/produtos.php');
}

// Fotos acima do limite do servidor chegam com o POST vazio: nao ha o que
// salvar, mas a mensagem precisa dizer o motivo certo.
if (post_body_too_large()) {
    flash_set('error', 'As fotos somam mais do que o servidor aceita de uma vez (' . post_limit_mb() . ' MB). Nada foi salvo: escolha 2 ou 3 fotos por vez.');
    ps_redirect(isset($_GET['id']) ? '/admin/produto-form.php?id=' . (int) $_GET['id'] : '/admin/produto-form.php');
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    flash_set('error', 'A página ficou aberta por muito tempo e o cadastro não foi aceito. Abra o produto de novo e repita a alteração.');
    ps_redirect('/admin/produtos.php');
}

$id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$editando = $id > 0;

if ($editando && ProductRepository::findAnyById($id) === null) {
    flash_set('error', 'Produto não encontrado (id ' . $id . ').');
    ps_redirect('/admin/produtos.php');
}

$voltarPara = $editando
    ? '/admin/produto-form.php?id=' . $id
    : '/admin/produto-form.php';

// ---------------------------------------------------------------------------
// Campos
// ---------------------------------------------------------------------------
$name = trim((string) ($_POST['name'] ?? ''));
$_SESSION['product_form_old'] = ['id' => $id, 'data' => $_POST, 'errors' => []];

/** Recusa o envio com o erro ao lado do campo (o formulario volta preenchido). */
function ps_field_fail(string $field, string $message, string $back): never
{
    $_SESSION['product_form_old']['errors'] = [$field => $message];
    ps_redirect($back);
}

if ($name === '') {
    ps_field_fail('name', 'Escreva o nome do produto.', $voltarPara);
}

$avisos = [];

// --- slug ---
$slugPedido = slugify((string) ($_POST['slug'] ?? ''), 200);
if ($slugPedido === '') {
    $slugPedido = slugify($name, 200);
    if ($slugPedido === '') {
        // Nome so com simbolos/emoji: nao da para derivar slug nenhum.
        $slugPedido = 'produto';
    }
}

$slug = ProductRepository::uniqueSlug($slugPedido, $editando ? $id : null);
if ($slug !== $slugPedido) {
    $avisos[] = 'O endereço "' . $slugPedido . '" já estava em uso; salvamos como "' . $slug . '".';
}

// --- categoria ---
$categoryId = (int) ($_POST['category_id'] ?? 0);
if ($categoryId > 0 && CategoryRepository::findAnyById($categoryId) === null) {
    $avisos[]   = 'A categoria escolhida não existe mais; o produto ficou sem categoria.';
    $categoryId = 0;
}

// --- preco ---
$onRequest  = !empty($_POST['price_on_request']);
$precoTexto = (string) ($_POST['price'] ?? '');
$cents      = $onRequest ? null : price_to_cents($precoTexto);

if (!$onRequest && $cents === null) {
    ps_field_fail('price', trim($precoTexto) === ''
        ? 'Digite o preço, como 129,90, ou marque "Sem preço na loja".'
        : 'Esse preço não ficou claro. Escreva assim: 129,90.', $voltarPara);
}

// --- estoque e promocao ---
$stock = null; $saleCents = null; $saleEnds = null;
if (ProductRepository::stockReady()) {
    try {
        $stock = ProductRepository::parseStock($_POST['stock_qty'] ?? '');
    } catch (DomainException $e) {
        ps_field_fail('stock_qty', $e->getMessage(), $voltarPara);
    }
    try {
        $saleText = (string) ($_POST['sale_price'] ?? '');
        $pctText = (string) ($_POST['sale_percent'] ?? '');
        $endsText = (string) ($_POST['sale_ends_on'] ?? '');
        if (trim($saleText) !== '' || trim($pctText) !== '') {
            $original = $editando ? ProductRepository::findAnyById($id) : null;
            // Data final que ja passou e nao foi mexida: promocao encerrada fica registrada.
            $keepExpired = $original !== null && $endsText !== '' && $endsText === (string) $original['sale_ends_on'] && $endsText < date('Y-m-d');
            [$saleCents, $saleEnds] = ProductRepository::parseSale(
                ['price_cents' => $cents, 'price_on_request' => $onRequest],
                $saleText,
                $pctText,
                $keepExpired ? '' : $endsText
            );
            if ($keepExpired) { $saleEnds = $endsText; }
        }
    } catch (DomainException $e) {
        ps_field_fail('sale_price', $e->getMessage() . ' Nada foi salvo.', $voltarPara);
    }
}

$dados = [
    'stock_qty'         => $stock,
    'show_stock'        => !empty($_POST['show_stock']),
    'sold_out'          => !empty($_POST['sold_out']),
    'sale_price_cents'  => $saleCents,
    'sale_ends_on'      => $saleEnds,
    'category_id'       => $categoryId,
    'name'              => $name,
    'slug'              => $slug,
    'short_description' => (string) ($_POST['short_description'] ?? ''),
    'description'       => (string) ($_POST['description'] ?? ''),
    'price_cents'       => $cents,
    'price_on_request'  => $onRequest,
    'availability'      => (string) ($_POST['availability'] ?? 'sob_encomenda'),
    'active'            => !empty($_POST['active']),
    'featured'          => !empty($_POST['featured']),
    'sort_order'        => (int) ($_POST['sort_order'] ?? 0),
];

// ---------------------------------------------------------------------------
// Grava
// ---------------------------------------------------------------------------
try {
    if ($editando) {
        ProductRepository::update($id, $dados);
        $novoId = $id;
        SecurityLog::audit((int) $admin['id'], 'produto_salvo', 'atualizado: ' . $name);
        flash_set('success', 'Produto "' . $name . '" atualizado.');
    } else {
        $novoId = ProductRepository::create($dados);
        SecurityLog::audit((int) $admin['id'], 'produto_salvo', 'cadastrado: ' . $name);
        flash_set('success', 'Produto "' . $name . '" cadastrado.');
    }
} catch (Throwable $e) {
    error_log('[lynch][produto-salvar] ' . $e->getMessage());
    flash_set('error', 'Não foi possível salvar o produto. Tente novamente.');
    ps_redirect($voltarPara);
}

foreach ($avisos as $aviso) {
    flash_set('info', $aviso);
}
unset($_SESSION['product_form_old']);

// ---------------------------------------------------------------------------
// Fotos escolhidas no proprio formulario (opcional). Mesmo caminho de
// produto-imagens.php: validacao por conteudo, recorte 4:5 e derivados WebP.
// O produto ja esta salvo; uma foto recusada nao desfaz o cadastro.
// ---------------------------------------------------------------------------
$arquivos = isset($_FILES['fotos']) ? Upload::normalizeMultiple($_FILES['fotos']) : [];
$arquivos = array_values(array_filter($arquivos, static fn ($f) => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
if ($arquivos !== []) {
    $enviadas = 0;
    foreach ($arquivos as $arquivo) {
        $r = Upload::handleProductPhoto($arquivo, 'uploads/products/' . $novoId, ImageProcessor::FOCAL_TOP);
        if (!$r['ok']) {
            flash_set('error', 'Foto recusada: ' . (string) ($arquivo['name'] ?? 'arquivo') . ': ' . (string) $r['error']);
            continue;
        }
        ProductRepository::addImage($novoId, (string) $r['path'], $name, $r['source_path'], ImageProcessor::FOCAL_TOP);
        $enviadas++;
    }
    if ($enviadas > 0) {
        flash_set('success', $enviadas . ' foto' . ($enviadas === 1 ? '' : 's') . ' enviada' . ($enviadas === 1 ? '' : 's') . '.');
    }
}

// Ao CRIAR, o destino natural e a tela de fotos: o produto acabou de ganhar um
// id e nao tem imagem nenhuma. Ao editar, volta para o proprio formulario.
if ($editando) {
    ps_redirect('/admin/produto-form.php?id=' . $novoId);
}

ps_redirect('/admin/produto-imagens.php?id=' . $novoId);

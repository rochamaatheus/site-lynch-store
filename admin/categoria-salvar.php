<?php
/**
 * Lynch Store - grava uma categoria (fase 6)
 *
 * POST puro + CSRF, sempre terminando em redirect (Post/Redirect/Get).
 * Mesma politica de produto-salvar.php: so o nome trava o cadastro; slug vazio
 * ou duplicado e ajustado automaticamente e avisado por flash.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';

/** Redireciona e encerra. */
function cs_redirect(string $query = ''): never
{
    header('Location: ' . APP_BASE_URL . '/admin/categorias.php' . $query, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Ver produto-salvar.php: GET aqui vira redirect com aviso, nao 405.
    flash_set('error', 'Essa página só responde ao envio de um formulário.');
    cs_redirect();
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    flash_set('error', 'A página ficou aberta por muito tempo e a categoria não foi salva. Tente de novo.');
    cs_redirect();
}

$id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$editando = $id > 0;

if ($editando && CategoryRepository::findAnyById($id) === null) {
    flash_set('error', 'Categoria não encontrada.');
    cs_redirect();
}

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    flash_set('error', 'O nome da categoria e obrigatorio.');
    cs_redirect($editando ? '?editar=' . $id : '');
}

$slugPedido = slugify((string) ($_POST['slug'] ?? ''), 120);
if ($slugPedido === '') {
    $slugPedido = slugify($name, 120);
    if ($slugPedido === '') {
        $slugPedido = 'categoria';
    }
}

$slug = CategoryRepository::uniqueSlug($slugPedido, $editando ? $id : null);

$dados = [
    'name'        => $name,
    'slug'        => $slug,
    'description' => (string) ($_POST['description'] ?? ''),
    'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
    'active'      => !empty($_POST['active']),
];

try {
    if ($editando) {
        CategoryRepository::update($id, $dados);
        flash_set('success', 'Categoria "' . $name . '" atualizada.');
    } else {
        CategoryRepository::create($dados);
        flash_set('success', 'Categoria "' . $name . '" criada.');
    }
} catch (Throwable $e) {
    error_log('[lynch][categoria-salvar] ' . $e->getMessage());
    flash_set('error', 'Nao foi possivel salvar a categoria. Tente novamente.');
    cs_redirect($editando ? '?editar=' . $id : '');
}

if ($slug !== $slugPedido) {
    flash_set('info', 'O endereço "' . $slugPedido . '" já estava em uso; salvamos como "' . $slug . '".');
}

cs_redirect();

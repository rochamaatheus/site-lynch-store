<?php
/**
 * Lynch Store - desativa (ou reativa) um produto (fase 6)
 *
 * "Excluir" no painel = active=0. NUNCA um DELETE real:
 *   - checkout_logs guarda snapshots historicos que citam esses produtos;
 *   - as fotos em uploads/ continuam validas se a Ray se arrepender;
 *   - reativar e um clique, recadastrar e meia hora de trabalho.
 *
 * POST puro (uma acao destrutiva nunca pode ser acionavel por link/prefetch),
 * com CSRF, sempre terminando em redirect.
 *
 * action=delete  -> desativa
 * action=restore -> reativa
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';

/** Redireciona e encerra. */
function pe_redirect(): never
{
    header('Location: ' . APP_BASE_URL . '/admin/produtos.php', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Ver produto-salvar.php: GET aqui vira redirect com aviso, nao 405.
    flash_set('error', 'Essa ação só pode ser feita pelo botão do painel.');
    pe_redirect();
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    flash_set('error', 'A página ficou aberta por muito tempo e a ação não foi aceita. Tente de novo.');
    pe_redirect();
}

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'delete');

if (!in_array($action, ['delete', 'restore'], true)) {
    flash_set('error', 'Ação desconhecida.');
    pe_redirect();
}

$produto = $id > 0 ? ProductRepository::findAnyById($id) : null;
if ($produto === null) {
    flash_set('error', 'Produto não encontrado.');
    pe_redirect();
}

$nome = (string) $produto['name'];

try {
    if ($action === 'restore') {
        ProductRepository::restore($id);
        flash_set('success', 'Produto "' . $nome . '" reativado e visível na loja de novo.');
    } else {
        ProductRepository::softDelete($id);
        flash_set('success', 'Produto "' . $nome . '" desativado. Ele sumiu da loja, mas continua aqui e pode ser reativado.');
    }
} catch (Throwable $e) {
    error_log('[lynch][produto-excluir] ' . $e->getMessage());
    flash_set('error', 'Não foi possível concluir a ação. Tente novamente.');
}

pe_redirect();

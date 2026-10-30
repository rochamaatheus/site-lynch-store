<?php
/**
 * Lynch Store - desativa ou apaga uma categoria (fase 6)
 *
 * DECISAO DE PROJETO (registrada aqui e explicada na propria tela):
 * categoria tem DUAS acoes, e nao uma so, porque as duas sao legitimas:
 *
 *   action=toggle  -> liga/desliga active. Reversivel, mantem o vinculo dos
 *                     produtos. E o caminho recomendado no dia a dia (o botao
 *                     que aparece primeiro na lista).
 *   action=delete  -> DELETE real. Seguro porque a FK products.category_id e
 *                     ON DELETE SET NULL: nenhum produto e apagado, eles so
 *                     ficam "sem categoria" e podem ser recategorizados.
 *
 * Produto e diferente: la existe soft-delete e ponto final, porque
 * checkout_logs guarda historico que cita produto por id/nome. Categoria nao
 * aparece em checkout_logs, entao apagar de verdade nao rasga historico nenhum.
 *
 * O DELETE nao e bloqueado quando ha produtos vinculados - so avisado, antes
 * (confirm no navegador) e depois (flash dizendo quantos ficaram sem categoria).
 *
 * POST puro + CSRF, sempre terminando em redirect.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';

/** Redireciona e encerra. */
function ce_redirect(): never
{
    header('Location: ' . APP_BASE_URL . '/admin/categorias.php', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Ver produto-salvar.php: GET aqui vira redirect com aviso, nao 405.
    flash_set('error', 'Essa ação só pode ser feita pelo botão do painel.');
    ce_redirect();
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    flash_set('error', 'A página ficou aberta por muito tempo e a ação não foi aceita. Tente de novo.');
    ce_redirect();
}

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'toggle');

if (!in_array($action, ['toggle', 'delete'], true)) {
    flash_set('error', 'Ação desconhecida.');
    ce_redirect();
}

$categoria = $id > 0 ? CategoryRepository::findAnyById($id) : null;
if ($categoria === null) {
    flash_set('error', 'Categoria não encontrada.');
    ce_redirect();
}

$nome = (string) $categoria['name'];

// Quantos produtos (ativos ou nao) perdem o vinculo se for DELETE.
$vinculados = 0;
foreach (CategoryRepository::listAll() as $linha) {
    if ((int) $linha['id'] === $id) {
        $vinculados = (int) $linha['product_count_all'];
        break;
    }
}

try {
    if ($action === 'toggle') {
        $agoraAtiva = CategoryRepository::toggleActive($id);
        flash_set(
            'success',
            $agoraAtiva
                ? 'Categoria "' . $nome . '" ativada e visível na loja.'
                : 'Categoria "' . $nome . '" desativada. Ela sumiu da loja, mas os produtos continuam vinculados.'
        );
    } else {
        CategoryRepository::delete($id);
        flash_set('success', 'Categoria "' . $nome . '" excluída.');

        if ($vinculados > 0) {
            flash_set(
                'info',
                $vinculados . ' produto' . ($vinculados === 1 ? '' : 's') . ' ficou' . ($vinculados === 1 ? '' : 'ram')
                . ' sem categoria (nenhum produto foi apagado). Use o filtro "Sem categoria" em Produtos para reorganizar.'
            );
        }
    }
} catch (Throwable $e) {
    error_log('[lynch][categoria-excluir] ' . $e->getMessage());
    flash_set('error', 'Não foi possível concluir a ação. Tente novamente.');
}

ce_redirect();

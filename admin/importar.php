<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
$admin = require_login();
require_once dirname(__DIR__) . '/includes/ProductImport.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sessão expirada. Atualize a página e envie novamente.';
    } else {
        try {
            if (($_POST['action'] ?? '') === 'confirm') {
                $preview = $_SESSION['product_import'] ?? null;
                if (!$preview || $preview['expires'] < time() || !hash_equals($preview['token'], (string) ($_POST['preview_token'] ?? ''))) {
                    throw new RuntimeException('Prévia expirada. Envie a planilha novamente.');
                }
                $result = ProductImport::apply($preview['products'], $preview['mode'], $preview['existing']);
                unset($_SESSION['product_import']);
                flash_set('success', "{$result['created']} produtos criados e {$result['updated']} atualizados. Fotos existentes preservadas.");
                header('Location: ' . base_url('admin/produtos.php'), true, 303); exit;
            }
            unset($_SESSION['product_import']);
            $file = $_FILES['sheet'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new RuntimeException('Selecione uma planilha de até 3 MB e tente novamente.');
            }
            $result = ProductImport::read($file['tmp_name'], strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)));
            $errors = $result['errors'];
            $mode = ($_POST['mode'] ?? '') === 'upsert' ? 'upsert' : 'create';
            if (!$errors) {
                $existing = ProductImport::existing($result['products']);
                if ($mode === 'create' && $existing) {
                    $errors[] = count($existing) . ' produtos da planilha já existem na loja (mesmo endereço do link). Escolha "Criar novos e atualizar os que já existem" ou mude o endereço na planilha.';
                } else {
                    $_SESSION['product_import'] = ['products' => $result['products'], 'mode' => $mode, 'existing' => $existing, 'expires' => time() + 1800, 'token' => bin2hex(random_bytes(20))];
                    header('Location: ' . base_url('admin/importar.php'), true, 303); exit;
                }
            }
        } catch (Throwable $error) {
            error_log('[import] ' . $error->getMessage());
            $errors[] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'Não foi possível ler a planilha. Confira o modelo e tente novamente.';
        }
    }
}
$preview = $_SESSION['product_import'] ?? null;
if ($preview && $preview['expires'] < time()) { unset($_SESSION['product_import']); $preview = null; }
$pageTitle = 'Importar produtos'; $navActive = 'importar';
require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head"><div><p class="eyebrow">Catálogo em lote</p><h1>Importar produtos</h1><p class="muted">Da sua planilha para a loja, com uma conferência antes de publicar.</p></div><a class="btn" href="<?= e(base_url('admin/exportar.php?modelo=1')) ?>">Baixar modelo CSV</a></div>
<?php foreach ($errors as $error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<div class="card">
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="form-grid">
<div class="field"><label for="sheet">Planilha CSV ou Excel (.xlsx)</label><input type="file" id="sheet" name="sheet" accept=".csv,.xlsx" required><span class="hint">Até 500 produtos e 3 MB. No Excel, usamos a primeira aba. Fotos são adicionadas na edição de cada produto.</span></div>
<div class="field"><label for="mode">Como importar</label><select id="mode" name="mode"><option value="create">Criar somente produtos novos</option><option value="upsert">Criar novos e atualizar os que já existem</option></select><span class="hint">Ao atualizar, os campos da planilha substituem os dados atuais. Campos vazios são limpos. Fotos e ordem da vitrine são preservadas.</span></div>
</div><button class="btn primary" type="submit">Conferir planilha</button>
</form>
</div>
<details class="card"><summary>Como preencher a planilha</summary><p><b>nome</b> é obrigatório. <b>slug</b> é o endereço do link do produto, por exemplo camiseta-gato. Se ficar vazio, será criado a partir do nome. Para atualizar um produto, mantenha o mesmo slug da exportação.</p><p><b>preco</b>: 129,90 ou 129.90; vazio significa consultar valor. <b>disponibilidade</b>: pronta_entrega ou sob_encomenda. <b>ativo</b> e <b>destaque</b>: 1 para sim, 0 para não. Categoria nova é criada automaticamente. Não use fórmulas.</p><p>Colunas opcionais de estoque e promoção (planilhas antigas sem elas continuam valendo e não mexem nesses campos): <b>estoque</b> é a quantidade, vazio para não controlar; <b>mostrar_estoque</b> e <b>esgotado</b>: 1 ou 0; <b>preco_promocional</b> precisa ser menor que o preço; <b>promocao_ate</b> é o último dia da promoção, como 30/11/2026, ou vazio.</p></details>
<?php if ($preview): $previewStock = ProductImport::stockColumns($preview['products']) !== []; ?>
<section class="card"><h2>Confira antes de importar</h2><p><?= count($preview['products']) - count($preview['existing']) ?> novos e <?= count($preview['existing']) ?> atualizações. Nenhum produto foi alterado ainda.</p>
<div class="table-wrap"><table><thead><tr><th scope="col">Produto</th><th scope="col">Link</th><th scope="col">Categoria</th><th scope="col">Preço</th><?php if ($previewStock): ?><th scope="col">Estoque</th><th scope="col">Promoção</th><?php endif; ?><th scope="col">Ação</th></tr></thead><tbody>
<?php foreach ($preview['products'] as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= e($p['slug']) ?></td><td><?= e($p['category']) ?></td><td><?= e(format_price($p['price_cents'])) ?></td><?php if ($previewStock): ?><td><?= array_key_exists('stock_qty', $p) ? ($p['stock_qty'] === null ? 'Não controla' : e($p['stock_qty'])) : 'Mantém' ?><?= !empty($p['sold_out']) ? ', esgotado' : '' ?></td><td><?= array_key_exists('sale_price_cents', $p) ? ($p['sale_price_cents'] === null ? 'Sem promoção' : e(format_price($p['sale_price_cents'])) . (!empty($p['sale_ends_on']) ? ' até ' . e(format_date_br($p['sale_ends_on'])) : '')) : 'Mantém' ?></td><?php endif; ?><td><?= isset($preview['existing'][$p['slug']]) ? 'Atualizar' : 'Criar' ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="preview_token" value="<?= e($preview['token']) ?>"><button class="btn primary" type="submit">Confirmar importação de <?= count($preview['products']) ?> produtos</button></form>
</section>
<?php endif; require __DIR__ . '/partials/admin-footer.php'; ?>

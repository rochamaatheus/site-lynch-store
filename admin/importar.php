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
<p class="crumb"><a href="<?= e(base_url('admin/mais.php')) ?>"><span aria-hidden="true">&larr;</span> Mais opções</a></p>
<div class="page-head"><div><h1>Importar planilha</h1><p class="muted">Para cadastrar ou atualizar muitos produtos de uma vez. É mais confortável no computador. Para um produto só, use Novo produto.</p></div></div>
<?php foreach ($errors as $error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if ($preview): $previewStock = ProductImport::stockColumns($preview['products']) !== []; ?>
<section class="card" aria-labelledby="h-conf"><h2 class="sec" id="h-conf">Confira antes de importar</h2><p><?= count($preview['products']) - count($preview['existing']) ?> novos e <?= count($preview['existing']) ?> atualizações. Nenhum produto foi alterado ainda.</p>
<ul class="import-list">
<?php foreach ($preview['products'] as $p): ?><li><b><?= e($p['name']) ?></b> <span class="badge <?= isset($preview['existing'][$p['slug']]) ? 'sun' : 'on' ?>"><?= isset($preview['existing'][$p['slug']]) ? 'Atualizar' : 'Criar' ?></span><span class="import-facts"><?= e($p['category'] ?: 'Sem categoria') ?> &middot; <?= e(format_price($p['price_cents'])) ?><?php if ($previewStock): ?> &middot; Estoque: <?= array_key_exists('stock_qty', $p) ? ($p['stock_qty'] === null ? 'não controla' : e($p['stock_qty'])) : 'mantém' ?><?= !empty($p['sold_out']) ? ', esgotado' : '' ?> &middot; Promoção: <?= array_key_exists('sale_price_cents', $p) ? ($p['sale_price_cents'] === null ? 'sem' : e(format_price($p['sale_price_cents'])) . (!empty($p['sale_ends_on']) ? ' até ' . e(format_date_br($p['sale_ends_on'])) : '')) : 'mantém' ?><?php endif; ?> &middot; link: <?= e($p['slug']) ?></span></li><?php endforeach; ?>
</ul>
<form method="post" data-busy="Importando..."><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="preview_token" value="<?= e($preview['token']) ?>"><button class="btn primary wide" type="submit">Confirmar importação de <?= count($preview['products']) ?> produtos</button></form>
<p class="hint">Mudou de ideia? É só não confirmar: a prévia some sozinha em 30 minutos.</p>
</section>
<?php endif; ?>
<div class="card">
<h2 class="sec"><?= $preview ? 'Enviar outra planilha' : 'Enviar planilha' ?></h2>
<form method="post" enctype="multipart/form-data" data-busy="Lendo a planilha...">
<?= csrf_field() ?>
<div class="form-grid">
<div class="field"><label for="sheet">Planilha CSV ou Excel (.xlsx)</label><input type="file" id="sheet" name="sheet" accept=".csv,.xlsx" required aria-describedby="sheet-h"><span class="hint" id="sheet-h">Até 500 produtos e 3 MB. No Excel, usamos a primeira aba. As fotos são enviadas depois, em cada produto.</span></div>
<div class="field"><label for="mode">Como importar</label><select id="mode" name="mode" aria-describedby="mode-h"><option value="create">Criar somente produtos novos</option><option value="upsert">Criar novos e atualizar os que já existem</option></select><span class="hint" id="mode-h">Ao atualizar, a planilha substitui os dados atuais, inclusive campos vazios. Fotos e ordem da vitrine ficam como estão.</span></div>
</div><div class="form-actions"><button class="btn primary wide" type="submit">Conferir planilha</button></div>
</form>
<p><a class="btn ghost small" href="<?= e(base_url('admin/exportar.php?modelo=1')) ?>">Baixar planilha modelo</a> <a class="btn ghost small" href="<?= e(base_url('admin/exportar.php')) ?>">Baixar todos os produtos</a></p>
</div>
<details class="card"><summary>Como preencher a planilha</summary><p><b>nome</b> é obrigatório. <b>slug</b> é o endereço do link do produto, por exemplo camiseta-gato. Se ficar vazio, será criado a partir do nome. Para atualizar um produto, mantenha o mesmo slug da exportação.</p><p><b>preco</b>: 129,90 ou 129.90; vazio significa consultar valor. <b>disponibilidade</b>: pronta_entrega ou sob_encomenda. <b>ativo</b> e <b>destaque</b>: 1 para sim, 0 para não. Categoria nova é criada automaticamente. Não use fórmulas.</p><p>Colunas opcionais de estoque e promoção (planilhas antigas sem elas continuam valendo e não mexem nesses campos): <b>estoque</b> é a quantidade, vazio para não controlar; <b>mostrar_estoque</b> e <b>esgotado</b>: 1 ou 0; <b>preco_promocional</b> precisa ser menor que o preço; <b>promocao_ate</b> é o último dia da promoção, como 30/11/2026, ou vazio.</p></details>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

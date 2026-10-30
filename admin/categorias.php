<?php
/**
 * Lynch Store - categorias do painel (fase 6)
 *
 * Categorias sao poucas (4 no seed) e simples, entao tudo cabe numa tela: o
 * formulario de criar no topo, a lista embaixo e a edicao inline (?editar=N
 * abre a linha em modo de formulario). Nao existe "categoria-form.php" de
 * proposito - seria uma pagina a mais para gerenciar 4 registros.
 *
 * Pagina de leitura: quem grava e categoria-salvar.php / categoria-excluir.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';

$categorias = CategoryRepository::listAll();
$editarId   = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;

$base      = APP_BASE_URL;
$pageTitle = 'Categorias';
$navActive = 'categorias';

require __DIR__ . '/partials/admin-header.php';
?>
<p class="crumb"><a href="<?= e($base) ?>/admin/mais.php"><span aria-hidden="true">&larr;</span> Mais opções</a></p>
<div class="page-head">
  <div>
    <h1>Categorias</h1>
    <p class="muted">Aparecem na faixa "Coleções" da página inicial e nos filtros da loja. Só as ativas aparecem para a cliente.</p>
  </div>
  <div class="actions"><a class="btn primary" href="#nova" data-focus-target="n-name"><span aria-hidden="true">+</span> Nova categoria</a></div>
</div>

<?php if ($categorias === []): ?>
  <div class="card"><div class="empty-state">Nenhuma categoria ainda. Toque em Nova categoria para criar a primeira.</div></div>
<?php else: ?>
  <ul class="catlist">
  <?php foreach ($categorias as $cat): ?>
    <?php $cid = (int) $cat['id']; $editando = $cid === $editarId; ?>
    <?php if ($editando): ?>
      <li class="catcard is-editing" id="editar">
        <form method="post" action="<?= e($base) ?>/admin/categoria-salvar.php" data-busy="Salvando...">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $cid ?>">
          <h2 class="sec">Editar categoria</h2>
          <div class="form-grid">
            <div class="field half">
              <label for="e-name-<?= $cid ?>">Nome <span class="req">(obrigatório)</span></label>
              <input type="text" id="e-name-<?= $cid ?>" name="name" maxlength="100" required autocomplete="off" value="<?= e((string) $cat['name']) ?>">
            </div>
            <div class="field half">
              <label for="e-desc-<?= $cid ?>">Descrição curta</label>
              <input type="text" id="e-desc-<?= $cid ?>" name="description" maxlength="255" autocomplete="off" value="<?= e((string) ($cat['description'] ?? '')) ?>">
            </div>
            <div class="field half">
              <label for="e-slug-<?= $cid ?>">Endereço do link</label>
              <input type="text" id="e-slug-<?= $cid ?>" name="slug" maxlength="120" autocomplete="off" autocapitalize="none" spellcheck="false" value="<?= e((string) $cat['slug']) ?>">
            </div>
            <div class="field quarter">
              <label for="e-sort-<?= $cid ?>">Ordem</label>
              <input type="text" id="e-sort-<?= $cid ?>" name="sort_order" inputmode="numeric" pattern="-?[0-9]*" maxlength="6" autocomplete="off" value="<?= (int) $cat['sort_order'] ?>">
            </div>
            <div class="field quarter">
              <span class="lbl">Na loja</span>
              <label class="checkline"><input type="checkbox" name="active" value="1" <?= $cat['active'] ? 'checked' : '' ?>> Ativa</label>
            </div>
          </div>
          <div class="form-actions">
            <a class="btn ghost" href="<?= e($base) ?>/admin/categorias.php">Cancelar</a>
            <button class="btn primary" type="submit">Salvar</button>
          </div>
        </form>
      </li>
    <?php else: ?>
      <li class="catcard<?= $cat['active'] ? '' : ' is-off' ?>">
        <div class="catcard-head">
          <h2 class="catcard-name"><?= e((string) $cat['name']) ?></h2>
          <span class="badge <?= $cat['active'] ? 'on' : 'off' ?>"><?= $cat['active'] ? 'Ativa' : 'Desativada' ?></span>
        </div>
        <?php if (!empty($cat['description'])): ?><p class="catcard-desc"><?= e(excerpt((string) $cat['description'], 90)) ?></p><?php endif; ?>
        <p class="catcard-meta">
          <a href="<?= e($base) ?>/admin/produtos.php?categoria=<?= $cid ?>"><?= (int) $cat['product_count'] ?> produto<?= (int) $cat['product_count'] === 1 ? '' : 's' ?> na loja</a><?php if ((int) $cat['product_count_all'] > (int) $cat['product_count']): ?>, <?= (int) $cat['product_count_all'] ?> no total<?php endif; ?>
          &middot; ordem <?= (int) $cat['sort_order'] ?>
        </p>
        <div class="catcard-actions">
          <a class="btn small" href="<?= e($base) ?>/admin/categorias.php?editar=<?= $cid ?>#editar">Editar<span class="sr"> <?= e((string) $cat['name']) ?></span></a>
          <form method="post" action="<?= e($base) ?>/admin/categoria-excluir.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $cid ?>">
            <input type="hidden" name="action" value="toggle">
            <button class="btn small ghost" type="submit"><?= $cat['active'] ? 'Desativar' : 'Ativar' ?><span class="sr"> <?= e((string) $cat['name']) ?></span></button>
          </form>
          <form class="catcard-del" method="post" action="<?= e($base) ?>/admin/categoria-excluir.php"
                data-confirm="<?= e('Excluir a categoria "' . (string) $cat['name'] . '" de vez?' . ((int) $cat['product_count_all'] > 0 ? ' Os ' . (int) $cat['product_count_all'] . ' produtos dela ficam sem categoria (nenhum produto é apagado).' : '')) ?>"
                data-confirm-ok="Excluir categoria">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $cid ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn small quiet" type="submit">Excluir<span class="sr"> <?= e((string) $cat['name']) ?></span></button>
          </form>
        </div>
      </li>
    <?php endif; ?>
  <?php endforeach; ?>
  </ul>
  <p class="note"><b>Desativar</b> esconde da loja e guarda tudo, dá para ativar depois. <b>Excluir</b> apaga a categoria; os produtos dela continuam, só ficam sem categoria.</p>
<?php endif; ?>

<form class="card" id="nova" tabindex="-1" method="post" action="<?= e($base) ?>/admin/categoria-salvar.php" data-busy="Criando...">
  <?= csrf_field() ?>
  <h2 class="sec">Nova categoria</h2>
  <div class="form-grid">
    <div class="field half">
      <label for="n-name">Nome <span class="req">(obrigatório)</span></label>
      <input type="text" id="n-name" name="name" maxlength="100" required autocomplete="off" aria-describedby="n-name-h">
      <span class="hint" id="n-name-h">Ex.: Acessórios</span>
    </div>
    <div class="field half">
      <label for="n-desc">Descrição curta</label>
      <input type="text" id="n-desc" name="description" maxlength="255" autocomplete="off" aria-describedby="n-desc-h">
      <span class="hint" id="n-desc-h">Aparece na faixa Coleções.</span>
    </div>
    <div class="field">
      <label class="checkline"><input type="checkbox" name="active" value="1" checked> Ativa (aparece na loja)</label>
    </div>
  </div>
  <details class="fold-inline">
    <summary>Mais opções</summary>
    <div class="form-grid">
      <div class="field half">
        <label for="n-slug">Endereço do link</label>
        <input type="text" id="n-slug" name="slug" maxlength="120" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="n-slug-h">
        <span class="hint" id="n-slug-h">Vazio: criado a partir do nome.</span>
      </div>
      <div class="field quarter">
        <label for="n-sort">Ordem</label>
        <input type="text" id="n-sort" name="sort_order" inputmode="numeric" pattern="-?[0-9]*" maxlength="6" autocomplete="off" value="<?= count($categorias) + 1 ?>">
      </div>
    </div>
  </details>
  <div class="form-actions">
    <button class="btn primary wide" type="submit">Criar categoria</button>
  </div>
</form>
<?php
require __DIR__ . '/partials/admin-footer.php';

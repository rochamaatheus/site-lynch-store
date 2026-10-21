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
<div class="page-head">
  <div>
    <p class="eyebrow">Organizacao</p>
    <h1>Categorias</h1>
    <p class="muted" style="margin:6px 0 0;font-size:14px">
      As categorias aparecem na faixa "Coleções" da home e nos filtros do catalogo.
      So as ativas aparecem para o cliente.
    </p>
  </div>
</div>

<div class="card">
  <h2 class="sec">Nova categoria</h2>
  <form method="post" action="<?= e($base) ?>/admin/categoria-salvar.php">
    <?= csrf_field() ?>
    <div class="form-grid" style="margin-top:14px">
      <div class="field half">
        <label for="n-name">Nome *</label>
        <input type="text" id="n-name" name="name" maxlength="100" required placeholder="Ex.: Acessorios">
      </div>
      <div class="field half">
        <label for="n-slug">Endereco (slug)</label>
        <input type="text" id="n-slug" name="slug" maxlength="120" placeholder="gerado a partir do nome">
      </div>
      <div class="field half">
        <label for="n-desc">Descricao curta</label>
        <input type="text" id="n-desc" name="description" maxlength="255" placeholder="Aparece na banda Colecoes">
      </div>
      <div class="field quarter">
        <label for="n-sort">Ordem</label>
        <input type="number" id="n-sort" name="sort_order" step="1" value="<?= count($categorias) + 1 ?>">
      </div>
      <div class="field quarter">
        <span class="lbl">Visibilidade</span>
        <label class="checkline">
          <input type="checkbox" name="active" value="1" checked> Ativa
        </label>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn primary" type="submit">Criar categoria</button>
    </div>
  </form>
</div>

<div class="card">
  <h2 class="sec">Categorias cadastradas</h2>

<?php if ($categorias === []): ?>
  <div class="empty-state" style="margin-top:14px">Nenhuma categoria cadastrada ainda.</div>
<?php else: ?>
  <div class="table-wrap" style="margin-top:14px">
    <table class="data">
      <thead>
        <tr>
          <th scope="col">Nome</th>
          <th scope="col">Endereco</th>
          <th scope="col">Produtos</th>
          <th scope="col">Ordem</th>
          <th scope="col">Status</th>
          <th scope="col" style="text-align:right">Acoes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categorias as $cat): ?>
        <?php $cid = (int) $cat['id']; $editando = $cid === $editarId; ?>

        <?php if ($editando): ?>
          <tr>
            <td colspan="6" style="background:rgba(185,137,88,.08)">
              <form method="post" action="<?= e($base) ?>/admin/categoria-salvar.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $cid ?>">
                <div class="form-grid">
                  <div class="field third">
                    <label for="e-name-<?= $cid ?>">Nome *</label>
                    <input type="text" id="e-name-<?= $cid ?>" name="name" maxlength="100" required
                           value="<?= e((string) $cat['name']) ?>">
                  </div>
                  <div class="field third">
                    <label for="e-slug-<?= $cid ?>">Endereco (slug)</label>
                    <input type="text" id="e-slug-<?= $cid ?>" name="slug" maxlength="120"
                           value="<?= e((string) $cat['slug']) ?>">
                  </div>
                  <div class="field third">
                    <label for="e-desc-<?= $cid ?>">Descricao</label>
                    <input type="text" id="e-desc-<?= $cid ?>" name="description" maxlength="255"
                           value="<?= e((string) ($cat['description'] ?? '')) ?>">
                  </div>
                  <div class="field quarter">
                    <label for="e-sort-<?= $cid ?>">Ordem</label>
                    <input type="number" id="e-sort-<?= $cid ?>" name="sort_order" step="1"
                           value="<?= (int) $cat['sort_order'] ?>">
                  </div>
                  <div class="field quarter">
                    <span class="lbl">Visibilidade</span>
                    <label class="checkline">
                      <input type="checkbox" name="active" value="1" <?= $cat['active'] ? 'checked' : '' ?>> Ativa
                    </label>
                  </div>
                </div>
                <div class="form-actions">
                  <button class="btn primary" type="submit">Salvar</button>
                  <a class="btn ghost" href="<?= e($base) ?>/admin/categorias.php">Cancelar</a>
                </div>
              </form>
            </td>
          </tr>
        <?php else: ?>
          <tr class="<?= $cat['active'] ? '' : 'is-off' ?>">
            <td class="cell-name">
              <b><?= e((string) $cat['name']) ?></b>
              <?php if (!empty($cat['description'])): ?>
                <small><?= e(excerpt((string) $cat['description'], 70)) ?></small>
              <?php endif; ?>
            </td>
            <td><small class="muted"><?= e((string) $cat['slug']) ?></small></td>
            <td>
              <a href="<?= e($base) ?>/admin/produtos.php?categoria=<?= $cid ?>">
                <?= (int) $cat['product_count'] ?> ativo<?= (int) $cat['product_count'] === 1 ? '' : 's' ?>
              </a>
              <?php if ((int) $cat['product_count_all'] > (int) $cat['product_count']): ?>
                <small class="muted">(<?= (int) $cat['product_count_all'] ?> no total)</small>
              <?php endif; ?>
            </td>
            <td><?= (int) $cat['sort_order'] ?></td>
            <td><span class="badge <?= $cat['active'] ? 'on' : 'off' ?>"><?= $cat['active'] ? 'Ativa' : 'Inativa' ?></span></td>
            <td class="cell-actions">
              <a class="btn small" href="<?= e($base) ?>/admin/categorias.php?editar=<?= $cid ?>#editar">Editar</a>
              <form method="post" action="<?= e($base) ?>/admin/categoria-excluir.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="action" value="toggle">
                <button class="btn small ghost" type="submit"><?= $cat['active'] ? 'Desativar' : 'Ativar' ?></button>
              </form>
              <form method="post" action="<?= e($base) ?>/admin/categoria-excluir.php"
                    data-confirm="<?= e('Excluir a categoria "' . (string) $cat['name'] . '" de vez?' . ((int) $cat['product_count_all'] > 0 ? ' Os ' . (int) $cat['product_count_all'] . ' produtos dela ficarão SEM categoria (nenhum produto é apagado).' : '')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn small danger" type="submit">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted" style="font-size:12.5px;margin:16px 0 0">
    <b>Desativar</b> esconde a categoria da loja mas mantem o vinculo dos produtos - e o
    caminho reversivel, recomendado no dia a dia.
    <b>Excluir</b> apaga a categoria de verdade; os produtos dela NÃO são apagados, apenas
    ficam sem categoria e podem ser recategorizados em Produtos.
  </p>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/partials/admin-footer.php';

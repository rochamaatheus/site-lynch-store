<?php
/**
 * Lynch Store - lista de produtos do painel (fase 6)
 *
 * Mostra ativos E inativos (ProductRepository::listAll()), com busca por nome/
 * slug e filtros de categoria, disponibilidade e status. E daqui que saem os
 * links para criar, editar, gerenciar fotos, desativar e reativar.
 *
 * Pagina de LEITURA: nao altera nada. Toda mudanca de estado sai em <form
 * method="post"> para produto-salvar.php / produto-excluir.php, com CSRF.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';

// ---------------------------------------------------------------------------
// Filtros (tudo vindo da URL e normalizado antes de virar consulta)
// ---------------------------------------------------------------------------
$busca        = trim((string) ($_GET['busca'] ?? ''));
$categoriaId  = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? (int) $_GET['categoria'] : 0;
$disponib     = (string) ($_GET['disponibilidade'] ?? '');
$status       = (string) ($_GET['status'] ?? '');

if (!in_array($disponib, ProductRepository::AVAILABILITIES, true)) {
    $disponib = '';
}
if (!in_array($status, ['ativo', 'inativo'], true)) {
    $status = '';
}

$filtros = [
    'search'       => $busca,
    'category_id'  => $categoriaId,
    'availability' => $disponib,
    'status'       => $status,
];

$cursor = is_string($_GET['cursor'] ?? null) ? substr($_GET['cursor'], 0, 128) : '';
$produtos   = ProductRepository::listAll($filtros + ['limit' => 31, 'cursor' => $cursor]);
$hasMore = count($produtos) > 30;
if ($hasMore) { array_pop($produtos); }
$nextUrl = '';
if ($hasMore) {
    $last = end($produtos);
    $params = ['busca' => $busca, 'categoria' => $categoriaId, 'disponibilidade' => $disponib, 'status' => $status, 'cursor' => base64_encode(json_encode([(int) $last['sort_order'], (int) $last['id']]))];
    $nextUrl = base_url('admin/produtos.php') . '?' . http_build_query($params);
}
$total      = ProductRepository::countAll($filtros);
$categorias = CategoryRepository::listAll();
$temFiltro  = $busca !== '' || $categoriaId !== 0 || $disponib !== '' || $status !== '';

$base      = APP_BASE_URL;
$pageTitle = 'Produtos';
$navActive = 'produtos';

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Catalogo</p>
    <h1>Produtos</h1>
    <p class="muted" style="margin:6px 0 0;font-size:14px">
      <?= $total ?> produto<?= $total === 1 ? '' : 's' ?><?= $temFiltro ? ' com os filtros atuais' : ' no catálogo' ?>.
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(base_url('admin/importar.php')) ?>">Importar planilha</a>
    <a class="btn" href="<?= e(base_url('admin/exportar.php')) ?>">Exportar CSV</a>
    <a class="btn primary" href="<?= e($base) ?>/admin/produto-form.php">+ Novo produto</a>
  </div>
</div>

<form class="filters" method="get" action="<?= e($base) ?>/admin/produtos.php">
  <div class="field grow">
    <label for="f-busca">Buscar</label>
    <input type="search" id="f-busca" name="busca" value="<?= e($busca) ?>" placeholder="Nome ou slug do produto">
  </div>
  <div class="field">
    <label for="f-cat">Categoria</label>
    <select id="f-cat" name="categoria">
      <option value="">Todas</option>
      <option value="-1" <?= $categoriaId === -1 ? 'selected' : '' ?>>Sem categoria</option>
      <?php foreach ($categorias as $cat): ?>
        <option value="<?= (int) $cat['id'] ?>" <?= $categoriaId === (int) $cat['id'] ? 'selected' : '' ?>>
          <?= e((string) $cat['name']) ?><?= $cat['active'] ? '' : ' (inativa)' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-disp">Disponibilidade</label>
    <select id="f-disp" name="disponibilidade">
      <option value="">Todas</option>
      <option value="sob_encomenda" <?= $disponib === 'sob_encomenda' ? 'selected' : '' ?>>Sob encomenda</option>
      <option value="pronta_entrega" <?= $disponib === 'pronta_entrega' ? 'selected' : '' ?>>Pronta entrega</option>
    </select>
  </div>
  <div class="field">
    <label for="f-status">Status</label>
    <select id="f-status" name="status">
      <option value="">Todos</option>
      <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativos</option>
      <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativos</option>
    </select>
  </div>
  <div class="btns">
    <button class="btn primary" type="submit">Filtrar</button>
    <?php if ($temFiltro): ?>
      <a class="btn ghost" href="<?= e($base) ?>/admin/produtos.php">Limpar</a>
    <?php endif; ?>
  </div>
</form>

<div class="card">
<?php if ($produtos === []): ?>
  <div class="empty-state">
    <?= $temFiltro
        ? 'Nenhum produto bate com esses filtros.'
        : 'Nenhum produto cadastrado ainda. Use o botão "Novo produto" para começar.' ?>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="data cards-mobile">
      <thead>
        <tr>
          <th scope="col" style="width:64px">Foto</th>
          <th scope="col">Produto</th>
          <th scope="col">Categoria</th>
          <th scope="col">Disponibilidade</th>
          <th scope="col">Preço</th>
          <th scope="col">Ordem</th>
          <th scope="col">Status</th>
          <th scope="col" style="text-align:right">Acoes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($produtos as $p): ?>
        <?php
          $pid    = (int) $p['id'];
          $ativo  = (bool) $p['active'];
          $foto   = (string) ($p['primary_image'] ?? '');
        ?>
        <tr class="<?= $ativo ? '' : 'is-off' ?>">
          <td class="cell-photo">
            <?php if ($foto !== ''): ?>
              <img class="thumb" src="<?= e(asset_url($foto)) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="thumb empty">sem<br>foto</span>
            <?php endif; ?>
          </td>
          <td class="cell-name">
            <b><?= e((string) $p['name']) ?></b>
            <small><?= e((string) $p['slug']) ?></small>
          </td>
          <td data-label="Categoria">
            <?php if (!empty($p['category_name'])): ?>
              <span class="badge kraft"><?= e((string) $p['category_name']) ?></span>
            <?php else: ?>
              <span class="badge off">sem categoria</span>
            <?php endif; ?>
          </td>
          <td data-label="Disponibilidade">
            <span class="badge <?= $p['availability'] === 'pronta_entrega' ? 'on' : 'sun' ?>">
              <?= e(availability_label((string) $p['availability'])) ?>
            </span>
          </td>
          <td data-label="Preço"><?= e(format_price($p['price_cents'], (bool) $p['price_on_request'])) ?></td>
          <td data-label="Ordem"><?= (int) $p['sort_order'] ?></td>
          <td data-label="Status">
            <span class="badge <?= $ativo ? 'on' : 'off' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
            <?php if (!empty($p['featured'])): ?><span class="badge sun">destaque</span><?php endif; ?>
          </td>
          <td class="cell-actions">
            <a class="btn small" href="<?= e($base) ?>/admin/produto-form.php?id=<?= $pid ?>">Editar</a>
            <a class="btn small ghost" href="<?= e($base) ?>/admin/produto-imagens.php?id=<?= $pid ?>">Fotos</a>
            <form method="post" action="<?= e($base) ?>/admin/produto-excluir.php"
                  data-confirm="<?= $ativo ? 'Desativar este produto? Ele sai da loja, mas continua aqui e você pode reativar quando quiser.' : 'Reativar este produto? Ele volta a aparecer na loja.' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $pid ?>">
              <input type="hidden" name="action" value="<?= $ativo ? 'delete' : 'restore' ?>">
              <button class="btn small <?= $ativo ? 'ghost' : '' ?>" type="submit">
                <?= $ativo ? 'Desativar' : 'Reativar' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:12.5px;margin:16px 0 0">
    "Desativar" tira o produto da loja, mas ele continua aqui e pode ser reativado
    a qualquer momento. As fotos já enviadas nunca são apagadas por essa ação.
  </p>
<?php endif; ?>
</div>
<nav class="actions" aria-label="Paginação dos produtos">
<?php if ($cursor !== ''): ?><a class="btn" href="<?= e(base_url('admin/produtos.php')) ?>">Voltar ao início</a><?php endif; ?>
<?php if ($nextUrl): ?><a class="btn primary" href="<?= e($nextUrl) ?>">Próximos produtos</a><?php endif; ?>
</nav>
<?php
require __DIR__ . '/partials/admin-footer.php';

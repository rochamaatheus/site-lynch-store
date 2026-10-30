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

require_once dirname(__DIR__) . '/includes/SettingsRepository.php';

// ---------------------------------------------------------------------------
// Filtros (tudo vindo da URL e normalizado antes de virar consulta)
// ---------------------------------------------------------------------------
$filtros     = ProductRepository::adminFiltersFromQuery($_GET);
$busca       = $filtros['search'];
$categoriaId = $filtros['category_id'];
$disponib    = $filtros['availability'];
$status      = $filtros['status'];
$aba         = $filtros['tab'];
$low         = low_stock_threshold();
$stockReady  = ProductRepository::stockReady();
$filtros['low'] = $low;

$cursor = is_string($_GET['cursor'] ?? null) ? substr($_GET['cursor'], 0, 128) : '';
$produtos   = ProductRepository::listAll($filtros + ['limit' => 31, 'cursor' => $cursor]);
$hasMore = count($produtos) > 30;
if ($hasMore) { array_pop($produtos); }
$keep = array_filter(['busca' => $busca, 'categoria' => $categoriaId ?: '', 'disponibilidade' => $disponib, 'status' => $status], static fn ($v) => $v !== '');
$nextUrl = '';
if ($hasMore) {
    $last = end($produtos);
    $nextUrl = base_url('admin/produtos.php') . '?' . http_build_query($keep + ['aba' => $aba, 'cursor' => base64_encode(json_encode([(int) $last['sort_order'], (int) $last['id']]))]);
}
$total      = ProductRepository::countAll($filtros);
$contagens  = ProductRepository::adminTabCounts($filtros, $low);
$categorias = CategoryRepository::listAll();
$temFiltro  = $busca !== '' || $categoriaId !== 0 || $disponib !== '' || $status !== '';

// Endereco atual da lista: as acoes rapidas voltam para ca sem JavaScript.
$back = (string) (parse_url(APP_BASE_URL, PHP_URL_PATH) ?? '') . '/admin/produtos.php' . (($q = http_build_query($keep + array_filter(['aba' => $aba, 'cursor' => $cursor]))) !== '' ? '?' . $q : '');

$abas = [
    ''         => 'Todos',
    'promocao' => 'Em promoção',
    'destaque' => 'Em destaque',
    'baixo'    => 'Estoque baixo',
    'esgotado' => 'Esgotados',
    'visivel'  => 'Estoque visível',
    'oculto'   => 'Estoque oculto',
];
if (!$stockReady) {
    $abas = ['' => 'Todos', 'destaque' => 'Em destaque'];
}

$base      = APP_BASE_URL;
$pageTitle = 'Produtos';
$navActive = 'produtos';

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <h1>Produtos</h1>
    <p class="muted">
      <?= $total ?> produto<?= $total === 1 ? '' : 's' ?><?= $temFiltro || $aba !== '' ? ' nesta escolha' : ' no catálogo' ?>.
    </p>
  </div>
  <div class="actions">
    <a class="btn primary" href="<?= e($base) ?>/admin/produto-form.php"><span aria-hidden="true">+</span> Novo produto</a>
  </div>
</div>

<form class="searchbar" method="get" action="<?= e($base) ?>/admin/produtos.php" role="search">
  <?php if ($aba !== ''): ?><input type="hidden" name="aba" value="<?= e($aba) ?>"><?php endif; ?>
  <?php foreach (['categoria' => $categoriaId ?: '', 'disponibilidade' => $disponib, 'status' => $status] as $kk => $vv): ?>
    <?php if ($vv !== ''): ?><input type="hidden" name="<?= $kk ?>" value="<?= e((string) $vv) ?>"><?php endif; ?>
  <?php endforeach; ?>
  <label class="sr" for="q-busca">Buscar produto pelo nome</label>
  <input type="search" id="q-busca" name="busca" value="<?= e($busca) ?>" placeholder="Buscar pelo nome" enterkeyhint="search" autocomplete="off">
  <button class="btn primary" type="submit">Buscar</button>
</form>

<nav class="tabs" aria-label="Mostrar produtos" data-tabs>
  <?php foreach ($abas as $chave => $rotulo): ?>
    <?php $n = $chave === '' ? $contagens['todos'] : $contagens[$chave]; ?>
    <a class="tab<?= $aba === $chave ? ' on' : '' ?>" href="<?= e(base_url('admin/produtos.php') . (($tq = http_build_query($keep + array_filter(['aba' => $chave]))) !== '' ? '?' . $tq : '')) ?>"<?= $aba === $chave ? ' aria-current="page"' : '' ?>>
      <?= e($rotulo) ?> <span class="tab-n" data-count="<?= e($chave === '' ? 'todos' : $chave) ?>"><?= $n ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if (!$stockReady): ?>
  <div class="flash info" role="status">Estoque e promoção ficam disponíveis assim que o banco da loja for atualizado.</div>
<?php endif; ?>

<?php $filtroExtra = $categoriaId !== 0 || $disponib !== '' || $status !== ''; ?>
<details class="filters-box"<?= $filtroExtra ? ' open' : '' ?>>
  <summary>Mais filtros<?= $filtroExtra ? ' (ligados)' : '' ?></summary>
<form class="filters" method="get" action="<?= e($base) ?>/admin/produtos.php">
  <?php if ($aba !== ''): ?><input type="hidden" name="aba" value="<?= e($aba) ?>"><?php endif; ?>
  <?php if ($busca !== ''): ?><input type="hidden" name="busca" value="<?= e($busca) ?>"><?php endif; ?>
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
    <label for="f-status">Na loja</label>
    <select id="f-status" name="status">
      <option value="">Todos</option>
      <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Aparecendo na loja</option>
      <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Fora da loja</option>
    </select>
  </div>
  <div class="btns">
    <button class="btn primary" type="submit">Aplicar filtros</button>
  </div>
</form>
</details>

<?php if ($temFiltro): ?>
  <p class="filter-on">
    <?= $busca !== '' ? 'Buscando "' . e($busca) . '".' : 'Filtros ligados.' ?>
    <a class="btn small ghost" href="<?= e($base) ?>/admin/produtos.php<?= $aba !== '' ? '?aba=' . e($aba) : '' ?>">Limpar busca e filtros</a>
  </p>
<?php endif; ?>

<?php if ($produtos === []): ?>
  <div class="card">
  <div class="empty-state">
    <?= $temFiltro || $aba !== ''
        ? 'Nenhum produto aqui com essa escolha. Troque a aba ou limpe a busca.'
        : 'Nenhum produto cadastrado ainda. Toque em Novo produto para começar.' ?>
  </div>
  </div>
<?php else: ?>
  <ul class="plist">
  <?php foreach ($produtos as $p): ?>
    <?php include __DIR__ . '/partials/product-row.php'; ?>
  <?php endforeach; ?>
  </ul>
  <p class="note">
    "Marcar como esgotado" deixa o produto na loja com o selo Esgotado, sem poder ir para a sacola.
    "Tirar da loja" esconde o produto da vitrine. As fotos nunca são apagadas por essas ações.
  </p>
<?php endif; ?>
<nav class="actions" aria-label="Paginação dos produtos">
<?php if ($cursor !== ''): ?><a class="btn ghost" href="<?= e(base_url('admin/produtos.php') . (($tq = http_build_query($keep + array_filter(['aba' => $aba]))) !== '' ? '?' . $tq : '')) ?>">Voltar ao começo da lista</a><?php endif; ?>
<?php if ($nextUrl): ?><a class="btn primary wide" href="<?= e($nextUrl) ?>">Ver mais produtos</a><?php endif; ?>
</nav>
<?php
require __DIR__ . '/partials/admin-footer.php';

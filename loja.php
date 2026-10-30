<?php
/**
 * Lynch Store - catalogo com busca, filtros e ordem. Tudo por GET, renderizado
 * no servidor: funciona sem JavaScript. "Ver mais" e cumulativo (mostrar=N).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/CategoryRepository.php';
require_once __DIR__ . '/includes/ProductRepository.php';

const PAGE_SIZE = 12;

$mode = trim((string) ($_GET['mode'] ?? ''));
$mode = in_array($mode, ProductRepository::AVAILABILITIES, true) ? $mode : '';

$categorySlug = trim((string) ($_GET['categoria'] ?? ''));
$category     = $categorySlug !== '' ? CategoryRepository::findBySlug($categorySlug) : null;
$categorySlug = $category !== null ? $categorySlug : '';

$search = mb_substr(trim((string) ($_GET['busca'] ?? '')), 0, 80);
$sort   = in_array($_GET['ordem'] ?? '', ['preco_asc', 'preco_desc'], true) ? (string) $_GET['ordem'] : '';
$shown  = (int) (ceil(max(PAGE_SIZE, min(240, (int) ($_GET['mostrar'] ?? PAGE_SIZE))) / PAGE_SIZE) * PAGE_SIZE);

$filters = array_filter([
    'availability'  => $mode,
    'category_slug' => $categorySlug,
    'search'        => $search,
], static fn (string $v): bool => $v !== '');

$total = ProductRepository::countActive($filters);

// listActive tem teto de 60 por chamada: busca em blocos ate a quantidade mostrada.
$items = [];
while (count($items) < min($shown, $total)) {
    $chunk = ProductRepository::listActive($filters + ['sort' => $sort, 'limit' => 60, 'offset' => count($items)]);
    if ($chunk === []) {
        break;
    }
    $items = array_merge($items, $chunk);
}
$items = array_slice($items, 0, $shown);

$keep = ['mode' => $mode, 'categoria' => $categorySlug, 'busca' => $search, 'ordem' => $sort];
$categories = array_values(array_filter(CategoryRepository::listActiveWithCounts(), static fn (array $c): bool => (int) $c['product_count'] > 0));
$hasPrices  = (bool) array_filter($items, static fn (array $p): bool => !empty($p['price_cents']));

if ($search !== '') {
    $heading = 'Busca por “' . $search . '”';
} elseif ($category !== null) {
    $heading = (string) $category['name'];
} elseif ($mode !== '') {
    $heading = availability_label($mode);
} else {
    $heading = 'Toda a loja';
}

$pageTitle       = $heading;
$pageDescription = $category !== null
    ? excerpt((string) ($category['description'] ?: 'Coleção ' . $category['name'] . ' da Lynch Store, com fotos reais e atendimento pelo WhatsApp.'), 155)
    : 'Catálogo da Lynch Store: cosplay, fantasia e camisetas geek com fotos reais e atendimento pelo WhatsApp.';
$pageCanonical   = $categorySlug !== '' ? url_catalog(['categoria' => $categorySlug]) : url_catalog();
$pageNoIndex     = $search !== '' || $mode !== '' || $sort !== '' || isset($_GET['mostrar']);

require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap shop">
  <h1 class="page-title"><?= e($heading) ?></h1>
  <p class="shop-count"><?= e($total) ?> <?= $total === 1 ? 'peça' : 'peças' ?></p>

  <form class="searchbox shop-search" id="busca" action="<?= e(url_catalog()) ?>" method="get" role="search">
    <?= icon('search') ?>
    <label class="sr" for="busca-loja">Buscar na loja</label>
    <input id="busca-loja" name="busca" type="search" maxlength="80" value="<?= e($search) ?>" placeholder="Anime, banda, personagem">
    <?php if ($mode !== ''): ?><input type="hidden" name="mode" value="<?= e($mode) ?>"><?php endif; ?>
    <?php if ($categorySlug !== ''): ?><input type="hidden" name="categoria" value="<?= e($categorySlug) ?>"><?php endif; ?>
    <button class="btn ink small">Buscar</button>
  </form>
</section>

<div class="filters">
  <nav class="pills" aria-label="Filtrar peças">
    <a class="pill<?= $mode === '' && $categorySlug === '' ? ' on' : '' ?>" href="<?= e(url_catalog(['busca' => $search, 'ordem' => $sort])) ?>"<?= $mode === '' && $categorySlug === '' ? ' aria-current="true"' : '' ?>>Tudo</a>
    <?php foreach (ProductRepository::AVAILABILITIES as $availability): ?>
    <?php $on = $mode === $availability; ?>
    <a class="pill<?= $on ? ' on' : '' ?>" href="<?= e(url_catalog(['mode' => $on ? '' : $availability] + $keep)) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e(availability_label($availability)) ?></a>
    <?php endforeach; ?>
    <i class="pills-gap" aria-hidden="true"></i>
    <?php foreach ($categories as $cat): ?>
    <?php $on = $categorySlug === $cat['slug']; ?>
    <a class="pill<?= $on ? ' on' : '' ?>" href="<?= e(url_catalog(['categoria' => $on ? '' : $cat['slug']] + $keep)) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($cat['name']) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<section class="wrap shop-list">
  <?php if ($hasPrices): ?>
  <form class="sort" method="get" action="<?= e(url_catalog()) ?>" data-autosubmit>
    <?php foreach (['mode' => $mode, 'categoria' => $categorySlug, 'busca' => $search] as $k => $v): ?><?php if ($v !== ''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?><?php endforeach; ?>
    <label for="ordem">Ordenar</label>
    <select id="ordem" name="ordem">
      <option value="">Escolhas da loja</option>
      <option value="preco_asc"<?= $sort === 'preco_asc' ? ' selected' : '' ?>>Menor preço</option>
      <option value="preco_desc"<?= $sort === 'preco_desc' ? ' selected' : '' ?>>Maior preço</option>
    </select>
    <noscript><button class="btn small">Aplicar</button></noscript>
  </form>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <div class="bag-empty">
      <?= sunflower(0, 'bag-empty-flower') ?>
      <h2>Nenhuma peça com esse filtro</h2>
      <p>Tente outra palavra ou veja tudo. Muita coisa também sai sob encomenda.</p>
      <a class="btn ink" href="<?= e(url_catalog()) ?>">Ver toda a loja</a>
    </div>
  <?php else: ?>
    <div class="grid" data-grid>
      <?php foreach ($items as $product): ?>
        <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php if ($total > count($items)): ?>
    <div class="more-wrap" data-more-wrap>
      <p><?= e(count($items)) ?> de <?= e($total) ?> peças</p>
      <a class="btn ink" data-more href="<?= e(url_catalog($keep + ['mostrar' => $shown + PAGE_SIZE])) ?>">Ver mais peças</a>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

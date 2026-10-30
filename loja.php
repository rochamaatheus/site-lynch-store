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
require_once __DIR__ . '/includes/Seo.php';

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

// Loja recem-publicada, sem nenhuma peca ativa: aviso de preparo no lugar dos filtros.
$catalogEmpty = $total === 0 && ($filters === [] || ProductRepository::countActive([]) === 0);
$waLink       = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$instagram    = setting('instagram_url');

$pageTitle = $heading;
if ($category !== null && $search === '' && $mode === '') {
    $pageFullTitle   = seo_title((string) $category['name'], seo_in_city());
    $pageDescription = trim((string) $category['description']) !== ''
        ? (string) $category['description']
        : 'Coleção ' . $category['name'] . ' da ' . store_name() . ', ateliê de moda geek' . seo_in_city() . ': fotos reais, peças prontas e sob medida, ' . seo_contact_phrase() . '.';
} elseif ($search === '' && $mode === '') {
    $pageFullTitle   = seo_title('Loja de moda geek, cosplay e camisetas');
    $pageDescription = 'Peças da ' . store_name() . ', ateliê' . seo_in_city() . ': cosplay, camisetas geek e roupas sob medida, com fotos reais e ' . seo_contact_phrase() . '.';
} else {
    $pageDescription = $search !== ''
        ? 'Resultado da busca por “' . $search . '” nas peças da ' . store_name() . '.'
        : 'Peças de ' . mb_strtolower($heading) . ' da ' . store_name() . seo_in_city() . '.';
}
// Uma URL indexavel por listagem: busca, modo, ordem e "ver mais" ficam noindex
// (sem canonical, para nao mandar sinais contraditorios). Parametros estranhos
// (utm, fbclid) caem no canonical limpo.
$pageCanonical = $categorySlug !== '' ? url_catalog(['categoria' => $categorySlug]) : url_catalog();
$pageNoIndex   = $search !== '' || $mode !== '' || $sort !== '' || isset($_GET['mostrar']);
$crumbs = [['Início', base_url()], ['Loja', url_catalog()]];
if ($category !== null) {
    $crumbs[] = [(string) $category['name'], $pageCanonical];
}
$pageSchema = [
    seo_page_node('CollectionPage', seo_abs($pageCanonical), $category !== null ? (string) $category['name'] : 'Loja', seo_description($pageDescription)),
    seo_breadcrumb($crumbs, seo_abs($pageCanonical)),
];

require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap shop">
  <h1 class="page-title"><?= e($heading) ?></h1>
  <?php if (!$catalogEmpty): ?>
  <p class="shop-count"><?= e($total) ?> <?= $total === 1 ? 'peça' : 'peças' ?></p>

  <form class="searchbox shop-search" id="busca" action="<?= e(url_catalog()) ?>" method="get" role="search">
    <?= icon('search') ?>
    <label class="sr" for="busca-loja">Buscar na loja</label>
    <input id="busca-loja" name="busca" type="search" maxlength="80" value="<?= e($search) ?>" placeholder="Anime, banda, personagem" autocomplete="off" enterkeyhint="search">
    <?php if ($mode !== ''): ?><input type="hidden" name="mode" value="<?= e($mode) ?>"><?php endif; ?>
    <?php if ($categorySlug !== ''): ?><input type="hidden" name="categoria" value="<?= e($categorySlug) ?>"><?php endif; ?>
    <button class="btn ink small">Buscar</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($catalogEmpty): ?>
<section class="wrap band prep" aria-labelledby="preparo-title">
  <p class="prep-tag">Em preparação</p>
  <h2 class="title" id="preparo-title">A arara está sendo montada</h2>
  <p class="prep-lead">As peças estão sendo fotografadas e cadastradas com calma. Enquanto isso, a loja atende por <?= $waLink !== '' ? 'WhatsApp' : 'mensagem' ?>: cosplay, camisetas e peças feitas nas suas medidas.</p>
  <?php if ($waLink !== '' || $instagram !== ''): ?>
  <div class="prep-actions">
    <?php if ($waLink !== ''): ?><a class="btn ink" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> Chamar no WhatsApp</a><?php endif; ?>
    <?php if ($instagram !== ''): ?><a class="btn line" href="<?= e($instagram) ?>" target="_blank" rel="noopener">Novidades no Instagram <?= e(instagram_handle($instagram)) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>

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
    <h2 class="sr">Peças</h2>
    <div class="grid" data-grid>
      <?php foreach ($items as $i => $product): ?>
        <?php $cardEager = $i < 2; include __DIR__ . '/includes/partials/product-card.php'; ?>
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
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

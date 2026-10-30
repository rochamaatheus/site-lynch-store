<?php
/**
 * Lynch Store - vitrine. O catalogo com filtros mora em loja.php; links antigos
 * com filtro (index.php?categoria=...) seguem para la com 301.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/CategoryRepository.php';
require_once __DIR__ . '/includes/ProductRepository.php';
require_once __DIR__ . '/includes/StoreInfo.php';
require_once __DIR__ . '/includes/Seo.php';

if (array_intersect(['mode', 'categoria', 'busca', 'mostrar_sob', 'mostrar_pronta'], array_keys($_GET)) !== []) {
    $legacy = array_intersect_key($_GET, array_flip(['mode', 'categoria', 'busca']));
    header('Location: ' . url_catalog(array_map('strval', array_filter($legacy, 'is_string'))), true, 301);
    exit;
}

$categories = array_values(array_filter(CategoryRepository::listActiveWithCounts(), static fn (array $c): bool => (int) $c['product_count'] > 0));
$featured   = ProductRepository::listActive(['featured' => true, 'limit' => 10]);
$ready      = ProductRepository::listActive(['availability' => 'pronta_entrega', 'limit' => 10]);
$made       = ProductRepository::listActive(['availability' => 'sob_encomenda', 'limit' => 6]);
// Loja recem-publicada: nenhuma peca ativa ainda. A home troca as vitrines vazias
// por um estado "arara em preparacao" com os canais que existem.
$catalogEmpty = ProductRepository::countActive([]) === 0;
$categorySlugs = array_column($categories, 'slug');

$heroTitle    = setting('hero_title', 'Um mundo sob medida.');
$heroSubtitle = setting('hero_subtitle', 'Moda geek, kawaii e cosplay, com atendimento direto pelo WhatsApp.');
$heroCta      = setting('hero_cta_label', 'Ver novidades');
$heroBanner   = setting('hero_banner_path');
$heroCity     = seo_city();
$waLink       = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$instagram    = setting('instagram_url');

// Pecas da arara: recorte, largura exibida e para onde o toque leva.
$rack = [
    ['cutout-03', 566, 1237, 'cosplay', 'Vestido com capa curta'],
    ['cutout-01', 698, 654, 'camisetas', 'Camiseta preta estampada'],
    ['cutout-04', 587, 1212, 'cosplay', 'Peça de cosplay com capa'],
    ['cutout-02', 696, 676, 'camisetas', 'Camiseta clara estampada'],
];
// Colecao sem pecas leva para a loja (ou para o aviso de preparo, com a loja vazia).
$rackHref = static fn (string $cat): string => in_array($cat, $categorySlugs, true)
    ? url_catalog(['categoria' => $cat])
    : ($catalogEmpty ? '#em-preparacao' : url_catalog());

$isHome          = true;
$pageFullTitle   = mb_strlen(store_name() . ' | Moda geek e cosplay' . seo_in_city()) <= SEO_TITLE_MAX
    ? store_name() . ' | Moda geek e cosplay' . seo_in_city()
    : store_name() . ' | Moda geek, cosplay e camisetas';
$pageDescription = seo_store_description();
$pageCanonical   = base_url();
$pageSchema      = [seo_page_node('WebPage', seo_abs(base_url()), $pageFullTitle, $pageDescription, false) + ['about' => ['@id' => seo_id('loja')]]];

require __DIR__ . '/includes/partials/header.php';
?>

<section class="hero">
  <?= sunflower(null, 'hero-sun') ?>
  <div class="rail" aria-hidden="true"></div>
  <?php if ($heroBanner !== ''): ?>
  <div class="rack rack-banner"><img src="<?= e(asset_url($heroBanner)) ?>" alt="" fetchpriority="high" width="720" height="900"></div>
  <?php else: ?>
  <div class="rack" data-rack role="group" aria-label="Peças da arara">
    <?php foreach ($rack as $i => [$file, $w, $h, $cat, $alt]): ?>
    <a class="hanging is-<?= e($file) ?><?= $h > 1000 ? ' is-long' : '' ?>" href="<?= e($rackHref($cat)) ?>" data-hanging>
      <svg class="hanger" viewBox="0 0 120 56" aria-hidden="true"><path d="M60 26V15c0-4 7-4 7-9s-4-5-7-5-6 2-6 5M60 26 8 50c-3 2-2 5 2 5h100c4 0 5-3 2-5z"/></svg>
      <img src="<?= e(asset_url('assets/hero/' . $file . '-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/' . $file . '-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/' . $file . '-720.webp')) ?> 720w" sizes="<?= $h > 1000 ? '(max-width:699px) 26vw, (max-width:999px) 19vw, 15vw' : '(max-width:699px) 34vw, (max-width:999px) 24vw, 18vw' ?>" alt="<?= e($alt) ?>" width="<?= $w ?>" height="<?= $h ?>"<?= $i < 2 ? ' fetchpriority="high"' : ' loading="lazy"' ?> draggable="false">
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="wrap hero-copy">
    <?php if ($heroCity !== ''): ?><p class="hero-tag" data-swing>Ateliê em <?= e($heroCity) ?></p><?php endif; ?>
    <h1 class="hero-title"><?= e($heroTitle) ?></h1>
    <p><?= e($heroSubtitle) ?></p>
    <div class="hero-actions">
      <?php if (!$catalogEmpty): ?>
      <a class="btn ink" href="<?= e(url_catalog()) ?>"><?= e($heroCta) ?></a>
      <?php if ($waLink !== ''): ?><a class="btn line" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> WhatsApp</a><?php endif; ?>
      <?php elseif ($waLink !== ''): ?>
      <a class="btn ink" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> Chamar no WhatsApp</a>
      <?php if ($instagram !== ''): ?><a class="btn line" href="<?= e($instagram) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      <?php elseif ($instagram !== ''): ?>
      <a class="btn ink" href="<?= e($instagram) ?>" target="_blank" rel="noopener">Ver no Instagram</a>
      <?php else: ?>
      <a class="btn ink" href="#em-preparacao">Ver novidades</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?= measuring_tape() ?>

<?php if ($catalogEmpty): ?>
<section class="wrap band prep" id="em-preparacao" aria-labelledby="preparo-title">
  <p class="prep-tag">Em preparação</p>
  <h2 class="title" id="preparo-title">A arara está sendo montada</h2>
  <p class="prep-lead">As peças estão sendo fotografadas e cadastradas com calma. Enquanto isso, a loja atende por <?= $waLink !== '' ? 'WhatsApp' : 'mensagem' ?>: cosplay, camisetas e peças feitas nas suas medidas.</p>
  <ol class="steps">
    <li><span><b>Conte a sua ideia</b>: personagem, peça ou referência.</span></li>
    <li><span><b>Combine medidas, tecido e prazo</b> com a loja.</span></li>
    <li><span><b>Aprove antes de pagar.</b> Nada é produzido sem o seu ok.</span></li>
  </ol>
  <?php if ($waLink !== '' || $instagram !== ''): ?>
  <div class="prep-actions">
    <?php if ($waLink !== ''): ?><a class="btn sun" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> Chamar no WhatsApp</a><?php endif; ?>
    <?php if ($instagram !== ''): ?><a class="btn line" href="<?= e($instagram) ?>" target="_blank" rel="noopener">Novidades no Instagram <?= e(instagram_handle($instagram)) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="wrap band" aria-labelledby="colecoes-title">
  <h2 class="title" id="colecoes-title">Escolha seu universo</h2>
  <div class="worlds">
    <?php foreach ($categories as $i => $cat): ?>
    <a class="world world-<?= $i % 4 ?>" href="<?= e(url_catalog(['categoria' => $cat['slug']])) ?>">
      <span class="world-name"><?= e($cat['name']) ?></span>
      <span class="world-count"><?= e($cat['product_count']) ?> <?= (int) $cat['product_count'] === 1 ? 'peça' : 'peças' ?></span>
      <?php if (!empty($cat['cover_image'])): ?>
      <span class="photo world-photo"><img src="<?= e(product_image_thumb_url($cat['cover_image'])) ?>" alt="" width="240" height="300" loading="lazy" decoding="async"></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($featured !== []): ?>
<section class="band" aria-labelledby="destaque-title">
  <div class="wrap band-head">
    <h2 class="title" id="destaque-title">Destaques da arara</h2>
  </div>
  <div class="shelf" tabindex="0" role="group" aria-labelledby="destaque-title">
    <?php foreach ($featured as $product): ?>
      <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($ready !== []): ?>
<section class="band band-kraft" aria-labelledby="pronta-title">
  <div class="wrap band-head">
    <h2 class="title" id="pronta-title">Prontas para sair</h2>
    <a class="more" href="<?= e(url_catalog(['mode' => 'pronta_entrega'])) ?>">Ver todas<span class="sr"> as peças de pronta entrega</span></a>
  </div>
  <div class="shelf" tabindex="0" role="group" aria-labelledby="pronta-title">
    <?php foreach ($ready as $product): ?>
      <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="story" id="brecho">
  <div class="story-photo photo"><img src="<?= e(asset_url('assets/hero/cutout-04-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/cutout-04-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-04-720.webp')) ?> 720w" sizes="(max-width:999px) 44vw, 380px" alt="Peça de cosplay com capa, foto real da loja" width="587" height="1212" loading="lazy" decoding="async"></div>
  <div class="wrap story-copy">
    <h2 class="title">Peças com história, prontas para outro arco.</h2>
    <p>Cosplay, garimpos e itens únicos do brechó. Cada peça existe uma vez só, então vale perguntar antes que ela mude de personagem.</p>
    <?php if ($waLink !== ''): ?>
    <a class="btn sun" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> Perguntar no WhatsApp</a>
    <?php elseif (in_array('cosplay', $categorySlugs, true)): ?>
    <a class="btn sun" href="<?= e(url_catalog(['categoria' => 'cosplay'])) ?>">Ver peças de cosplay</a>
    <?php elseif ($instagram !== ''): ?>
    <a class="btn sun" href="<?= e($instagram) ?>" target="_blank" rel="noopener">Ver no Instagram</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($made !== []): ?>
<section class="wrap band" aria-labelledby="medida-title">
  <div class="band-head">
    <h2 class="title" id="medida-title">Feitas nas suas medidas</h2>
    <a class="more" href="<?= e(url_catalog(['mode' => 'sob_encomenda'])) ?>">Ver todas<span class="sr"> as peças sob encomenda</span></a>
  </div>
  <ol class="steps">
    <li><span><b>Escolha a peça</b> e coloque na sacola.</span></li>
    <li><span><b>Envie pelo WhatsApp</b> e combine medidas, tecido e prazo.</span></li>
    <li><span><b>Aprove antes de pagar.</b> Nada é produzido sem o seu ok.</span></li>
  </ol>
  <div class="grid">
    <?php foreach ($made as $product): ?>
      <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

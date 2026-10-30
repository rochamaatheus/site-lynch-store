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

if (array_intersect(['mode', 'categoria', 'busca', 'mostrar_sob', 'mostrar_pronta'], array_keys($_GET)) !== []) {
    $legacy = array_intersect_key($_GET, array_flip(['mode', 'categoria', 'busca']));
    header('Location: ' . url_catalog(array_map('strval', array_filter($legacy, 'is_string'))), true, 301);
    exit;
}

$categories = array_values(array_filter(CategoryRepository::listActiveWithCounts(), static fn (array $c): bool => (int) $c['product_count'] > 0));
$featured   = ProductRepository::listActive(['featured' => true, 'limit' => 10]);
$ready      = ProductRepository::listActive(['availability' => 'pronta_entrega', 'limit' => 10]);
$made       = ProductRepository::listActive(['availability' => 'sob_encomenda', 'limit' => 6]);

$heroTitle    = setting('hero_title', 'Um mundo sob medida.');
$heroSubtitle = setting('hero_subtitle', 'Moda geek, kawaii e cosplay, com atendimento direto pelo WhatsApp.');
$heroCta      = setting('hero_cta_label', 'Ver novidades');
$heroBanner   = setting('hero_banner_path');
$waLink       = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));

// Pecas da arara: recorte, largura exibida e para onde o toque leva.
$rack = [
    ['cutout-03', 566, 1237, 'cosplay', 'Vestido com capa curta'],
    ['cutout-01', 698, 654, 'camisetas', 'Camiseta preta estampada'],
    ['cutout-04', 587, 1212, 'cosplay', 'Peça de cosplay com capa'],
    ['cutout-02', 696, 676, 'camisetas', 'Camiseta clara estampada'],
];

$isHome          = true;
$pageTitle       = 'Moda Geek, Cosplay e Camisetas';
$pageDescription = 'Lynch Store: fotos reais de cosplay, fantasia e camisetas geek. Consulte disponibilidade, medidas e valores pelo WhatsApp.';
$pageCanonical   = base_url();
$pageJsonLd = json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Store',
    'name'        => 'Lynch Store',
    'description' => $pageDescription,
    'image'       => asset_url('assets/seo-banner.webp'),
    'logo'        => asset_url('assets/lynch-logo.webp'),
    'url'         => base_url(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require __DIR__ . '/includes/partials/header.php';
?>

<section class="hero">
  <?= sunflower(null, 'hero-sun') ?>
  <div class="rail" aria-hidden="true"></div>
  <?php if ($heroBanner !== ''): ?>
  <div class="rack rack-banner"><img src="<?= e(asset_url($heroBanner)) ?>" alt="" fetchpriority="high" width="720" height="900"></div>
  <?php else: ?>
  <div class="rack" data-rack tabindex="0" role="group" aria-label="Peças da arara, deslize para ver">
    <?php foreach ($rack as $i => [$file, $w, $h, $cat, $alt]): ?>
    <a class="hanging<?= $h > 1000 ? ' is-long' : '' ?>" href="<?= e(url_catalog(['categoria' => $cat])) ?>" style="--i:<?= $i ?>" data-hanging>
      <svg class="hanger" viewBox="0 0 120 56" aria-hidden="true"><path d="M60 26V15c0-4 7-4 7-9s-4-5-7-5-6 2-6 5M60 26 8 50c-3 2-2 5 2 5h100c4 0 5-3 2-5z"/></svg>
      <img src="<?= e(asset_url('assets/hero/' . $file . '-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/' . $file . '-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/' . $file . '-720.webp')) ?> 720w" sizes="(max-width:699px) 40vw, (max-width:999px) 230px, 22vw" alt="<?= e($alt) ?>" width="<?= $w ?>" height="<?= $h ?>"<?= $i < 2 ? ' fetchpriority="high"' : ' loading="lazy"' ?> draggable="false">
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="wrap hero-copy">
    <h1 class="hero-title"><?= e($heroTitle) ?></h1>
    <p><?= e($heroSubtitle) ?></p>
    <a class="btn ink" href="<?= e(url_catalog()) ?>"><?= e($heroCta) ?></a>
  </div>
</section>

<?= measuring_tape() ?>

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
    <a class="more" href="<?= e(url_catalog(['mode' => 'pronta_entrega'])) ?>">Ver todas</a>
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
    <?php else: ?>
    <a class="btn sun" href="<?= e(url_catalog(['categoria' => 'cosplay'])) ?>">Ver peças de cosplay</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($made !== []): ?>
<section class="wrap band" aria-labelledby="medida-title">
  <div class="band-head">
    <h2 class="title" id="medida-title">Feitas nas suas medidas</h2>
    <a class="more" href="<?= e(url_catalog(['mode' => 'sob_encomenda'])) ?>">Ver todas</a>
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

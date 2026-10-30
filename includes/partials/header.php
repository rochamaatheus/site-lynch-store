<?php
/**
 * Lynch Store - cabecalho do site publico (fase 3: Catalogo publico)
 *
 * Nao e uma pagina: so pode ser incluido. Alem do bloqueio do .htaccess da raiz
 * (includes/ -> 403), este guard cobre servidores sem .htaccess (php -S).
 *
 * Variaveis opcionais que a pagina pode definir ANTES do include:
 *   $pageTitle       string  titulo da aba (sem o sufixo "| Lynch Store")
 *   $pageFullTitle   string  titulo completo, ja com a marca (home e coleções)
 *   $pageDescription string  meta description
 *   $pageImage       array   {url,width,height} da og:image (padrao: capa de assets/)
 *   $pageImageAlt    string  texto alternativo da og:image
 *   $pageCanonical   string  URL canonica (vira absoluta no dominio fixo)
 *   $pageNoIndex     bool    noindex, follow (sem canonical nem JSON-LD)
 *   $pageSchema      array   nos JSON-LD da pagina; loja e site entram sozinhos
 *   $isHome          bool    true na home (habilita preload do hero)
 *   $activeCategory  string  slug da categoria ativa (destaca no menu)
 *
 * Abre <main> e NAO fecha - quem fecha e includes/partials/footer.php.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/SettingsRepository.php';
require_once dirname(__DIR__) . '/CategoryRepository.php';
require_once dirname(__DIR__) . '/ProductRepository.php';
require_once dirname(__DIR__) . '/Cart.php';
require_once dirname(__DIR__) . '/Seo.php';

$pageTitle       = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Moda geek, cosplay e camisetas';
$pageDescription = seo_description(isset($pageDescription) && is_string($pageDescription) && $pageDescription !== ''
    ? $pageDescription
    : seo_store_description());
$fullTitle     = isset($pageFullTitle) && is_string($pageFullTitle) && $pageFullTitle !== '' ? $pageFullTitle : seo_title($pageTitle);
$pageNoIndex   = !empty($pageNoIndex) || http_response_code() === 404;
$pageCanonical = !$pageNoIndex && isset($pageCanonical) && is_string($pageCanonical) && $pageCanonical !== '' ? seo_abs($pageCanonical) : '';
$pageOgImage   = isset($pageImage) && is_array($pageImage) && !empty($pageImage['url']) ? $pageImage : seo_cover();
$pageOgAlt     = isset($pageImageAlt) && is_string($pageImageAlt) && $pageImageAlt !== '' && isset($pageImage) && is_array($pageImage) ? $pageImageAlt : seo_cover_alt();
$pageOgIsCover = !(isset($pageImage) && is_array($pageImage) && !empty($pageImage['url']));
// JSON-LD so nas paginas indexaveis: loja + site + o que a pagina mandou.
$pageJsonLd    = $pageCanonical !== '' ? seo_jsonld(array_merge(seo_site_nodes(), isset($pageSchema) && is_array($pageSchema) ? $pageSchema : [])) : '';
$isHome        = !empty($isHome);
$isBagPage     = !empty($isBagPage);
$isProductPage = !empty($isProductPage);
// Colecao sem peca nao aparece para quem compra.
$navCategories = array_values(array_filter(CategoryRepository::listActiveWithCounts(), static fn (array $c): bool => (int) $c['product_count'] > 0));
$navSearch     = isset($_GET['busca']) ? trim((string) $_GET['busca']) : '';
$navCartCount  = cart_count();
$navPath       = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));

// O token CSRF exige sessao aberta antes da primeira linha de HTML.
csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>@view-transition{navigation:auto}</style>
<link rel="expect" href="#conteudo" blocking="render">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($pageDescription) ?>">
<meta name="robots" content="<?= $pageNoIndex ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
<meta name="theme-color" content="<?= $isHome ? '#DDAE35' : '#F7EFE2' ?>">
<meta name="color-scheme" content="only light">
<meta name="format-detection" content="telephone=no">
<meta name="referrer" content="strict-origin-when-cross-origin">
<?php if ($pageCanonical !== ''): ?>
<link rel="canonical" href="<?= e($pageCanonical) ?>">
<meta property="og:url" content="<?= e($pageCanonical) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= e(isset($pageOgType) && is_string($pageOgType) ? $pageOgType : 'website') ?>">
<meta property="og:site_name" content="<?= e(store_name()) ?>">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:image" content="<?= e($pageOgImage['url']) ?>">
<meta property="og:image:width" content="<?= e($pageOgImage['width']) ?>">
<meta property="og:image:height" content="<?= e($pageOgImage['height']) ?>">
<meta property="og:image:alt" content="<?= e($pageOgAlt) ?>">
<?php if ($pageOgIsCover): $pageOgSquare = seo_cover(true); ?>
<meta property="og:image" content="<?= e($pageOgSquare['url']) ?>">
<meta property="og:image:width" content="<?= e($pageOgSquare['width']) ?>">
<meta property="og:image:height" content="<?= e($pageOgSquare['height']) ?>">
<meta property="og:image:alt" content="<?= e($pageOgAlt) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($fullTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDescription) ?>">
<meta name="twitter:image" content="<?= e($pageOgImage['url']) ?>">
<meta name="twitter:image:alt" content="<?= e($pageOgAlt) ?>">
<link rel="icon" href="<?= e(base_url('favicon.ico')) ?>" sizes="16x16 32x32 48x48">
<link rel="icon" href="<?= e(asset_url('assets/icon-192.png')) ?>" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="<?= e(asset_url('assets/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(base_url('site.webmanifest')) ?>">
<?php if ($isHome && empty($heroBanner)): ?>
<link rel="preload" as="image" href="<?= e(asset_url('assets/hero/cutout-01-360.webp')) ?>" imagesrcset="<?= e(asset_url('assets/hero/cutout-01-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-01-720.webp')) ?> 720w" imagesizes="(max-width:699px) 40vw, (max-width:999px) 230px, 22vw" fetchpriority="high">
<?php endif; ?>
<link rel="preload" as="font" type="font/woff2" href="<?= e(asset_url('assets/fonts/Shrikhand-latin.woff2')) ?>" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="<?= e(asset_url('assets/fonts/Inter-Loja.woff2')) ?>" crossorigin>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/loja.css')) ?>">
<?php if ($pageJsonLd !== ''): ?>
<script type="application/ld+json"><?= $pageJsonLd ?></script>
<?php endif; ?>
</head>
<body class="<?= $isHome ? 'is-home' : '' ?><?= $isProductPage ? ' is-product' : '' ?>">
<a class="skip" href="#conteudo">Pular para o conteúdo</a>
<header class="top">
  <div class="wrap top-row">
    <?php if ($isProductPage): ?>
    <a class="round plain top-back" href="<?= e(url_catalog()) ?>" aria-label="Voltar para a loja"><?= icon('back') ?></a>
    <?php endif; ?>
    <a class="brand" href="<?= e(base_url()) ?>" aria-label="Lynch Store, página inicial">
      <img src="<?= e(asset_url('assets/lynch-logo-96.webp')) ?>" alt="" width="40" height="40">
      <span>Lynch Store</span>
    </a>
    <nav class="top-nav" aria-label="Coleções">
      <a href="<?= e(url_catalog()) ?>">Toda a loja</a>
      <?php foreach ($navCategories as $navCat): ?>
      <a href="<?= e(url_catalog(['categoria' => $navCat['slug']])) ?>"><?= e($navCat['name']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="top-tools">
      <a class="round plain" href="<?= e(url_catalog([], 'busca')) ?>" data-open-search aria-label="Buscar"><?= icon('search') ?></a>
      <a class="round plain top-only" href="<?= e(base_url('favoritos.php')) ?>" aria-label="Favoritos"><?= icon('heart') ?></a>
      <a class="top-bag" href="<?= e(base_url('carrinho.php')) ?>"<?= $isBagPage ? '' : ' data-open-bag' ?> aria-label="Sacola com <?= e($navCartCount) ?> <?= $navCartCount === 1 ? 'peça' : 'peças' ?>"><?= bag_bloom($navCartCount) ?></a>
    </div>
  </div>
</header>
<main id="conteudo" tabindex="-1">
<?php include __DIR__ . '/flash.php'; ?>

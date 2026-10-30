<?php
/**
 * Lynch Store - cabecalho do site publico (fase 3: Catalogo publico)
 *
 * Nao e uma pagina: so pode ser incluido. Alem do bloqueio do .htaccess da raiz
 * (includes/ -> 403), este guard cobre servidores sem .htaccess (php -S).
 *
 * Variaveis opcionais que a pagina pode definir ANTES do include:
 *   $pageTitle       string  titulo da aba (sem o sufixo "| Lynch Store")
 *   $pageDescription string  meta description
 *   $pageImage       string  caminho da imagem de og:image (padrao: seo-banner)
 *   $pageCanonical   string  URL canonica absoluta
 *   $pageJsonLd      string  bloco JSON-LD ja serializado
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

$pageTitle       = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Moda Geek, Cosplay e Camisetas';
$pageDescription = isset($pageDescription) && is_string($pageDescription) && $pageDescription !== ''
    ? $pageDescription
    : 'Lynch Store: fotos reais de cosplay, fantasia e camisetas geek. Consulte disponibilidade, medidas e valores pelo WhatsApp.';
$pageImage     = isset($pageImage) && is_string($pageImage) && $pageImage !== '' ? $pageImage : 'assets/seo-banner.webp';
$pageCanonical = isset($pageCanonical) && is_string($pageCanonical) ? $pageCanonical : '';
$pageJsonLd    = isset($pageJsonLd) && is_string($pageJsonLd) ? $pageJsonLd : '';
$isHome        = !empty($isHome);
$isBagPage     = !empty($isBagPage);
$isProductPage = !empty($isProductPage);
$fullTitle     = 'Lynch Store | ' . $pageTitle;
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
<meta name="robots" content="<?= !empty($pageNoIndex) || http_response_code() === 404 ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
<meta name="theme-color" content="<?= $isHome ? '#DDAE35' : '#F7EFE2' ?>">
<meta name="color-scheme" content="only light">
<meta name="format-detection" content="telephone=no">
<meta name="referrer" content="strict-origin-when-cross-origin">
<?php if ($pageCanonical !== ''): ?>
<link rel="canonical" href="<?= e($pageCanonical) ?>">
<meta property="og:url" content="<?= e($pageCanonical) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= e(isset($pageOgType) && is_string($pageOgType) ? $pageOgType : 'website') ?>">
<meta property="og:site_name" content="Lynch Store">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:image" content="<?= e(asset_url($pageImage)) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset_url('assets/lynch-logo.webp')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset_url('assets/apple-touch-icon.png')) ?>">
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

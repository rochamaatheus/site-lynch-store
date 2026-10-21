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
    : 'Lynch Store: vitrine com fotos reais de cosplay, fantasia e camisetas geek. Consulte disponibilidade, medidas e valores pelo WhatsApp.';
$pageImage      = isset($pageImage) && is_string($pageImage) && $pageImage !== ''
    ? $pageImage
    : 'assets/seo-banner.webp';
$pageCanonical  = isset($pageCanonical) && is_string($pageCanonical) ? $pageCanonical : '';
$pageJsonLd     = isset($pageJsonLd) && is_string($pageJsonLd) ? $pageJsonLd : '';
$isHome         = !empty($isHome);
$activeCategory = isset($activeCategory) && is_string($activeCategory) ? $activeCategory : '';

$fullTitle = 'Lynch Store | ' . $pageTitle;

// Numero de WhatsApp ainda pode estar vazio (a Ray cadastra no admin).
// Sem numero, o botao vira um estado "em breve" - nunca um link morto.
$waLink  = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$waReady = $waLink !== '';

// Menu mobile: categorias reais do banco + contagens por disponibilidade.
$navCategories   = CategoryRepository::listActiveWithCounts();
$navTotalActive  = ProductRepository::countActive();
$navSobEncomenda = ProductRepository::countActive(['availability' => 'sob_encomenda']);
$navProntaEntrega = ProductRepository::countActive(['availability' => 'pronta_entrega']);

// Termo de busca atual (o campo do header e um GET real).
$navSearch = isset($_GET['busca']) ? trim((string) $_GET['busca']) : '';

// Contador do carrinho (fase 4). cart_count() so soma a sessao - nao vai ao
// banco e nao cria sessao para quem nunca adicionou nada. O CSS esconde o badge
// quando data-count="0".
$navCartCount = cart_count();

// Os botoes "Adicionar ao carrinho" sao <form method="post"> com token CSRF, e
// o token exige sessao aberta. session_start() precisa acontecer ANTES da
// primeira linha de HTML (senao o cookie da sessao nao e enviado e o PHP
// reclama de "headers already sent"), entao o token e criado aqui, no prelude
// do header, e nao la embaixo no card.
csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($pageDescription) ?>">
<meta name="robots" content="<?= !empty($pageNoIndex) || http_response_code() === 404 ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
<meta name="theme-color" content="#A64E2E">
<meta name="color-scheme" content="light">
<meta name="referrer" content="strict-origin-when-cross-origin">
<?php if ($pageCanonical !== ''): ?>
<link rel="canonical" href="<?= e($pageCanonical) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= e(isset($pageOgType) && is_string($pageOgType) ? $pageOgType : 'website') ?>">
<meta property="og:site_name" content="Lynch Store">
<meta property="og:locale" content="pt_BR">
<?php if ($pageCanonical !== ''): ?><meta property="og:url" content="<?= e($pageCanonical) ?>"><?php endif; ?>
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e($pageDescription) ?>">
<meta property="og:image" content="<?= e(asset_url($pageImage)) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($fullTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDescription) ?>">
<meta name="twitter:image" content="<?= e(asset_url($pageImage)) ?>">
<link rel="icon" href="<?= e(asset_url('assets/lynch-logo.webp')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset_url('assets/lynch-logo.webp')) ?>">
<?php if ($isHome && empty($heroBanner)): ?>
<link rel="preload" as="image" href="<?= e(asset_url('assets/hero/cutout-02-360.webp')) ?>" imagesrcset="<?= e(asset_url('assets/hero/cutout-02-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-02-720.webp')) ?> 720w" imagesizes="(max-width:760px) 45vw, 300px" fetchpriority="high">
<?php endif; ?>
<!-- Fase 7: Inter variavel self-hosted. O subset "latin" cobre todo o portugues
     (inclusive acentos e cedilha), entao so ele e pre-carregado; o "latin-ext"
     e baixado sob demanda pelo @font-face. crossorigin e obrigatorio em preload
     de fonte, mesmo sendo do mesmo dominio - sem ele o arquivo baixa duas vezes. -->
<link rel="preload" as="font" type="font/woff2" href="<?= e(asset_url('assets/fonts/Inter-Variable-latin.woff2')) ?>" crossorigin>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
<?php if ($pageJsonLd !== ''): ?>
<script type="application/ld+json"><?= $pageJsonLd ?></script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header>
  <div class="container head">
    <a class="logo" href="<?= e(base_url()) ?>" aria-label="Lynch Store - pagina inicial">
      <span class="mark"><img src="<?= e(asset_url('assets/lynch-logo.webp')) ?>" alt="Lynch Store" width="42" height="42"></span>
      <span><b>Lynch Store</b><small>geek &amp; cosplay</small></span>
    </a>
    <nav class="desktop-nav" aria-label="Navegacao principal">
      <a href="<?= e(base_url('index.php')) ?>#produtos">Produtos</a>
      <a href="<?= e(base_url('index.php')) ?>#colecoes">Coleções</a>
      <a href="<?= e(base_url('index.php')) ?>#brecho">Brechó</a>
      <a href="<?= e(base_url('index.php')) ?>#contato">Contato</a>
    </nav>
    <form class="search" action="<?= e(base_url('index.php')) ?>" method="get" role="search">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
      <label class="visually-hidden" for="busca">Buscar na loja</label>
      <input type="search" id="busca" name="busca" value="<?= e($navSearch) ?>" placeholder="Buscar peça, anime ou coleção">
    </form>
    <div class="head-actions">
      <?php if ($waReady): ?>
      <a class="btn primary" href="<?= e($waLink) ?>" target="_blank" rel="noopener" aria-label="Falar no WhatsApp">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
        <span>WhatsApp</span>
      </a>
      <?php else: ?>
      <span class="btn primary is-disabled" title="Configuração pendente" aria-disabled="true">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
        <span>WhatsApp</span>
      </span>
      <?php endif; ?>
      <a class="icon-btn" href="<?= e(base_url('carrinho.php')) ?>" aria-label="Ver carrinho<?= $navCartCount > 0 ? ' (' . e($navCartCount) . ' ' . ($navCartCount === 1 ? 'item' : 'itens') . ')' : ' (vazio)' ?>">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z"></path><path d="M9 8a3 3 0 0 1 6 0"></path></svg>
        <span class="cart-count" id="cart-count" data-count="<?= e($navCartCount) ?>"><?= e($navCartCount) ?></span>
      </a>
      <button class="icon-btn menu-btn" type="button" data-menu-open aria-label="Abrir menu" aria-controls="drawer" aria-expanded="false">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
      </button>
    </div>
  </div>
</header>
<form class="mobile-search container" action="<?= e(base_url('index.php')) ?>#produtos" method="get" role="search">
  <label class="visually-hidden" for="busca-mobile">Buscar no catálogo</label>
  <input id="busca-mobile" name="busca" type="search" placeholder="Encontre sua próxima peça" value="<?= e($navSearch) ?>" maxlength="80">
  <button class="btn" type="submit">Buscar</button>
</form>
<!-- Fase 7: sentinel de 1px do header de dois estados. Precisa vir logo DEPOIS
     do </header> (e antes de qualquer conteudo) para representar "a pagina esta
     no topo". Quem observa e assets/js/main.js. -->
<div class="head-sentinel" data-head-sentinel aria-hidden="true"></div>

<div class="drawer" id="drawer" data-drawer role="dialog" aria-modal="true" aria-label="Menu da loja" inert>
  <nav class="drawer-panel" aria-label="Menu mobile">
    <button class="drawer-close" type="button" data-menu-close aria-label="Fechar menu">&times;</button>
    <h2>Disponibilidade</h2>
    <a href="<?= e(url_catalog([], 'produtos')) ?>">Todos os produtos <span><?= e($navTotalActive) ?></span></a>
    <a href="<?= e(url_catalog(['mode' => 'sob_encomenda'], 'produtos')) ?>">Sob encomenda <span><?= e($navSobEncomenda) ?></span></a>
    <a href="<?= e(url_catalog(['mode' => 'pronta_entrega'], 'produtos')) ?>">Pronta entrega <span><?= e($navProntaEntrega) ?></span></a>
    <h2>Por coleção</h2>
    <?php foreach ($navCategories as $navCat): ?>
    <a href="<?= e(url_catalog(['categoria' => $navCat['slug']], 'produtos')) ?>"><?= e($navCat['name']) ?> <span><?= e($navCat['product_count']) ?></span></a>
    <?php endforeach; ?>
    <h2>Mais</h2>
    <a href="<?= e(base_url('index.php')) ?>#brecho">Brechó de Cosplay <span>›</span></a>
    <a href="<?= e(base_url('index.php')) ?>#contato">Contato <span>›</span></a>
  </nav>
</div>

<main id="conteudo" tabindex="-1">
<?php include __DIR__ . '/flash.php'; ?>

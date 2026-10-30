<?php
/**
 * Lynch Store - cabecalho das paginas do painel
 *
 * Nao e uma pagina: so pode ser incluido. Alem do bloqueio do .htaccess da raiz
 * (RewriteRule ^admin/partials -> 403), este guard cobre servidores sem
 * .htaccess (php -S, nginx mal configurado).
 *
 * Variaveis opcionais que a pagina pode definir ANTES do include:
 *   $pageTitle (string)  - titulo da aba
 *   $admin     (array)   - resultado de require_login(), evita reconsultar
 *   $navActive (string)  - area atual: dashboard | produtos | pedidos | cupons |
 *                          categorias | configuracoes | conta | importar | mais
 *   $bodyClass (string)  - 'has-savebar' nas telas com barra de salvar fixa
 *                          (no celular ela ocupa o lugar do menu de baixo)
 *
 * Celular primeiro: o menu principal e uma barra fixa embaixo (alcance do
 * polegar) com Inicio, Produtos, Pedidos, Cupons e Mais. A partir de 900px o
 * mesmo conjunto aparece no topo, com as areas do "Mais" abertas.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

$admin     = isset($admin) && is_array($admin) ? $admin : current_admin();
$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Painel';
$navActive = isset($navActive) && is_string($navActive) ? $navActive : 'dashboard';
$bodyClass = isset($bodyClass) && is_string($bodyClass) ? $bodyClass : '';
$base      = APP_BASE_URL;

// Pedidos novos: selo no item Pedidos. Tabela pequena; se falhar, so some o selo.
try {
    $adminNovos = (int) db()->query("SELECT COUNT(*) FROM checkout_logs WHERE status='novo'")->fetchColumn();
} catch (Throwable $e) {
    $adminNovos = 0;
}

$adminIcons = [
    'home'  => '<path d="M4 11l8-7 8 7v9h-5v-6H9v6H4z"/>',
    'tag'   => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
    'bag'   => '<path d="M5 8h14l-1 12H6zM9 8V6a3 3 0 0 1 6 0v2"/>',
    'cut'   => '<path d="M4 7h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4z"/><path d="M14 7v10" stroke-dasharray="2 2"/>',
    'more'  => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
];
$adminIcon = static fn (string $name): string => '<svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true">' . ($adminIcons[$name] ?? '') . '</svg>';

// Barra de baixo (celular): as cinco areas mais usadas.
$adminTabs = [
    'dashboard' => ['Início', '/admin/index.php', 'home'],
    'produtos'  => ['Produtos', '/admin/produtos.php', 'tag'],
    'pedidos'   => ['Pedidos', '/admin/pedidos.php', 'bag'],
    'cupons'    => ['Cupons', '/admin/cupons.php', 'cut'],
    'mais'      => ['Mais', '/admin/mais.php', 'more'],
];
$adminMoreKeys = ['mais', 'categorias', 'configuracoes', 'conta', 'importar'];
$adminTabActive = in_array($navActive, $adminMoreKeys, true) ? 'mais' : $navActive;

// Menu do topo (telas largas): tudo aberto, sem o "Mais".
$adminNavItems = [
    'dashboard'     => ['Início', '/admin/index.php'],
    'produtos'      => ['Produtos', '/admin/produtos.php'],
    'pedidos'       => ['Pedidos', '/admin/pedidos.php'],
    'cupons'        => ['Cupons', '/admin/cupons.php'],
    'categorias'    => ['Categorias', '/admin/categorias.php'],
    'configuracoes' => ['Configurações', '/admin/configuracoes.php'],
    'importar'      => ['Importar', '/admin/importar.php'],
    'conta'         => ['Minha conta', '/admin/conta.php'],
];

$adminFlash = flash_get();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#171210">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Lynch painel">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle) ?> | Lynch Store</title>
<link rel="manifest" href="<?= e($base) ?>/admin/manifest.webmanifest">
<link rel="icon" href="<?= e(asset_url('assets/lynch-logo.webp')) ?>">
<link rel="apple-touch-icon" href="<?= e($base) ?>/admin/icons/apple-touch-icon.png">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
<script src="<?= e(asset_url('assets/js/admin.js')) ?>" defer></script>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip" href="#conteudo">Pular para o conteúdo</a>
<header class="admin-head">
  <div class="container bar">
    <a class="admin-brand" href="<?= e($base) ?>/admin/index.php">
      <?= sunflower() ?>
      <span><b>Lynch Store</b><small>Painel</small></span>
    </a>
    <nav class="admin-nav" aria-label="Navegação do painel">
      <?php foreach ($adminNavItems as $navKey => [$navLabel, $navPath]): ?>
        <a href="<?= e($base . $navPath) ?>"<?= $navActive === $navKey ? ' aria-current="page"' : '' ?>><?= e($navLabel) ?><?php if ($navKey === 'pedidos' && $adminNovos > 0): ?> <span class="nav-n"><?= $adminNovos ?><span class="sr"> novos</span></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="head-store" href="<?= e($base) ?>/index.php" target="_blank" rel="noopener">Ver a loja<span class="sr"> (abre em outra aba)</span> <span aria-hidden="true">&nearr;</span></a>
    <form class="head-out" method="post" action="<?= e(base_url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="btn small" type="submit">Sair</button></form>
  </div>
</header>
<?= measuring_tape() ?>
<nav class="tabbar" aria-label="Menu principal">
  <?php foreach ($adminTabs as $tabKey => [$tabLabel, $tabPath, $tabIcon]): ?>
    <a href="<?= e($base . $tabPath) ?>"<?= $adminTabActive === $tabKey ? ' aria-current="page"' : '' ?>>
      <?= $adminIcon($tabIcon) ?>
      <span class="tabbar-lbl"><?= e($tabLabel) ?></span>
      <?php if ($tabKey === 'pedidos' && $adminNovos > 0): ?><span class="tabbar-n"><?= $adminNovos > 99 ? '99+' : $adminNovos ?><span class="sr"> novos</span></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
<main id="conteudo" tabindex="-1">
  <div class="container">
<?php foreach ($adminFlash as $adminFlashItem): ?>
  <?php
    $adminFlashType = (string) ($adminFlashItem['type'] ?? 'info');
    if (!in_array($adminFlashType, ['success', 'error', 'info'], true)) {
        $adminFlashType = 'info';
    }
  ?>
  <div class="flash <?= $adminFlashType ?>" role="<?= $adminFlashType === 'error' ? 'alert' : 'status' ?>"><?= e((string) ($adminFlashItem['message'] ?? '')) ?></div>
<?php endforeach; ?>

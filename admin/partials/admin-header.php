<?php
/**
 * Lynch Store - cabecalho das paginas do painel (fase 5)
 *
 * Nao e uma pagina: so pode ser incluido. Alem do bloqueio do .htaccess da raiz
 * (RewriteRule ^admin/partials -> 403), este guard cobre servidores sem
 * .htaccess (php -S, nginx mal configurado).
 *
 * Variaveis opcionais que a pagina pode definir ANTES do include:
 *   $pageTitle (string)  - titulo da aba e do cabecalho
 *   $admin     (array)   - resultado de require_login(), evita reconsultar
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
$adminName = (string) ($admin['display_name'] ?? $admin['username'] ?? '');
$base      = APP_BASE_URL;

// Item de menu ativo: a pagina define $navActive antes do include
// ('produtos' | 'categorias' | 'configuracoes' | 'dashboard').
$navActive = isset($navActive) && is_string($navActive) ? $navActive : 'dashboard';

$adminFlash = flash_get();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Lynch Store</title>
<link rel="icon" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/lynch-logo.webp">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
<script src="<?= e(asset_url('assets/js/admin.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#conteudo">Pular para o conteúdo</a>
<header class="admin-head">
  <div class="container bar">
    <a class="admin-brand" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/admin/index.php">
      <?= sunflower() ?>
      <span><b>Lynch Store</b><small>Painel administrativo</small></span>
    </a>
    <nav class="admin-nav" aria-label="Navegação do painel">
      <?php
      $adminNavItems = [
          'dashboard'     => ['Dashboard', '/admin/index.php'],
          'produtos'      => ['Produtos', '/admin/produtos.php'],
          'pedidos'       => ['Pedidos', '/admin/pedidos.php'],
          'importar'      => ['Importar', '/admin/importar.php'],
          'categorias'    => ['Categorias', '/admin/categorias.php'],
          'configuracoes' => ['Configurações', '/admin/configuracoes.php'],
          'conta'         => ['Minha conta', '/admin/conta.php'],
      ];
      foreach ($adminNavItems as $navKey => [$navLabel, $navPath]):
      ?>
        <a href="<?= htmlspecialchars($base . $navPath, ENT_QUOTES, 'UTF-8') ?>"
           <?= $navActive === $navKey ? 'aria-current="page"' : '' ?>><?= $navLabel ?></a>
      <?php endforeach; ?>
      <a class="nav-out" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php" target="_blank" rel="noopener">Ver a loja &nearr;</a>
      <?php if ($adminName !== ''): ?>
        <span class="who"><i></i><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <form method="post" action="<?= e(base_url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="btn small" type="submit">Sair</button></form>
    </nav>
  </div>
</header>
<?= measuring_tape() ?>
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

<?php
/**
 * Lynch Store - login do painel (fase 5)
 *
 * Tela isolada: nao usa os partials do admin (nao tem nav, nem "Sair").
 * Visual em assets/css/admin.css (sem estilo inline), o mesmo do restante do painel.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ensure_session();

$dashboard = APP_BASE_URL . '/admin/index.php';

// ?redirect= (GET) sobrevive ao POST por um campo hidden. Sempre validado:
// so caminho local dentro de /admin - nunca uma URL externa (open redirect).
$redirectRaw  = $_POST['redirect'] ?? $_GET['redirect'] ?? null;
$safeRedirect = safe_admin_redirect(is_string($redirectRaw) ? $redirectRaw : null);
$destination  = $safeRedirect ?? $dashboard;

// Ja logada? Nao faz sentido ver o formulario.
if (current_admin() !== null) {
    header('Location: ' . $destination, true, 302);
    exit;
}

$error    = null;
$username = '';
$expired  = isset($_GET['expirou']) && $_SERVER['REQUEST_METHOD'] === 'GET';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token    = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null;

    if (!csrf_check($token)) {
        // Mensagem generica: nem confirma nem nega que as credenciais serviriam.
        $error = 'Não foi possível validar o formulário. Recarregue a página e tente de novo.';
    } else {
        $result = attempt_login($username, $password);

        if ($result['ok']) {
            header('Location: ' . $destination, true, 302);
            exit;
        }

        $error = $result['error'] ?? 'Usuário ou senha inválidos.';
    }
}

header('X-Robots-Tag: noindex, nofollow');

/** Atalho local de escape. */
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Entrar | Lynch Store</title>
<link rel="icon" href="<?= $h(APP_BASE_URL) ?>/assets/lynch-logo.webp">
<link rel="stylesheet" href="<?= $h(asset_url('assets/css/admin.css')) ?>">
</head>
<body class="login">
<?= measuring_tape() ?>
<main class="login-main">
  <?= sunflower(null, 'login-flower') ?>
  <div class="card login-card">
  <p class="eyebrow">Lynch Store</p>
  <h1>Painel administrativo</h1>
  <p class="sub">Entre para gerenciar produtos, fotos e configurações da loja.</p>

  <?php if ($error !== null): ?>
    <p class="alert" role="alert"><?= $h($error) ?></p>
  <?php elseif ($expired): ?>
    <p class="alert info" role="status">Sua sessão expirou por inatividade. Entre de novo e refaça a última ação: o que ficou sem salvar precisa ser digitado outra vez.</p>
  <?php endif; ?>

  <form method="post" action="<?= $h(APP_BASE_URL) ?>/admin/login.php" autocomplete="on" novalidate>
    <?= csrf_field() ?>
    <?php if ($safeRedirect !== null): ?>
      <input type="hidden" name="redirect" value="<?= $h($safeRedirect) ?>">
    <?php endif; ?>

    <div class="field">
      <label for="username">Usuário</label>
      <input type="text" id="username" name="username" value="<?= $h($username) ?>"
             autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </div>

    <div class="field">
      <label for="password">Senha</label>
      <div class="pw">
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <button type="button" class="pw-toggle" data-pw-toggle="password" aria-pressed="false" hidden>Mostrar</button>
      </div>
    </div>

    <button class="btn primary wide" type="submit">Entrar</button>
  </form>

  <p class="foot">Acesso restrito &middot; Lynch Store</p>
  </div>
</main>
<script src="<?= $h(asset_url('assets/js/admin.js')) ?>" defer></script>
</body>
</html>

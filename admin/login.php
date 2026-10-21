<?php
/**
 * Lynch Store - login do painel (fase 5)
 *
 * Tela isolada: nao usa os partials do admin (nao tem nav, nem "Sair").
 * Estilo inline de proposito - o CSS publico completo so chega na fase 3/7.
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
<style>
:root{
  --ink:#171210; --muted:#6E5B4E; --soft:#715B48;
  --paper:#F7EFE2; --cream:#FFF7EA; --kraft:#B98958;
  --rust:#A64E2E; --sun:#DDAE35;
  --line:rgba(23,18,16,.15); --line-strong:rgba(23,18,16,.28);
  --white:#fff; --shadow:0 18px 48px rgba(23,18,16,.15);
  --r:24px; --pill:999px;
}
*{box-sizing:border-box}
body{
  margin:0;min-height:100vh;min-height:100svh;color:var(--ink);
  display:grid;place-items:center;padding:32px 20px;
  background:
    linear-gradient(180deg,rgba(247,239,226,.96),rgba(255,247,234,.98)),
    radial-gradient(circle at 14% 10%,rgba(221,174,53,.22),transparent 32%),
    radial-gradient(circle at 86% 18%,rgba(166,78,46,.18),transparent 30%);
  font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
  line-height:1.5;
}
a{color:var(--rust)}
:focus-visible{outline:2px solid var(--rust);outline-offset:3px}
.card{
  width:100%;max-width:420px;background:var(--white);
  border:1px solid var(--line);border-radius:var(--r);
  padding:34px 30px;box-shadow:var(--shadow);
}
.eyebrow{
  font-size:11px;letter-spacing:.18em;text-transform:uppercase;
  font-weight:850;color:var(--rust);margin:0 0 8px;
}
h1{font-size:26px;margin:0 0 6px;letter-spacing:.01em}
.sub{margin:0 0 24px;font-size:14px;color:var(--muted)}
label{display:block;font-size:12px;font-weight:850;letter-spacing:.06em;
  text-transform:uppercase;color:var(--muted);margin:0 0 6px}
.field{margin-bottom:18px}
input[type=text],input[type=password]{
  width:100%;min-height:46px;padding:11px 15px;font:inherit;color:var(--ink);
  border:1px solid var(--line-strong);border-radius:14px;background:var(--cream);
}
input:focus{border-color:var(--rust);background:var(--white)}
.btn{
  width:100%;min-height:48px;border:1px solid var(--rust);border-radius:var(--pill);
  background:var(--rust);color:#fff;font:inherit;font-weight:850;font-size:15px;
  cursor:pointer;
}
.btn:hover{filter:brightness(1.05)}
.pw{position:relative}
.pw input{padding-right:92px}
.pw-toggle{
  position:absolute;top:0;right:0;height:100%;min-width:84px;padding:0 14px;border:0;
  background:none;color:var(--rust);font:inherit;font-size:13px;font-weight:850;cursor:pointer;
}
.pw-toggle[hidden]{display:none}
.alert.info{border-color:var(--line-strong);background:var(--cream);color:var(--ink)}
.alert{
  border:1px solid rgba(166,78,46,.4);background:rgba(166,78,46,.09);
  color:#7d3620;border-radius:14px;padding:12px 14px;font-size:14px;
  font-weight:700;margin:0 0 20px;
}
.foot{margin:22px 0 0;font-size:12px;color:var(--soft);text-align:center}
</style>
</head>
<body>
<main class="card">
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

    <button class="btn" type="submit">Entrar</button>
  </form>

  <p class="foot">Acesso restrito &middot; Lynch Store</p>
</main>
<script src="<?= $h(asset_url('assets/js/admin.js')) ?>" defer></script>
</body>
</html>

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
$admin = require_login();
require_once dirname(__DIR__) . '/includes/functions.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { $error = 'A página ficou aberta por muito tempo. Recarregue e tente de novo.'; }
    else {
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $stmt = db()->prepare('SELECT password_hash FROM admin_users WHERE id=?');
        $stmt->execute([$admin['id']]);
        if (!password_verify($current, (string) $stmt->fetchColumn())) { $error = 'A senha atual não confere.'; }
        elseif (strlen($password) < 12 || strlen($password) > 72) { $error = 'Use uma senha com pelo menos 12 caracteres. Uma frase longa é uma boa escolha.'; }
        elseif ($password !== $confirm) { $error = 'A confirmação precisa ser igual à nova senha.'; }
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            db()->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')->execute([$hash, $admin['id']]);
            $_SESSION['admin_fingerprint'] = hash('sha256', $hash);
            session_regenerate_id(true);
            unset($_SESSION['csrf']);
            flash_set('success', 'Senha alterada. As outras sessões serão desconectadas.');
            header('Location: ' . base_url('admin/conta.php'), true, 303); exit;
        }
    }
}
$pageTitle = 'Minha conta'; $navActive = 'conta';
require __DIR__ . '/partials/admin-header.php';
?>
<p class="crumb"><a href="<?= e(base_url('admin/mais.php')) ?>"><span aria-hidden="true">&larr;</span> Mais opções</a></p>
<div class="page-head"><div><h1>Minha conta</h1><p class="muted">Você entra como <b><?= e($admin['username']) ?></b>.</p></div></div>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card narrow-form" data-busy="Salvando..."><?= csrf_field() ?>
<h2 class="sec">Trocar a senha</h2>
<input type="text" name="username" value="<?= e($admin['username']) ?>" autocomplete="username" hidden>
<div class="field"><label for="current_password">Senha atual</label><div class="pw"><input id="current_password" type="password" name="current_password" autocomplete="current-password" autocapitalize="none" spellcheck="false" required><button type="button" class="pw-toggle" data-pw-toggle="current_password" aria-label="Mostrar senha atual" hidden>Mostrar</button></div></div>
<div class="field"><label for="password">Nova senha</label><div class="pw"><input id="password" type="password" name="password" autocomplete="new-password" autocapitalize="none" spellcheck="false" required minlength="12" maxlength="72" aria-describedby="pw-hint"><button type="button" class="pw-toggle" data-pw-toggle="password" aria-label="Mostrar nova senha" hidden>Mostrar</button></div><span class="hint" id="pw-hint">Pelo menos 12 caracteres. Uma frase longa é fácil de lembrar, como "girassol azul na arara".</span></div>
<div class="field"><label for="confirm_password">Confirme a nova senha</label><div class="pw"><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" autocapitalize="none" spellcheck="false" required minlength="12" maxlength="72"><button type="button" class="pw-toggle" data-pw-toggle="confirm_password" aria-label="Mostrar confirme a nova senha" hidden>Mostrar</button></div></div>
<button class="btn primary wide" type="submit">Alterar senha</button>
<p class="hint">Ao trocar, os outros aparelhos conectados saem do painel.</p></form>
<section class="card narrow-form out-card" aria-labelledby="h-sair">
<h2 class="sec" id="h-sair">Sair deste aparelho</h2>
<p class="hint">Sem uso por 2 horas, o painel pede a senha de novo.</p>
<form method="post" action="<?= e(base_url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="btn ghost wide" type="submit">Sair</button></form>
</section>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

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
<div class="page-head"><div><h1>Minha conta</h1><p class="muted">Acesso de <?= e($admin['username']) ?>. Use uma senha exclusiva para a loja.</p></div></div>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card narrow-form"><?= csrf_field() ?>
<div class="field"><label for="current_password">Senha atual</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
<div class="field"><label for="password">Nova senha</label><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
<div class="field"><label for="confirm_password">Confirme a nova senha</label><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
<button class="btn primary" type="submit">Alterar senha</button></form>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

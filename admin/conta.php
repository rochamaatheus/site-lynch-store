<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
$admin = require_login();
require_once dirname(__DIR__) . '/includes/functions.php';
$error = '';
$revokeError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'A página ficou aberta por muito tempo. Recarregue e tente de novo.';
    } elseif (($_POST['op'] ?? '') === 'encerrar') {
        // Encerra as sessoes dos outros aparelhos (pede a senha: e uma acao de seguranca).
        if (admin_revoke_other_sessions((int) $admin['id'], (string) ($_POST['revoke_password'] ?? ''))) {
            flash_set('success', 'Pronto. Os outros aparelhos saíram do painel e vão precisar da senha para entrar de novo.');
            header('Location: ' . base_url('admin/conta.php'), true, 303); exit;
        }
        $revokeError = 'A senha não confere. Nada foi encerrado.';
    } else {
        $error = (string) admin_change_password(
            (int) $admin['id'],
            (string) $admin['username'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if ($error === '') {
            flash_set('success', 'Senha alterada. As outras sessões serão desconectadas.');
            header('Location: ' . base_url('admin/conta.php'), true, 303); exit;
        }
    }
}
$history = SecurityLog::recent(40);
$myIp = SecurityLog::ipHash();
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
<div class="field"><label for="password">Nova senha</label><div class="pw"><input id="password" type="password" name="password" autocomplete="new-password" autocapitalize="none" spellcheck="false" required minlength="12" maxlength="72" aria-describedby="pw-hint"><button type="button" class="pw-toggle" data-pw-toggle="password" aria-label="Mostrar nova senha" hidden>Mostrar</button></div><span class="hint" id="pw-hint">Pelo menos 12 caracteres. Uma frase longa e só sua é fácil de lembrar e difícil de adivinhar. Senhas de exemplo e sequências como 123456789012 são recusadas.</span></div>
<div class="field"><label for="confirm_password">Confirme a nova senha</label><div class="pw"><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" autocapitalize="none" spellcheck="false" required minlength="12" maxlength="72"><button type="button" class="pw-toggle" data-pw-toggle="confirm_password" aria-label="Mostrar confirme a nova senha" hidden>Mostrar</button></div></div>
<button class="btn primary wide" type="submit">Alterar senha</button>
<p class="hint">Ao trocar, os outros aparelhos conectados saem do painel.</p></form>

<form method="post" class="card narrow-form" data-busy="Encerrando..." aria-labelledby="h-encerrar"><?= csrf_field() ?>
<input type="hidden" name="op" value="encerrar">
<h2 class="sec" id="h-encerrar">Encerrar as sessões dos outros aparelhos</h2>
<p class="hint">Use se perdeu um celular, entrou num computador emprestado ou viu um acesso estranho na lista abaixo. Este aparelho continua conectado.</p>
<?php if ($revokeError): ?><div class="flash error" role="alert"><?= e($revokeError) ?></div><?php endif; ?>
<input type="text" name="username" value="<?= e($admin['username']) ?>" autocomplete="username" hidden>
<div class="field"><label for="revoke_password">Sua senha</label><div class="pw"><input id="revoke_password" type="password" name="revoke_password" autocomplete="current-password" autocapitalize="none" spellcheck="false" required><button type="button" class="pw-toggle" data-pw-toggle="revoke_password" aria-label="Mostrar sua senha" hidden>Mostrar</button></div></div>
<button class="btn ghost wide" type="submit">Encerrar os outros aparelhos</button>
</form>

<section class="card narrow-form" aria-labelledby="h-acessos">
<h2 class="sec" id="h-acessos">Acessos e alterações recentes</h2>
<?php if (!SecurityLog::ready()): ?>
<p class="hint">A lista de acessos aparece depois que o banco da loja for atualizado (db/upgrade.php). Avise o suporte.</p>
<?php elseif ($history === []): ?>
<p class="hint">Nada registrado ainda.</p>
<?php else: ?>
<p class="hint">"Esta rede" marca o que veio da mesma conexão que você usa agora. Muitas senhas erradas de outra rede são tentativas de fora: o painel bloqueia sozinho, mas troque a senha se ela for fácil.</p>
<ul class="audit-list">
<?php foreach ($history as $row): $label = SecurityLog::LABELS[$row['event']] ?? $row['event']; $alert = in_array($row['event'], ['login_falha', 'login_bloqueado', 'login_desconhecido'], true); ?>
<li class="<?= $alert ? 'is-alert' : '' ?>"><span class="audit-when"><?= e(date('d/m/Y H:i', (int) strtotime((string) $row['created_at']))) ?></span> <b><?= e($label) ?></b><?php if ($row['detail'] !== ''): ?> <span class="muted"><?= e($row['detail']) ?></span><?php endif; ?> <span class="audit-net"><?= hash_equals($myIp, (string) $row['ip_hash']) ? 'esta rede' : 'rede ' . e(substr((string) $row['ip_hash'], 0, 6)) ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="card narrow-form out-card" aria-labelledby="h-sair">
<h2 class="sec" id="h-sair">Sair deste aparelho</h2>
<p class="hint">Sem uso por 2 horas, ou 12 horas depois de entrar, o painel pede a senha de novo.</p>
<form method="post" action="<?= e(base_url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="btn ghost wide" type="submit">Sair</button></form>
</section>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

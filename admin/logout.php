<?php
/**
 * Lynch Store - sair do painel (fase 5)
 * Destroi a sessao e volta para a tela de login.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(405);
    header('Allow: POST');
    exit('Use o botão Sair do painel.');
}
logout();

header('Location: ' . APP_BASE_URL . '/admin/login.php', true, 302);
exit;

<?php
/**
 * Lynch Store - mantém a sessão do painel viva enquanto um formulário longo
 * está aberto (chamado por assets/js/admin.js). Sem corpo: 204 se a sessão
 * vale, redirect do porteiro para o login se já caiu.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

require_login();

header('Cache-Control: no-store');
http_response_code(204);

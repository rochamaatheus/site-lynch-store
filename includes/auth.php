<?php
/**
 * Lynch Store - autenticacao do painel admin (fase 5)
 *
 * Sessao segura (as diretivas de hardening ja vieram de config.php), login com
 * password_verify + throttling em banco, logout, e tokens CSRF.
 *
 * Uso numa pagina do admin:
 *     require_once __DIR__ . '/../includes/auth.php';
 *     $admin = require_login();
 *
 * Regras de seguranca aplicadas aqui:
 *  - toda consulta usa prepared statement (PDO);
 *  - mensagem de erro de login e SEMPRE generica (nunca revela se o usuario existe);
 *  - session_regenerate_id(true) no login (corta session fixation);
 *  - throttling: 5 falhas -> 10 minutos de bloqueio (failed_attempts/locked_until);
 *  - CSRF comparado com hash_equals() (tempo constante), nunca com ===.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/RateLimit.php';

// ---------------------------------------------------------------------------
// Parametros do throttling
// ---------------------------------------------------------------------------
if (!defined('AUTH_MAX_ATTEMPTS')) {
    define('AUTH_MAX_ATTEMPTS', 5);      // falhas toleradas antes do bloqueio
}
if (!defined('AUTH_IP_MAX_ATTEMPTS')) {
    define('AUTH_IP_MAX_ATTEMPTS', 10);  // falhas do mesmo IP (qualquer usuario) por janela
}
if (!defined('AUTH_LOCK_SECONDS')) {
    define('AUTH_LOCK_SECONDS', 600);    // 10 minutos
}

/**
 * Hash descartavel usado so para gastar o mesmo tempo de CPU quando o usuario
 * NAO existe. Sem isso, um atacante mede o tempo de resposta e descobre quais
 * usernames existem (timing attack) mesmo com a mensagem sendo generica.
 * Corresponde a senha "lynch-store-dummy-password".
 */
if (!defined('AUTH_DUMMY_HASH')) {
    define('AUTH_DUMMY_HASH', '$2y$10$9/KKa3X.hIGr.MWyIiE9qepA0nppv6GZuR7YuDuFfhJoXZbi/uF2m');
}

// ---------------------------------------------------------------------------
// Sessao
// ---------------------------------------------------------------------------

/**
 * Garante que a sessao esta aberta, sem nunca chamar session_start() duas vezes.
 * As diretivas (httponly/samesite/strict_mode/secure) ja foram definidas em
 * config.php, que precisa ser carregado ANTES daqui - e e, no topo do arquivo.
 */
function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// ---------------------------------------------------------------------------
// Usuario logado
// ---------------------------------------------------------------------------

/**
 * Dados do admin logado, ou null se nao houver sessao valida.
 *
 * O resultado fica em cache estatico por requisicao (chaveado pelo id da
 * sessao), entao chamar current_admin() varias vezes na mesma pagina custa
 * uma unica consulta.
 *
 * Se o usuario tiver sido apagado ou desativado enquanto a sessao existia, a
 * sessao e invalidada na hora.
 *
 * @return array<string,mixed>|null
 */
function current_admin(): ?array
{
    /** @var array{id:int,row:array<string,mixed>|null}|null $cache */
    static $cache = null;

    ensure_session();

    $id = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
    if ($id <= 0) {
        return null;
    }

    if ($cache !== null && $cache['id'] === $id) {
        return $cache['row'];
    }

    $stmt = db()->prepare(
        'SELECT id, username, display_name, active, last_login_at, created_at, password_hash
           FROM admin_users
          WHERE id = ? AND active = 1
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!is_array($row) || !hash_equals((string) ($_SESSION['admin_fingerprint'] ?? ''), hash('sha256', (string) ($row['password_hash'] ?? ''))) || time() - (int) ($_SESSION['admin_last_seen'] ?? 0) > 7200) {
        // Usuario removido/desativado: a sessao nao vale mais nada.
        unset($_SESSION['admin_id']);
        $cache = ['id' => $id, 'row' => null];
        return null;
    }

    $_SESSION['admin_last_seen'] = time();
    unset($row['password_hash']);
    $cache = ['id' => $id, 'row' => $row];

    return $row;
}

/**
 * Atalho booleano (util em partials e menus).
 */
function is_logged_in(): bool
{
    return current_admin() !== null;
}

/**
 * Porteiro das paginas do admin. Chamar na PRIMEIRA linha util de todo
 * admin/*.php (menos login.php), antes de qualquer saida de HTML.
 *
 * @return array<string,mixed> dados do admin logado
 */
function require_login(): array
{
    ensure_session();

    $admin = current_admin();
    if ($admin !== null) {
        return $admin;
    }

    $target = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        // Um POST nao pode ser repetido por GET depois do login: volta para a
        // tela de onde o formulario saiu (mesmo host), nao para o manipulador.
        $target = '';
        $referer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if (is_array($referer) && ($referer['host'] ?? '') === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            $target = ($referer['path'] ?? '') . (isset($referer['query']) ? '?' . $referer['query'] : '');
        }
    }
    $url = APP_BASE_URL . '/admin/login.php?expirou=1';
    if ($target !== '') {
        $url .= '&redirect=' . urlencode($target);
    }

    header('Location: ' . $url, true, 302);
    exit;
}

// ---------------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------------

/**
 * Tenta autenticar. Nunca revela se o problema foi usuario ou senha.
 *
 * @return array{ok:bool,error:?string}
 */
function attempt_login(string $username, string $password): array
{
    ensure_session();

    // Uma unica mensagem para senha errada, usuario inexistente e conta em
    // bloqueio: quem erra nao descobre quais usuarios existem, e quem esta
    // bloqueado ainda entende que precisa esperar.
    $genericError = 'Usuário ou senha inválidos. Depois de várias tentativas seguidas o acesso fica bloqueado por alguns minutos.';

    $username = trim($username);

    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => $genericError];
    }

    // Limite por endereco de rede, somando todos os usuarios tentados: o bloqueio
    // por conta (abaixo) nao segura quem chuta varios logins diferentes.
    if (RateLimit::tooMany('login', AUTH_IP_MAX_ATTEMPTS, AUTH_LOCK_SECONDS)) {
        password_verify($password, AUTH_DUMMY_HASH);
        RateLimit::hit('login', AUTH_LOCK_SECONDS);
        return ['ok' => false, 'error' => $genericError];
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, active, failed_attempts, locked_until
           FROM admin_users
          WHERE username = ?
          LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Usuario inexistente ou desativado: gasta o mesmo tempo de um bcrypt real
    // e devolve exatamente a mesma mensagem de senha errada.
    if (!is_array($user) || (int) $user['active'] !== 1) {
        password_verify($password, AUTH_DUMMY_HASH);
        RateLimit::hit('login', AUTH_LOCK_SECONDS);
        return ['ok' => false, 'error' => $genericError];
    }

    $now = time();

    // Bloqueio ativo?
    if (!empty($user['locked_until'])) {
        $lockedUntil = strtotime((string) $user['locked_until']);
        if ($lockedUntil !== false && $lockedUntil > $now) {
            password_verify($password, AUTH_DUMMY_HASH);
            RateLimit::hit('login', AUTH_LOCK_SECONDS);
            return ['ok' => false, 'error' => $genericError];
        }

        // Bloqueio ja venceu: zera o contador antes de avaliar esta tentativa.
        $reset = $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
        $reset->execute([(int) $user['id']]);
        $user['failed_attempts'] = 0;
        $user['locked_until']    = null;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        auth_register_failure($pdo, (int) $user['id']);
        RateLimit::hit('login', AUTH_LOCK_SECONDS);
        return ['ok' => false, 'error' => $genericError];
    }

    // ---- Sucesso -----------------------------------------------------------
    RateLimit::clear('login');
    // ID novo para a sessao autenticada (session fixation) e token CSRF novo.
    session_regenerate_id(true);
    unset($_SESSION['csrf']);

    $_SESSION['admin_id']       = (int) $user['id'];
    $_SESSION['admin_login_at'] = $now;
    $_SESSION['admin_last_seen'] = $now;
    $_SESSION['admin_fingerprint'] = hash('sha256', (string) $user['password_hash']);

    $upd = $pdo->prepare(
        'UPDATE admin_users
            SET failed_attempts = 0,
                locked_until    = NULL,
                last_login_at   = ?
          WHERE id = ?'
    );
    $upd->execute([date('Y-m-d H:i:s', $now), (int) $user['id']]);

    // Se o custo/algoritmo padrao do PHP mudou, reforca o hash silenciosamente.
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehash->execute([$newHash, (int) $user['id']]);
        $_SESSION['admin_fingerprint'] = hash('sha256', $newHash);
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Contabiliza uma tentativa falha e aplica o bloqueio quando passar do limite.
 * Interna - nao chamar de fora de auth.php.
 */
function auth_register_failure(PDO $pdo, int $userId): void
{
    // Incremento feito no banco, em uma unica instrucao: tentativas simultaneas
    // nao se atropelam lendo o mesmo contador. locked_until vem antes de
    // failed_attempts porque o MySQL avalia as atribuicoes da esquerda para a
    // direita (aqui ainda ve o valor antigo, por isso o + 1).
    $stmt = $pdo->prepare(
        'UPDATE admin_users
            SET locked_until    = IF(failed_attempts + 1 > ?, ?, locked_until),
                failed_attempts = failed_attempts + 1
          WHERE id = ?'
    );
    $stmt->execute([AUTH_MAX_ATTEMPTS, date('Y-m-d H:i:s', time() + AUTH_LOCK_SECONDS), $userId]);
}

/**
 * Encerra a sessao: limpa os dados, expira o cookie e destroi a sessao.
 */
function logout(): void
{
    ensure_session();

    $_SESSION = [];

    // session_destroy() nao apaga o cookie do navegador; alguns navegadores
    // antigos ficariam mandando um ID morto pra sempre. Expira na marra.
    if (ini_get('session.use_cookies') && PHP_SAPI !== 'cli' && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => ($params['path'] !== '' ? $params['path'] : '/'),
            'domain'   => $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => ($params['samesite'] !== '' ? $params['samesite'] : 'Lax'),
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/**
 * Token CSRF da sessao (o mesmo durante toda a sessao; trocado no login).
 */
function csrf_token(): string
{
    ensure_session();

    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Campo hidden pronto para colar dentro de <form method="post">.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

/**
 * Confere o token recebido. Comparacao em tempo constante (hash_equals).
 */
function csrf_check(mixed $token): bool
{
    ensure_session();

    if (!is_string($token) || $token === '') {
        return false;
    }
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf'], $token);
}

// ---------------------------------------------------------------------------
// Redirect seguro (anti open redirect)
// ---------------------------------------------------------------------------

/**
 * Valida o ?redirect= do login. So aceita caminho LOCAL apontando para dentro
 * de /admin/. Bloqueia "https://evil.com", "//evil.com", "\\evil.com",
 * CR/LF (header injection) e qualquer caminho fora do admin.
 *
 * @return string|null caminho seguro (path + querystring) ou null
 */
function safe_admin_redirect(?string $target): ?string
{
    if (!is_string($target)) {
        return null;
    }

    $target = trim($target);
    if ($target === '' || strlen($target) > 512) {
        return null;
    }

    // Caracteres de controle (inclui \r e \n) invalidam de cara.
    if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
        return null;
    }

    // "\" vira "/" nos navegadores: normaliza antes de julgar.
    $target = str_replace('\\', '/', $target);

    if ($target[0] !== '/' || str_starts_with($target, '//')) {
        return null;   // relativo, protocol-relative ou absoluto com esquema
    }

    $parts = parse_url($target);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
        return null;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || str_contains($path, '..')) {
        return null;
    }

    // Prefixos aceitos: /admin/... e (se o site vive numa subpasta) /sub/admin/...
    $basePath = rtrim((string) (parse_url(APP_BASE_URL, PHP_URL_PATH) ?? ''), '/');
    $allowed  = ['/admin/'];
    if ($basePath !== '') {
        $allowed[] = $basePath . '/admin/';
    }

    foreach ($allowed as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return $target;
        }
    }

    return null;
}

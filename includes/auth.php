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
require_once __DIR__ . '/SecurityLog.php';

// ---------------------------------------------------------------------------
// Parametros do throttling
//
// Tres camadas, para que ninguem de fora consiga trancar a Ray do painel:
//  1. mesmo IP + mesma conta: 5 erros em 15 min bloqueiam SO aquele IP naquela
//     conta (o aparelho da Ray, em outra rede, continua entrando);
//  2. mesmo IP, qualquer conta: 10 erros em 15 min (robo que chuta usuarios);
//  3. a conta inteira, somando todos os IPs: 30 erros seguidos bloqueiam por 15
//     min apenas aparelhos desconhecidos. O aparelho onde a Ray ja entrou guarda
//     um cookie assinado (nao e login automatico) e passa por esse bloqueio.
// Cada erro ainda segura a resposta um pouco mais (ate 1,5 s).
// ---------------------------------------------------------------------------
if (!defined('AUTH_MAX_ATTEMPTS')) {
    define('AUTH_MAX_ATTEMPTS', 5);      // conta + IP
}
if (!defined('AUTH_IP_MAX_ATTEMPTS')) {
    define('AUTH_IP_MAX_ATTEMPTS', 10);  // mesmo IP, qualquer usuario
}
if (!defined('AUTH_GLOBAL_MAX_ATTEMPTS')) {
    define('AUTH_GLOBAL_MAX_ATTEMPTS', 30); // conta inteira (so aparelho desconhecido)
}
if (!defined('AUTH_LOCK_SECONDS')) {
    define('AUTH_LOCK_SECONDS', 900);    // 15 minutos
}
if (!defined('AUTH_IDLE_SECONDS')) {
    define('AUTH_IDLE_SECONDS', 7200);   // 2 h sem uso
}
if (!defined('AUTH_ABSOLUTE_SECONDS')) {
    define('AUTH_ABSOLUTE_SECONDS', 43200); // 12 h desde o login, mesmo em uso
}
if (!defined('AUTH_DEVICE_DAYS')) {
    define('AUTH_DEVICE_DAYS', 180);
}

// ---------------------------------------------------------------------------
// Senhas
// ---------------------------------------------------------------------------

/**
 * Algoritmo e custo explicitos. PASSWORD_DEFAULT no PHP 8.3 (o da Hostinger) e
 * bcrypt custo 10; aqui e Argon2id quando o PHP tem, senao bcrypt custo 12.
 *
 * @return array{0:string|int,1:array<string,int>}
 */
function lynch_password_algo(): array
{
    if (defined('PASSWORD_ARGON2ID')) {
        return [PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]];
    }
    return [PASSWORD_BCRYPT, ['cost' => 12]];
}

function lynch_password_hash(string $password): string
{
    [$algo, $options] = lynch_password_algo();
    return password_hash($password, $algo, $options);
}

function lynch_password_needs_rehash(string $hash): bool
{
    [$algo, $options] = lynch_password_algo();
    return password_needs_rehash($hash, $algo, $options);
}

/**
 * Hash descartavel do MESMO algoritmo e custo do hash real, usado quando o
 * usuario nao existe ou o acesso esta bloqueado: a resposta leva o mesmo tempo e
 * o tempo nao revela quais usuarios existem. Senha "lynch-store-dummy-password".
 */
function auth_dummy_hash(): string
{
    return defined('PASSWORD_ARGON2ID')
        ? '$argon2id$v=19$m=65536,t=4,p=1$UUtwUHprWE5ZUzFrVkZqMA$QJM+IFzSs2Z0e6ySfdAPqxMDN5fjZEpSrBlTYGhTCQg'
        : '$2y$12$LfZutqNka3JQKtHuhRyJLOGVhbatew4lpLcoQgAyd0lYkUbmQmMFO';
}

/**
 * Politica de senha do painel. null = aceita; senao a mensagem para a tela.
 * 12 a 72 bytes (limite do bcrypt), sem senha de exemplo, sem repeticao boba,
 * sem conter o proprio login.
 */
function password_policy_error(string $password, string $username = ''): ?string
{
    $length = strlen($password);
    if ($length < 12) {
        return 'Use uma senha com pelo menos 12 caracteres. Uma frase longa é uma boa escolha.';
    }
    if ($length > 72) {
        return 'A senha pode ter no máximo 72 caracteres.';
    }
    $lower = mb_strtolower($password, 'UTF-8');
    $plain = (string) preg_replace('/[^a-z0-9]/', '', $lower);
    $banned = [
        'troqueestasenha', 'trocarestasenha', 'lynchstoredummypassword', 'lynchstore', 'lynchstore123',
        'senhadopainel', 'senha123456', 'senhasenha', 'admin123456', 'administrador', 'password123',
        'qwerty123456', '123456789012', '1234567890123', 'abcdefghijkl', 'girassolazulnaarara',
    ];
    foreach ($banned as $bad) {
        if ($plain === $bad || ($bad !== '' && strlen($bad) >= 10 && str_starts_with($plain, $bad) && strlen($plain) - strlen($bad) <= 3)) {
            return 'Essa senha é conhecida demais (aparece em exemplos e listas de senhas vazadas). Escolha outra.';
        }
    }
    if (preg_match('/^(.)\1+$/u', $password) === 1 || count(array_unique(mb_str_split($password))) < 5) {
        return 'A senha repete poucos caracteres. Misture palavras, como uma frase.';
    }
    if (preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop)/', $plain) === 1) {
        return 'Sequência de teclado não serve como senha. Use uma frase.';
    }
    $user = mb_strtolower(trim($username), 'UTF-8');
    if ($user !== '' && mb_strlen($user) >= 3 && str_contains($lower, $user)) {
        return 'A senha não pode conter o seu login.';
    }
    return null;
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
        // Marca o nascimento da sessao (finalizar.php recusa pedido de sessao com
        // poucos segundos de vida: robo que abre e ja envia).
        if (!isset($_SESSION['_born']) || !is_int($_SESSION['_born'])) {
            $_SESSION['_born'] = time();
        }
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

    $now = time();
    $expired = $now - (int) ($_SESSION['admin_last_seen'] ?? 0) > AUTH_IDLE_SECONDS
        || $now - (int) ($_SESSION['admin_login_at'] ?? 0) > AUTH_ABSOLUTE_SECONDS;
    // A impressao digital e o hash da senha: trocar a senha (ou "Encerrar as
    // outras sessoes", que regrava o hash) derruba todas as outras sessoes.
    if (!is_array($row) || !hash_equals((string) ($_SESSION['admin_fingerprint'] ?? ''), hash('sha256', (string) ($row['password_hash'] ?? ''))) || $expired) {
        // Usuario removido/desativado, senha trocada ou tempo esgotado.
        if ($expired && is_array($row)) {
            SecurityLog::event('sessao_expirada', ['admin' => $id]);
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_fingerprint'], $_SESSION['admin_login_at'], $_SESSION['admin_last_seen']);
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

    if ($username === '' || $password === '' || strlen($username) > 50 || strlen($password) > 4096) {
        return ['ok' => false, 'error' => $genericError];
    }

    // Balde conta + IP: chave com o login normalizado (o mesmo para usuario que
    // existe e que nao existe, entao o limite nao revela nada).
    $accountClient = RateLimit::clientId() . '|' . hash('sha256', mb_strtolower($username, 'UTF-8'));
    $fail = static function (?int $userId, string $event) use ($password, $accountClient, $genericError): array {
        RateLimit::hit('login', AUTH_LOCK_SECONDS);
        RateLimit::hit('login-conta', AUTH_LOCK_SECONDS, $accountClient);
        SecurityLog::audit($userId, $event, $userId === null ? 'usuário digitado não existe' : '');
        // Atraso progressivo pelos erros deste IP nesta conta (ate 1,5 s).
        if (PHP_SAPI !== 'cli') {
            $n = RateLimit::count('login-conta', AUTH_LOCK_SECONDS, $accountClient);
            usleep(min(1500000, 250000 * max(0, $n - 1)));
        }
        unset($password);
        return ['ok' => false, 'error' => $genericError];
    };

    // Limites por endereco de rede: qualquer usuario (robo chutando logins) e
    // esta conta neste IP. Nenhum dos dois tranca a conta para outros IPs.
    if (RateLimit::tooMany('login', AUTH_IP_MAX_ATTEMPTS, AUTH_LOCK_SECONDS)
        || RateLimit::tooMany('login-conta', AUTH_MAX_ATTEMPTS, AUTH_LOCK_SECONDS, $accountClient)) {
        password_verify($password, auth_dummy_hash());
        return $fail(null, 'login_bloqueado');
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

    // Usuario inexistente ou desativado: gasta o mesmo tempo de um hash real
    // e devolve exatamente a mesma mensagem de senha errada.
    if (!is_array($user) || (int) $user['active'] !== 1) {
        password_verify($password, auth_dummy_hash());
        return $fail(null, 'login_desconhecido');
    }

    $now = time();
    $userId = (int) $user['id'];

    // Bloqueio global da conta (muitos erros somando todos os IPs): vale so para
    // aparelho desconhecido. O aparelho onde a Ray ja entrou segue podendo entrar.
    if (!empty($user['locked_until'])) {
        $lockedUntil = strtotime((string) $user['locked_until']);
        if ($lockedUntil !== false && $lockedUntil > $now && !auth_trusted_device($user)) {
            password_verify($password, auth_dummy_hash());
            return $fail($userId, 'login_bloqueado');
        }
        if ($lockedUntil === false || $lockedUntil <= $now) {
            // Bloqueio ja venceu: zera o contador antes de avaliar esta tentativa.
            $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$userId]);
        }
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        auth_register_failure($pdo, $userId);
        return $fail($userId, 'login_falha');
    }

    // ---- Sucesso -----------------------------------------------------------
    RateLimit::clear('login');
    RateLimit::clear('login-conta', $accountClient);
    // ID novo para a sessao autenticada (session fixation) e token CSRF novo.
    session_regenerate_id(true);
    unset($_SESSION['csrf']);

    $hash = (string) $user['password_hash'];
    // Se o algoritmo/custo mudou (ex.: bcrypt 10 -> Argon2id), reforca o hash agora.
    if (lynch_password_needs_rehash($hash)) {
        $hash = lynch_password_hash($password);
        $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    }

    $_SESSION['admin_id']          = $userId;
    $_SESSION['admin_login_at']    = $now;
    $_SESSION['admin_last_seen']   = $now;
    $_SESSION['admin_fingerprint'] = hash('sha256', $hash);

    $pdo->prepare(
        'UPDATE admin_users
            SET failed_attempts = 0,
                locked_until    = NULL,
                last_login_at   = ?
          WHERE id = ?'
    )->execute([date('Y-m-d H:i:s', $now), $userId]);

    auth_issue_device_cookie($userId, $hash);
    SecurityLog::audit($userId, 'login_ok');

    return ['ok' => true, 'error' => null];
}

/**
 * Contabiliza uma tentativa falha na conta (somando todos os IPs) e aplica o
 * bloqueio global quando passar do limite. Interna - nao chamar de fora de auth.php.
 */
function auth_register_failure(PDO $pdo, int $userId): void
{
    // Incremento feito no banco, em uma unica instrucao: tentativas simultaneas
    // nao se atropelam lendo o mesmo contador. locked_until vem antes de
    // failed_attempts porque o MySQL avalia as atribuicoes da esquerda para a
    // direita (aqui ainda ve o valor antigo, por isso o + 1).
    $stmt = $pdo->prepare(
        'UPDATE admin_users
            SET locked_until    = IF(failed_attempts + 1 >= ?, ?, locked_until),
                failed_attempts = LEAST(failed_attempts + 1, 65535)
          WHERE id = ?'
    );
    $stmt->execute([AUTH_GLOBAL_MAX_ATTEMPTS, date('Y-m-d H:i:s', time() + AUTH_LOCK_SECONDS), $userId]);
}

// ---------------------------------------------------------------------------
// Aparelho conhecido (cookie assinado; NAO e "lembrar senha" nem login automatico)
//
// Depois de um login certo, o navegador guarda um cookie HttpOnly, Secure,
// SameSite=Strict com id do usuario + validade + numero aleatorio, assinado com
// HMAC cuja chave deriva do hash da senha (segredo que so existe no banco).
// Serve para uma unica coisa: deixar a Ray entrar durante o bloqueio global da
// conta, que um atacante consegue provocar de varios IPs. A senha continua sendo
// pedida. Trocar a senha (ou encerrar as sessoes) invalida todos esses cookies.
// ---------------------------------------------------------------------------

function auth_device_cookie_name(): string
{
    return (lynch_is_https() ? '__Host-' : '') . 'lynch_aparelho';
}

function auth_device_signature(int $userId, int $expires, string $nonce, string $passwordHash): string
{
    $key = hash('sha256', 'lynch-aparelho|' . $passwordHash . '|' . DB_NAME, true);
    return hash_hmac('sha256', $userId . '|' . $expires . '|' . $nonce, $key);
}

function auth_issue_device_cookie(int $userId, string $passwordHash): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $expires = time() + AUTH_DEVICE_DAYS * 86400;
    $nonce = bin2hex(random_bytes(16));
    $value = $userId . '.' . $expires . '.' . $nonce . '.' . auth_device_signature($userId, $expires, $nonce, $passwordHash);
    setcookie(auth_device_cookie_name(), $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => lynch_is_https() || str_starts_with(APP_BASE_URL, 'https://'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** @param array<string,mixed> $user linha de admin_users com id e password_hash */
function auth_trusted_device(array $user): bool
{
    $raw = $_COOKIE[auth_device_cookie_name()] ?? '';
    if (!is_string($raw) || strlen($raw) > 200) {
        return false;
    }
    $parts = explode('.', $raw);
    if (count($parts) !== 4 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_xdigit($parts[2]) || !ctype_xdigit($parts[3])) {
        return false;
    }
    [$uid, $expires, $nonce, $sig] = $parts;
    if ((int) $uid !== (int) $user['id'] || (int) $expires < time()) {
        return false;
    }
    return hash_equals(auth_device_signature((int) $uid, (int) $expires, $nonce, (string) $user['password_hash']), $sig);
}

/**
 * "Encerrar as sessoes dos outros aparelhos": confere a senha atual e regrava o
 * hash com sal novo. A impressao digital das outras sessoes deixa de bater (elas
 * caem no proximo clique) e os cookies de aparelho conhecido perdem a assinatura.
 * Esta sessao e este aparelho continuam valendo.
 */
function admin_revoke_other_sessions(int $userId, string $currentPassword): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM admin_users WHERE id = ? AND active = 1');
    $stmt->execute([$userId]);
    $hash = (string) $stmt->fetchColumn();
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        return false;
    }
    $new = lynch_password_hash($currentPassword);
    db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([$new, $userId]);
    $_SESSION['admin_fingerprint'] = hash('sha256', $new);
    session_regenerate_id(true);
    auth_issue_device_cookie($userId, $new);
    SecurityLog::audit($userId, 'sessoes_encerradas');
    return true;
}

/**
 * Troca de senha (Minha conta). Devolve null em sucesso ou a mensagem de erro.
 */
function admin_change_password(int $userId, string $username, string $current, string $new, string $confirm): ?string
{
    $stmt = db()->prepare('SELECT password_hash FROM admin_users WHERE id = ? AND active = 1');
    $stmt->execute([$userId]);
    $hash = (string) $stmt->fetchColumn();
    if ($hash === '' || !password_verify($current, $hash)) {
        SecurityLog::audit($userId, 'login_falha', 'senha atual errada ao trocar a senha');
        return 'A senha atual não confere.';
    }
    if (($policy = password_policy_error($new, $username)) !== null) {
        return $policy;
    }
    if (!hash_equals($new, $confirm)) {
        return 'A confirmação precisa ser igual à nova senha.';
    }
    if (password_verify($new, $hash)) {
        return 'A nova senha precisa ser diferente da atual.';
    }
    $newHash = lynch_password_hash($new);
    db()->prepare('UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$newHash, $userId]);
    $_SESSION['admin_fingerprint'] = hash('sha256', $newHash);
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    auth_issue_device_cookie($userId, $newHash);
    SecurityLog::audit($userId, 'senha_trocada');
    return null;
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
 * Defesa em profundidade, alem do token: o navegador diz de onde veio o envio.
 * Sec-Fetch-Site "cross-site" ou um Origin de outro endereco sao recusados.
 * Sem esses cabecalhos (navegador antigo, ferramenta) vale so o token.
 */
function request_same_origin(): bool
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'cross-site') {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        return true;
    }
    $allowed = [];
    if (APP_BASE_URL !== '') {
        $p = parse_url(APP_BASE_URL);
        if (is_array($p) && isset($p['scheme'], $p['host'])) {
            $allowed[] = strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
        }
    }
    // O proprio endereco pedido tambem vale (www e sem www): o navegador da vitima
    // manda o Host verdadeiro, entao isso nao abre brecha para outro site.
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && preg_match('/^[a-z0-9.\-]+(?::\d{1,5})?$/D', $host) === 1) {
        $allowed[] = (lynch_is_https() ? 'https://' : 'http://') . $host;
    }
    return in_array(strtolower(rtrim($origin, '/')), $allowed, true);
}

/**
 * Confere o token recebido. Comparacao em tempo constante (hash_equals).
 */
function csrf_check(mixed $token): bool
{
    ensure_session();

    $ok = is_string($token) && $token !== ''
        && !empty($_SESSION['csrf']) && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token)
        && (PHP_SAPI === 'cli' || request_same_origin());
    if (!$ok && PHP_SAPI !== 'cli') {
        SecurityLog::event('csrf_invalido', ['origem' => substr((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 80), 'site' => (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')]);
    }
    return $ok;
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

<?php
/**
 * Lynch Store - configuracao central (fase 1: Fundacao)
 *
 * Carrega o .env da raiz do projeto com um parser manual (sem Composer, sem
 * biblioteca de dotenv - a hospedagem alvo e cPanel compartilhado, sem shell),
 * define as constantes da aplicacao e prepara o hardening de sessao.
 *
 * IMPORTANTE: este arquivo NAO chama session_start(). Ele apenas configura as
 * diretivas de sessao (que precisam ser definidas ANTES do start). Quem abre a
 * sessao e includes/auth.php (fase 5).
 *
 * Uso: require_once __DIR__ . '/config.php';
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Caminhos base
// ---------------------------------------------------------------------------
if (!defined('APP_ROOT')) {
    // includes/ fica um nivel abaixo da raiz do projeto (docroot).
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
}

// ---------------------------------------------------------------------------
// Parser manual do .env
// ---------------------------------------------------------------------------

/**
 * Le um arquivo .env linha a linha e popula $_ENV / getenv().
 * Suporta: comentarios (# e ;), linhas em branco, prefixo "export ",
 * valores entre aspas simples ou duplas, e valores vazios.
 * Nao suporta (de proposito): interpolacao de variaveis e valores multilinha.
 */
function lynch_load_env(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Comentario ou linha vazia
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        // Precisa ter um "=" para ser um par chave/valor
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Aceita "export CHAVE=valor"
        if (stripos($key, 'export ') === 0) {
            $key = trim(substr($key, 7));
        }

        // Nome de variavel valido apenas
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        // Remove aspas envolventes (e so entao considera comentario inline)
        $len = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"') ||
            ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                // Em aspas duplas, permite escapes basicos
                $value = preg_replace_callback('/\\\\([nrt"\\\\])/', static fn($m) => ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'][$m[1]], $value);
            }
        } else {
            // Sem aspas: um " #" corta comentario inline.
            // (Um "#" colado no valor, como numa senha, e preservado.)
            $hash = strpos($value, ' #');
            if ($hash !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
        }

        $_ENV[$key]    = $value;
        $_SERVER[$key] = $_SERVER[$key] ?? $value;
        putenv($key . '=' . $value);
    }

    return true;
}

/**
 * Le uma variavel de ambiente ja carregada, com valor padrao.
 */
function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ---------------------------------------------------------------------------
// Carga do .env (falha com mensagem clara, nao com stack trace feio)
// ---------------------------------------------------------------------------
$lynchEnvPath = getenv('LYNCH_ENV_FILE') ?: APP_ROOT . DIRECTORY_SEPARATOR . '.env';

if (!lynch_load_env($lynchEnvPath)) {
    $msg = "Arquivo de configuracao .env nao encontrado (ou sem permissao de leitura) em:\n"
         . "  {$lynchEnvPath}\n\n"
         . "Como resolver: copie o modelo e preencha os valores do seu ambiente.\n"
         . "  Windows : copy .env.example .env\n"
         . "  Linux   : cp .env.example .env\n";

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\n[Lynch Store] " . $msg . "\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Loja em configuração</title><h1>Loja em configuração</h1><p>O responsável precisa concluir a instalação.</p><p><a href="instalar.php">Abrir instalador</a></p></html>';
    exit(1);
}
unset($lynchEnvPath);

// ---------------------------------------------------------------------------
// Constantes da aplicacao
// ---------------------------------------------------------------------------
define('APP_ENV',      env('APP_ENV', 'production'));
define('APP_BASE_URL', rtrim((string) env('APP_BASE_URL', ''), '/'));
define('APP_DEBUG',    APP_ENV === 'local');

define('DB_HOST',    env('DB_HOST', '127.0.0.1'));
define('DB_NAME',    env('DB_NAME', 'lynchstore'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    (string) env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', (int) env('DB_PORT', '3306'));

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}

// Painel (/admin/) usa uma sessao propria, SameSite=Strict; a loja usa outra, Lax
// (o link do WhatsApp/Instagram precisa chegar com a sacola). Login, CSRF e
// cookie de um lado nunca valem do outro.
if (!defined('LYNCH_ADMIN_AREA')) {
    define('LYNCH_ADMIN_AREA', PHP_SAPI !== 'cli'
        && preg_match('~/admin/[^/]+\.php$~', str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))) === 1);
}

/** A requisicao chegou por HTTPS (inclusive atras do proxy da hospedagem)? */
function lynch_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Pasta privada para logs (fora do alcance do navegador). Ordem: LYNCH_PRIVATE_DIR
 * do .env; em producao, a pasta ACIMA do public_html (lynch-privado); por ultimo a
 * pasta temporaria do PHP. Nunca aceita uma pasta dentro do site. null = sem
 * pasta gravavel (o log so deixa de ser gravado; a loja segue).
 */
function lynch_private_dir(): ?string
{
    static $dir = false;
    if ($dir !== false) {
        return $dir;
    }
    $candidates = [];
    $fromEnv = trim((string) env('LYNCH_PRIVATE_DIR', ''));
    if ($fromEnv !== '') {
        $candidates[] = $fromEnv;
    }
    if (APP_ENV === 'production') {
        $candidates[] = dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'lynch-privado';
    }
    $candidates[] = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'lynch-privado-' . substr(hash('sha256', APP_ROOT . '|' . DB_NAME), 0, 12);
    $root = rtrim(str_replace('\\', '/', (string) (realpath(APP_ROOT) ?: APP_ROOT)), '/') . '/';
    foreach ($candidates as $candidate) {
        if (!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate)) {
            continue;
        }
        $real = rtrim(str_replace('\\', '/', (string) realpath($candidate)), '/') . '/';
        if ($real === '/' || str_starts_with($real, $root) || !is_writable($candidate)) {
            continue;
        }
        return $dir = rtrim((string) realpath($candidate), '/\\');
    }
    return $dir = null;
}

if (PHP_SAPI !== 'cli') {
    // So GET, HEAD e POST: TRACE, PUT, DELETE, OPTIONS e afins nao servem a loja.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        exit('Método não permitido.');
    }
    unset($method);
    foreach ($_GET as $value) {
        if (is_array($value)) { http_response_code(400); exit('Parâmetro inválido.'); }
    }
    foreach ($_POST as $key => $value) {
        if (is_array($value) && ($key !== 'ordem' || array_filter($value, 'is_array'))) {
            http_response_code(400); exit('Formulário inválido.');
        }
    }
    // Versao do PHP nao e da conta de ninguem (expose_php so muda no php.ini).
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    // Nenhuma pagina da loja ou do painel e feita para abrir dentro de iframe
    // (clique-jacking): X-Frame-Options para navegador antigo, frame-ancestors na CSP.
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    // Sem 'unsafe-inline' em script-src nem em style-src: todo JavaScript vive em
    // assets/js e todo CSS em assets/css (o JS so mexe em estilo pelo CSSOM, que a
    // CSP permite), entao um texto malicioso salvo no banco nao executa script nem
    // injeta estilo na pagina. Unica excecao: o hash do <style> de uma linha com
    // @view-transition (header.php), que precisa estar no HTML para a transicao
    // entre paginas valer antes do CSS externo carregar. Se mudar o texto, recalcule
    // o hash (sha256 em base64).
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'sha256-SREix9zPMZHrSuo8zRSjb672r1gsHIh96MJuaZq6iJo='; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://wa.me https://api.whatsapp.com");
    header('Cache-Control: private, no-store');
    // HSTS tambem atras do proxy/CDN da hospedagem, que termina o TLS e avisa por
    // X-Forwarded-Proto (o .htaccess ja redireciona http para https fora do localhost).
    // includeSubDomains: todo subdominio precisa ter HTTPS. Sem "preload" de
    // proposito (entrar na lista dos navegadores e dificil de desfazer).
    if (lynch_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Rastro de excecao no log sem os argumentos das funcoes (attempt_login recebe a
// senha; um erro de banco no meio do login nao pode grava-la no error_log).
ini_set('zend.exception_ignore_args', '1');

set_exception_handler(static function (Throwable $error): void {
    error_log('[Lynch Store] ' . $error);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
    // Descarta qualquer saida parcial (ex.: sitemap em montagem) antes da pagina de erro.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Loja temporariamente indisponível</title><h1>Voltamos em instantes</h1><p>Não foi possível carregar a loja. Tente novamente em alguns minutos.</p></html>';
});

// ---------------------------------------------------------------------------
// Relatorio de erros: verboso so no ambiente local
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('log_errors', '1');
// Em producao o log do PHP vai para a pasta privada (fora do public_html), nunca
// para um error_log solto dentro do site.
if (APP_ENV === 'production' && ($privateDir = lynch_private_dir()) !== null) {
    ini_set('error_log', $privateDir . DIRECTORY_SEPARATOR . 'php-erros.log');
}
unset($privateDir);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// Fuso padrao do projeto (evita warnings e datas erradas no admin)
date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

// ---------------------------------------------------------------------------
// Hardening de sessao - precisa vir ANTES de qualquer session_start().
// Este arquivo NAO inicia a sessao: quem faz isso e includes/auth.php (fase 5).
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $requestHttps = lynch_is_https();
    $isHttps = $requestHttps || str_starts_with(APP_BASE_URL, 'https://');

    ini_set('session.cookie_httponly', '1');   // JS nao le o cookie de sessao
    ini_set('session.gc_maxlifetime', '7200');
    // Limpeza de sessoes velhas mesmo se a hospedagem desligou o GC.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    // Painel: Strict (nenhum site de fora leva o cookie do admin, nem por link).
    // Loja: Lax (quem chega pelo link do Instagram encontra a sacola).
    ini_set('session.cookie_samesite', LYNCH_ADMIN_AREA ? 'Strict' : 'Lax');
    ini_set('session.use_strict_mode', '1');   // rejeita ID de sessao nao gerado pelo servidor
    ini_set('session.use_only_cookies', '1');  // nunca aceita ID pela URL
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    ini_set('session.cookie_lifetime', '0');   // some ao fechar o navegador
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    // Prefixo __Host- em HTTPS: o navegador so aceita o cookie com Secure, Path=/
    // e sem Domain, entao um subdominio nao consegue plantar nem ler o cookie.
    $sessionBase = LYNCH_ADMIN_AREA ? 'LYNCHADM' : 'LYNCHSESSID';
    ini_set('session.name', ($requestHttps ? '__Host-' : '') . $sessionBase);

    unset($isHttps, $requestHttps, $sessionBase);
}

// Limite leve por IP nas paginas publicas (o painel fica de fora: a Ray nao pode
// ser barrada no proprio painel).
if (PHP_SAPI !== 'cli' && !LYNCH_ADMIN_AREA) {
    require_once __DIR__ . '/RateLimit.php';
    RateLimit::guardPage();
}

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

if (PHP_SAPI !== 'cli') {
    foreach ($_GET as $value) {
        if (is_array($value)) { http_response_code(400); exit('Parâmetro inválido.'); }
    }
    foreach ($_POST as $key => $value) {
        if (is_array($value) && ($key !== 'ordem' || array_filter($value, 'is_array'))) {
            http_response_code(400); exit('Formulário inválido.');
        }
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Sem 'unsafe-inline' em script-src: todo JavaScript vive em assets/js, entao
    // um texto malicioso salvo no banco nao consegue executar script na pagina.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: private, no-store');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

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
    // Detecta HTTPS (inclusive atras de proxy/CDN da hospedagem)
    $isHttps =
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || str_starts_with(APP_BASE_URL, 'https://');

    ini_set('session.cookie_httponly', '1');   // JS nao le o cookie de sessao
    ini_set('session.gc_maxlifetime', '7200');
    ini_set('session.cookie_samesite', 'Lax'); // corta CSRF cross-site basico
    ini_set('session.use_strict_mode', '1');   // rejeita ID de sessao nao gerado pelo servidor
    ini_set('session.use_only_cookies', '1');  // nunca aceita ID pela URL
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.name', 'LYNCHSESSID');

    unset($isHttps);
}

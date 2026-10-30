<?php
/**
 * Lynch Store - helpers puros da vitrine publica (fase 3: Catalogo publico)
 *
 * Nada aqui toca o banco: sao so funcoes de formatacao, URL e mensagens flash.
 * Consultas ficam nos repositorios (ProductRepository, CategoryRepository,
 * SettingsRepository).
 *
 * Uso: require_once __DIR__ . '/functions.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Escape de saida
// ---------------------------------------------------------------------------

/**
 * Atalho para htmlspecialchars(). Usado em TODO lugar que imprime dado vindo
 * do banco - desde a fase 6 os produtos sao cadastrados pela Ray no admin,
 * entao nenhum texto do catalogo pode ser tratado como literal confiavel.
 *
 * Aceita int/float/null alem de string porque colunas como price_cents,
 * sort_order e description chegam do PDO como int ou NULL.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Preco
// ---------------------------------------------------------------------------

/**
 * Formata um preco guardado em CENTAVOS.
 *
 * Regras (iguais as do schema): price_on_request=1 -> "Consultar valor";
 * price_cents NULL tambem cai em "Consultar valor" (nunca imprime "R$ 0,00"
 * por engano quando o admin esquecer de preencher).
 */
function format_price(?int $cents, bool $onRequest = false): string
{
    if ($onRequest || $cents === null) {
        return 'Consultar valor';
    }

    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------

/**
 * Monta uma URL do site a partir de um caminho relativo a raiz.
 *
 * Se APP_BASE_URL estiver definida no .env, gera URL absoluta (bom para o link
 * dos produtos dentro da mensagem de WhatsApp, na fase 4). Se estiver vazia,
 * cai numa URL relativa a raiz ("/produto.php?slug=x"), que continua correta.
 */
function base_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';

    if ($base === '') {
        return '/' . $path;
    }

    return $path === '' ? $base . '/' : $base . '/' . $path;
}

/**
 * URL de um arquivo estatico (css, js, imagens de assets/ e de uploads/).
 */
function asset_url(string $path): string
{
    $url = base_url($path);
    // Versiona CSS/JS e as imagens fixas do tema (assets/): trocar a logo ou o
    // hero apos a publicacao nao pode esperar o cache de 1 mes do navegador.
    // Uploads tem nome aleatorio e nunca mudam, entao nao precisam disso.
    if (preg_match('/\.(css|js)$/', $path) || (str_starts_with($path, 'assets/') && preg_match('/\.(webp|png|jpe?g)$/', $path))) {
        if (!is_file(APP_ROOT . '/' . $path)) {
            return $url;
        }
        $url .= '?v=' . filemtime(APP_ROOT . '/' . $path);
    }
    return $url;
}

/**
 * URL da pagina de um produto.
 *
 * Fase 3 usa query string (produto.php?slug=...). As regras de URL bonita
 * (/produto/slug) ficam comentadas no .htaccess da raiz - se forem ativadas,
 * basta trocar o retorno desta funcao e todo o site acompanha.
 */
function product_url(string $slug): string
{
    return base_url('produto.php') . '?slug=' . rawurlencode($slug);
}

/**
 * URL do catalogo com filtros. Parametros com valor null/'' sao omitidos,
 * entao url_catalog(['categoria' => null]) devolve a home limpa.
 *
 * @param array<string,string|int|null> $params
 */
function url_catalog(array $params = [], string $fragment = ''): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === 0) {
            continue;
        }
        $clean[$key] = (string) $value;
    }

    $url = base_url('loja.php');
    if ($clean !== []) {
        $url .= '?' . http_build_query($clean);
    }
    if ($fragment !== '') {
        $url .= '#' . $fragment;
    }

    return $url;
}

/**
 * Caminho da imagem de um produto, com fallback para o placeholder do site
 * quando o produto ainda nao tem foto cadastrada.
 */
function product_image_url(?string $filePath): string
{
    $filePath = trim((string) $filePath);
    if ($filePath === '') {
        return asset_url('assets/lynch-logo.webp');
    }

    $url = asset_url($filePath);

    // Cache-busting por data de modificacao: o pipeline de imagem (ImageProcessor)
    // reescreve o MESMO nome de arquivo quando a Ray troca o recorte (foco
    // topo/centro/base) ou substitui uma foto. Sem isso, quem ja visitou a pagina
    // continua vendo a versao antiga em cache do navegador indefinidamente, mesmo
    // com o arquivo certo no servidor - foi exatamente o que aconteceu no reprocessamento
    // do catalogo inteiro.
    $abs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($filePath, '/'));
    $mtime = @filemtime($abs);
    if ($mtime !== false) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $mtime;
    }

    return $url;
}

/**
 * Insere um sufixo antes da extensao de um caminho de imagem
 * ('uploads/x/ab.webp' + 'thumb' -> 'uploads/x/ab-thumb.webp').
 *
 * O pipeline de imagem (includes/ImageProcessor.php) grava os 3 derivados com o
 * MESMO nome base do arquivo principal, variando so o sufixo - por isso o banco
 * guarda um caminho so. Se o derivado nao existir em disco (foto legada, subida
 * antes do pipeline), cai no arquivo principal em vez de dar 404.
 */
function product_image_variant_url(?string $filePath, string $suffix): string
{
    $filePath = trim((string) $filePath);
    if ($filePath === '' || $suffix === '') {
        return product_image_url($filePath);
    }

    $dot = strrpos($filePath, '.');
    if ($dot === false) {
        return product_image_url($filePath);
    }

    $variant = substr($filePath, 0, $dot) . '-' . $suffix . substr($filePath, $dot);

    $abs = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($variant, '/'));

    return product_image_url(is_file($abs) ? $variant : $filePath);
}

/** Miniatura 240x300 (galeria da pagina de produto). */
function product_image_thumb_url(?string $filePath): string
{
    return product_image_variant_url($filePath, 'thumb');
}

/** Foto de card para celular 480x600. */
function product_image_small_url(?string $filePath): string
{
    return product_image_variant_url($filePath, 'small');
}

/** Imagem grande 1080x1350 (foto principal da galeria, com zoom). */
function product_image_detail_url(?string $filePath): string
{
    return product_image_variant_url($filePath, 'detail');
}

/**
 * Valida um destino de redirect vindo do cliente (campo redirect_to dos forms
 * do carrinho). So aceita caminho LOCAL - qualquer coisa com host, esquema,
 * "//", ".." ou caractere de controle e recusada (open redirect / header
 * injection).
 *
 * E a mesma logica de includes/auth.php::safe_admin_redirect(), sem a exigencia
 * do prefixo /admin/: aqui os destinos legitimos sao paginas publicas
 * (index.php, produto.php, carrinho.php). Duplicar as ~20 linhas foi preferido
 * a parametrizar a funcao do admin, que e codigo de autenticacao ja verificado
 * e nao deve ser mexido pela fase 4.
 *
 * @return string|null caminho seguro (path + query) ou null
 */
function safe_local_redirect(?string $target): ?string
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

    return $target;
}

// ---------------------------------------------------------------------------
// Texto
// ---------------------------------------------------------------------------

/**
 * Rotulo legivel para a coluna availability (ENUM do banco).
 */
function availability_label(?string $availability): string
{
    return $availability === 'pronta_entrega' ? 'Pronta entrega' : 'Sob encomenda';
}

/**
 * Transforma um texto em slug ("Peca de Cosplay 01" -> "peca-de-cosplay-01").
 *
 * Espelha a funcao slugify() em JS de admin/produto-form.php: o campo slug e
 * preenchido no navegador enquanto a Ray digita o nome, mas o servidor NUNCA
 * confia nisso - ele reprocessa o que chegou. Se as duas divergirem, o valor
 * que vale e o do servidor.
 *
 * Acentos sao transliterados (Coracao -> coracao) por uma tabela propria: iconv
 * com //TRANSLIT depende do locale do sistema e devolve "?" em varias
 * hospedagens compartilhadas.
 */
function slugify(?string $text, int $maxLength = 200): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = mb_strtolower($text, 'UTF-8');

    $map = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y',
        'ª'=>'a','º'=>'o','&'=>'e',
    ];
    $text = strtr($text, $map);

    // Tudo que sobrou fora de [a-z0-9] vira separador.
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
    $text = trim($text, '-');

    if ($text === '') {
        return '';
    }

    if (strlen($text) > $maxLength) {
        $text = rtrim(substr($text, 0, $maxLength), '-');
    }

    return $text;
}

/**
 * Converte o preco digitado em reais para CENTAVOS (int), ou null.
 *
 * Aceita as formas que a Ray pode digitar: "189,90", "189.90", "R$ 1.189,90",
 * "1189", "" (vazio -> null). Nunca usa (float) direto: "1.189,90" viraria
 * 1.189 e o produto seria salvo por um real e dezoito.
 */
function price_to_cents(?string $input): ?int
{
    $raw = trim((string) $input);
    if ($raw === '') {
        return null;
    }

    $raw = preg_replace('/^R\$\s*/u', '', $raw) ?? '';
    $raw = str_replace([" ", "\u{00A0}"], '', $raw);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/D', $raw)) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d{1,2}$/D', $raw)) {
        $raw = str_replace(',', '', $raw);
    } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $raw)) {
        $raw = str_replace('.', '', $raw);
    } elseif (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    if (strlen(ltrim($whole, '0')) > 6) { return null; }
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

/**
 * Centavos -> texto para o value de um <input> de preco ("18990" -> "189,90").
 * Devolve '' quando nao ha preco (nunca "0,00").
 */
function cents_to_input(?int $cents): string
{
    if ($cents === null) {
        return '';
    }

    return number_format($cents / 100, 2, ',', '');
}

/**
 * Corta um texto em N caracteres sem quebrar palavra no meio.
 */
function excerpt(?string $text, int $limit = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    if ($text === '' || mb_strlen($text) <= $limit) {
        return $text;
    }

    $cut = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > 40) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }

    return rtrim($cut, " ,.;:-") . '...';
}

// ---------------------------------------------------------------------------
// Mensagens flash (sessao)
// ---------------------------------------------------------------------------

/**
 * Abre a sessao sem colidir com o ensure_session() do includes/auth.php.
 * Se auth.php ja tiver sido carregado, delega para ele (mesma semantica).
 */
function lynch_ensure_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (function_exists('ensure_session')) {
        ensure_session();
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Guarda uma mensagem para ser exibida na PROXIMA requisicao.
 * $type: 'success' | 'error' | 'info'
 */
function flash_set(string $type, string $message): void
{
    lynch_ensure_session();
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Le e LIMPA as mensagens pendentes.
 *
 * Nao abre sessao a toa: se o visitante nao tem cookie de sessao, nao ha nada
 * para ler e criar uma sessao so para descobrir isso desperdicaria disco e
 * atrapalharia cache de pagina.
 *
 * @return array<int,array{type:string,message:string}>
 */
function flash_get(): array
{
    $sessionName = session_name();
    if (session_status() !== PHP_SESSION_ACTIVE && empty($_COOKIE[$sessionName])) {
        return [];
    }

    lynch_ensure_session();

    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : [];
}

// ---------------------------------------------------------------------------
// Limite de envio do servidor (post_max_size)
// ---------------------------------------------------------------------------

/**
 * Converte "16M", "512K", "1G" ou bytes puros do php.ini em bytes (0 = sem limite).
 */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $number = (float) $value;
    $unit   = strtolower(substr($value, -1));
    $factor = match ($unit) {
        'g' => 1073741824,
        'm' => 1048576,
        'k' => 1024,
        default => 1,
    };

    return max(0, (int) ($number * $factor));
}

/**
 * Limite total de um envio (post_max_size) em MB inteiros; 0 se ilimitado.
 */
function post_limit_mb(): int
{
    return (int) floor(ini_bytes((string) ini_get('post_max_size')) / 1048576);
}

/**
 * True quando o navegador mandou mais do que post_max_size: o PHP descarta
 * $_POST e $_FILES inteiros, e o formulário pareceria "sessão expirada".
 */
function post_body_too_large(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && $_POST === []
        && $_FILES === [];
}

// ---------------------------------------------------------------------------
// Desenhos da marca (SVG inline, sem requisicao extra)
// ---------------------------------------------------------------------------

/** Icone de traco da loja. */
function icon(string $name): string
{
    $paths = [
        'home'   => '<path d="M4 11l8-6.5L20 11M6 10v9.5h12V10M10 19.5V14h4v5.5"/>',
        'shop'   => '<path d="M12 8.6V7.4c1.2 0 2.1-.9 2.1-2S13.2 3.4 12 3.400s-2.1.9-2.1 2M12 8.6 3.5 15c-1 .8-.5 2.5 1 2.500h15c1.5 0 2-1.7 1-2.500z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'heart'  => '<path d="M12 20s-7.5-4.6-7.5-10.200A4.3 4.3 0 0 1 12 7.200a4.3 4.3 0 0 1 7.5 2.600C19.5 15.4 12 20 12 20z"/>',
        'back'   => '<path d="M15 5l-7 7 7 7"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'close'  => '<path d="M6 6l12 12M18 6L6 18"/>',
        'share'  => '<path d="M12 15V4M8 8l4-4 4 4M6 12v8h12v-8"/>',
        'chat'   => '<path d="M4 20.500l1.5-4A8 8 0 1 1 9 19.500z"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/**
 * O girassol da marca. Na sacola, cada peca acende uma petala: $lit e quantas
 * ficam acesas (null = todas, uso decorativo).
 */
function sunflower(?int $lit = null, string $class = ''): string
{
    $petals = '';
    for ($i = 0; $i < 12; $i++) {
        $on = $lit === null || $i < $lit;
        $petals .= '<path class="petal' . ($on ? ' on' : '') . '" transform="rotate(' . ($i * 30) . ' 50 50)" d="M50 34C43 26 44 11 50 3c6 8 7 23 0 31z"/>';
    }
    return '<svg class="sunflower ' . e($class) . '" viewBox="0 0 100 100" aria-hidden="true">' . $petals . '<circle class="seed" cx="50" cy="50" r="19"/></svg>';
}

/** Contador da sacola em forma de girassol. */
function bag_bloom(int $count): string
{
    return '<span class="bloom" data-bloom data-count="' . $count . '">' . sunflower(min($count, 12)) . '<b data-bloom-n aria-hidden="true">' . $count . '</b></span>';
}

/** Fita metrica: o "sob medida" da loja. Numeros decorativos. */
function measuring_tape(): string
{
    $marks = '';
    for ($i = 1; $i <= 60; $i++) {
        $marks .= '<span>' . $i . '</span>';
    }
    return '<div class="tape" aria-hidden="true"><div class="tape-run" data-tape>' . $marks . '</div></div>';
}

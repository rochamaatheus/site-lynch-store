<?php
/**
 * Lynch Store - acoes rapidas da lista de produtos (estoque, promocao,
 * destaque, mostrar estoque, esgotado).
 *
 * POST com CSRF. Sem JavaScript: grava, guarda a mensagem e volta para a lista
 * (303). Com o cabecalho X-Lynch-Ajax: 1 (assets/js/admin.js), responde JSON
 * com a linha redesenhada e as contagens das abas, sem recarregar a pagina.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/SettingsRepository.php';

$isAjax = ($_SERVER['HTTP_X_LYNCH_AJAX'] ?? '') === '1';
$listUrl = APP_BASE_URL . '/admin/produtos.php';
$back = safe_admin_redirect(is_string($_POST['back'] ?? null) ? $_POST['back'] : null);
if ($back === null || !str_contains((string) parse_url($back, PHP_URL_PATH), '/admin/produtos.php')) {
    $back = (string) (parse_url($listUrl, PHP_URL_PATH) ?: '/admin/produtos.php');
}

/** Responde e encerra (JSON com JS, redirect sem JS). */
function quick_reply(bool $ok, string $message, ?array $product = null): never
{
    global $isAjax, $back;

    if ($isAjax) {
        $payload = ['ok' => $ok, 'message' => $message];
        if ($product !== null) {
            $p = $product;
            $low = low_stock_threshold();
            $stockReady = ProductRepository::stockReady();
            $openPromo = in_array((string) ($_POST['op'] ?? ''), ['sale_set', 'sale_off'], true) && !$ok ? (int) $p['id'] : null;
            ob_start();
            include __DIR__ . '/partials/product-row.php';
            $payload['html'] = (string) ob_get_clean();
            $query = [];
            parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
            $payload['counts'] = ProductRepository::adminTabCounts(ProductRepository::adminFiltersFromQuery($query), $low);
        }
        http_response_code($ok ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    flash_set($ok ? 'success' : 'error', $message);
    $id = (int) ($_POST['id'] ?? 0);
    header('Location: ' . $back . ($id > 0 ? '#produto-' . $id : ''), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $listUrl, true, 303);
    exit;
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    quick_reply(false, 'A página ficou aberta por muito tempo. Atualize a lista e repita a alteração.');
}

$id = (int) ($_POST['id'] ?? 0);
$op = (string) ($_POST['op'] ?? '');

// Botoes de liga/desliga chegam como "featured_on" / "show_stock_off".
$map = [
    'featured_on'    => ['featured', ['value' => 1]],
    'featured_off'   => ['featured', ['value' => 0]],
    'show_stock_on'  => ['show_stock', ['value' => 1]],
    'show_stock_off' => ['show_stock', ['value' => 0]],
];
[$action, $input] = $map[$op] ?? [$op, $_POST];

try {
    $product = ProductRepository::quickAction($id, $action, is_array($input) ? $input : []);
} catch (DomainException $e) {
    quick_reply(false, $e->getMessage(), ProductRepository::findAnyById($id));
} catch (Throwable $e) {
    error_log('[lynch][produto-rapido] ' . $e->getMessage());
    quick_reply(false, 'Não foi possível salvar agora. Tente de novo.', ProductRepository::findAnyById($id));
}

$name = '"' . $product['name'] . '"';
$stock = $product['stock_qty'];
$message = match ($op) {
    'featured_on'    => $name . ' está em destaque.',
    'featured_off'   => $name . ' saiu dos destaques.',
    'show_stock_on'  => 'A loja agora mostra o estoque de ' . $name . '.',
    'show_stock_off' => 'A loja não mostra mais o estoque de ' . $name . '.',
    'stock_set', 'stock_inc', 'stock_dec' => $stock === null
        ? $name . ': estoque sem controle.'
        : ($stock === 0 ? $name . ': estoque 0, ficou esgotado.' : $name . ': estoque ' . $stock . '.' . (!empty($product['is_sold_out']) ? ' Continua marcado como esgotado.' : '')),
    'sold_out'       => $name . ' marcado como esgotado. Continua na loja, mas não pode ser pedido.',
    'reactivate'     => $name . ' voltou à venda' . ($stock !== null ? ' com estoque ' . $stock : '') . '.',
    'sale_set'       => 'Promoção aplicada em ' . $name . ': ' . format_price((int) $product['sale_price_cents'])
        . ($product['sale_active'] ? ' (' . $product['sale_percent'] . '% de desconto)' : '') . '.',
    'sale_off'       => 'Promoção de ' . $name . ' encerrada.',
    default          => 'Alteração salva.',
};

SecurityLog::audit((int) $admin['id'], 'produto_rapido', $op . ': ' . $product['name']);
quick_reply(true, $message, $product);

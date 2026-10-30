<?php
/**
 * Lynch Store - finalizar pedido (fase 4: Carrinho + checkout WhatsApp)
 *
 * Ultimo passo do "checkout": transforma o carrinho em mensagem, guarda um
 * snapshot em checkout_logs (historico de referencia para a Ray, NAO um sistema
 * de pedidos) e manda o cliente para a conversa no WhatsApp.
 *
 * SO ACEITA POST. Um GET aqui responde 405: se este endereco fosse acionavel
 * por link, qualquer crawler/prefetch do navegador "finalizaria" pedidos e
 * esvaziaria carrinhos sozinho.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/Cart.php';
require_once __DIR__ . '/includes/WhatsAppMessage.php';

// "Comprar agora" usa a sua propria sacola na sessao (ver carrinho.php).
if (($_POST['bag'] ?? '') === 'direct') {
    cart_bag('cart_direct');
}
$cartUrl = base_url('carrinho.php') . (cart_is_direct() ? '?direta=1' : '');


/**
 * Volta para o carrinho com uma mensagem. Nunca limpa o carrinho: se deu
 * errado, o cliente precisa encontrar tudo no lugar ao chegar la.
 */
function checkout_fail(string $message, string $cartUrl): never
{
    flash_set('error', $message);
    header('Location: ' . $cartUrl, true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// 1. Metodo e CSRF
// ---------------------------------------------------------------------------

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/html; charset=utf-8');
    // Sem estilo inline: a CSP do site nao libera 'unsafe-inline' em style-src.
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
       . '<meta name="robots" content="noindex"><title>Método não permitido</title>'
       . '<h1>Método não permitido</h1>'
       . '<p>O pedido só é finalizado pelo botão da sacola.</p>'
       . '<p><a href="' . e($cartUrl) . '">Voltar à sacola</a></p></html>';
    exit;
}

if (!csrf_check(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
    checkout_fail('Não foi possível finalizar o pedido. Atualize a página e tente novamente.', $cartUrl);
}

// ---------------------------------------------------------------------------
// 2. Carrinho (relido do banco, nunca da sessao)
// ---------------------------------------------------------------------------

$items = cart_items();

if ($items === []) {
    checkout_fail('Sua sacola está vazia. Escolha uma peça antes de enviar o pedido.', $cartUrl);
}

// Esgotado e estoque conferidos no servidor: cart_items() ja releu o banco e
// ajustou quantidades acima do estoque; peca esgotada bloqueia o envio.
$soldOut = cart_sold_out_items($items);
if ($soldOut !== []) {
    $names = implode(', ', array_map(static fn (array $i): string => (string) $i['name'], $soldOut));
    checkout_fail('Esgotou: ' . $names . '. Tire da sacola para enviar o pedido.', $cartUrl);
}
foreach ($items as $item) {
    if (!empty($item['qty_adjusted'])) {
        checkout_fail('O estoque de ' . $item['name'] . ' mudou e ajustamos a quantidade. Confira a sacola e envie de novo.', $cartUrl);
    }
}

// ---------------------------------------------------------------------------
// 3. Link do WhatsApp
// ---------------------------------------------------------------------------

$number = WhatsAppMessage::normalizeNumber(whatsapp_number());
$url    = whatsapp_checkout_url($items, ['number' => $number]);

if ($url === null) {
    // Sem numero configurado: aviso claro e carrinho INTACTO. Melhor isso do
    // que gerar "https://wa.me/?text=..." (bug do site estatico antigo).
    checkout_fail(
        'A loja ainda não configurou o número de WhatsApp. Sua sacola foi mantida. Tente novamente em instantes.',
        $cartUrl
    );
}

// ---------------------------------------------------------------------------
// 4. Cupom revalidado (pode ter expirado, esgotado ou sido desligado depois
//    de aplicado) e snapshot do pedido
// ---------------------------------------------------------------------------

$lastKey  = cart_is_direct() ? 'last_checkout_direct' : 'last_checkout';
$previous = $_SESSION[$lastKey] ?? null;
$recent   = is_array($previous) && time() - (int) ($previous['at'] ?? 0) < 1800;
$couponCode = cart_coupon_code();
// O mesmo checkout repetido em 30 minutos ja contou o uso: nao pode ser barrado
// pelo limite que ele mesmo consumiu.
$alreadyCounted = $recent && $couponCode !== '' && ($previous['coupon_code'] ?? '') === $couponCode;
$summary = cart_summary($items, $alreadyCounted);

if ($summary['coupon_code'] !== '' && $summary['coupon'] === null) {
    if ($summary['coupon_permanent']) {
        cart_coupon_clear();
        checkout_fail('O cupom ' . $summary['coupon_code'] . ' não vale mais (' . rtrim((string) $summary['coupon_error'], '.') . ') e foi retirado. Confira o novo total e envie de novo.', $cartUrl);
    }
    checkout_fail('Cupom ' . $summary['coupon_code'] . ': ' . $summary['coupon_error'] . ' Ajuste a sacola ou remova o cupom.', $cartUrl);
}

$couponSnap = $summary['coupon'] === null ? null : [
    'id'             => $summary['coupon']['id'],
    'code'           => $summary['coupon']['code'],
    'type'           => $summary['coupon']['discount_type'],
    'value'          => $summary['coupon']['discount_value'],
    'label'          => CouponRepository::label($summary['coupon']),
    'discount_cents' => $summary['discount_cents'],
];

$snapshot = [
    'items' => array_map(
        static fn (array $item): array => [
            'id'               => $item['id'],
            'name'             => $item['name'],
            'slug'             => $item['slug'],
            'qty'              => $item['qty'],
            'price_cents'      => $item['price_cents'],
            'price_on_request' => $item['price_on_request'],
            'regular_price_cents' => $item['regular_price_cents'],
            'subtotal_cents'   => $item['subtotal_cents'],
        ],
        $items
    ),
    'subtotal_cents' => $summary['subtotal_cents'],
    'coupon'         => $couponSnap,
    'discount_cents' => $summary['discount_cents'],
    'total_cents'    => $summary['total_cents'],
    'direct'         => cart_is_direct(),
];

// ---------------------------------------------------------------------------
// 5. Log + uso do cupom, na mesma transacao (o log NUNCA pode impedir o
//    pedido por erro do banco sem avisar; o cupom so conta se o log gravar)
// ---------------------------------------------------------------------------

$fingerprint = hash('sha256', json_encode($snapshot) . $number);
$orderId = null;
if ($recent && ($previous['fingerprint'] ?? '') === $fingerprint) {
    $orderId = (int) $previous['id'];
}
if ($orderId === null) {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($couponSnap !== null && !CouponRepository::claim($pdo, (int) $couponSnap['id'])) {
            $pdo->rollBack();
            cart_coupon_clear();
            checkout_fail('O cupom ' . $couponSnap['code'] . ' acabou de esgotar ou expirar e foi retirado. Confira o novo total e envie de novo.', $cartUrl);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO checkout_logs
                (cart_json, item_count, total_cents, has_price_on_request, whatsapp_number, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            cart_items_qty($items),
            $summary['total_cents'],
            $summary['on_request'] ? 1 : 0,
            substr($number, 0, 20),
            null,
            null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->commit();
        $_SESSION[$lastKey] = ['fingerprint' => $fingerprint, 'id' => $orderId, 'at' => time(), 'coupon_code' => $couponSnap['code'] ?? ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Banco fora do ar, tabela ausente, disco cheio: o cliente nao paga por isso.
        error_log('[Lynch Store] falha ao gravar checkout_logs: ' . $e->getMessage());
        checkout_fail('Não foi possível registrar o pedido. Sua sacola foi mantida. Tente novamente.', $cartUrl);
    }
}

// ---------------------------------------------------------------------------
// 6. Manda para o WhatsApp (a sacola continua salva)
// ---------------------------------------------------------------------------

$url = whatsapp_checkout_url($items, ['number' => $number, 'order_id' => $orderId, 'summary' => $summary]);
flash_set('info', 'Pedido #' . $orderId . ' preparado. Envie a mensagem no WhatsApp para iniciar o atendimento. ' . (cart_is_direct() ? 'Sua sacola não mudou.' : 'Sua sacola continua salva.'));
header('Location: ' . $url, true, 303);
exit;

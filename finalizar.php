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

$cartUrl = base_url('carrinho.php');

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
    echo '<!doctype html><meta charset="utf-8"><title>Método não permitido</title>'
       . '<div style="font:16px/1.6 Inter,system-ui,sans-serif;max-width:560px;margin:14vh auto;padding:0 24px">'
       . '<h1 style="font-size:1.3rem">Método não permitido</h1>'
       . '<p>O pedido só é finalizado pelo botão da sacola.</p>'
       . '<p><a href="' . e($cartUrl) . '">Voltar à sacola</a></p></div>';
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
// 4. Log (nice to have - NUNCA pode impedir o pedido)
// ---------------------------------------------------------------------------

$snapshot = array_map(
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
);

$fingerprint = hash('sha256', json_encode($snapshot) . $number);
$previous = $_SESSION['last_checkout'] ?? null;
$orderId = null;
if (is_array($previous) && ($previous['fingerprint'] ?? '') === $fingerprint && time() - (int) ($previous['at'] ?? 0) < 1800) {
    $orderId = (int) $previous['id'];
}
if ($orderId === null) {
try {
    $stmt = db()->prepare(
        'INSERT INTO checkout_logs
            (cart_json, item_count, total_cents, has_price_on_request, whatsapp_number, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        cart_count(),
        cart_total_cents($items),
        cart_has_price_on_request($items) ? 1 : 0,
        substr($number, 0, 20),
        null,
        null,
    ]);
    $orderId = (int) db()->lastInsertId();
    $_SESSION['last_checkout'] = ['fingerprint' => $fingerprint, 'id' => $orderId, 'at' => time()];
} catch (Throwable $e) {
    // Banco fora do ar, tabela ausente, disco cheio: o cliente nao paga por isso.
    error_log('[Lynch Store] falha ao gravar checkout_logs: ' . $e->getMessage());
    checkout_fail('Não foi possível registrar o pedido. Sua sacola foi mantida. Tente novamente.', $cartUrl);
}
}

// ---------------------------------------------------------------------------
// 5. Esvazia o carrinho e manda para o WhatsApp
// ---------------------------------------------------------------------------

$message = 'Pedido #' . $orderId . "\n\n" . whatsapp_checkout_message($items, ['number' => $number]);
$url = 'https://wa.me/' . rawurlencode($number) . '?text=' . rawurlencode($message);
flash_set('info', 'Pedido #' . $orderId . ' preparado. Envie a mensagem no WhatsApp para iniciar o atendimento. Sua sacola continua salva.');
header('Location: ' . $url, true, 303);
exit;

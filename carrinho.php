<?php
/**
 * Lynch Store - carrinho (fase 4: Carrinho + checkout WhatsApp)
 *
 * Duas responsabilidades no mesmo arquivo, como o resto do site (arquivo = rota):
 *
 *  1. POST ?action=add|update|remove|clear|buy_now|coupon_apply|coupon_remove
 *                                           -> muda a sessao e REDIRECIONA (303).
 *     Nunca renderiza HTML depois de um POST: assim F5 nao reenvia o formulario
 *     (padrao POST/Redirect/GET).
 *  2. GET                                   -> desenha o carrinho.
 *     GET ?direta=1                         -> desenha o "Comprar agora": outra
 *     sacola na sessao (cart_direct), com a peca escolhida na pagina do produto.
 *     A sacola de sempre nao e tocada. Os formularios dessa tela levam bag=direct.
 *
 * Mudar o carrinho por GET nao e possivel de proposito: adicionar item e
 * mutacao de estado, entao um link (que crawler/prefetch do navegador segue
 * sozinho) nao pode fazer isso. Todo botao "Adicionar" do site e <form method="post">.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/Cart.php';
require_once __DIR__ . '/includes/WhatsAppMessage.php';

// ---------------------------------------------------------------------------
// Helpers locais
// ---------------------------------------------------------------------------

/**
 * Redireciona e encerra. 303 (See Other) e o codigo certo depois de um POST:
 * obriga o navegador a refazer a navegacao como GET.
 */
function cart_redirect(string $url, int $status = 303): never
{
    // Pedido da sacola sem recarregar (assets/js/loja.js): responde o novo estado
    // em vez de redirecionar. Sem JS o fluxo POST/Redirect/GET segue igual.
    if (($_SERVER['HTTP_X_LYNCH_AJAX'] ?? '') === '1') {
        $flash = flash_get();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'count'   => cart_count(),   // sempre a sacola de sempre (girassol)
            'message' => $flash !== [] ? end($flash) : null,
            'html'    => cart_lines_html(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $url, true, $status);
    exit;
}

function cart_lines_html(): string
{
    ob_start();
    include __DIR__ . '/includes/partials/cart-lines.php';
    return (string) ob_get_clean();
}

/**
 * Para onde voltar depois de um "Adicionar": a pagina de onde o cliente veio,
 * se o destino for local e seguro; senao, o proprio carrinho.
 */
function cart_back_url(): string
{
    $target = safe_local_redirect($_POST['redirect_to'] ?? null);

    return $target ?? base_url('carrinho.php');
}

// ---------------------------------------------------------------------------
// 1. POST: acoes do carrinho
// ---------------------------------------------------------------------------

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action        = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$directUrl     = base_url('carrinho.php') . '?direta=1';
if (($requestMethod === 'POST' && ($_POST['bag'] ?? '') === 'direct') || ($requestMethod !== 'POST' && isset($_GET['direta']))) {
    cart_bag('cart_direct');
}
$cartUrl       = cart_is_direct() ? $directUrl : base_url('carrinho.php');

if ($requestMethod === 'POST') {
    // CSRF antes de qualquer coisa. Token invalido = nao processa nada.
    if (!csrf_check(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        flash_set('error', 'Não foi possível completar a ação. Atualize a página e tente novamente.');
        cart_redirect($cartUrl);
    }

    $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $qtyRaw    = $_POST['qty'] ?? null;
    $qty       = is_scalar($qtyRaw) && trim((string) $qtyRaw) !== '' ? (int) $qtyRaw : 1;

    switch ($action) {
        case 'add':
            $addProduct = $productId > 0 ? ProductRepository::findById($productId) : null;
            if ($addProduct === null) {
                flash_set('error', 'Produto indisponível no momento.');
                cart_redirect($cartUrl);
            }
            // Estoque e esgotado decididos aqui, com o produto lido do banco.
            if (!empty($addProduct['is_sold_out'])) {
                flash_set('error', 'Esta peça esgotou e não pode ir para a sacola.');
                cart_redirect(cart_back_url());
            }
            $room = cart_room_for($addProduct);
            if ($room <= 0) {
                flash_set('error', 'Você já tem na sacola todas as unidades disponíveis desta peça.');
                cart_redirect(cart_back_url());
            }

            if (cart_add($productId, min(max(1, $qty), $room))) {
                flash_set('success', 'Peça na sacola.');
            } else {
                flash_set('error', 'A sacola chegou ao limite de ' . CART_MAX_LINES . ' peças diferentes. Finalize este pedido e faça outro para o restante.');
            }
            cart_redirect(cart_back_url());
            // no break (cart_redirect nunca retorna)

        case 'update':
            $updProduct = $qty > 0 && $productId > 0 ? ProductRepository::findById($productId) : null;
            if ($qty > 0 && $updProduct !== null && $updProduct['stock_qty'] !== null && empty($updProduct['is_sold_out']) && $qty > (int) $updProduct['stock_qty']) {
                $qty = min(CART_MAX_QTY, (int) $updProduct['stock_qty']);
                cart_update($productId, $qty);
                flash_set('info', 'Só temos ' . $qty . ' ' . ($qty === 1 ? 'unidade' : 'unidades') . ' desta peça. Ajustamos a quantidade.');
                cart_redirect($cartUrl);
            }
            if ($qty > 0 && $updProduct !== null && !empty($updProduct['is_sold_out'])) {
                flash_set('error', 'Esta peça esgotou. Tire da sacola para enviar o pedido.');
                cart_redirect($cartUrl);
            }
            if (!cart_update($productId, $qty)) {
                flash_set('error', 'Não foi possível atualizar essa peça. Confira a sacola e tente de novo.');
            } elseif ($qty > CART_MAX_QTY) {
                flash_set('info', 'O máximo por peça é ' . CART_MAX_QTY . '. Ajustamos a quantidade para ' . CART_MAX_QTY . '. Para mais que isso, fale com a loja.');
            } else {
                flash_set('info', $qty <= 0 ? 'Peça removida da sacola.' : 'Quantidade atualizada.');
            }
            cart_redirect($cartUrl);

        case 'buy_now':
            // "Comprar agora": a peca vai sozinha para a sacola do Comprar agora,
            // que e trocada a cada clique. A sacola de sempre continua como estava.
            $buyProduct = $productId > 0 ? ProductRepository::findById($productId) : null;
            if ($buyProduct === null) {
                flash_set('error', 'Produto indisponível no momento.');
                cart_redirect(base_url('carrinho.php'));
            }
            if (!empty($buyProduct['is_sold_out'])) {
                flash_set('error', 'Esta peça esgotou e não pode ser pedida.');
                cart_redirect(cart_back_url());
            }
            cart_bag('cart_direct');
            cart_store([$productId => 1]);
            cart_redirect($directUrl);

        case 'coupon_apply':
            $couponInput = is_string($_POST['coupon'] ?? null) ? $_POST['coupon'] : '';
            if (!CouponRepository::ready()) {
                cart_coupon_notice('error', 'Cupons indisponíveis no momento.');
                cart_redirect($cartUrl);
            }
            if (coupon_locked()) {
                coupon_register_fail();
                cart_coupon_notice('error', 'Muitas tentativas de cupom. Espere 10 minutos e tente de novo.');
                cart_redirect($cartUrl);
            }
            if (trim($couponInput) === '') {
                cart_coupon_notice('error', 'Digite o código do cupom.');
                cart_redirect($cartUrl);
            }
            $couponCode = CouponRepository::normalizeCode($couponInput);
            $coupon = $couponCode !== '' ? CouponRepository::findByCode($couponCode) : null;
            $couponItems = cart_items();
            $couponCheck = CouponRepository::evaluate($coupon, cart_total_cents($couponItems), cart_has_fixed_price($couponItems));
            if (!$couponCheck['ok']) {
                if ($coupon === null) {
                    coupon_register_fail();
                }
                cart_coupon_notice('error', (string) $couponCheck['error']);
                cart_redirect($cartUrl);
            }
            cart_coupon_set($couponCode);
            cart_coupon_notice('ok', 'Cupom ' . $couponCode . ' aplicado: você economiza ' . format_price($couponCheck['discount_cents']) . '.');
            cart_redirect($cartUrl);

        case 'coupon_remove':
            cart_coupon_clear();
            cart_coupon_notice('ok', 'Cupom removido.');
            cart_redirect($cartUrl);

        case 'remove':
            cart_remove($productId);
            flash_set('info', 'Peça removida da sacola.');
            cart_redirect($cartUrl);

        case 'clear':
            cart_clear();
            flash_set('info', 'Sacola esvaziada.');
            cart_redirect($cartUrl);

        default:
            flash_set('error', 'Ação inválida.');
            cart_redirect($cartUrl);
    }
}

// GET com ?action=... (link antigo, bookmark, crawler): nao processa nada,
// so devolve o carrinho limpo.
if ($action !== '') {
    cart_redirect($cartUrl, 302);
}

// ---------------------------------------------------------------------------
// 2. GET: render
// ---------------------------------------------------------------------------

// Conteudo da sacola para a gaveta (sem layout). Sempre a sacola de sempre.
if (isset($_GET['sheet'])) {
    cart_bag('cart');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo cart_lines_html();
    exit;
}

// Lido ANTES do header: cart_items() descarta linhas orfas e acerta o contador.
cart_items();

$isDirect        = cart_is_direct();
$pageTitle       = $isDirect ? 'Comprar agora' : 'Sua sacola';
$pageNoIndex     = true;
$pageDescription = 'Revise as peças escolhidas e envie o pedido pelo WhatsApp da Lynch Store.';
$isBagPage       = !$isDirect;

require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap bag-page">
  <h1 class="page-title"><?= $isDirect ? 'Comprar agora' : 'Sua sacola' ?></h1>
  <?php if ($isDirect): ?><p class="bag-lede">Revise a peça, use um cupom se tiver e envie pelo WhatsApp. Pagamento e entrega você combina na conversa.</p><?php endif; ?>
  <div data-bag-body><?php include __DIR__ . '/includes/partials/cart-lines.php'; ?></div>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

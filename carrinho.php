<?php
/**
 * Lynch Store - carrinho (fase 4: Carrinho + checkout WhatsApp)
 *
 * Duas responsabilidades no mesmo arquivo, como o resto do site (arquivo = rota):
 *
 *  1. POST ?action=add|update|remove|clear  -> muda a sessao e REDIRECIONA (303).
 *     Nunca renderiza HTML depois de um POST: assim F5 nao reenvia o formulario
 *     (padrao POST/Redirect/GET).
 *  2. GET                                   -> desenha o carrinho.
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
    header('Location: ' . $url, true, $status);
    exit;
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
$cartUrl       = base_url('carrinho.php');

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
            if ($productId <= 0 || ProductRepository::findById($productId) === null) {
                flash_set('error', 'Produto indisponível no momento.');
                cart_redirect($cartUrl);
            }

            if (cart_add($productId, $qty)) {
                flash_set('success', 'Produto adicionado ao carrinho.');
            } else {
                flash_set('error', 'O carrinho chegou ao limite de ' . CART_MAX_LINES . ' peças diferentes. Finalize este pedido e faça outro para o restante.');
            }
            cart_redirect(cart_back_url());
            // no break (cart_redirect nunca retorna)

        case 'update':
            if (!cart_update($productId, $qty)) {
                flash_set('error', 'Não foi possível atualizar essa peça. Confira o carrinho e tente de novo.');
            } elseif ($qty > CART_MAX_QTY) {
                flash_set('info', 'O máximo por peça é ' . CART_MAX_QTY . '. Ajustamos a quantidade para ' . CART_MAX_QTY . '. Para mais que isso, fale com a loja.');
            } else {
                flash_set('info', $qty <= 0 ? 'Item removido do carrinho.' : 'Quantidade atualizada.');
            }
            cart_redirect($cartUrl);

        case 'remove':
            cart_remove($productId);
            flash_set('info', 'Item removido do carrinho.');
            cart_redirect($cartUrl);

        case 'clear':
            cart_clear();
            flash_set('info', 'Carrinho esvaziado.');
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

// Lido ANTES do header: cart_items() e quem descarta linhas orfas da sessao,
// entao o contador do header ja sai correto.
$items          = cart_items();
$lineCount      = count($items);
$totalCents     = cart_total_cents($items);
$hasOnRequest   = cart_has_price_on_request($items);
$hasFixedPrice  = cart_has_fixed_price($items);
$omitsLinks     = $lineCount > WhatsAppMessage::MAX_ITEMS_WITH_LINKS;
$whatsappReady  = WhatsAppMessage::normalizeNumber(whatsapp_number()) !== '';

$pageTitle       = 'Seu carrinho';
$pageNoIndex     = true;
$pageDescription = 'Revise os itens escolhidos e finalize o pedido pelo WhatsApp da Lynch Store.';
$pageScripts     = ['assets/js/cart.js'];

require __DIR__ . '/includes/partials/header.php';
?>

<section class="container cart-page">
  <a class="back" href="<?= e(url_catalog([], 'produtos')) ?>">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"></path></svg>
    Continuar comprando
  </a>

  <div class="section-head">
    <div>
      <span class="eyebrow">Pedido</span>
      <h1>Seu carrinho</h1>
    </div>
    <?php if ($lineCount > 0): ?>
    <p class="cart-head-note">
      <?= e($lineCount) ?> <?= $lineCount === 1 ? 'peça' : 'peças' ?> ·
      <?= e(cart_count()) ?> <?= cart_count() === 1 ? 'unidade' : 'unidades' ?>
    </p>
    <?php endif; ?>
  </div>

  <?php if ($lineCount === 0): ?>

    <div class="empty">
      <h3>Seu carrinho está vazio</h3>
      <p>Escolha as peças que você quer e nós combinamos medidas, prazo e valor direto no WhatsApp.</p>
      <a class="btn primary" href="<?= e(url_catalog([], 'produtos')) ?>">Ver produtos</a>
    </div>

  <?php else: ?>

    <div class="cart-grid">
      <div class="cart-lines">
        <?php foreach ($items as $item): ?>
        <article class="cart-row">
          <a class="cart-thumb" href="<?= e($item['url']) ?>">
            <img src="<?= e($item['image']) ?>" alt="<?= e($item['image_alt']) ?>" width="140" height="175" loading="lazy" decoding="async">
          </a>

          <div class="cart-info">
            <h3><a href="<?= e($item['url']) ?>"><?= e($item['name']) ?></a></h3>
            <span class="cart-meta">
              <?= $item['category_name'] !== '' ? e($item['category_name']) . ' · ' : '' ?><?= e($item['availability_label']) ?>
            </span>
            <span class="cart-unit">Unidade: <b><?= e($item['price_label']) ?></b></span>
          </div>

          <form class="cart-qty" method="post" action="<?= e($cartUrl) ?>?action=update" data-cart-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
            <!-- Os botoes +/- nascem hidden e so aparecem se assets/js/cart.js
                 rodar: sem JS eles nao fariam nada, e botao morto e pior que
                 botao ausente. Sem JS o fluxo continua inteiro pelo campo
                 numerico + "Atualizar". -->
            <button class="qty-step" type="button" data-cart-step="-1" hidden aria-label="Diminuir quantidade de <?= e($item['name']) ?>">&minus;</button>
            <label class="visually-hidden" for="qty-<?= e($item['id']) ?>">Quantidade de <?= e($item['name']) ?></label>
            <input class="qty-input" id="qty-<?= e($item['id']) ?>" type="number" name="qty"
                   value="<?= e($item['qty']) ?>" min="0" max="<?= e(CART_MAX_QTY) ?>" step="1"
                   inputmode="numeric" data-cart-qty>
            <button class="qty-step" type="button" data-cart-step="1" hidden aria-label="Aumentar quantidade de <?= e($item['name']) ?>">+</button>
            <button class="qty-apply" type="submit" data-cart-apply>Atualizar</button>
          </form>

          <div class="cart-line-end">
            <span class="cart-subtotal"><?= e($item['subtotal_label']) ?></span>
            <form method="post" action="<?= e($cartUrl) ?>?action=remove">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
              <button class="cart-remove" type="submit" aria-label="Remover <?= e($item['name']) ?> do carrinho">Remover</button>
            </form>
          </div>
        </article>
        <?php endforeach; ?>

        <form class="cart-clear" method="post" action="<?= e($cartUrl) ?>?action=clear">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear">
          <button type="submit">Esvaziar carrinho</button>
        </form>
      </div>

      <aside class="cart-summary">
        <h3>Resumo</h3>

        <?php if ($hasFixedPrice): ?>
          <div class="cart-total">
            <span><?= $hasOnRequest ? 'Total estimado' : 'Total' ?></span>
            <b><?= e(format_price($totalCents)) ?></b>
          </div>
          <?php if ($hasOnRequest): ?>
            <p class="cart-note">Itens marcados como <b>Consultar valor</b> não entram nesse total. Combinamos o preço deles na conversa.</p>
          <?php endif; ?>
        <?php else: ?>
          <div class="cart-total">
            <span>Total</span>
            <b>A combinar</b>
          </div>
          <p class="cart-note">Todas as peças escolhidas são <b>Consultar valor</b>: o orçamento sai na hora, pelo WhatsApp.</p>
        <?php endif; ?>

        <?php if ($omitsLinks): ?>
          <p class="cart-note">Como o pedido tem mais de <?= e(WhatsAppMessage::MAX_ITEMS_WITH_LINKS) ?> peças, a mensagem vai só com nome e quantidade (sem o link de cada produto) para não estourar o tamanho do link.</p>
        <?php endif; ?>

        <?php if ($whatsappReady): ?>
          <form method="post" action="<?= e(base_url('finalizar.php')) ?>">
            <?= csrf_field() ?>
            <!-- Fase 7: CTA pilula + badge circular. Junto com o CTA do hero,
                 sao os dois unicos botoes do site com esse tratamento - e o
                 momento de maior intencao de compra. -->
            <button class="btn primary cart-checkout cta-pill" type="submit">
              <span class="cta-pill-text">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
                Finalizar pedido
              </span>
              <span class="cta-badge" aria-hidden="true">
                <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h13M12 6l6 6-6 6"></path></svg>
              </span>
            </button>
          </form>
          <p class="cart-note">Abre o WhatsApp da loja com a lista já escrita. Você confere e envia.</p>
        <?php else: ?>
          <!-- Mesmo desenho do botao ativo (pilula + badge), so que apagado: a
               loja ainda nao cadastrou o numero. O CSS ja previa esse estado em
               .cta-pill.is-disabled - o disco fica em --soft e nao desliza. -->
          <span class="btn primary is-disabled cart-checkout cta-pill" title="Configuração pendente" aria-disabled="true">
            <span class="cta-pill-text">
              <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
              Finalizar pedido
            </span>
            <span class="cta-badge" aria-hidden="true">
              <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h13M12 6l6 6-6 6"></path></svg>
            </span>
          </span>
          <p class="cart-note">A loja ainda não cadastrou o número de WhatsApp. Seu carrinho continua salvo aqui.</p>
        <?php endif; ?>
      </aside>
    </div>

  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

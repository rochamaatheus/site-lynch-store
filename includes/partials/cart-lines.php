<?php
/**
 * Conteudo da sacola: usado na pagina carrinho.php, na gaveta (dialog) e no
 * "Comprar agora" (carrinho.php?direta=1, outra sacola na sessao).
 * Cada acao e um <form> POST normal; assets/js/loja.js so evita o recarregamento.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/Cart.php';
require_once dirname(__DIR__) . '/SettingsRepository.php';
require_once dirname(__DIR__) . '/WhatsAppMessage.php';

$bagItems     = cart_items();
$bagDirect    = cart_is_direct();
$bagUrl       = base_url('carrinho.php');
$bagSum       = cart_summary($bagItems);
$bagHasFixed  = $bagSum['has_fixed'];
$bagOnRequest = $bagSum['on_request'];
$bagReady     = WhatsAppMessage::normalizeNumber(whatsapp_number()) !== '';
$bagSoldOut   = cart_sold_out_items($bagItems);
$bagNotice    = cart_coupon_notice();
$bagCoupons   = CouponRepository::ready();
$bagField     = $bagDirect ? '<input type="hidden" name="bag" value="direct">' : '';
$bagMainCount = cart_count();
?>
<?php if ($bagItems === []): ?>
  <div class="bag-empty">
    <?= sunflower(0, 'bag-empty-flower') ?>
    <?php if ($bagDirect): ?>
    <h2>Nada para comprar agora</h2>
    <p>Escolha uma peça e toque em "Comprar agora" para revisar e enviar pelo WhatsApp.</p>
    <?php else: ?>
    <h2>Sua sacola está vazia</h2>
    <p>Cada peça que você escolher acende uma pétala do girassol.</p>
    <?php endif; ?>
    <a class="btn ink" href="<?= e(url_catalog()) ?>">Ver as peças</a>
  </div>
<?php else: ?>
  <ul class="bag-lines">
    <?php foreach ($bagItems as $item): ?>
    <li class="bag-line">
      <a class="photo bag-photo" href="<?= e($item['url']) ?>">
        <img src="<?= e($item['image']) ?>" alt="<?= e($item['image_alt']) ?>" width="144" height="180" loading="lazy" decoding="async">
      </a>
      <div class="bag-info">
        <h3><a href="<?= e($item['url']) ?>"><?= e($item['name']) ?></a></h3>
        <p class="bag-meta"><?= e($item['availability_label']) ?></p>
        <?php if ($item['sold_out']): ?>
        <p class="bag-price bag-out">Esgotou. Tire da sacola para enviar o pedido.</p>
        <?php else: ?>
        <p class="bag-price"><?php if ($item['old_subtotal_label'] !== null): ?><s><span class="sr">De </span><?= e($item['old_subtotal_label']) ?></s> <span class="sr">por </span><?php endif; ?><?= e($item['subtotal_label']) ?></p>
        <?php endif; ?>
        <div class="bag-actions">
          <?php if (!$item['sold_out']): ?>
          <form class="stepper" method="post" action="<?= e($bagUrl) ?>" data-cart-form>
            <?= csrf_field() ?><?= $bagField ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
            <button name="qty" value="<?= e($item['qty'] - 1) ?>" aria-label="Tirar uma unidade de <?= e($item['name']) ?>"><?= icon('minus') ?></button>
            <output aria-label="Quantidade"><?= e($item['qty']) ?></output>
            <button name="qty" value="<?= e(min($item['qty'] + 1, $item['max_qty'])) ?>" aria-label="Somar uma unidade de <?= e($item['name']) ?>"<?= $item['qty'] >= $item['max_qty'] ? ' disabled' : '' ?>><?= icon('plus') ?></button>
          </form>
          <?php endif; ?>
          <form method="post" action="<?= e($bagUrl) ?>" data-cart-form>
            <?= csrf_field() ?><?= $bagField ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
            <button class="link-btn" aria-label="Remover <?= e($item['name']) ?> da sacola">Remover</button>
          </form>
        </div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($bagCoupons): ?>
  <div class="bag-coupon">
    <?php if ($bagSum['coupon_code'] !== ''): ?>
      <div class="coupon-on<?= $bagSum['coupon'] === null ? ' is-error' : '' ?>">
        <p>
          <span class="coupon-tag"><?= e($bagSum['coupon_code']) ?></span>
          <?php if ($bagSum['coupon'] !== null): ?>
            Cupom de <?= e(CouponRepository::label($bagSum['coupon'])) ?> aplicado.
          <?php else: ?>
            <?= e((string) $bagSum['coupon_error']) ?>
          <?php endif; ?>
        </p>
        <form class="coupon-remove" method="post" action="<?= e($bagUrl) ?>" data-cart-form>
          <?= csrf_field() ?><?= $bagField ?>
          <input type="hidden" name="action" value="coupon_remove">
          <button class="link-btn" data-coupon-focus>Remover cupom</button>
        </form>
      </div>
    <?php else: ?>
      <form class="coupon-form" method="post" action="<?= e($bagUrl) ?>" data-cart-form>
        <?= csrf_field() ?><?= $bagField ?>
        <input type="hidden" name="action" value="coupon_apply">
        <label for="cupom-campo">Tem cupom?</label>
        <div class="coupon-row">
          <input id="cupom-campo" name="coupon" type="text" maxlength="30" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go" placeholder="Digite o código" data-coupon-focus<?= $bagNotice !== null && $bagNotice['type'] === 'error' ? ' aria-invalid="true" aria-describedby="cupom-recado"' : '' ?>>
          <button class="btn line" type="submit">Aplicar</button>
        </div>
      </form>
    <?php endif; ?>
    <?php if ($bagNotice !== null): ?>
      <p class="coupon-msg <?= $bagNotice['type'] === 'error' ? 'is-error' : 'is-ok' ?>" id="cupom-recado" role="<?= $bagNotice['type'] === 'error' ? 'alert' : 'status' ?>"><?= e((string) $bagNotice['text']) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* O aviso fica fora do rodape fixo: no celular a barra de envio ocupa so total e botao. */ ?>
  <p class="bag-note bag-note-lead"><?php
    if ($bagOnRequest && $bagHasFixed) {
        echo 'Peças com valor a combinar não entram no total' . ($bagSum['discount_cents'] > 0 ? ' nem no desconto' : '') . '. ';
    }
    echo $bagOnRequest ? 'A loja confirma valor, pagamento e entrega na conversa.' : 'Pagamento e entrega você combina na conversa.';
  ?></p>
  <div class="bag-foot">
    <?php if ($bagSum['discount_cents'] > 0): ?>
    <div class="bag-row"><span>Subtotal</span><span><?= e(format_price($bagSum['subtotal_cents'])) ?></span></div>
    <div class="bag-row bag-discount"><span>Cupom <?= e($bagSum['coupon_code']) ?></span><span>&minus;<?= e(format_price($bagSum['discount_cents'])) ?></span></div>
    <?php endif; ?>
    <div class="bag-total">
      <span><?= $bagOnRequest && $bagHasFixed ? 'Total estimado' : 'Total' ?></span>
      <b><?= $bagHasFixed ? e(format_price($bagSum['total_cents'])) : 'A combinar' ?></b>
    </div>
    <?php if ($bagSoldOut !== []): ?>
      <span class="btn ink wide is-off" aria-disabled="true"><?= icon('chat') ?> <?= $bagDirect ? 'Enviar pelo WhatsApp' : 'Enviar pedido no WhatsApp' ?></span>
      <p class="bag-note">Tem peça esgotada na sacola. Tire ela para enviar o pedido.</p>
    <?php elseif ($bagReady): ?>
      <form method="post" action="<?= e(base_url('finalizar.php')) ?>">
        <?= csrf_field() ?><?= $bagField ?>
        <div class="hp"><label>Deixe este campo vazio <input type="text" name="site_url" value="" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn ink wide" type="submit" data-sending="Abrindo o WhatsApp…"><?= icon('chat') ?> <?= $bagDirect ? 'Enviar pelo WhatsApp' : 'Enviar pedido no WhatsApp' ?></button>
      </form>
    <?php else: ?>
      <span class="btn ink wide is-off" aria-disabled="true"><?= icon('chat') ?> <?= $bagDirect ? 'Enviar pelo WhatsApp' : 'Enviar pedido no WhatsApp' ?></span>
      <p class="bag-note">O WhatsApp da loja ainda não foi cadastrado. Sua sacola fica salva aqui.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($bagDirect): ?>
  <p class="bag-keep">Sua sacola não muda com esta compra<?= $bagMainCount > 0 ? ': ela continua com ' . e($bagMainCount) . ' ' . ($bagMainCount === 1 ? 'peça' : 'peças') . '.' : '.' ?> <a href="<?= e($bagUrl) ?>">Ver a sacola</a></p>
<?php endif; ?>

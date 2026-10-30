<?php
/**
 * Conteudo da sacola: usado na pagina carrinho.php e na gaveta (dialog).
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
$bagUrl       = base_url('carrinho.php');
$bagHasFixed  = cart_has_fixed_price($bagItems);
$bagOnRequest = cart_has_price_on_request($bagItems);
$bagTotal     = cart_total_cents($bagItems);
$bagReady     = WhatsAppMessage::normalizeNumber(whatsapp_number()) !== '';
$bagSoldOut   = cart_sold_out_items($bagItems);
?>
<?php if ($bagItems === []): ?>
  <div class="bag-empty">
    <?= sunflower(0, 'bag-empty-flower') ?>
    <h2>Sua sacola está vazia</h2>
    <p>Cada peça que você escolher acende uma pétala do girassol.</p>
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
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
            <button name="qty" value="<?= e($item['qty'] - 1) ?>" aria-label="Tirar uma unidade de <?= e($item['name']) ?>"><?= icon('minus') ?></button>
            <output aria-label="Quantidade"><?= e($item['qty']) ?></output>
            <button name="qty" value="<?= e(min($item['qty'] + 1, $item['max_qty'])) ?>" aria-label="Somar uma unidade de <?= e($item['name']) ?>"<?= $item['qty'] >= $item['max_qty'] ? ' disabled' : '' ?>><?= icon('plus') ?></button>
          </form>
          <?php endif; ?>
          <form method="post" action="<?= e($bagUrl) ?>" data-cart-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
            <button class="link-btn" aria-label="Remover <?= e($item['name']) ?> da sacola">Remover</button>
          </form>
        </div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="bag-foot">
    <div class="bag-total">
      <span><?= $bagOnRequest && $bagHasFixed ? 'Total estimado' : 'Total' ?></span>
      <b><?= $bagHasFixed ? e(format_price($bagTotal)) : 'A combinar' ?></b>
    </div>
    <p class="bag-note"><?= $bagOnRequest ? 'A loja confirma valor, frete e prazo na conversa.' : 'A loja confirma frete e prazo na conversa.' ?></p>
    <?php if ($bagSoldOut !== []): ?>
      <span class="btn ink wide is-off" aria-disabled="true"><?= icon('chat') ?> Enviar pedido no WhatsApp</span>
      <p class="bag-note">Tem peça esgotada na sacola. Tire ela para enviar o pedido.</p>
    <?php elseif ($bagReady): ?>
      <form method="post" action="<?= e(base_url('finalizar.php')) ?>">
        <?= csrf_field() ?>
        <button class="btn ink wide" type="submit"><?= icon('chat') ?> Enviar pedido no WhatsApp</button>
      </form>
    <?php else: ?>
      <span class="btn ink wide is-off" aria-disabled="true"><?= icon('chat') ?> Enviar pedido no WhatsApp</span>
      <p class="bag-note">O WhatsApp da loja ainda não foi cadastrado. Sua sacola fica salva aqui.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

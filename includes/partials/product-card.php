<?php
/**
 * Card de produto. Espera $product (linha de ProductRepository).
 * "Adicionar" e POST de verdade; assets/js/loja.js so evita o recarregamento.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/Cart.php';
require_once dirname(__DIR__) . '/SettingsRepository.php';

if (!isset($product) || !is_array($product)) {
    return;
}

$cardName  = (string) ($product['name'] ?? 'Produto');
$cardSlug  = (string) ($product['slug'] ?? '');
$cardId    = (int) ($product['id'] ?? 0);
$cardAlt   = (string) ($product['primary_image_alt'] ?? '');
$cardAlt   = $cardAlt !== '' ? $cardAlt : $cardName . ', foto real da Lynch Store';
$cardPrice = product_price_view($product);
$cardOut   = !empty($product['is_sold_out']);
$cardStock = $product['stock_qty'] ?? null;
$cardLeft  = !$cardOut && !empty($product['show_stock']) && $cardStock !== null && $cardStock <= low_stock_threshold() ? (int) $cardStock : 0;
?>
<article class="card<?= $cardOut ? ' is-out' : '' ?>">
  <div class="photo card-photo" data-morph="<?= e($cardSlug) ?>">
    <a href="<?= e(product_url($cardSlug)) ?>" aria-label="Ver <?= e($cardName) ?>">
      <img src="<?= e(product_image_url($product['primary_image'] ?? null)) ?>" srcset="<?= e(product_image_thumb_url($product['primary_image'] ?? null)) ?> 240w, <?= e(product_image_small_url($product['primary_image'] ?? null)) ?> 480w, <?= e(product_image_url($product['primary_image'] ?? null)) ?> 720w" sizes="(max-width:699px) 47vw, (max-width:999px) 31vw, 300px" alt="<?= e($cardAlt) ?>" width="720" height="900"<?= !empty($cardEager) ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
    </a>
    <span class="tag"><?= e(availability_label($product['availability'] ?? null)) ?></span>
    <?php if ($cardOut || $cardPrice['on_sale'] || !empty($product['featured'])): ?>
    <p class="seals">
      <?php if ($cardOut): ?><span class="seal seal-out">Esgotado</span><?php endif; ?>
      <?php if (!$cardOut && $cardPrice['on_sale']): ?><span class="seal seal-sale">Promoção <?= e($cardPrice['percent']) ?>%</span><?php endif; ?>
      <?php if (!empty($product['featured'])): ?><span class="seal seal-star">Destaque</span><?php endif; ?>
    </p>
    <?php endif; ?>
    <button class="round fav" type="button" data-fav="<?= e($cardId) ?>" aria-pressed="false" aria-label="Favoritar <?= e($cardName) ?>" hidden><?= icon('heart') ?></button>
    <form class="quick" method="post" action="<?= e(base_url('carrinho.php')) ?>?action=add" data-add-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="product_id" value="<?= e($cardId) ?>">
      <input type="hidden" name="qty" value="1">
      <input type="hidden" name="redirect_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '')) ?>">
      <?php if ($cardOut): ?>
      <button class="round add" disabled aria-label="<?= e($cardName) ?> esgotou"><?= icon('plus') ?><?= icon('check') ?></button>
      <?php else: ?>
      <button class="round add" aria-label="Colocar <?= e($cardName) ?> na sacola"><?= icon('plus') ?><?= icon('check') ?></button>
      <?php endif; ?>
    </form>
  </div>
  <h3 class="card-name"><a href="<?= e(product_url($cardSlug)) ?>"><?= e($cardName) ?></a></h3>
  <p class="card-price"><?php if ($cardPrice['old'] !== null): ?><s><span class="sr">De </span><?= e($cardPrice['old']) ?></s> <span class="sr">por </span><?php endif; ?><?= e($cardPrice['now']) ?></p>
  <?php if ($cardLeft > 0): ?><p class="card-left"><?= $cardLeft === 1 ? 'Resta 1' : 'Restam ' . e($cardLeft) ?></p><?php endif; ?>
</article>

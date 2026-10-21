<?php
/**
 * Lynch Store - card de produto da grade (fase 3: Catalogo publico)
 *
 * Espera a variavel $product em escopo (uma linha de ProductRepository::listActive):
 *     foreach ($produtos as $product) { include __DIR__ . '/product-card.php'; }
 *
 * O botao "Adicionar" e um <form method="post"> para carrinho.php?action=add
 * (fase 4). E POST, e nao um link, porque adicionar ao carrinho muda estado -
 * um <a> seria seguido por prefetch do navegador e por crawler.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/Cart.php';   // csrf_field() + acoes do carrinho

if (!isset($product) || !is_array($product)) {
    return;
}

$cardName   = (string) ($product['name'] ?? 'Produto');
$cardSlug   = (string) ($product['slug'] ?? '');
$cardUrl    = $cardSlug !== '' ? product_url($cardSlug) : '#';
$cardImg    = product_image_url($product['primary_image'] ?? null);
$cardAlt    = (string) ($product['primary_image_alt'] ?? '');
if ($cardAlt === '') {
    $cardAlt = $cardName . ' - foto real da Lynch Store';
}
$cardCat    = (string) ($product['category_name'] ?? '');
$cardAvail  = availability_label($product['availability'] ?? null);
$cardPrice  = format_price(
    isset($product['price_cents']) ? (int) $product['price_cents'] : null,
    (bool) ($product['price_on_request'] ?? true)
);
$cardId      = (int) ($product['id'] ?? 0);
$cardAddUrl  = base_url('carrinho.php') . '?action=add';
// Volta para a MESMA pagina (com filtros/paginacao preservados) depois do add.
// carrinho.php valida esse destino com safe_local_redirect() antes de usar.
$cardBackTo  = (string) ($_SERVER['REQUEST_URI'] ?? '');
?>
<article class="card">
  <div class="pic">
    <img class="product-photo" src="<?= e($cardImg) ?>" srcset="<?= e(product_image_thumb_url($product['primary_image'] ?? null)) ?> 240w, <?= e($cardImg) ?> 720w" sizes="(max-width:600px) 44vw, (max-width:1000px) 30vw, 280px" alt="<?= e($cardAlt) ?>" width="720" height="900" loading="lazy" decoding="async">
    <a class="pic-link" href="<?= e($cardUrl) ?>">
      <span class="visually-hidden">Ver <?= e($cardName) ?></span>
    </a>
    <span class="tag-avail"><?= e($cardAvail) ?></span>
    <form class="buy-form" method="post" action="<?= e($cardAddUrl) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="product_id" value="<?= e($cardId) ?>">
      <input type="hidden" name="qty" value="1">
      <input type="hidden" name="redirect_to" value="<?= e($cardBackTo) ?>">
      <button class="buy" type="submit" aria-label="Adicionar <?= e($cardName) ?> ao carrinho">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z"></path><path d="M9 8a3 3 0 0 1 6 0"></path></svg>
        Adicionar
      </button>
    </form>
  </div>
  <div class="card-copy">
    <h3><a href="<?= e($cardUrl) ?>"><?= e($cardName) ?></a></h3>
    <span class="card-meta"><?= $cardCat !== '' ? e($cardCat) . ' · ' : '' ?><?= e($cardAvail) ?></span>
    <span class="card-price"><?= e($cardPrice) ?></span>
  </div>
</article>

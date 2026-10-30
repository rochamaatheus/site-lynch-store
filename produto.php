<?php
/**
 * Lynch Store - pagina de um produto (fase 3: Catalogo publico)
 *
 * Rota: produto.php?slug=peca-de-cosplay-01
 * (As regras de URL bonita /produto/slug estao prontas, porem comentadas, no
 *  .htaccess da raiz - ver nota no fim deste arquivo.)
 *
 * Slug inexistente, inativo ou ausente => 404 de verdade (http_response_code),
 * com a pagina de erro dentro do layout do site, nunca um fatal error do PHP.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/ProductRepository.php';

$slug    = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$product = $slug !== '' ? ProductRepository::findBySlug($slug) : null;

// ---------------------------------------------------------------------------
// 404
// ---------------------------------------------------------------------------
if ($product === null) {
    http_response_code(404);

    $pageTitle       = 'Peça não encontrada';
    $pageDescription = 'Esta peça não está mais disponível no catálogo da Lynch Store.';

    require __DIR__ . '/includes/partials/header.php';
    ?>
    <section class="wrap bag-empty">
      <?= sunflower(0, 'bag-empty-flower') ?>
      <h1 class="page-title">Essa peça saiu da arara</h1>
      <p>O link pode ter mudado ou a peça saiu do catálogo.</p>
      <a class="btn ink" href="<?= e(url_catalog()) ?>">Ver as peças</a>
    </section>
    <?php
    require __DIR__ . '/includes/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// Dados do produto
// ---------------------------------------------------------------------------
$images = is_array($product['images'] ?? null) ? $product['images'] : [];
$name   = (string) $product['name'];
$avail  = (string) $product['availability'];
$availLabel = availability_label($avail);
$priceView = product_price_view($product);
$price  = $priceView['now'];
$soldOut = !empty($product['is_sold_out']);
$stockLeft = !$soldOut && !empty($product['show_stock']) && $product['stock_qty'] !== null ? (int) $product['stock_qty'] : 0;
$stockLow  = $stockLeft > 0 && $stockLeft <= low_stock_threshold();
$categoryName = (string) ($product['category_name'] ?? '');
$categorySlug = (string) ($product['category_slug'] ?? '');

// Descricao: o seed nao preencheu nenhuma (os 46 produtos sao placeholder ate a
// Ray cadastrar os reais). Nunca deixar um <p> vazio na pagina.
$description = trim((string) ($product['description'] ?? ''));
if ($description === '') {
    $description = trim((string) ($product['short_description'] ?? ''));
}
if ($description === '') {
    $description = 'Foto real do acervo da Lynch Store. Consulte medidas, disponibilidade e valor pelo WhatsApp antes de finalizar a compra.';
}

$mainImage    = $images[0]['file_path'] ?? null;
$mainImageAlt = (string) ($images[0]['alt_text'] ?? '');
if ($mainImageAlt === '') {
    $mainImageAlt = $name . ' - foto real da Lynch Store';
}

$related = ProductRepository::related($product, 8);

// Mensagem pronta para o WhatsApp com o link desta peca
$waMessage = setting('whatsapp_message_intro', 'Olá! Quero fazer um pedido na Lynch Store:')
    . "\n\n" . $name . ' - ' . $price . "\n" . product_url((string) $product['slug']);
$waLink  = whatsapp_link($waMessage);
$waReady = $waLink !== '';

// Adicionar ao carrinho e POST (muda estado), nunca um link - ver fase 4.
$cartAddUrl = base_url('carrinho.php') . '?action=add';
$cartBackTo = (string) ($_SERVER['REQUEST_URI'] ?? '');
$productId  = (int) $product['id'];
$productSlug = (string) $product['slug'];

// ---------------------------------------------------------------------------
// Meta / SEO
// ---------------------------------------------------------------------------
$pageTitle       = $name;
// Sem descricao propria, a meta description leva nome, colecao e disponibilidade
// (todas as paginas com o mesmo texto seriam tratadas como duplicadas).
$hasOwnDescription = trim((string) ($product['description'] ?? '')) !== '' || trim((string) ($product['short_description'] ?? '')) !== '';
$pageDescription   = $hasOwnDescription
    ? excerpt($description, 155)
    : excerpt($name . ($categoryName !== '' ? ' - ' . $categoryName : '') . ', ' . mb_strtolower($availLabel) . '. Foto real da Lynch Store; consulte medidas e valor pelo WhatsApp.', 155);
$pageOgType      = 'product';
$pageImage       = $mainImage ?? 'assets/seo-banner.webp';
$pageCanonical   = product_url((string) $product['slug']);
$isProductPage   = true;
$pageJsonLd = json_encode(array_filter([
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $name,
    'description' => $pageDescription,
    'image'       => array_values(array_map(
        static fn (array $img): string => product_image_url($img['file_path']),
        $images
    )),
    'category'    => $categoryName,
    'brand'       => ['@type' => 'Brand', 'name' => 'Lynch Store'],
    'offers'      => $product['current_price_cents'] === null ? null : array_filter([
        '@type'         => 'Offer',
        'url'           => $pageCanonical,
        'priceCurrency' => 'BRL',
        'availability'  => $soldOut
            ? 'https://schema.org/SoldOut'
            : ($avail === 'pronta_entrega' ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder'),
        'price'         => number_format(((int) $product['current_price_cents']) / 100, 2, '.', ''),
        'priceValidUntil' => $priceView['on_sale'] ? $product['sale_ends_on'] : null,
    ]),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require __DIR__ . '/includes/partials/header.php';
?>

<article class="pdp">
  <div class="pdp-media">
    <div class="pdp-gallery" data-gallery tabindex="0" role="group" aria-label="Fotos de <?= e($name) ?>">
      <?php foreach ($images ?: [['file_path' => null, 'alt_text' => '']] as $i => $img): ?>
      <?php $alt = (string) ($img['alt_text'] ?? ''); ?>
      <figure class="photo pdp-photo"<?= $i === 0 ? ' data-morph="' . e($productSlug) . '" style="view-transition-name:peca"' : '' ?>>
        <img src="<?= e(product_image_detail_url($img['file_path'])) ?>" srcset="<?= e(product_image_url($img['file_path'])) ?> 720w, <?= e(product_image_detail_url($img['file_path'])) ?> 1080w" sizes="(max-width:899px) 100vw, 50vw" alt="<?= e($alt !== '' ? $alt : $name . ', foto ' . ($i + 1)) ?>" width="1080" height="1350"<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
      </figure>
      <?php endforeach; ?>
    </div>
    <?php if (count($images) > 1): ?>
    <div class="dots" data-dots aria-hidden="true"><?php foreach ($images as $i => $_): ?><i<?= $i === 0 ? ' class="on"' : '' ?>></i><?php endforeach; ?></div>
    <?php endif; ?>
    <span class="tag tag-big" data-swing><?= e($availLabel) ?></span>
    <button class="round fav" type="button" data-fav="<?= e($productId) ?>" aria-pressed="false" aria-label="Favoritar <?= e($name) ?>" hidden><?= icon('heart') ?></button>
  </div>

  <div class="wrap pdp-info">
    <?php if ($categoryName !== ''): ?>
    <a class="pdp-cat" href="<?= e(url_catalog(['categoria' => $categorySlug])) ?>"><?= e($categoryName) ?></a>
    <?php endif; ?>
    <h1 class="pdp-name"><?= e($name) ?></h1>
    <?php if ($soldOut || $priceView['on_sale'] || !empty($product['featured'])): ?>
    <p class="seals seals-pdp">
      <?php if ($soldOut): ?><span class="seal seal-out">Esgotado</span><?php endif; ?>
      <?php if (!$soldOut && $priceView['on_sale']): ?><span class="seal seal-sale">Promoção <?= e($priceView['percent']) ?>%</span><?php endif; ?>
      <?php if (!empty($product['featured'])): ?><span class="seal seal-star">Destaque</span><?php endif; ?>
    </p>
    <?php endif; ?>
    <p class="pdp-price"><?php if ($priceView['old'] !== null): ?><s><span class="sr">De </span><?= e($priceView['old']) ?></s> <span class="sr">por </span><?php endif; ?><?= e($price) ?></p>
    <?php if ($priceView['on_sale'] && !empty($product['sale_ends_on'])): ?><p class="pdp-stock">Promoção até <?= e(format_date_br($product['sale_ends_on'])) ?></p><?php endif; ?>
    <?php if ($soldOut): ?>
    <p class="pdp-stock is-out">Esta peça esgotou. Pergunte no WhatsApp se ela volta ou se dá para fazer sob encomenda.</p>
    <?php elseif ($stockLeft > 0): ?>
    <p class="pdp-stock<?= $stockLow ? ' is-low' : '' ?>"><?= $stockLow ? ($stockLeft === 1 ? 'Resta 1 unidade' : 'Restam ' . e($stockLeft) . ' unidades') : e($stockLeft) . ' em estoque' ?></p>
    <?php endif; ?>
    <p class="pdp-desc"><?= e($description) ?></p>

    <div class="buybar">
      <a class="top-bag" href="<?= e(base_url('carrinho.php')) ?>" data-open-bag aria-label="Sacola com <?= e(cart_count()) ?> <?= cart_count() === 1 ? 'peça' : 'peças' ?>"><?= bag_bloom(cart_count()) ?></a>
      <form method="post" action="<?= e($cartAddUrl) ?>" data-add-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="product_id" value="<?= e($productId) ?>">
        <input type="hidden" name="qty" value="1">
        <input type="hidden" name="redirect_to" value="<?= e($cartBackTo) ?>">
        <?php if ($soldOut): ?>
        <button class="btn ink wide" type="submit" disabled>Esgotado</button>
        <?php else: ?>
        <button class="btn ink wide" type="submit">Colocar na sacola</button>
        <?php endif; ?>
      </form>
    </div>

    <div class="pdp-links">
      <?php if ($waReady): ?><a class="btn line" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> Perguntar no WhatsApp</a><?php endif; ?>
      <button class="btn line" type="button" data-share hidden><?= icon('share') ?> Compartilhar</button>
      <span class="sr" data-share-status role="status"></span>
    </div>

    <div class="pdp-faq">
      <details>
        <summary><?= $avail === 'sob_encomenda' ? 'Como funciona a encomenda' : 'Como funciona a compra' ?></summary>
        <div><p><?= $avail === 'sob_encomenda'
            ? 'Você envia a peça pelo WhatsApp e combina medidas, tecido, cores e prazo antes de qualquer pagamento. Nada é produzido sem o seu ok.'
            : 'Você envia a peça pelo WhatsApp, a loja confirma a disponibilidade e vocês combinam pagamento, envio ou retirada.' ?></p></div>
      </details>
      <details>
        <summary>Como tirar as medidas</summary>
        <div><p>Use uma fita métrica e anote busto, cintura, quadril e altura, por cima de roupa leve e sem apertar. Na conversa a loja confere com você e pede alguma medida extra, se a peça precisar.</p></div>
      </details>
      <details>
        <summary>Frete, prazo e pagamento</summary>
        <div><p>O frete é calculado pelo seu CEP na conversa, e a retirada pode ser combinada. O prazo das encomendas é informado antes de você fechar o pedido.</p></div>
      </details>
      <details>
        <summary>Cuidados, trocas e ajustes</summary>
        <div><p>Tecidos, estampas e apliques podem pedir cuidados diferentes: consulte a loja sobre a sua peça. Para troca ou ajuste, confira as condições antes de confirmar o pedido, inclusive em peças sob medida.</p></div>
      </details>
    </div>
  </div>
</article>

<?php if ($related !== []): ?>
<section class="band band-kraft" aria-labelledby="related-title">
  <div class="wrap band-head"><h2 class="title" id="related-title">Combina com esta</h2></div>
  <div class="shelf" tabindex="0" role="group" aria-labelledby="related-title">
    <?php foreach ($related as $product): ?>
      <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

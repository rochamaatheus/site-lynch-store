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
    <section class="container errorpage">
      <span class="eyebrow">erro 404</span>
      <h1>Peça não encontrada</h1>
      <p>O link pode ter mudado ou a peça saiu do catálogo. Dá uma olhada no que está disponível agora.</p>
      <a class="btn primary" href="<?= e(url_catalog([], 'produtos')) ?>">Ver o catálogo</a>
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
$price  = format_price($product['price_cents'], (bool) $product['price_on_request']);
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

$related = ProductRepository::related($product, 4);

// Mensagem pronta para o WhatsApp com o link desta peca
$waMessage = setting('whatsapp_message_intro', 'Olá! Quero fazer um pedido na Lynch Store:')
    . "\n\n" . $name . ' - ' . $price . "\n" . product_url((string) $product['slug']);
$waLink  = whatsapp_link($waMessage);
$waReady = $waLink !== '';

// Adicionar ao carrinho e POST (muda estado), nunca um link - ver fase 4.
$cartAddUrl = base_url('carrinho.php') . '?action=add';
$cartBackTo = (string) ($_SERVER['REQUEST_URI'] ?? '');

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
$pageScripts     = ['assets/js/gallery.js'];
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
    'offers'      => ($product['price_on_request'] || $product['price_cents'] === null) ? null : [
        '@type'         => 'Offer',
        'url'           => $pageCanonical,
        'priceCurrency' => 'BRL',
        'availability'  => $avail === 'pronta_entrega'
            ? 'https://schema.org/InStock'
            : 'https://schema.org/PreOrder',
    ] + ($product['price_on_request'] || $product['price_cents'] === null
        ? []
        : ['price' => number_format(((int) $product['price_cents']) / 100, 2, '.', '')]),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require __DIR__ . '/includes/partials/header.php';
?>

<section class="container product-page">
  <a class="back" href="<?= e(url_catalog([], 'produtos')) ?>">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"></path></svg>
    Voltar à loja
  </a>

  <div class="pgrid">
    <div>
      <div class="gallery-main" data-gallery>
        <!-- "detail" (1080x1350): a caixa tem zoom de 2x, entao a foto grande
             compensa; product_image_detail_url() cai no arquivo padrao se o
             derivado nao existir (foto legada). -->
        <img data-gallery-image
             src="<?= e(product_image_detail_url($mainImage)) ?>"
             srcset="<?= e(product_image_url($mainImage)) ?> 720w, <?= e(product_image_detail_url($mainImage)) ?> 1080w"
             sizes="(max-width:900px) calc(100vw - 32px), 50vw"
             alt="<?= e($mainImageAlt) ?>"
             width="1080" height="1350" fetchpriority="high" decoding="async">
      </div>

      <?php if (count($images) > 1): ?>
      <div class="thumbs" data-thumbs>
        <?php foreach ($images as $i => $img): ?>
          <?php
            $thumbAlt = (string) ($img['alt_text'] ?? '');
            if ($thumbAlt === '') {
                $thumbAlt = $name . ' - foto ' . ($i + 1);
            }
          ?>
          <button type="button"
                  class="<?= $i === 0 ? 'active' : '' ?>"
                  data-thumb
                  data-src="<?= e(product_image_detail_url($img['file_path'])) ?>"
                  data-alt="<?= e($thumbAlt) ?>"
                  aria-label="Ver foto <?= e($i + 1) ?> de <?= e($name) ?>">
            <!-- data-src carrega o "detail" na caixa grande; o <img> daqui e o
                 "thumb" de 240x300, que pesa poucos KB por miniatura. -->
            <img src="<?= e(product_image_thumb_url($img['file_path'])) ?>" alt="" loading="lazy" decoding="async" width="240" height="300">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <!-- Com uma unica foto as miniaturas nem sao renderizadas: o site nunca
           inventa imagens que o produto nao tem. -->
    </div>

    <div class="pinfo">
      <span class="eyebrow">
        <?= $categoryName !== '' ? e($categoryName) . ' · ' : '' ?><?= e($availLabel) ?>
      </span>
      <h1><?= e($name) ?></h1>
      <p class="big-price"><?= e($price) ?></p>
      <span class="chip"><i></i><b>Foto real</b> da loja</span>

      <div class="modes">
        <div class="mode <?= $avail === 'pronta_entrega' ? 'on' : '' ?>"><b>Pronta entrega</b>envio rápido</div>
        <div class="mode <?= $avail === 'sob_encomenda' ? 'on' : '' ?>"><b>Sob encomenda</b>medidas combinadas</div>
      </div>

      <!-- Fase 7: container ESTREITO (.inset) na coluna de leitura. Abaixo de
           900px o .pinfo ocupa a largura inteira da pagina e a descricao viraria
           uma linha longa demais para ler confortavelmente. -->
      <div class="inset">
        <div class="facts">
          <div class="fact"><span>Coleção</span><b><?= $categoryName !== '' ? e($categoryName) : 'Catálogo geral' ?></b></div>
          <div class="fact"><span>Disponibilidade</span><b><?= e($availLabel) ?></b></div>
          <div class="fact"><span>Valor</span><b><?= e($price) ?></b></div>
          <div class="fact"><span>Compra</span><b>WhatsApp</b></div>
        </div>

        <p class="pdesc"><?= e($description) ?></p>
      </div>

      <div class="pcta">
        <form class="pcta-form" method="post" action="<?= e($cartAddUrl) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= e((int) $product['id']) ?>">
          <input type="hidden" name="qty" value="1">
          <input type="hidden" name="redirect_to" value="<?= e($cartBackTo) ?>">
          <button class="btn primary" type="submit">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z"></path><path d="M9 8a3 3 0 0 1 6 0"></path></svg>
            Adicionar ao carrinho
          </button>
        </form>
        <?php if ($waReady): ?>
          <a class="btn sun" href="<?= e($waLink) ?>" target="_blank" rel="noopener">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
            Comprar no WhatsApp
          </a>
        <?php else: ?>
          <span class="btn sun is-disabled" title="Configuração pendente" aria-disabled="true">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
            Comprar no WhatsApp
          </span>
        <?php endif; ?>
      </div>

      <div class="freight">
        <button class="btn" type="button" data-share hidden>Compartilhar peça</button><span data-share-status role="status"></span>
        <p><span class="eyebrow">frete e prazo</span><br>Calculado e combinado direto no WhatsApp, junto com o valor da peça.</p>
        <?php if ($categorySlug !== ''): ?>
          <a class="btn" href="<?= e(url_catalog(['categoria' => $categorySlug], 'produtos')) ?>">Ver a coleção</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fase 7: accordion de FAQ (conteudo NOVO, complementar a lista .facts, que
       continua onde estava). E institucional da loja - vale para qualquer peca,
       nao depende de dado do produto. Fica FORA do .pinfo de proposito: aquele
       painel e sticky no desktop e, se crescesse mais que a viewport, o final do
       texto ficaria inalcancavel com o painel grudado no topo. -->
  <section class="inset faq" aria-labelledby="faq-title">
    <div class="faq-head">
      <span class="eyebrow">antes de fechar o pedido</span>
      <h2 id="faq-title">Perguntas <span class="hl">frequentes</span></h2>
    </div>

    <details>
      <summary>Como funciona uma peça sob encomenda?<span class="plus" aria-hidden="true">+</span></summary>
      <div class="faq-body">
        <p>Você escolhe a peça aqui, manda no WhatsApp e a gente combina medidas, tecido, cores e prazo antes de qualquer pagamento. Nada é produzido sem o seu ok. Cada peça é feita para o seu corpo e para o seu personagem.</p>
      </div>
    </details>

    <details>
      <summary>Como tiro as medidas certas?<span class="plus" aria-hidden="true">+</span></summary>
      <div class="faq-body">
        <p>Peça uma fita métrica emprestada e anote busto, cintura, quadril e altura, sempre por cima de roupa leve e sem apertar a fita. Na conversa a gente confere junto e pede alguma medida extra se a peça precisar (comprimento de manga, ombro a ombro, entrepernas).</p>
      </div>
    </details>

    <details>
      <summary>Frete, prazo e formas de pagamento<span class="plus" aria-hidden="true">+</span></summary>
      <div class="faq-body">
        <p>O frete é calculado pelo seu CEP na hora da conversa, e retirada combinada também é possível. Peças de pronta entrega saem assim que o pagamento é confirmado; as sob encomenda têm o prazo informado antes de você fechar o pedido.</p>
      </div>
    </details>

    <details>
      <summary>Cuidados com a peça<span class="plus" aria-hidden="true">+</span></summary>
      <div class="faq-body">
        <p>Consulte as instruções de cuidado da sua peça com a loja. Tecidos, estampas e apliques podem exigir formas diferentes de lavagem e conservação.</p>
      </div>
    </details>

    <details>
      <summary>Trocas e ajustes<span class="plus" aria-hidden="true">+</span></summary>
      <div class="faq-body">
        <p>Precisa de uma troca ou ajuste? Fale com a loja e conte o que aconteceu. Consulte as condições de atendimento antes de confirmar o pedido, inclusive para peças sob medida.</p>
      </div>
    </details>
  </section>

  <?php if ($related !== []): ?>
  <section class="shelf related" aria-labelledby="related-title">
    <div class="shelf-head">
      <div>
        <h3 id="related-title">Você também pode gostar</h3>
        <p><?= $categoryName !== '' ? 'Outras peças de ' . e($categoryName) . '.' : 'Outras peças do catálogo.' ?></p>
      </div>
      <span class="shelf-count"><?= e(count($related)) ?> itens</span>
    </div>
    <div class="product-grid">
      <?php foreach ($related as $product): ?>
        <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

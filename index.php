<?php
/**
 * Lynch Store - home / catalogo publico (fase 3)
 *
 * Tudo aqui e renderizado NO SERVIDOR: os filtros sao links (GET de verdade),
 * entao o catalogo funciona com JavaScript desligado. O JS da fase 3 cuida
 * apenas do drawer mobile.
 *
 * Parametros aceitos (GET):
 *   mode       'sob_encomenda' | 'pronta_entrega'  - mostra so aquela prateleira
 *   categoria  slug da categoria
 *   busca      termo de busca por nome
 *   mostrar_sob / mostrar_pronta   quantas pecas da prateleira aparecem (multiplo de PAGE_SIZE)
 *
 * A paginacao e CUMULATIVA (limit = quantidade mostrada, offset 0): o link
 * "Próximos produtos" so aumenta a quantidade, entao o que a cliente ja viu
 * continua na tela e nada depende de JS.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/CategoryRepository.php';
require_once __DIR__ . '/includes/ProductRepository.php';

const PAGE_SIZE = 8;

/**
 * Fase 7 (design) - destaque tipografico em titulo que vem do BANCO.
 *
 * O hero_title e o titulo da prateleira sao texto dinamico (site_settings /
 * nome da categoria / termo buscado), entao nao da para marcar "a palavra
 * certa" na mao no HTML. A regra escolhida e a que nunca quebra, seja qual for
 * o texto que a Ray escrever: destaca a ULTIMA palavra e devolve a pontuacao
 * final para fora do destaque ("Um mundo sob medida." -> "medida" em ferrugem,
 * o ponto continua preto). Titulo de uma palavra so fica sem destaque, de
 * proposito - pintar o titulo inteiro nao e destaque, e outra cor.
 *
 * Devolve HTML JA ESCAPADO: o unico markup do retorno e o <span> daqui.
 */
function hl_last_word(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $words = preg_split('/\s+/u', $text) ?: [];
    if (count($words) < 2) {
        return e($text);
    }

    $last   = (string) array_pop($words);
    $suffix = '';
    if (preg_match('/^(.*[^\p{P}\p{S}])([\p{P}\p{S}]+)$/u', $last, $m) === 1) {
        $last   = $m[1];
        $suffix = $m[2];
    }

    return e(implode(' ', $words)) . ' <span class="hl">' . e($last) . '</span>' . e($suffix);
}

// ---------------------------------------------------------------------------
// Entrada (tudo validado antes de virar filtro de consulta)
// ---------------------------------------------------------------------------
$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
if (!in_array($mode, ProductRepository::AVAILABILITIES, true)) {
    $mode = '';
}

$categorySlug = isset($_GET['categoria']) ? trim((string) $_GET['categoria']) : '';
$category     = $categorySlug !== '' ? CategoryRepository::findBySlug($categorySlug) : null;
if ($category === null) {
    $categorySlug = '';
}

$search = isset($_GET['busca']) ? trim((string) $_GET['busca']) : '';
if (mb_strlen($search) > 80) {
    $search = mb_substr($search, 0, 80);
}

$hasFilter = $mode !== '' || $categorySlug !== '' || $search !== '';

// Filtros comuns a todas as consultas de produto desta pagina
$baseFilters = [
    'category_slug' => $categorySlug !== '' ? $categorySlug : null,
    'search'        => $search !== '' ? $search : null,
];

// Parametros de URL que precisam sobreviver a troca de pagina
$keepParams = [
    'mode'      => $mode !== '' ? $mode : null,
    'categoria' => $categorySlug !== '' ? $categorySlug : null,
    'busca'     => $search !== '' ? $search : null,
];

// ---------------------------------------------------------------------------
// Prateleiras
// ---------------------------------------------------------------------------
$shelfDefs = [
    'sob_encomenda' => [
        'title' => 'Sob encomenda',
        'text'  => 'Peças de cosplay e fantasia para consultar medidas, ajustes e prazo diretamente pelo WhatsApp.',
        'param' => 'mostrar_sob',
    ],
    'pronta_entrega' => [
        'title' => 'Pronta entrega',
        'text'  => 'Camisetas e itens disponíveis para combinar compra, retirada ou envio.',
        'param' => 'mostrar_pronta',
    ],
];

// Quantas pecas cada prateleira mostra (multiplo de PAGE_SIZE, com teto).
$shownMap = [];
foreach ($shelfDefs as $def) {
    $wanted = (int) ($_GET[$def['param']] ?? PAGE_SIZE);
    $shownMap[$def['param']] = (int) (ceil(max(PAGE_SIZE, min(240, $wanted)) / PAGE_SIZE) * PAGE_SIZE);
}

$shelves     = [];
$totalShown  = 0;
$totalResult = 0;

foreach ($shelfDefs as $availability => $def) {
    if ($mode !== '' && $mode !== $availability) {
        continue;
    }

    $showParam = $def['param'];
    $shown     = $shownMap[$showParam];

    $total = ProductRepository::countActive($baseFilters + ['availability' => $availability]);
    $items = $total > 0
        ? ProductRepository::listActive($baseFilters + [
            'availability' => $availability,
            'limit'        => $shown,
            'offset'       => 0,
        ])
        : [];

    $remaining = max(0, $total - count($items));

    $shelves[] = [
        'key'       => $availability,
        'title'     => $def['title'],
        'text'      => $def['text'],
        'total'     => $total,
        'items'     => $items,
        'remaining' => $remaining,
        'next_url'  => $remaining > 0
            ? url_catalog($keepParams + [$showParam => $shown + PAGE_SIZE] + array_filter($shownMap, static fn (int $n): bool => $n > PAGE_SIZE), 'shelf-' . str_replace('_', '-', $availability))
            : '',
    ];

    $totalShown  += count($items);
    $totalResult += $total;
}

$categories = CategoryRepository::listActiveWithCounts();
$totalAtivos = ProductRepository::countActive();
$countSob    = ProductRepository::countActive(['availability' => 'sob_encomenda']);
$countPronta = ProductRepository::countActive(['availability' => 'pronta_entrega']);

// ---------------------------------------------------------------------------
// Textos do hero (editaveis pela Ray em site_settings)
// ---------------------------------------------------------------------------
$heroTitle    = setting('hero_title', 'Um mundo sob medida.');
$heroSubtitle = setting('hero_subtitle', 'Acessórios, moda kawaii geek e cosplay com clima artesanal, tons terrosos e atendimento direto pelo WhatsApp.');
$heroCta      = setting('hero_cta_label', 'Ver novidades');
$heroChip     = setting('hero_chip', 'moda geek, cosplay e peças fofinhas');
$heroBanner = setting('hero_banner_path');

$waLink   = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$waReady  = $waLink !== '';

// Titulo da prateleira conforme o filtro ativo
if ($search !== '') {
    $shopHeading = 'Resultados para “' . $search . '”';
} elseif ($category !== null) {
    $shopHeading = (string) $category['name'];
} elseif ($mode !== '') {
    $shopHeading = availability_label($mode);
} else {
    $shopHeading = 'Prateleira da Lynch';
}

// Lede curta abaixo do titulo da prateleira (fase 7): explica o que a pessoa
// esta vendo sem depender do filtro ativo virar texto tecnico.
if ($search !== '') {
    $shopLede = 'Peças do catálogo cujo nome combina com o que você procurou.';
} elseif ($category !== null) {
    $shopLede = 'Tudo o que está disponível nesta coleção, com foto real e disponibilidade atualizada.';
} elseif ($mode !== '') {
    $shopLede = $mode === 'sob_encomenda'
        ? 'Peças feitas depois do pedido: medidas, tecido e prazo combinados na conversa.'
        : 'Peças que já estão prontas na loja, esperando só a combinação de envio.';
} else {
    $shopLede = 'Fotos reais do acervo, divididas entre o que sai sob encomenda e o que já está pronto para levar.';
}

// ---------------------------------------------------------------------------
// Meta / SEO
// ---------------------------------------------------------------------------
$isHome    = true;
$pageTitle = $hasFilter ? $shopHeading : 'Moda Geek, Cosplay e Camisetas';
$pageDescription = 'Lynch Store: vitrine com fotos reais de cosplay, fantasia e camisetas geek. Consulte disponibilidade, medidas e valores pelo WhatsApp.';
$pageCanonical   = $categorySlug !== '' ? url_catalog(['categoria' => $categorySlug]) : base_url();
$pageNoIndex = $search !== '' || $mode !== '' || isset($_GET['mostrar_sob']) || isset($_GET['mostrar_pronta']);
if ($category !== null) { $pageDescription = excerpt((string) ($category['description'] ?: 'Explore a coleção ' . $category['name'] . ' da Lynch Store. Fotos reais, peças geek e atendimento pelo WhatsApp.'), 155); }
$activeCategory  = $categorySlug;
$pageJsonLd = json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Organization',
    'name'        => 'Lynch Store',
    'description' => $pageDescription,
    'image'       => asset_url('assets/seo-banner.webp'),
    'logo'        => asset_url('assets/lynch-logo.webp'),
    'url'         => base_url(),
    'brand'       => ['@type' => 'Brand', 'name' => 'Lynch Store'],
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name'  => 'Catálogo Lynch Store',
        'itemListElement' => array_map(
            static fn (array $c): array => ['@type' => 'OfferCatalog', 'name' => $c['name']],
            $categories
        ),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require __DIR__ . '/includes/partials/header.php';
?>

<section class="hero">
  <div class="container hero-media">
    <div class="hero-content">
      <div class="hero-copy">
        <span class="chip"><i></i> <?= e($heroChip) ?></span>
        <<?= $hasFilter ? 'p' : 'h1' ?> class="hero-title"><?= hl_last_word($heroTitle) ?></<?= $hasFilter ? 'p' : 'h1' ?>>
        <p><?= e($heroSubtitle) ?></p>
        <div class="hero-ctas">
          <!-- CTA pilula + badge: so aqui e no "Finalizar pedido" do carrinho -->
          <a class="btn sun cta-pill" href="#produtos">
            <span class="cta-pill-text"><?= e($heroCta) ?></span>
            <span class="cta-badge" aria-hidden="true">
              <svg class="icon" viewBox="0 0 24 24"><path d="M5 12h13M12 6l6 6-6 6"></path></svg>
            </span>
          </a>
          <a class="btn" href="#colecoes">Explorar coleções</a>
        </div>
      </div>
    </div>
    <div class="hero-visual<?= $heroBanner !== '' ? ' custom-banner' : '' ?>" aria-hidden="true">
      <?php if ($heroBanner !== ''): ?>
      <img src="<?= e(asset_url($heroBanner)) ?>" alt="" fetchpriority="high" width="720" height="900">
      <?php else: ?>
      <img src="<?= e(asset_url('assets/hero/cutout-01-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/cutout-01-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-01-720.webp')) ?> 720w" sizes="(max-width:760px) 45vw, 260px" alt="" fetchpriority="high" width="698" height="654">
      <img src="<?= e(asset_url('assets/hero/cutout-02-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/cutout-02-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-02-720.webp')) ?> 720w" sizes="(max-width:760px) 45vw, 300px" alt="" fetchpriority="high" width="696" height="676">
      <img src="<?= e(asset_url('assets/hero/cutout-03-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/cutout-03-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-03-720.webp')) ?> 720w" sizes="(max-width:760px) 35vw, 300px" alt="" loading="lazy" width="566" height="1237">
      <img src="<?= e(asset_url('assets/hero/cutout-04-360.webp')) ?>" srcset="<?= e(asset_url('assets/hero/cutout-04-360.webp')) ?> 360w, <?= e(asset_url('assets/hero/cutout-04-720.webp')) ?> 720w" sizes="260px" alt="" loading="lazy" width="587" height="1212">
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="trust">
  <!-- Fase 7: grid full-bleed. A faixa NAO usa .container de proposito - ela vai
       de borda a borda da tela e o ritmo vem das hairlines de 1px entre as
       celulas (2 colunas no mobile, 4 no desktop). -->
  <div class="trust-row">
    <div class="trust-item">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7l1.4-2.6a1 1 0 0 1 .9-.4h3.4a1 1 0 0 1 .9.4L16 7"></path><circle cx="12" cy="13.5" r="3.4"></circle></svg>
      <span>Fotos reais da loja</span>
    </div>
    <div class="trust-item">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
      <span>Atendimento direto no WhatsApp</span>
    </div>
    <div class="trust-item">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11V4a1 1 0 0 1 1-1h7l10 10a1 1 0 0 1 0 1.4l-7 7a1 1 0 0 1-1.4 0L3 11z"></path><circle cx="7.5" cy="7.5" r="1.4"></circle></svg>
      <span>Peças sob medida</span>
    </div>
    <div class="trust-item">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>
      <span>Consulta sem compromisso</span>
    </div>
  </div>
</section>

<section class="band" id="colecoes">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">por universo</span>
        <h2>Nossas <span class="hl">coleções</span></h2>
        <p class="lede">Cada universo com as fotos reais da loja: cosplay, fantasia, camisetas e o que acabou de chegar.</p>
      </div>
      <a class="btn" href="<?= e(url_catalog([], 'produtos')) ?>">Ver tudo</a>
    </div>
    <!-- Ate 759px este grid vira um trilho horizontal com scroll-snap (CSS puro) -->
    <div class="collections no-scrollbar">
      <?php foreach ($categories as $cat): ?>
        <?php
          $catCover = $cat['cover_image'] ?? null;
          $catStyle = $catCover
              ? "--photo:url('" . e(product_image_url($catCover)) . "')"
              : '--photo:none';
          $catActive = $categorySlug !== '' && $categorySlug === $cat['slug'];
        ?>
        <a class="collection<?= $catActive ? ' active' : '' ?>"
           href="<?= e(url_catalog(['categoria' => $cat['slug']], 'produtos')) ?>"
           style="<?= $catStyle ?>">
          <span><?= e($cat['product_count']) ?> <?= $cat['product_count'] === 1 ? 'peça' : 'peças' ?></span>
          <b><?= e($cat['name']) ?></b>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="shop-wrap" id="produtos">
  <div class="container shop">
    <aside class="filters" aria-label="Filtros da loja">
      <div class="filter-group">
        <h2>Disponibilidade</h2>
        <a class="check<?= $mode === '' ? ' active' : '' ?>"<?= $mode === '' ? ' aria-current="true"' : '' ?> href="<?= e(url_catalog(['categoria' => $categorySlug, 'busca' => $search], 'produtos')) ?>"><i></i> Todos <span></span></a>
        <a class="check<?= $mode === 'sob_encomenda' ? ' active' : '' ?>"<?= $mode === 'sob_encomenda' ? ' aria-current="true"' : '' ?> href="<?= e(url_catalog(['mode' => 'sob_encomenda', 'categoria' => $categorySlug, 'busca' => $search], 'produtos')) ?>"><i></i> Sob encomenda</a>
        <a class="check<?= $mode === 'pronta_entrega' ? ' active' : '' ?>"<?= $mode === 'pronta_entrega' ? ' aria-current="true"' : '' ?> href="<?= e(url_catalog(['mode' => 'pronta_entrega', 'categoria' => $categorySlug, 'busca' => $search], 'produtos')) ?>"><i></i> Pronta entrega</a>
      </div>
      <div class="filter-group">
        <h2>Coleções</h2>
        <a class="filter-link<?= $categorySlug === '' ? ' active' : '' ?>"<?= $categorySlug === '' ? ' aria-current="true"' : '' ?> href="<?= e(url_catalog(['mode' => $mode, 'busca' => $search], 'produtos')) ?>">Todas <span><?= e($totalAtivos) ?></span></a>
        <?php foreach ($categories as $cat): ?>
        <a class="filter-link<?= $categorySlug === $cat['slug'] ? ' active' : '' ?>"<?= $categorySlug === $cat['slug'] ? ' aria-current="true"' : '' ?>
           href="<?= e(url_catalog(['mode' => $mode, 'categoria' => $cat['slug'], 'busca' => $search], 'produtos')) ?>">
          <?= e($cat['name']) ?> <span><?= e($cat['product_count']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </aside>

    <div>
      <div class="toolbar">
        <div>
          <span class="eyebrow">prateleira da loja</span>
          <<?= $hasFilter ? 'h1' : 'h2' ?> id="shop-heading"><?= hl_last_word($shopHeading) ?></<?= $hasFilter ? 'h1' : 'h2' ?>>
          <p class="lede"><?= e($shopLede) ?></p>
        </div>
        <div class="toolbar-actions">
          <button class="btn filter-mobile" type="button" data-menu-open>
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"></path></svg>
            Filtros
          </button>
        </div>
      </div>

      <?php if ($hasFilter): ?>
      <p class="active-filters">
        <?php if ($category !== null): ?>
          <span class="chip">Coleção: <b><?= e($category['name']) ?></b></span>
        <?php endif; ?>
        <?php if ($mode !== ''): ?>
          <span class="chip">Disponibilidade: <b><?= e(availability_label($mode)) ?></b></span>
        <?php endif; ?>
        <?php if ($search !== ''): ?>
          <span class="chip">Busca: <b><?= e($search) ?></b></span>
        <?php endif; ?>
        <a class="chip" href="<?= e(url_catalog([], 'produtos')) ?>"><b>Limpar filtros ×</b></a>
      </p>
      <?php endif; ?>

      <?php if ($totalResult === 0): ?>
        <div class="empty">
          <h3>Nenhuma peça encontrada</h3>
          <p>Ainda não temos nada com esses filtros. Tente outra coleção ou fale com a loja - muita coisa sai sob encomenda.</p>
          <a class="btn primary" href="<?= e(url_catalog([], 'produtos')) ?>">Ver todo o catálogo</a>
        </div>
      <?php else: ?>
        <div class="shelves">
          <?php foreach ($shelves as $shelf): ?>
            <?php if ($shelf['total'] === 0) { continue; } ?>
            <?php $shelfId = 'shelf-' . str_replace('_', '-', $shelf['key']); ?>
            <section class="shelf" id="<?= e($shelfId) ?>" aria-labelledby="<?= e($shelfId) ?>-title">
              <div class="shelf-head">
                <div>
                  <h3 id="<?= e($shelfId) ?>-title"><?= e($shelf['title']) ?></h3>
                  <p><?= e($shelf['text']) ?></p>
                </div>
                <span class="shelf-count"><?= e($shelf['total']) ?> <?= $shelf['total'] === 1 ? 'item' : 'itens' ?></span>
              </div>
              <div class="product-grid">
                <?php foreach ($shelf['items'] as $product): ?>
                  <?php include __DIR__ . '/includes/partials/product-card.php'; ?>
                <?php endforeach; ?>
              </div>
              <?php if ($shelf['next_url'] !== ''): ?>
                <a class="load-more" href="<?= e($shelf['next_url']) ?>">
                  Próximos produtos <span aria-hidden="true">›</span>
                </a>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="life" id="brecho">
        <!-- Duotone em CSS puro sobre a MESMA foto que ja estava aqui (nenhum
             asset novo): .life-photo leva o filter de dessaturacao e as duas
             camadas de tinta ficam FORA dela, porque filter afeta os filhos. -->
        <div class="life-media">
          <div class="life-photo" aria-hidden="true"></div>
          <div class="life-tint" aria-hidden="true"></div>
          <div class="life-tint warm" aria-hidden="true"></div>
        </div>
        <div class="life-text">
          <span class="eyebrow">brechó de cosplay</span>
          <h2>Peças com história, prontas para outro arco.</h2>
          <p>Um espaço especial para cosplay, garimpos e itens únicos, usando as fotos reais da loja para mostrar textura, caimento e acabamento.</p>
          <div class="life-cta">
            <?php if ($waReady): ?>
              <a class="btn sun" href="<?= e($waLink) ?>" target="_blank" rel="noopener">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
                Chamar no WhatsApp
              </a>
            <?php else: ?>
              <span class="btn sun is-disabled" title="Configuração pendente" aria-disabled="true">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21l1.5-4A8 8 0 1 1 9 19.5z"></path></svg>
                Chamar no WhatsApp
              </span>
            <?php endif; ?>
            <span class="life-note">
              <?= $waReady ? 'Resposta rápida, direto com a loja' : 'Contato do WhatsApp em configuração' ?>
            </span>
          </div>
        </div>
      </div>

      <p class="foot-note" style="border:0;margin-top:8px;padding:0">
        <?= e($countSob) ?> peças sob encomenda e <?= e($countPronta) ?> em pronta entrega no catálogo.
      </p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

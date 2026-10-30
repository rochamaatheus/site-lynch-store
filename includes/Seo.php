<?php
/**
 * Lynch Store - SEO da vitrine: titulos, URLs absolutas, capa de compartilhamento
 * e dados estruturados (JSON-LD em @graph).
 *
 * Regras:
 *  - canonical, og:url, sitemap e JSON-LD sempre absolutos e no dominio fixo
 *    (APP_BASE_URL; sem ele, o dominio publicado). Nunca HTTP_HOST.
 *  - Titulo ate ~60 caracteres e descricao ate ~155, sem travessao.
 *  - Nada de dado inventado: endereco, horario, telefone e Instagram so entram
 *    quando cadastrados em Configuracoes.
 *  - Produto "Consultar valor" nao vira Product (o Google exige offers, review ou
 *    aggregateRating e marcaria erro): fica como ItemPage com a foto.
 *
 * A capa padrao e gerada por scripts/gerar-capa.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/StoreInfo.php';

/** Dominio publicado (usado so quando APP_BASE_URL nao e uma URL absoluta). */
const SEO_SITE_URL = 'https://lynchstore.com.br';
const SEO_TITLE_MAX = 60;
const SEO_DESCRIPTION_MAX = 155;
/** Capa padrao: [arquivo, largura, altura]. */
const SEO_COVER = ['assets/capa-1200x630.jpg', 1200, 630];
const SEO_COVER_SQUARE = ['assets/capa-1200x1200.jpg', 1200, 1200];
/** Foto de produto acima disso nao vai para og:image (WhatsApp ignora previa pesada). */
const SEO_OG_IMAGE_MAX_BYTES = 300_000;

/** Raiz absoluta do site, sem barra no fim. */
function seo_site_url(): string
{
    $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';
    return preg_match('~^https?://[^/\s]+~i', $base) === 1 ? $base : SEO_SITE_URL;
}

/** Torna absoluta uma URL do site ("/loja.php" ou "loja.php"); URLs http(s) passam direto. */
function seo_abs(string $url): string
{
    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }
    $root = seo_site_url();
    // base_url() sem APP_BASE_URL devolve caminho a partir da raiz
    return $root . '/' . ltrim($url, '/');
}

/** "Joinville, SC" a partir das Configuracoes ('' sem cidade cadastrada). */
function seo_city(): string
{
    $city = setting('address_city');
    $state = setting('address_state');
    return $city === '' ? '' : $city . ($state !== '' ? ', ' . $state : '');
}

/** " em Joinville, SC" ou ''. */
function seo_in_city(): string
{
    $city = seo_city();
    return $city === '' ? '' : ' em ' . $city;
}

/** Corta sem quebrar palavra e sem reticencias (titulo). */
function seo_cut(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max + 1);
    $space = mb_strrpos($cut, ' ');
    $cut = $space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);
    return rtrim($cut, " ,.;:-");
}

/**
 * "<pagina> | Lynch Store", cabendo em SEO_TITLE_MAX. Se a pagina tiver um
 * complemento opcional (cidade), ele so entra quando couber.
 */
function seo_title(string $page, string $extra = ''): string
{
    $brand = ' | ' . store_name();
    if ($extra !== '' && mb_strlen($page . $extra . $brand) <= SEO_TITLE_MAX) {
        return $page . $extra . $brand;
    }
    return seo_cut($page, SEO_TITLE_MAX - mb_strlen($brand)) . $brand;
}

/** Meta description limpa, cortada em SEO_DESCRIPTION_MAX. */
function seo_description(string $text): string
{
    return excerpt($text, SEO_DESCRIPTION_MAX);
}

/** " e pedido pelo WhatsApp" quando o numero estiver configurado. */
function seo_contact_phrase(): string
{
    return whatsapp_link() !== '' ? 'pedido pelo WhatsApp' : 'atendimento direto com a loja';
}

/** Descricao da home (tambem usada no JSON-LD da loja). */
function seo_store_description(): string
{
    return seo_description('Ateliê de moda geek, cosplay e camisetas' . seo_in_city() . '. Peças prontas e sob medida, com fotos reais e ' . seo_contact_phrase() . '.');
}

/**
 * Imagem de compartilhamento de um produto: o maior derivado (detail 1080x1350)
 * se existir e for leve, senao a foto do card (720x900). null sem foto.
 *
 * @return array{url:string,width:int,height:int}|null
 */
function seo_product_image(?string $filePath): ?array
{
    $filePath = trim((string) $filePath);
    if ($filePath === '') {
        return null;
    }
    $candidates = [];
    $dot = strrpos($filePath, '.');
    if ($dot !== false) {
        $candidates[] = substr($filePath, 0, $dot) . '-detail' . substr($filePath, $dot);
    }
    $candidates[] = $filePath;
    foreach ($candidates as $candidate) {
        $abs = APP_ROOT . '/' . ltrim($candidate, '/');
        if (!is_file($abs) || filesize($abs) > SEO_OG_IMAGE_MAX_BYTES) {
            continue;
        }
        $info = @getimagesize($abs);
        if (!$info) {
            continue;
        }
        return ['url' => seo_abs(product_image_url($candidate)), 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }
    return null;
}

/** @return array{url:string,width:int,height:int} */
function seo_cover(bool $square = false): array
{
    [$file, $w, $h] = $square ? SEO_COVER_SQUARE : SEO_COVER;
    return ['url' => seo_abs(asset_url($file)), 'width' => $w, 'height' => $h];
}

function seo_cover_alt(): string
{
    $slogan = setting('store_slogan');
    return store_name() . ': arara de ateliê com peças de moda geek e cosplay' . ($slogan !== '' ? ' e o lema ' . $slogan : '');
}

/** @id dos nos fixos do grafo. */
function seo_id(string $node): string
{
    return seo_site_url() . '/#' . $node;
}

/**
 * Loja (Store e um LocalBusiness) e site. Vao em todas as paginas indexaveis.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_site_nodes(): array
{
    $store = [
        '@type'       => 'Store',
        '@id'         => seo_id('loja'),
        'name'        => store_name(),
        'description' => seo_store_description(),
        'url'         => seo_abs(base_url()),
        'logo'        => ['@type' => 'ImageObject', 'url' => seo_abs(asset_url('assets/icon-512.png')), 'width' => 512, 'height' => 512],
        'image'       => [seo_cover()['url'], seo_cover(true)['url']],
    ] + store_jsonld();

    $site = [
        '@type'      => 'WebSite',
        '@id'        => seo_id('site'),
        'url'        => seo_abs(base_url()),
        'name'       => store_name(),
        'inLanguage' => 'pt-BR',
        'publisher'  => ['@id' => seo_id('loja')],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => seo_abs(url_catalog()) . '?busca={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    return [$store, $site];
}

/**
 * Trilha de navegacao. Recebe [[nome, url], ...] a partir da home.
 *
 * @param array<int,array{0:string,1:string}> $items
 * @return array<string,mixed>
 */
function seo_breadcrumb(array $items, string $pageUrl): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $url]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seo_abs($url)];
    }
    return ['@type' => 'BreadcrumbList', '@id' => $pageUrl . '#trilha', 'itemListElement' => $list];
}

/** Pagina comum (WebPage, CollectionPage, ItemPage) ligada ao site. */
function seo_page_node(string $type, string $url, string $name, string $description, bool $withCrumb = true): array
{
    return array_filter([
        '@type'       => $type,
        '@id'         => $url,
        'url'         => $url,
        'name'        => $name,
        'description' => $description,
        'inLanguage'  => 'pt-BR',
        'isPartOf'    => ['@id' => seo_id('site')],
        'breadcrumb'  => $withCrumb ? ['@id' => $url . '#trilha'] : null,
    ], static fn ($v): bool => $v !== null);
}

/**
 * Dados estruturados do produto. Com preco: Product com Offer. Sem preco
 * ("Consultar valor"): ItemPage com a foto, sem Product.
 *
 * @param array<string,mixed> $product linha de ProductRepository::findBySlug
 * @return array<int,array<string,mixed>>
 */
function seo_product_nodes(array $product, string $url, string $description): array
{
    $name = (string) $product['name'];
    $images = array_values(array_filter(array_map(
        static fn (array $img): ?string => seo_product_image($img['file_path'] ?? null)['url'] ?? null,
        is_array($product['images'] ?? null) ? $product['images'] : []
    )));
    $priceCents = $product['current_price_cents'] ?? null;

    if ($priceCents === null) {
        $page = seo_page_node('ItemPage', $url, $name, $description);
        if ($images !== []) {
            $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $images[0]];
        }
        return [$page];
    }

    $soldOut = !empty($product['is_sold_out']);
    $view = product_price_view($product);
    $offer = array_filter([
        '@type'           => 'Offer',
        'url'             => $url,
        'priceCurrency'   => 'BRL',
        'price'           => number_format(((int) $priceCents) / 100, 2, '.', ''),
        'availability'    => $soldOut
            ? 'https://schema.org/SoldOut'
            : (($product['availability'] ?? '') === 'pronta_entrega' ? 'https://schema.org/InStock' : 'https://schema.org/MadeToOrder'),
        'priceValidUntil' => $view['on_sale'] && !empty($product['sale_ends_on']) ? (string) $product['sale_ends_on'] : null,
        'seller'          => ['@id' => seo_id('loja')],
    ], static fn ($v): bool => $v !== null);

    $node = array_filter([
        '@type'       => 'Product',
        '@id'         => $url . '#produto',
        'name'        => $name,
        'description' => $description,
        'url'         => $url,
        'image'       => $images ?: [seo_cover()['url']],
        'sku'         => (string) $product['slug'],
        'category'    => (string) ($product['category_name'] ?? '') ?: null,
        'brand'       => ['@type' => 'Brand', 'name' => store_name()],
        'offers'      => $offer,
    ], static fn ($v): bool => $v !== null);

    return [seo_page_node('ItemPage', $url, $name, $description) + ['mainEntity' => ['@id' => $url . '#produto']], $node];
}

/**
 * Serializa o grafo para <script type="application/ld+json">. JSON_HEX_* impede
 * que um nome de produto feche a tag.
 *
 * @param array<int,array<string,mixed>> $nodes
 */
function seo_jsonld(array $nodes): string
{
    return json_encode(['@context' => 'https://schema.org', '@graph' => array_values($nodes)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

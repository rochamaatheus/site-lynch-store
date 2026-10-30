<?php
/**
 * Lynch Store - carrinho de compras (fase 4: Carrinho + checkout WhatsApp)
 *
 * O carrinho vive inteiro em $_SESSION['cart'], no formato mais burro possivel:
 *
 *     $_SESSION['cart'] = [ product_id => qty, ... ]
 *
 * Guardar SO id e quantidade e uma decisao de seguranca, nao de preguica: nome,
 * preco e disponibilidade sao relidos do banco (ProductRepository::findById())
 * toda vez que o carrinho e renderizado. Assim, se a Ray corrigir um preco no
 * admin, o carrinho de quem estava com a pagina aberta acompanha - e ninguem
 * consegue "congelar" um preco antigo mexendo no cookie/sessao.
 *
 * Estilo procedural, igual a includes/functions.php (o site publico nao usa
 * classes fora dos repositorios).
 *
 * Uso:
 *     require_once __DIR__ . '/Cart.php';
 *     cart_add(12, 2);
 *     foreach (cart_items() as $item) { ... }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ProductRepository.php';
// auth.php entra so pelas funcoes genericas de sessao/CSRF (ensure_session,
// csrf_token, csrf_field, csrf_check). NENHUMA pagina publica chama
// require_login() - o arquivo so define funcoes, nao executa nada por si.
require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------------
// Limites
// ---------------------------------------------------------------------------

/** Teto por item. Evita "qty=999999" digitado na URL virando um subtotal absurdo. */
if (!defined('CART_MAX_QTY')) {
    define('CART_MAX_QTY', 99);
}

/** Teto de linhas distintas no carrinho (protege a sessao e a URL do wa.me). */
if (!defined('CART_MAX_LINES')) {
    define('CART_MAX_LINES', 60);
}

// ---------------------------------------------------------------------------
// Sessao
// ---------------------------------------------------------------------------

/**
 * Garante a sessao aberta antes de tocar $_SESSION['cart'].
 *
 * Com $create = false NAO cria sessao para quem ainda nao tem cookie: um
 * visitante que so passou pela home nao merece um arquivo de sessao no disco
 * (mesma economia que flash_get() ja faz em functions.php).
 *
 * @return bool true quando ha sessao ativa para ler/escrever
 */
function cart_session(bool $create = false): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    if (!$create && empty($_COOKIE[session_name()])) {
        return false;
    }

    ensure_session();

    return session_status() === PHP_SESSION_ACTIVE;
}

/**
 * Conteudo cru do carrinho, ja normalizado para array<int,int>.
 *
 * @return array<int,int> product_id => qty
 */
function cart_raw(bool $create = false): array
{
    if (!cart_session($create)) {
        return [];
    }

    $raw = $_SESSION['cart'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $clean = [];
    foreach ($raw as $id => $qty) {
        $id  = (int) $id;
        $qty = (int) $qty;
        if ($id > 0 && $qty > 0) {
            $clean[$id] = min($qty, CART_MAX_QTY);
        }
    }

    return $clean;
}

/**
 * Grava o carrinho de volta na sessao (interna).
 *
 * @param array<int,int> $cart
 */
function cart_store(array $cart): void
{
    if (!cart_session(true)) {
        return;
    }

    $_SESSION['cart'] = $cart;
    cart_flush_cache();
}

/**
 * Descarta o cache de cart_items() da requisicao atual.
 */
function cart_flush_cache(): void
{
    cart_items_cache(null, true);
}

/**
 * Cache estatico de cart_items() (interna).
 *
 * @param array<int,array<string,mixed>>|null $value
 * @return array<int,array<string,mixed>>|null
 */
function cart_items_cache(?array $value = null, bool $reset = false): ?array
{
    /** @var array<int,array<string,mixed>>|null $cache */
    static $cache = null;

    if ($reset) {
        $cache = null;
        return null;
    }
    if ($value !== null) {
        $cache = $value;
    }

    return $cache;
}

// ---------------------------------------------------------------------------
// Escrita
// ---------------------------------------------------------------------------

/**
 * Soma $qty a quantidade que ja existe do produto.
 *
 * Ids invalidos e quantidades <= 0 sao ignorados (uma requisicao suja nunca
 * derruba a pagina, so nao faz nada). O resultado e sempre saturado em
 * CART_MAX_QTY, nunca fica negativo.
 */
function cart_add(int $productId, int $qty = 1): bool
{
    if ($productId <= 0) {
        return false;
    }

    // "Adicionar" com quantidade zerada/negativa e sempre 1 unidade: quem quer
    // reduzir usa cart_update()/cart_remove(), nao um add negativo.
    if ($qty <= 0) {
        $qty = 1;
    }
    $qty = min($qty, CART_MAX_QTY);

    $cart = cart_raw(true);

    // Produto novo num carrinho ja gigante: recusa (quem chama avisa a cliente).
    if (!isset($cart[$productId]) && count($cart) >= CART_MAX_LINES) {
        return false;
    }

    $current = $cart[$productId] ?? 0;
    $cart[$productId] = max(1, min(CART_MAX_QTY, $current + $qty));

    cart_store($cart);

    return true;
}

/**
 * Define a quantidade EXATA de um produto. qty <= 0 remove a linha.
 */
function cart_update(int $productId, int $qty): bool
{
    if ($productId <= 0) {
        return false;
    }

    if ($qty <= 0) {
        cart_remove($productId);
        return true;
    }

    $cart = cart_raw(true);

    if (!isset($cart[$productId]) && count($cart) >= CART_MAX_LINES) {
        return false;
    }

    $cart[$productId] = min($qty, CART_MAX_QTY);

    cart_store($cart);

    return true;
}

/**
 * Tira o produto do carrinho (idempotente).
 */
function cart_remove(int $productId): void
{
    if ($productId <= 0) {
        return;
    }

    $cart = cart_raw();
    if (!isset($cart[$productId])) {
        return;
    }

    unset($cart[$productId]);
    cart_store($cart);
}

/**
 * Esvazia o carrinho. Chamado depois de mandar o cliente para o WhatsApp.
 */
function cart_clear(): void
{
    if (!cart_session()) {
        cart_flush_cache();
        return;
    }

    unset($_SESSION['cart']);
    cart_flush_cache();
}

// ---------------------------------------------------------------------------
// Leitura
// ---------------------------------------------------------------------------

/**
 * Itens do carrinho prontos para renderizar, com os dados ATUAIS do banco.
 *
 * Produtos que sumiram (excluidos ou desativados no admin depois de terem sido
 * adicionados) sao ignorados em silencio E removidos da sessao na hora - um
 * carrinho nao pode ficar apontando para fantasma nem mostrar erro ao cliente
 * por uma decisao administrativa que nao e problema dele.
 *
 * @return array<int,array<string,mixed>>
 */
function cart_items(): array
{
    $cached = cart_items_cache();
    if ($cached !== null) {
        return $cached;
    }

    $cart = cart_raw();
    if ($cart === []) {
        cart_items_cache([]);
        return [];
    }

    $items   = [];
    $orphans = [];
    $products = ProductRepository::findActiveByIds(array_keys($cart));

    foreach ($cart as $productId => $qty) {
        $product = $products[$productId] ?? null;

        if ($product === null) {
            $orphans[] = $productId;
            continue;
        }

        $onRequest  = (bool) ($product['price_on_request'] ?? true);
        // Preco VIGENTE (promocao valida hoje ou preco normal), sempre do banco.
        $priceCents = $onRequest ? null : ($product['current_price_cents'] ?? null);
        $soldOut    = !empty($product['is_sold_out']);
        $stock      = $product['stock_qty'] ?? null;

        // Mais unidades na sacola do que o estoque: ajusta para o que existe.
        $qtyAdjusted = false;
        if (!$soldOut && $stock !== null && $qty > $stock) {
            $qty = (int) $stock;
            $cart[$productId] = $qty;
            $qtyAdjusted = true;
        }

        $subtotal = ($priceCents === null || $soldOut) ? null : $priceCents * $qty;
        $slug     = (string) ($product['slug'] ?? '');
        $altText  = (string) ($product['primary_image_alt'] ?? '');
        $name     = (string) ($product['name'] ?? 'Produto');
        $onSale   = !$soldOut && !empty($product['sale_active']);

        $items[] = [
            'id'                 => (int) $product['id'],
            'name'               => $name,
            'slug'               => $slug,
            'url'                => $slug !== '' ? product_url($slug) : '',
            'qty'                => $qty,
            'price_cents'        => $priceCents,
            'price_on_request'   => $onRequest,
            'price_label'        => $soldOut ? 'Esgotado' : format_price($priceCents, $onRequest),
            'regular_price_cents'=> $onSale ? (int) $product['price_cents'] : null,
            'on_sale'            => $onSale,
            'sold_out'           => $soldOut,
            'stock'              => $stock,
            'max_qty'            => $stock !== null ? min(CART_MAX_QTY, (int) $stock) : CART_MAX_QTY,
            'qty_adjusted'       => $qtyAdjusted,
            'subtotal_cents'     => $subtotal,
            'subtotal_label'     => $soldOut ? 'Esgotado' : ($subtotal === null ? 'Consultar valor' : format_price($subtotal)),
            'old_subtotal_label' => $onSale ? format_price((int) $product['price_cents'] * $qty) : null,
            'image'              => product_image_url($product['primary_image'] ?? null),
            'image_alt'          => $altText !== '' ? $altText : $name,
            'availability'       => (string) ($product['availability'] ?? 'sob_encomenda'),
            'availability_label' => availability_label($product['availability'] ?? null),
            'category_name'      => (string) ($product['category_name'] ?? ''),
        ];
    }

    // Limpa as linhas orfas e grava quantidades ajustadas ao estoque (uma escrita).
    $adjusted = array_filter($items, static fn (array $i): bool => $i['qty_adjusted']);
    if ($orphans !== [] || $adjusted !== []) {
        foreach ($orphans as $orphanId) {
            unset($cart[$orphanId]);
        }
        if (cart_session()) {
            $_SESSION['cart'] = $cart;
        }
    }

    cart_items_cache($items);

    return $items;
}

/**
 * Soma das quantidades (o numerinho vermelho do header).
 *
 * De proposito NAO vai ao banco: o header de toda pagina do site chama isto, e
 * pagar N consultas so para pintar um badge seria caro. Uma eventual linha orfa
 * (produto desativado) e corrigida assim que o cliente abre carrinho.php.
 */
function cart_count(): int
{
    $total = 0;
    foreach (cart_raw() as $qty) {
        $total += $qty;
    }

    return $total;
}

/**
 * Quantas LINHAS distintas o carrinho tem (usado pela regra dos >6 itens).
 *
 * @param array<int,array<string,mixed>>|null $items
 */
function cart_line_count(?array $items = null): int
{
    return count($items ?? cart_items());
}

/**
 * Total em centavos, somando SO os itens com preco fechado.
 *
 * Itens "Consultar valor" continuam no carrinho e na mensagem, mas nao entram
 * na conta - o valor deles e combinado no WhatsApp.
 *
 * @param array<int,array<string,mixed>>|null $items
 */
function cart_total_cents(?array $items = null): int
{
    $total = 0;
    foreach ($items ?? cart_items() as $item) {
        if ($item['subtotal_cents'] !== null) {
            $total += (int) $item['subtotal_cents'];
        }
    }

    return $total;
}

/**
 * true quando pelo menos UM item e "consultar valor".
 *
 * Quem decide se a tela mostra "Total estimado" com ressalva ou um total
 * fechado - e se a mensagem do WhatsApp leva a nota de rodape.
 *
 * @param array<int,array<string,mixed>>|null $items
 */
function cart_has_price_on_request(?array $items = null): bool
{
    foreach ($items ?? cart_items() as $item) {
        if (!empty($item['price_on_request']) && empty($item['sold_out'])) {
            return true;
        }
    }

    return false;
}

/**
 * Itens esgotados que ainda estao na sacola (bloqueiam o envio do pedido).
 *
 * @param array<int,array<string,mixed>>|null $items
 * @return array<int,array<string,mixed>>
 */
function cart_sold_out_items(?array $items = null): array
{
    return array_values(array_filter($items ?? cart_items(), static fn (array $i): bool => !empty($i['sold_out'])));
}

/**
 * Quantas unidades ainda cabem na sacola para este produto (0 = esgotado ou
 * no limite do estoque). Decidido no servidor, com o produto lido do banco.
 *
 * @param array<string,mixed> $product linha de ProductRepository
 */
function cart_room_for(array $product): int
{
    if (!empty($product['is_sold_out'])) {
        return 0;
    }
    $max = $product['stock_qty'] !== null ? min(CART_MAX_QTY, (int) $product['stock_qty']) : CART_MAX_QTY;
    $inBag = cart_raw()[(int) $product['id']] ?? 0;

    return max(0, $max - $inBag);
}

/**
 * true quando pelo menos UM item tem preco fechado (o oposto util do anterior:
 * sem nenhum preco fixo nao existe "Total estimado" para mostrar).
 *
 * @param array<int,array<string,mixed>>|null $items
 */
function cart_has_fixed_price(?array $items = null): bool
{
    foreach ($items ?? cart_items() as $item) {
        if ($item['subtotal_cents'] !== null) {
            return true;
        }
    }

    return false;
}

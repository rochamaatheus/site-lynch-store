<?php
/**
 * Lynch Store - carrinho -> mensagem -> link wa.me (fase 4)
 *
 * Este arquivo E o checkout. Nao ha gateway, nao ha status de pedido: o
 * "finalizar" da loja e transformar o carrinho num texto bem formatado e
 * empurrar o cliente para a conversa no WhatsApp com esse texto ja digitado.
 *
 * Formato da mensagem (legivel no WhatsApp: uma informacao por linha, negrito
 * do WhatsApp so no numero do pedido e no total):
 *
 *     Olá! Quero fazer um pedido na Lynch Store:
 *
 *     *Pedido #123*
 *
 *     1. Camiseta Geek 01
 *        Qtd: 2 x R$ 90,00 = R$ 180,00
 *        Promoção: de R$ 100,00 por R$ 90,00
 *        https://.../produto.php?slug=camiseta-geek-01
 *     2. Vestido sob medida
 *        Qtd: 1, valor a combinar
 *
 *     Subtotal: R$ 180,00
 *     Cupom LYNCH10 (10%): -R$ 18,00
 *     *Total estimado: R$ 162,00*
 *     Itens com valor a combinar (1) não entram no total nem no desconto.
 *
 *     Podemos combinar o pagamento e a entrega por aqui?
 *
 *     Enviado pelo site.
 *
 * Regras que NAO sao detalhe:
 *  - rawurlencode() (nao urlencode) sobre texto UTF-8: o wa.me quer %20, nao "+";
 *  - carrinho com mais de 6 linhas omite a URL de cada produto; se mesmo assim o
 *    link passar de MAX_URL_LENGTH, a lista e encurtada com "e mais N itens"
 *    (a lista completa fica no pedido do painel);
 *  - sem numero configurado a funcao devolve null - QUEM CHAMA mostra o aviso.
 *    Nunca, jamais, gerar "https://wa.me/?text=..." (era o bug do site antigo).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/Coupon.php';

final class WhatsAppMessage
{
    /** Acima disso, a mensagem perde a linha de URL por item. */
    public const MAX_ITEMS_WITH_LINKS = 6;

    /** Teto pratico do link inteiro (wa.me redireciona para api.whatsapp.com). */
    public const MAX_URL_LENGTH = 4000;

    /** Primeira linha padrao (o admin pode trocar em site_settings). */
    public const DEFAULT_INTRO = 'Olá! Quero fazer um pedido na Lynch Store:';

    /** Assinatura final - ajuda a Ray a saber que o pedido veio do site. */
    public const DEFAULT_SIGNATURE = 'Enviado pelo site.';

    /** Pedido de combinar o que o site nao fecha. */
    public const CLOSING = 'Podemos combinar o pagamento e a entrega por aqui?';

    /**
     * Monta o TEXTO da mensagem (sem encode, sem link).
     *
     * @param array<int,array<string,mixed>> $items saida de cart_items()
     * @param array<string,mixed>            $settings intro, signature, order_id,
     *        summary (saida de cart_summary, com cupom), links (bool), limit (int)
     */
    public static function build(array $items, array $settings = []): string
    {
        $storeName = setting('store_name', 'Lynch Store');
        $intro = trim((string) ($settings['intro'] ?? setting('whatsapp_message_intro', 'Olá! Quero fazer um pedido na ' . $storeName . ':')));
        if ($intro === '') {
            $intro = self::DEFAULT_INTRO;
        }

        $signature = array_key_exists('signature', $settings)
            ? trim((string) $settings['signature'])
            : self::DEFAULT_SIGNATURE;

        // Peca esgotada nunca entra na mensagem (finalizar.php ja bloqueia antes).
        $items = array_values(array_filter($items, static fn (array $i): bool => empty($i['sold_out'])));

        // Carrinho grande: some a linha de URL de cada produto.
        $withLinks = (bool) ($settings['links'] ?? count($items) <= self::MAX_ITEMS_WITH_LINKS);
        $limit = isset($settings['limit']) ? max(1, (int) $settings['limit']) : count($items);

        $lines = [$intro, ''];
        if (!empty($settings['order_id'])) {
            $lines[] = '*Pedido #' . (int) $settings['order_id'] . '*';
            $lines[] = '';
        }

        $subtotal = 0;
        $hasFixed = false;
        $onRequest = 0;

        foreach ($items as $index => $item) {
            $name  = trim((string) ($item['name'] ?? 'Produto'));
            $qty   = max(1, (int) ($item['qty'] ?? 1));
            $cents = $item['price_cents'] ?? null;
            $cents = $cents === null ? null : (int) $cents;
            $isOnRequest = !empty($item['price_on_request']) || $cents === null;

            if ($isOnRequest) {
                $onRequest++;
            } else {
                $hasFixed = true;
                $subtotal += $cents * $qty;
            }
            if ($index >= $limit) {
                continue;
            }

            $lines[] = ($index + 1) . '. ' . $name;
            if ($isOnRequest) {
                $lines[] = '   Qtd: ' . $qty . ', valor a combinar';
            } else {
                $lines[] = '   Qtd: ' . $qty . ' x ' . format_price($cents) . ' = ' . format_price($cents * $qty);
                if (!empty($item['on_sale']) && !empty($item['regular_price_cents'])) {
                    $lines[] = '   Promoção: de ' . format_price((int) $item['regular_price_cents']) . ' por ' . format_price($cents);
                }
            }
            if ($withLinks) {
                $url = self::absoluteUrl((string) ($item['url'] ?? ''));
                if ($url !== '') {
                    $lines[] = '   ' . $url;
                }
            }
        }
        if (count($items) > $limit) {
            $rest = count($items) - $limit;
            $lines[] = '... e mais ' . $rest . ($rest === 1 ? ' item' : ' itens') . ' (a lista completa fica registrada no pedido).';
        }

        $lines[] = '';

        $summary  = is_array($settings['summary'] ?? null) ? $settings['summary'] : [];
        $discount = (int) ($summary['discount_cents'] ?? 0);
        $coupon   = $summary['coupon'] ?? null;
        $total    = max(0, $subtotal - $discount);

        if ($hasFixed) {
            if ($discount > 0 && is_array($coupon)) {
                $lines[] = 'Subtotal: ' . format_price($subtotal);
                $lines[] = 'Cupom ' . $coupon['code'] . ' (' . CouponRepository::label($coupon) . '): -' . format_price($discount);
            }
            $lines[] = '*' . ($onRequest > 0 ? 'Total estimado' : 'Total') . ': ' . format_price($total) . '*';
            if ($onRequest > 0) {
                $lines[] = 'Itens com valor a combinar (' . $onRequest . ') não entram no total' . ($discount > 0 ? ' nem no desconto' : '') . '.';
            }
        } else {
            // Nunca imprimir "Total: R$ 0,00": com tudo sob consulta nao existe
            // total nenhum para estimar.
            $lines[] = 'Valores a combinar na conversa.';
        }

        $lines[] = '';
        $lines[] = self::CLOSING;

        if ($signature !== '') {
            $lines[] = '';
            $lines[] = $signature;
        }

        return implode("\n", $lines);
    }

    /**
     * Link wa.me completo, ou null quando o numero ainda nao foi configurado.
     * Respeita MAX_URL_LENGTH: tira os links dos produtos e, se preciso, encurta
     * a lista.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed>            $settings aceita 'number' para testes
     */
    public static function url(array $items, array $settings = []): ?string
    {
        if ($items === []) {
            return null;
        }

        $number = self::normalizeNumber(
            (string) ($settings['number'] ?? whatsapp_number())
        );

        if ($number === '') {
            return null;
        }

        $prefix = 'https://wa.me/' . $number . '?text=';
        $link = static fn (array $opts): string => $prefix . rawurlencode(self::build($items, $opts + $settings));

        $url = $link([]);
        if (strlen($url) <= self::MAX_URL_LENGTH) {
            return $url;
        }
        $url = $link(['links' => false]);
        for ($limit = count($items) - 1; strlen($url) > self::MAX_URL_LENGTH && $limit >= 1; $limit--) {
            $url = $link(['links' => false, 'limit' => $limit]);
        }

        return $url;
    }

    /**
     * So digitos. A fase 6 (admin) normaliza na gravacao, mas conferir de novo
     * aqui e barato e impede um link quebrado se alguem editar o banco na mao.
     * Numero curto demais (menos de 10 digitos: DDI + DDD + numero) e tratado
     * como "nao configurado" - melhor o aviso claro do que um link que abre
     * uma conversa com ninguem.
     */
    public static function normalizeNumber(string $raw): string
    {
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return '';
        }

        return $digits;
    }

    /**
     * Garante URL absoluta dentro da mensagem.
     *
     * product_url() ja devolve absoluta quando APP_BASE_URL esta preenchida.
     * Se estiver vazia (deploy mal configurado), monta a partir do host da
     * requisicao; sem host, devolve '' e a linha de link simplesmente nao entra
     * - um caminho relativo colado no WhatsApp nao clica em lugar nenhum.
     */
    private static function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        // Host vem do cliente: aceita so o que parece hostname[:porta].
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
            return '';
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return ($isHttps ? 'https://' : 'http://') . $host . '/' . ltrim($url, '/');
    }
}

/**
 * Atalho procedural: texto da mensagem do carrinho.
 *
 * @param array<int,array<string,mixed>> $items
 * @param array<string,string>           $settings
 */
function whatsapp_checkout_message(array $items, array $settings = []): string
{
    return WhatsAppMessage::build($items, $settings);
}

/**
 * Atalho procedural: link wa.me do carrinho, ou null se nao houver numero.
 *
 * @param array<int,array<string,mixed>> $items
 * @param array<string,string>           $settings
 */
function whatsapp_checkout_url(array $items, array $settings = []): ?string
{
    return WhatsAppMessage::url($items, $settings);
}

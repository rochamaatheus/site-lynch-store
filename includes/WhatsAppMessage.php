<?php
/**
 * Lynch Store - carrinho -> mensagem -> link wa.me (fase 4)
 *
 * Este arquivo E o checkout. Nao ha gateway, nao ha status de pedido: o
 * "finalizar" da loja e transformar o carrinho num texto bem formatado e
 * empurrar o cliente para a conversa no WhatsApp com esse texto ja digitado.
 *
 * Formato da mensagem (definido no plano):
 *
 *     Ola! Quero fazer um pedido na Lynch Store:
 *
 *     1. Camiseta Geek 01 - Qtd: 2 - Consultar valor
 *        https://.../produto.php?slug=camiseta-geek-01
 *     2. ...
 *
 *     Total estimado: R$ 189,90 (itens sem preco fixo serao confirmados no WhatsApp)
 *
 *     Enviado pelo site.
 *
 * Regras que NAO sao detalhe:
 *  - rawurlencode() (nao urlencode): o wa.me quer %20, nao "+";
 *  - carrinho com mais de 6 linhas omite a URL de cada produto, senao a URL do
 *    link estoura o limite pratico dos navegadores/WhatsApp;
 *  - sem numero configurado a funcao devolve null - QUEM CHAMA mostra o aviso.
 *    Nunca, jamais, gerar "https://wa.me/?text=..." (era o bug do site antigo).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/SettingsRepository.php';

final class WhatsAppMessage
{
    /** Acima disso, a mensagem perde a linha de URL por item. */
    public const MAX_ITEMS_WITH_LINKS = 6;

    /** Primeira linha padrao (o admin pode trocar em site_settings). */
    public const DEFAULT_INTRO = 'Olá! Quero fazer um pedido na Lynch Store:';

    /** Assinatura final - ajuda a Ray a saber que o pedido veio do site. */
    public const DEFAULT_SIGNATURE = 'Enviado pelo site.';

    /**
     * Monta o TEXTO da mensagem (sem encode, sem link).
     *
     * @param array<int,array<string,mixed>> $items saida de cart_items()
     * @param array<string,string>           $settings intro/signature opcionais
     */
    public static function build(array $items, array $settings = []): string
    {
        $intro = trim((string) ($settings['intro'] ?? setting('whatsapp_message_intro', self::DEFAULT_INTRO)));
        if ($intro === '') {
            $intro = self::DEFAULT_INTRO;
        }

        $signature = array_key_exists('signature', $settings)
            ? trim((string) $settings['signature'])
            : self::DEFAULT_SIGNATURE;

        // Carrinho grande: some a linha de URL de cada produto.
        $withLinks = count($items) <= self::MAX_ITEMS_WITH_LINKS;

        $lines   = [$intro, ''];
        $total   = 0;
        $hasFixed = false;
        $hasOnRequest = false;
        $n = 0;

        foreach ($items as $item) {
            $n++;

            $name  = trim((string) ($item['name'] ?? 'Produto'));
            $qty   = max(1, (int) ($item['qty'] ?? 1));
            $cents = $item['price_cents'] ?? null;
            $cents = $cents === null ? null : (int) $cents;
            $onRequest = !empty($item['price_on_request']) || $cents === null;

            if ($onRequest) {
                $hasOnRequest = true;
            } else {
                $hasFixed = true;
                $total += $cents * $qty;
            }

            $price = (string) ($item['price_label'] ?? format_price($cents, $onRequest));

            // Travessao (em dash) igual ao template do plano.
            $lines[] = $n . '. ' . $name . ' | Qtd: ' . $qty . ' | ' . $price;

            if ($withLinks) {
                $url = self::absoluteUrl((string) ($item['url'] ?? ''));
                if ($url !== '') {
                    $lines[] = '   ' . $url;
                }
            }
        }

        $lines[] = '';

        if ($hasFixed) {
            $totalLine = 'Total estimado: ' . format_price($total);
            if ($hasOnRequest) {
                $totalLine .= ' (itens sem preço fixo serão confirmados no WhatsApp)';
            }
            $lines[] = $totalLine;
        } else {
            // Nunca imprimir "Total estimado: R$ 0,00": com tudo sob consulta
            // nao existe total nenhum para estimar.
            $lines[] = 'Valores a confirmar no WhatsApp.';
        }

        if ($signature !== '') {
            $lines[] = '';
            $lines[] = $signature;
        }

        return implode("\n", $lines);
    }

    /**
     * Link wa.me completo, ou null quando o numero ainda nao foi configurado.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,string>           $settings aceita 'number' para testes
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

        return 'https://wa.me/' . $number . '?text=' . rawurlencode(self::build($items, $settings));
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

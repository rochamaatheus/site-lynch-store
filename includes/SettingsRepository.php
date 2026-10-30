<?php
/**
 * Lynch Store - leitura de site_settings (fase 3: Catalogo publico)
 *
 * site_settings e uma tabela chave/valor pequena (uma dezena de linhas). Ler
 * tudo de uma vez e guardar em cache estatico custa UMA consulta por
 * requisicao, mesmo que o header, o hero e o rodape peguem chaves diferentes.
 *
 * Uso:
 *     require_once __DIR__ . '/SettingsRepository.php';
 *     echo setting('hero_title', 'Um mundo sob medida.');
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class SettingsRepository
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Todas as configuracoes como chave => valor (uma consulta por requisicao).
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = db()
            ->query('SELECT setting_key, setting_value FROM site_settings')
            ->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        self::$cache = $map;

        return $map;
    }

    /**
     * Valor de uma chave. Devolve $default quando a chave nao existe OU quando
     * o valor esta vazio - importante para whatsapp_number, que o seed deixa em
     * branco de proposito ate a Ray cadastrar o numero real.
     */
    public static function get(string $key, string $default = ''): string
    {
        $value = self::all()[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    /**
     * true quando a chave existe e tem conteudo.
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    /**
     * Numero de WhatsApp normalizado (so digitos), ou '' se nao configurado.
     * O link wa.me so aceita digitos com DDI.
     */
    public static function whatsappNumber(): string
    {
        $digits = preg_replace('/\D+/', '', self::get('whatsapp_number'));

        return (string) $digits;
    }

    /**
     * Valor CRU de uma chave: '' continua sendo '' (nao vira o default).
     *
     * get() existe para templates ("se estiver vazio, mostre o texto padrao");
     * o formulario do admin precisa do oposto - saber que o campo esta em
     * branco para renderizar um input vazio, e nao o placeholder do site.
     */
    public static function raw(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    // -----------------------------------------------------------------------
    // ESCRITA (fase 6 - admin/configuracoes.php)
    // -----------------------------------------------------------------------

    /**
     * Grava uma configuracao (insere ou atualiza).
     *
     * ON DUPLICATE KEY UPDATE porque setting_key e a PRIMARY KEY: uma chave
     * nova nasce aqui sem precisar de migration - foi exatamente para isso que
     * site_settings existe (ex.: hero_banner_path, criada nesta fase).
     */
    public static function set(string $key, string $value): void
    {
        $stmt = db()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([':k' => mb_substr(trim($key), 0, 64), ':v' => $value]);

        self::flush();
    }

    /**
     * Grava varias de uma vez, dentro de UMA transacao: o formulario de
     * configuracoes salva 6 campos juntos e nao pode deixar o site com metade
     * dos textos novos e metade dos antigos se algo falhar no meio.
     *
     * @param array<string,string> $keyValues
     */
    public static function setMany(array $keyValues): void
    {
        if ($keyValues === []) {
            return;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO site_settings (setting_key, setting_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );

            foreach ($keyValues as $key => $value) {
                $stmt->execute([
                    ':k' => mb_substr(trim((string) $key), 0, 64),
                    ':v' => (string) $value,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::flush();
    }

    /**
     * Descarta o cache. Util no admin (fase 6), depois de salvar configuracoes
     * na mesma requisicao em que a pagina sera re-renderizada.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}

/**
 * Atalho procedural, para nao poluir os templates com o nome da classe.
 */
function setting(string $key, string $default = ''): string
{
    return SettingsRepository::get($key, $default);
}

/**
 * "Estoque baixo" = este numero ou menos (Configuracoes; padrao 3, entre 1 e 99).
 */
function low_stock_threshold(): int
{
    $value = (int) SettingsRepository::get('low_stock_threshold', '3');
    return $value >= 1 && $value <= 99 ? $value : 3;
}

/**
 * Numero de WhatsApp so com digitos ('' quando ainda nao configurado).
 */
function whatsapp_number(): string
{
    return SettingsRepository::whatsappNumber();
}

/**
 * Monta um link wa.me com mensagem opcional, ou devolve '' quando o numero
 * ainda nao foi configurado. Nunca gera "https://wa.me/" vazio - esse era
 * exatamente o bug do site estatico antigo.
 */
function whatsapp_link(string $message = ''): string
{
    $number = whatsapp_number();
    // Mesmo criterio do carrinho (10 a 15 digitos): botao do cabecalho e checkout
    // nunca discordam sobre o numero estar configurado.
    if ($number === '' || strlen($number) < 10 || strlen($number) > 15) {
        return '';
    }

    $url = 'https://wa.me/' . $number;
    if ($message !== '') {
        $url .= '?text=' . rawurlencode($message);
    }

    return $url;
}

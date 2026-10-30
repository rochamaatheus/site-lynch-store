<?php
/**
 * Lynch Store - cupons de desconto.
 *
 * Regras (todas decididas aqui, no servidor):
 *  - o desconto incide sobre o subtotal dos itens COM preco, ja com o preco
 *    promocional vigente; itens "Consultar valor" ficam fora da conta;
 *  - percentual arredonda para o centavo mais proximo; valor fixo nunca passa
 *    do subtotal, entao o total nunca fica negativo;
 *  - a sacola guarda so o CODIGO na sessao; o cupom e relido do banco a cada
 *    calculo, e finalizar.php revalida e conta o uso de forma atomica;
 *  - tentativas erradas contam por sessao: 5 erros em 10 minutos bloqueiam,
 *    e cada erro espera um pouco antes de responder (contra adivinhar codigos).
 *
 * Leitura tolerante: sem a tabela coupons (banco ainda sem db/upgrade.php), a
 * loja segue sem cupom e o painel avisa que falta atualizar o banco.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/SecurityLog.php';

final class CouponRepository
{
    public const CODE_PATTERN = '/^[A-Z0-9-]{3,20}$/D';
    public const VALUE_MAX = 16777215;   // MEDIUMINT UNSIGNED
    public const USES_MAX = 65535;       // SMALLINT UNSIGNED
    public const MAX_FAILS = 5;
    public const IP_MAX_FAILS = 20;      // mesmo IP, somando sessoes (wi-fi compartilhado tem folga)
    public const FAIL_WINDOW = 600;      // segundos
    public const FAIL_DELAY_US = 400000; // 0,4 s por tentativa errada

    private const COLUMNS = 'id, code, discount_type, discount_value, min_order_cents, expires_on, max_uses, uses, active';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = db_schema_ready('coupons', static fn (): bool => db()->query("SHOW TABLES LIKE 'coupons'")->fetch() !== false);
            } catch (Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Para testes que removem/recriam a tabela na mesma execucao. */
    public static function flush(): void
    {
        self::$ready = null;
    }

    /** Maiusculo, sem espacos. Devolve '' quando nao parece um codigo. */
    public static function normalizeCode(string $raw): string
    {
        $code = strtoupper((string) preg_replace('/\s+/u', '', $raw));
        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : '';
    }

    /** Sugestao de codigo para o painel: LYNCH + 4 caracteres sem 0/O/1/I. */
    public static function suggestCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 5; $try++) {
            $code = 'LYNCH';
            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!self::ready() || self::findAnyByCode($code) === null) {
                return $code;
            }
        }
        return $code;
    }

    /** Cupom nao excluido pelo codigo (ja normalizado), ou null. */
    public static function findByCode(string $code): ?array
    {
        if ($code === '' || !self::ready()) {
            return null;
        }
        $stmt = db()->prepare('SELECT ' . self::COLUMNS . ' FROM coupons WHERE code = ? AND deleted = 0 LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    private static function findAnyByCode(string $code): ?array
    {
        $stmt = db()->prepare('SELECT id, deleted FROM coupons WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT ' . self::COLUMNS . ' FROM coupons WHERE id = ? AND deleted = 0 LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** Lista do painel: mais novos primeiro. */
    public static function listAll(): array
    {
        if (!self::ready()) {
            return [];
        }
        $rows = db()->query('SELECT ' . self::COLUMNS . ' FROM coupons WHERE deleted = 0 ORDER BY id DESC LIMIT 200')->fetchAll();
        return array_map([self::class, 'hydrate'], $rows);
    }

    private static function hydrate(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'code'            => (string) $row['code'],
            'discount_type'   => (string) $row['discount_type'],
            'discount_value'  => (int) $row['discount_value'],
            'min_order_cents' => $row['min_order_cents'] === null ? null : (int) $row['min_order_cents'],
            'expires_on'      => $row['expires_on'] === null ? null : (string) $row['expires_on'],
            'max_uses'        => $row['max_uses'] === null ? null : (int) $row['max_uses'],
            'uses'            => (int) $row['uses'],
            'active'          => (bool) $row['active'],
        ];
    }

    // -----------------------------------------------------------------------
    // Regras
    // -----------------------------------------------------------------------

    /** 'ativo' | 'desativado' | 'expirado' | 'esgotado' */
    public static function status(array $coupon, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if (!$coupon['active']) {
            return 'desativado';
        }
        if ($coupon['expires_on'] !== null && $coupon['expires_on'] < $today) {
            return 'expirado';
        }
        if ($coupon['max_uses'] !== null && $coupon['uses'] >= $coupon['max_uses']) {
            return 'esgotado';
        }
        return 'ativo';
    }

    /** "10%" ou "R$ 15,00". */
    public static function label(array $coupon): string
    {
        return $coupon['discount_type'] === 'percent'
            ? $coupon['discount_value'] . '%'
            : format_price($coupon['discount_value']);
    }

    /** Desconto em centavos sobre um subtotal, nunca maior que ele. */
    public static function discountFor(array $coupon, int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }
        $discount = $coupon['discount_type'] === 'percent'
            ? intdiv($subtotalCents * min(100, $coupon['discount_value']) + 50, 100)
            : $coupon['discount_value'];

        return max(0, min($subtotalCents, $discount));
    }

    /**
     * Confere o cupom contra a sacola.
     *
     * @param bool $ignoreLimit true quando este mesmo checkout ja contou o uso
     *                          (repeticao em 30 minutos com a mesma referencia)
     * @return array{ok:bool,error:?string,discount_cents:int,permanent:bool}
     */
    public static function evaluate(?array $coupon, int $subtotalCents, bool $hasFixed, bool $ignoreLimit = false): array
    {
        $fail = static fn (string $msg, bool $permanent): array => ['ok' => false, 'error' => $msg, 'discount_cents' => 0, 'permanent' => $permanent];

        if ($coupon === null) {
            return $fail('Cupom não encontrado. Confira as letras e os números.', true);
        }
        $status = self::status($coupon);
        if ($status === 'esgotado' && $ignoreLimit) {
            $status = 'ativo';
        }
        if ($status === 'desativado') {
            return $fail('Este cupom não está valendo agora.', true);
        }
        if ($status === 'expirado') {
            return $fail('Cupom expirado.', true);
        }
        if ($status === 'esgotado') {
            return $fail('Cupom esgotado: ele já foi usado o máximo de vezes.', true);
        }
        if (!$hasFixed || $subtotalCents <= 0) {
            return $fail('O cupom vale para peças com preço. As peças desta sacola têm valor a combinar na conversa.', false);
        }
        if ($coupon['min_order_cents'] !== null && $subtotalCents < $coupon['min_order_cents']) {
            return $fail('Pedido mínimo de ' . format_price($coupon['min_order_cents']) . ' para este cupom. Faltam ' . format_price($coupon['min_order_cents'] - $subtotalCents) . '.', false);
        }

        return ['ok' => true, 'error' => null, 'discount_cents' => self::discountFor($coupon, $subtotalCents), 'permanent' => false];
    }

    /**
     * Conta um uso. Atomico: so sobe se o cupom ainda vale e tem uso sobrando.
     * Chamado por finalizar.php dentro da mesma transacao do checkout_logs.
     */
    public static function claim(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE coupons SET uses = uses + 1
              WHERE id = ? AND active = 1 AND deleted = 0
                AND (expires_on IS NULL OR expires_on >= ?)
                AND (max_uses IS NULL OR uses < max_uses)'
        );
        $stmt->execute([$id, date('Y-m-d')]);
        return $stmt->rowCount() === 1;
    }

    // -----------------------------------------------------------------------
    // Painel
    // -----------------------------------------------------------------------

    /**
     * Valida o formulario curto do painel.
     *
     * @return array{code:string,discount_type:string,discount_value:int,min_order_cents:?int,expires_on:?string,max_uses:?int}
     * @throws DomainException com mensagem para a Ray
     */
    public static function parseForm(array $in): array
    {
        $code = self::normalizeCode((string) ($in['code'] ?? ''));
        if ($code === '') {
            throw new DomainException('O código precisa ter de 3 a 20 letras ou números, sem espaço (o hífen também vale).');
        }
        $type = ($in['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $rawValue = trim((string) ($in['discount_value'] ?? ''));
        if ($type === 'percent') {
            $pct = str_replace([',', '%', ' '], ['.', '', ''], $rawValue);
            if (!preg_match('/^\d{1,3}$/D', $pct) || (int) $pct < 1 || (int) $pct > 100) {
                throw new DomainException('A porcentagem precisa ser um número inteiro de 1 a 100.');
            }
            $value = (int) $pct;
        } else {
            $value = price_to_cents($rawValue);
            if ($value === null || $value < 1 || $value > self::VALUE_MAX) {
                throw new DomainException('Informe o desconto em reais, por exemplo 15,00.');
            }
        }

        $min = null;
        $rawMin = trim((string) ($in['min_order'] ?? ''));
        if ($rawMin !== '') {
            $min = price_to_cents($rawMin);
            if ($min === null || $min > self::VALUE_MAX) {
                throw new DomainException('O pedido mínimo precisa ser um valor em reais, por exemplo 100,00.');
            }
            if ($min === 0) {
                $min = null;
            }
        }
        $expires = null;
        $rawDate = trim((string) ($in['expires_on'] ?? ''));
        if ($rawDate !== '') {
            $expires = parse_date_br($rawDate);
            if ($expires === null) {
                throw new DomainException('A validade precisa ser uma data, por exemplo 30/11/2026.');
            }
            if ($expires < date('Y-m-d')) {
                throw new DomainException('A validade já passou. Escolha hoje ou uma data futura.');
            }
        }

        $maxUses = null;
        $rawUses = trim((string) ($in['max_uses'] ?? ''));
        if ($rawUses !== '') {
            if (!preg_match('/^\d{1,5}$/D', $rawUses) || (int) $rawUses < 1 || (int) $rawUses > self::USES_MAX) {
                throw new DomainException('O limite de usos precisa ser um número inteiro maior que zero.');
            }
            $maxUses = (int) $rawUses;
        }

        return ['code' => $code, 'discount_type' => $type, 'discount_value' => $value, 'min_order_cents' => $min, 'expires_on' => $expires, 'max_uses' => $maxUses];
    }

    /**
     * Cria o cupom. Codigo de um cupom excluido volta a valer como cupom novo
     * (contador zerado); codigo em uso e recusado.
     */
    public static function create(array $data): int
    {
        $existing = self::findAnyByCode($data['code']);
        if ($existing !== null && !(int) $existing['deleted']) {
            throw new DomainException('Já existe um cupom ' . $data['code'] . '. Escolha outro código.');
        }
        $params = [$data['discount_type'], $data['discount_value'], $data['min_order_cents'], $data['expires_on'], $data['max_uses']];
        if ($existing !== null) {
            $stmt = db()->prepare('UPDATE coupons SET discount_type = ?, discount_value = ?, min_order_cents = ?, expires_on = ?, max_uses = ?, uses = 0, active = 1, deleted = 0, created_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([...$params, (int) $existing['id']]);
            return (int) $existing['id'];
        }
        $stmt = db()->prepare('INSERT INTO coupons (code, discount_type, discount_value, min_order_cents, expires_on, max_uses) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$data['code'], ...$params]);
        return (int) db()->lastInsertId();
    }

    public static function setActive(int $id, bool $active): bool
    {
        $stmt = db()->prepare('UPDATE coupons SET active = ? WHERE id = ? AND deleted = 0');
        $stmt->execute([$active ? 1 : 0, $id]);
        return $stmt->rowCount() === 1 || self::find($id) !== null;
    }

    public static function softDelete(int $id): bool
    {
        $stmt = db()->prepare('UPDATE coupons SET deleted = 1, active = 0 WHERE id = ? AND deleted = 0');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }
}

// ---------------------------------------------------------------------------
// Tentativas por sessao (contra adivinhar codigos)
// ---------------------------------------------------------------------------

/**
 * true quando a sessao errou demais nos ultimos 10 minutos, ou quando o mesmo
 * endereco de rede errou demais somando todas as sessoes (um robo que descarta
 * o cookie a cada tentativa ganharia 5 chances novas por sessao).
 */
function coupon_locked(): bool
{
    $now = time();
    $fails = array_filter((array) ($_SESSION['coupon_fails'] ?? []), static fn ($t): bool => is_int($t) && $now - $t < CouponRepository::FAIL_WINDOW);
    $_SESSION['coupon_fails'] = array_values($fails);
    return count($fails) >= CouponRepository::MAX_FAILS
        || RateLimit::tooMany('cupom', CouponRepository::IP_MAX_FAILS, CouponRepository::FAIL_WINDOW);
}

/** Registra um erro (na sessao e no endereco de rede) e segura a resposta um instante. */
function coupon_register_fail(): void
{
    $fails = (array) ($_SESSION['coupon_fails'] ?? []);
    $fails[] = time();
    $_SESSION['coupon_fails'] = array_slice($fails, -CouponRepository::MAX_FAILS * 2);
    RateLimit::hit('cupom', CouponRepository::FAIL_WINDOW);
    SecurityLog::event('cupom_invalido');
    if (PHP_SAPI !== 'cli') {
        usleep(CouponRepository::FAIL_DELAY_US);
    }
}

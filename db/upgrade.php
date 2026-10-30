<?php
declare(strict_types=1);

/**
 * Evolucao do schema de um banco ja existente. Idempotente: confere com
 * SHOW COLUMNS / information_schema antes de cada ALTER, entao pode rodar de novo.
 *
 * Uso:
 *   php db/upgrade.php                 aplica (so em banco de teste)
 *   php db/upgrade.php --rollback      desfaz a parte de estoque e promocao (so em teste)
 *   php db/upgrade.php --rollback=cupons
 *                                      remove so a tabela coupons (so em teste)
 *   php db/upgrade.php --aplicar-em=<banco> --backup=<dump.sql>
 *                                      aplica num banco sem marcador de teste, depois
 *                                      de validado em copia e com dump recente
 */

const UPGRADE_STOCK_COLUMNS = [
    'stock_qty'        => 'SMALLINT UNSIGNED DEFAULT NULL',
    'show_stock'       => 'TINYINT(1) NOT NULL DEFAULT 0',
    'sold_out'         => 'TINYINT(1) NOT NULL DEFAULT 0',
    'sale_price_cents' => 'INT UNSIGNED DEFAULT NULL',
    'sale_ends_on'     => 'DATE DEFAULT NULL',
];

/** Mesma definicao de db/schema.sql (instalacao nova). */
const UPGRADE_COUPONS_TABLE = "CREATE TABLE IF NOT EXISTS coupons (
    id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20) NOT NULL,
    discount_type    ENUM('percent','fixed') NOT NULL,
    discount_value   MEDIUMINT UNSIGNED NOT NULL,
    min_order_cents  MEDIUMINT UNSIGNED DEFAULT NULL,
    expires_on       DATE DEFAULT NULL,
    max_uses         SMALLINT UNSIGNED DEFAULT NULL,
    uses             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    deleted          TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code),
    CONSTRAINT chk_coupons_value
        CHECK (discount_value > 0 AND (discount_type = 'fixed' OR discount_value <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

function upgrade_has_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function upgrade_columns(PDO $pdo, string $table): array
{
    return array_column($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

function upgrade_has_constraint(PDO $pdo, string $table, string $name): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1');
    $stmt->execute([$table, $name]);
    return $stmt->fetchColumn() !== false;
}

function upgrade_schema(PDO $pdo): void
{
    $columns = upgrade_columns($pdo, 'checkout_logs');
    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE checkout_logs ADD COLUMN status ENUM('novo','em_atendimento','concluido','cancelado') NOT NULL DEFAULT 'novo', ADD COLUMN notes VARCHAR(2000) NOT NULL DEFAULT ''");
    }

    // Recorte automatico das fotos: sem estas colunas a pagina de produto quebra.
    $imageColumns = upgrade_columns($pdo, 'product_images');
    if (!in_array('source_path', $imageColumns, true)) {
        $pdo->exec('ALTER TABLE product_images ADD COLUMN source_path VARCHAR(255) DEFAULT NULL AFTER file_path');
    }
    if (!in_array('focal_y', $imageColumns, true)) {
        $pdo->exec('ALTER TABLE product_images ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER source_path');
    }

    // Estoque e promocao: um unico ALTER com as colunas que faltarem.
    $productColumns = upgrade_columns($pdo, 'products');
    $add = [];
    $after = 'sort_order';
    foreach (UPGRADE_STOCK_COLUMNS as $name => $definition) {
        if (!in_array($name, $productColumns, true)) {
            $add[] = 'ADD COLUMN ' . $name . ' ' . $definition . ' AFTER ' . $after;
        }
        $after = $name;
    }
    if ($add !== []) {
        $pdo->exec('ALTER TABLE products ' . implode(', ', $add));
    }
    if (!upgrade_has_constraint($pdo, 'products', 'chk_products_sale_price')) {
        $pdo->exec('ALTER TABLE products ADD CONSTRAINT chk_products_sale_price CHECK (sale_price_cents IS NULL OR (price_cents IS NOT NULL AND sale_price_cents < price_cents))');
    }

    // Cupons: tabela nova, nada muda nas existentes (o cupom do pedido vai no cart_json).
    if (!upgrade_has_table($pdo, 'coupons')) {
        $pdo->exec(UPGRADE_COUPONS_TABLE);
    }
}

/** Desfaz so os cupons (a tabela e o que ela guarda; os pedidos nao mudam). */
function downgrade_coupons_schema(PDO $pdo): void
{
    if (upgrade_has_table($pdo, 'coupons')) {
        $pdo->exec('DROP TABLE coupons');
    }
}

/** Desfaz so a parte de estoque e promocao (as colunas antigas continuam). */
function downgrade_stock_schema(PDO $pdo): void
{
    if (upgrade_has_constraint($pdo, 'products', 'chk_products_sale_price')) {
        $pdo->exec('ALTER TABLE products DROP CONSTRAINT chk_products_sale_price');
    }
    $productColumns = upgrade_columns($pdo, 'products');
    $drop = [];
    foreach (array_keys(UPGRADE_STOCK_COLUMNS) as $name) {
        if (in_array($name, $productColumns, true)) {
            $drop[] = 'DROP COLUMN ' . $name;
        }
    }
    if ($drop !== []) {
        $pdo->exec('ALTER TABLE products ' . implode(', ', $drop));
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require dirname(__DIR__) . '/includes/db.php';
    $opts = getopt('', ['rollback::', 'aplicar-em:', 'backup:']);
    $isTest = preg_match('/(?:^|_)(?:test|teste|local|staging|dev)(?:_|$)/', DB_NAME) === 1;

    if (!$isTest) {
        // Banco real: exige repetir o nome do banco e apontar um dump nao vazio.
        $target = (string) ($opts['aplicar-em'] ?? '');
        $backup = (string) ($opts['backup'] ?? '');
        if ($target !== DB_NAME || $backup === '' || !is_file($backup) || filesize($backup) < 1024) {
            fwrite(STDERR, "Valide primeiro em banco de teste. Para aplicar em " . DB_NAME . ", faça um dump recente e rode:\n  php db/upgrade.php --aplicar-em=" . DB_NAME . " --backup=<caminho-do-dump.sql>\n");
            exit(1);
        }
        if (isset($opts['rollback'])) {
            fwrite(STDERR, "Rollback fora de banco de teste: restaure o dump em vez disso.\n");
            exit(1);
        }
    }

    if (isset($opts['rollback']) && $opts['rollback'] === 'cupons') {
        downgrade_coupons_schema(db());
        echo "Tabela de cupons removida de " . DB_NAME . ".
";
        exit(0);
    }
    if (isset($opts['rollback'])) {
        downgrade_stock_schema(db());
        echo "Estoque e promoção removidos do schema de " . DB_NAME . ".\n";
        exit(0);
    }
    upgrade_schema(db());
    echo "Schema de " . DB_NAME . " atualizado.\n";
}

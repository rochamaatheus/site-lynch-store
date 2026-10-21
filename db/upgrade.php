<?php
declare(strict_types=1);

function upgrade_schema(PDO $pdo): void
{
    $columns = array_column($pdo->query('SHOW COLUMNS FROM checkout_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE checkout_logs ADD COLUMN status ENUM('novo','em_atendimento','concluido','cancelado') NOT NULL DEFAULT 'novo', ADD COLUMN notes VARCHAR(2000) NOT NULL DEFAULT ''");
    }

    // Recorte automatico das fotos: sem estas colunas a pagina de produto quebra.
    $imageColumns = array_column($pdo->query('SHOW COLUMNS FROM product_images')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('source_path', $imageColumns, true)) {
        $pdo->exec('ALTER TABLE product_images ADD COLUMN source_path VARCHAR(255) DEFAULT NULL AFTER file_path');
    }
    if (!in_array('focal_y', $imageColumns, true)) {
        $pdo->exec('ALTER TABLE product_images ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER source_path');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require dirname(__DIR__) . '/includes/db.php';
    if (!preg_match('/(?:^|_)(?:test|teste|local|staging|dev)(?:_|$)/', DB_NAME)) { throw new RuntimeException('Valide primeiro em banco de teste. Atualização de banco existente exige backup e procedimento documentado.'); }
    upgrade_schema(db());
    echo "Schema de teste atualizado.\n";
}

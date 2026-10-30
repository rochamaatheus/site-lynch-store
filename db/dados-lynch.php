<?php
/**
 * Lynch Store - dados reais da loja (passados pela Ray pelo WhatsApp).
 *
 * Grava em site_settings, o mesmo lugar que admin/configuracoes.php edita.
 * Idempotente: INSERT ... ON DUPLICATE KEY UPDATE, so nestas chaves; pode rodar
 * de novo. Nao grava dominio nem e-mail (ainda nao definidos).
 *
 * Uso:
 *   php db/dados-lynch.php                      grava (so em banco de teste)
 *   php db/dados-lynch.php --conferir           mostra o que esta gravado, sem escrever
 *   php db/dados-lynch.php --aplicar-em=<banco> --backup=<dump.sql>
 *                                               grava num banco sem marcador de teste,
 *                                               depois de validado em copia e com dump recente
 *
 * Tambem e lido por db/seed.php (instalacao nova ja nasce com estes dados).
 */

declare(strict_types=1);

/** @return array<string,string> */
function lynch_store_settings(): array
{
    return [
        'store_name'       => 'Lynch Store',
        'store_slogan'     => 'Um mundo sobre medida para você',
        'store_bio'        => 'Cosmaker e Estilista, fundadora da Lynch Store.',
        // +55 47 99178-1461 com o 9 de celular.
        'whatsapp_number'  => '5547991781461',
        'instagram_url'    => 'https://instagram.com/lynchstore',
        'opening_hours'    => json_encode([
            'seg_sex' => [['09:00', '12:00'], ['14:00', '18:30']],
            'sab'     => [['09:00', '12:00']],
            'dom'     => [],
        ], JSON_THROW_ON_ERROR),
        'appointment_note' => 'Entre em contato para agendar seu horário.',
        'address_street'   => 'Avenida Júpiter, 146',
        'address_district' => 'Jardim Paraíso',
        'address_city'     => 'Joinville',
        'address_state'    => 'SC',
        'address_zip'      => '89226-600',
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require dirname(__DIR__) . '/includes/db.php';
    $opts = getopt('', ['conferir', 'aplicar-em:', 'backup:']);
    $data = lynch_store_settings();
    $pdo  = db();

    $show = static function () use ($pdo, $data): void {
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($data), '?')) . ')');
        $stmt->execute(array_keys($data));
        $now = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'setting_value', 'setting_key');
        foreach ($data as $key => $value) {
            $current = $now[$key] ?? null;
            echo str_pad($key, 18) . ($current === $value ? ' ok  ' : ' --  ') . ($current ?? '(sem valor)') . "\n";
        }
    };

    if (isset($opts['conferir'])) {
        echo 'Banco ' . DB_NAME . ":\n";
        $show();
        exit(0);
    }

    $isTest = preg_match('/(?:^|_)(?:test|teste|local|staging|dev)(?:_|$)/', DB_NAME) === 1;
    if (!$isTest) {
        $target = (string) ($opts['aplicar-em'] ?? '');
        $backup = (string) ($opts['backup'] ?? '');
        if ($target !== DB_NAME || $backup === '' || !is_file($backup) || filesize($backup) < 1024) {
            fwrite(STDERR, "Valide primeiro em banco de teste. Para gravar em " . DB_NAME . ", faça um dump recente e rode:\n  php db/dados-lynch.php --aplicar-em=" . DB_NAME . " --backup=<caminho-do-dump.sql>\n");
            exit(1);
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($data as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Nada foi gravado: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo count($data) . ' configurações da Lynch gravadas em ' . DB_NAME . ":\n";
    $show();
}

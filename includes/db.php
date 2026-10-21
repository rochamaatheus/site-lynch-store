<?php
/**
 * Lynch Store - conexao PDO (fase 1: Fundacao)
 *
 * db() retorna uma unica instancia de PDO por requisicao (singleton), criada
 * de forma preguicosa: nenhuma conexao com o MySQL e aberta enquanto ninguem
 * chamar db(). Assim paginas que nao tocam o banco nao pagam a conexao.
 *
 * Uso: require_once __DIR__ . '/db.php';
 *      $stmt = db()->prepare('SELECT * FROM products WHERE slug = ?');
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Instancia PDO compartilhada da aplicacao.
 *
 * @throws RuntimeException se a conexao falhar (mensagem detalhada so em local)
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s;port=%d',
        DB_HOST,
        DB_NAME,
        DB_CHARSET,
        DB_PORT
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // prepares reais no servidor
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
    } catch (PDOException $e) {
        // Nunca vazar credenciais/DSN para o visitante em producao.
        error_log('[Lynch Store] Falha ao conectar no banco: ' . $e->getMessage());

        $detail = APP_DEBUG
            ? $e->getMessage() . ' (DSN: ' . $dsn . ', usuario: ' . DB_USER . ')'
            : 'Verifique as credenciais no arquivo .env e se o servidor MySQL esta no ar.';

        throw new RuntimeException('Nao foi possivel conectar ao banco de dados. ' . $detail, 0, $e);
    }

    return $pdo;
}

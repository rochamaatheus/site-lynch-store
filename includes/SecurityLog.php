<?php
/**
 * Lynch Store - registro de seguranca e trilha de auditoria do painel.
 *
 * Dois destinos, cada um para um publico:
 *  - event(): arquivo JSON por linha, FORA da pasta do site (lynch_private_dir),
 *    para o Matheus investigar (login errado, CSRF invalido, cupom inexistente,
 *    upload recusado, limite estourado). Gira sozinho em 1 MB (guarda 2 antigos).
 *  - audit(): tabela audit_log, que a Ray ve em Minha conta (quem, quando, o que,
 *    de qual rede). Sem a tabela (banco sem db/upgrade.php) so o arquivo e gravado.
 *
 * LGPD: nunca senha, token, nome ou telefone de cliente. O IP vira um hash curto
 * com chave do servidor (nao da para voltar ao IP testando os 4 bilhoes de IPv4
 * sem a chave). Cada IP grava no maximo 60 linhas por hora no arquivo, para um
 * robo nao encher o disco.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/RateLimit.php';

final class SecurityLog
{
    public const MAX_BYTES = 1048576;
    public const KEEP_DAYS = 180;

    /** Eventos que a tela Minha conta sabe descrever em portugues. */
    public const LABELS = [
        'login_ok'          => 'Entrou no painel',
        'login_falha'       => 'Senha errada',
        'login_bloqueado'   => 'Tentativa bloqueada (muitos erros)',
        'login_desconhecido'=> 'Tentativa com usuário que não existe',
        'logout'            => 'Saiu do painel',
        'senha_trocada'     => 'Senha trocada',
        'sessoes_encerradas'=> 'Encerrou as sessões dos outros aparelhos',
        'sessao_expirada'   => 'Sessão expirou',
        'produto_salvo'     => 'Produto salvo',
        'produto_status'    => 'Produto tirado ou devolvido à loja',
        'produto_rapido'    => 'Alteração rápida de produto',
        'fotos'             => 'Fotos de produto alteradas',
        'categoria'         => 'Categoria alterada',
        'cupom'             => 'Cupom alterado',
        'config'            => 'Configurações salvas',
        'importacao'        => 'Planilha importada',
        'exportacao'        => 'Planilha de produtos baixada',
        'pedido'            => 'Situação de pedido alterada',
    ];

    private static ?bool $tableReady = null;

    /** Hash curto e com chave do endereco de rede (IPv6 pelo /64, como o RateLimit). */
    public static function ipHash(?string $ip = null): string
    {
        $key = 'lynch-ip|' . APP_ROOT . '|' . DB_NAME . '|' . DB_PASS;
        return substr(hash_hmac('sha256', RateLimit::clientId($ip), $key), 0, 12);
    }

    /** Evento de seguranca no arquivo privado. $data nunca deve levar segredo. */
    public static function event(string $type, array $data = []): void
    {
        try {
            if (PHP_SAPI !== 'cli' && RateLimit::throttle('log', 60, 3600) > 0) {
                return;
            }
            $dir = lynch_private_dir();
            if ($dir === null) {
                return;
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'seguranca.log';
            if (is_file($file) && (int) @filesize($file) > self::MAX_BYTES) {
                @rename($dir . DIRECTORY_SEPARATOR . 'seguranca.log.1', $dir . DIRECTORY_SEPARATOR . 'seguranca.log.2');
                @rename($file, $dir . DIRECTORY_SEPARATOR . 'seguranca.log.1');
            }
            $clean = [];
            foreach ($data as $k => $v) {
                $clean[preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k)) ?: 'x'] = self::clip(is_scalar($v) || $v === null ? (string) $v : json_encode($v), 120);
            }
            $line = json_encode([
                't'    => date('c'),
                'ev'   => preg_replace('/[^a-z0-9_]/', '', $type),
                'ip'   => self::ipHash(),
                'm'    => strtoupper(substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 7)),
                'path' => self::clip((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 80),
            ] + $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Registro nunca derruba a pagina.
        }
    }

    /** Trilha do painel (tabela audit_log) + linha no arquivo. */
    public static function audit(?int $adminId, string $event, string $detail = ''): void
    {
        self::event($event, ['admin' => $adminId, 'detalhe' => $detail]);
        if (!self::ready()) {
            return;
        }
        try {
            $pdo = db();
            $pdo->prepare('INSERT INTO audit_log (admin_id, event, detail, ip_hash) VALUES (?, ?, ?, ?)')
                ->execute([$adminId, substr(preg_replace('/[^a-z0-9_]/', '', $event) ?? '', 0, 32), self::clip($detail, 200), self::ipHash()]);
            if (random_int(1, 100) === 1) {
                $pdo->prepare('DELETE FROM audit_log WHERE created_at < ? LIMIT 5000')
                    ->execute([date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
            }
        } catch (Throwable $e) {
            error_log('[Lynch Store] audit_log: ' . $e->getMessage());
        }
    }

    /**
     * Ultimos registros para Minha conta.
     *
     * @return array<int,array{created_at:string,event:string,detail:string,ip_hash:string,admin_id:?int}>
     */
    public static function recent(int $limit = 40): array
    {
        if (!self::ready()) {
            return [];
        }
        $stmt = db()->prepare('SELECT created_at, admin_id, event, detail, ip_hash FROM audit_log ORDER BY id DESC LIMIT :n');
        $stmt->bindValue(':n', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function ready(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = db_schema_ready('audit', static fn (): bool => db()->query("SHOW TABLES LIKE 'audit_log'")->fetch() !== false);
            } catch (Throwable) {
                self::$tableReady = false;
            }
        }
        return self::$tableReady;
    }

    public static function flush(): void
    {
        self::$tableReady = null;
    }

    private static function clip(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        return mb_substr($text, 0, $max);
    }
}

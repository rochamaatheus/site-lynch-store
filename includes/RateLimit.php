<?php
/**
 * Lynch Store - limite de tentativas por endereco de rede.
 *
 * Complementa os limites que ja existem (cupom: 5 erros por sessao; login: 5
 * erros por conta). Esses dois somem quando o robo descarta o cookie ou troca
 * de usuario; este conta por IP, entao continuar tentando exige trocar de rede.
 *
 * Guarda so o horario das falhas, por 1 janela (minutos), com o IP transformado
 * em hash: nada de IP legivel em disco (LGPD: dado minimo, prazo curto, so para
 * seguranca). IPv6 conta pelo prefixo /64, que e o que um cliente costuma ter.
 *
 * Onde guarda: APCu, se a hospedagem tiver; senao arquivos na pasta temporaria
 * do PHP (fora do site), com trava de arquivo.
 *
 * Uso:
 *     if (RateLimit::tooMany('cupom', 20, 600)) { ...recusa... }
 *     RateLimit::hit('cupom', 600);   // a cada falha
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class RateLimit
{
    /** true quando este cliente ja falhou $max vezes nos ultimos $window segundos. */
    public static function tooMany(string $bucket, int $max, int $window, ?string $client = null): bool
    {
        return count(self::recent($bucket, $window, $client)) >= $max;
    }

    /** Quantas falhas este cliente tem dentro da janela. */
    public static function count(string $bucket, int $window, ?string $client = null): int
    {
        return count(self::recent($bucket, $window, $client));
    }

    /** Registra uma falha deste cliente. */
    public static function hit(string $bucket, int $window, ?string $client = null): void
    {
        $now = time();
        self::update($bucket, $client, static function (array $times) use ($now, $window): array {
            $times = array_values(array_filter($times, static fn ($t): bool => is_int($t) && $now - $t < $window));
            $times[] = $now;
            return array_slice($times, -200);
        }, $window);
    }

    /** Apaga as falhas deste cliente (ex.: depois de um login certo). */
    public static function clear(string $bucket, ?string $client = null): void
    {
        $key = self::key($bucket, $client);
        if (self::apcu()) {
            apcu_delete($key);
            return;
        }
        $file = self::file($key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Contador de janela fixa (barato: um numero por cliente e janela). Conta esta
     * requisicao e devolve 0 se ela cabe no limite, ou quantos segundos faltam
     * para a janela virar (para o Retry-After).
     */
    public static function throttle(string $bucket, int $max, int $window, ?string $client = null): int
    {
        $now = time();
        $slot = intdiv($now, max(1, $window));
        $count = 0;
        self::update($bucket . '-w' . $slot, $client, static function (array $data) use (&$count): array {
            $count = (int) ($data[0] ?? 0) + 1;
            return [$count];
        }, $window);
        return $count > $max ? max(1, ($slot + 1) * $window - $now) : 0;
    }

    /** Quantas vezes este cliente ja passou pela janela fixa atual, sem contar. */
    public static function peek(string $bucket, int $window, ?string $client = null): int
    {
        $slot = intdiv(time(), max(1, $window));
        $key = self::key($bucket . '-w' . $slot, $client);
        if (self::apcu()) {
            $data = apcu_fetch($key);
        } else {
            $file = self::file($key);
            $raw = $file !== null && is_file($file) ? @file_get_contents($file) : false;
            $data = is_string($raw) ? json_decode($raw, true) : [];
        }
        return is_array($data) ? (int) ($data[0] ?? 0) : 0;
    }

    /**
     * Limite leve das paginas publicas (anti raspagem e anti busca repetida).
     * Generoso de proposito: em rede movel com CGNAT muitas clientes saem pelo
     * mesmo IP. Valores em .env: LYNCH_RATE_PAGES e LYNCH_RATE_SEARCH no formato
     * "quantidade/segundos".
     */
    public static function guardPage(bool $search = false): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        [$max, $window] = self::limitFromEnv($search ? 'LYNCH_RATE_SEARCH' : 'LYNCH_RATE_PAGES', $search ? [120, 60] : [300, 60]);
        $wait = self::throttle($search ? 'busca' : 'pagina', $max, $window);
        if ($wait === 0) {
            return;
        }
        if (class_exists('SecurityLog', false)) {
            SecurityLog::event('limite_paginas', ['tipo' => $search ? 'busca' : 'pagina']);
        }
        http_response_code(429);
        header('Retry-After: ' . $wait);
        header('Cache-Control: no-store');
        if ($search) {
            header('Content-Type: application/json; charset=utf-8');
            echo '[]';
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="robots" content="noindex"><title>Muitos acessos</title><h1>Muitos acessos em pouco tempo</h1><p>Espere ' . (int) $wait . ' segundos e atualize a página. Se precisar, chame a loja no WhatsApp.</p></html>';
        exit;
    }

    /** @return array{0:int,1:int} */
    private static function limitFromEnv(string $name, array $default): array
    {
        $raw = function_exists('env') ? (string) env($name, '') : '';
        if (preg_match('~^(\d{1,6})/(\d{1,5})$~D', $raw, $m) && (int) $m[1] > 0 && (int) $m[2] > 0) {
            return [(int) $m[1], (int) $m[2]];
        }
        return $default;
    }

    /**
     * Identificador do cliente: IPv4 inteiro, IPv6 pelo /64. Sem REMOTE_ADDR
     * (linha de comando) vira 'cli'.
     */
    public static function clientId(?string $ip = null): string
    {
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return 'cli';
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'invalido';
        }
        if (strlen($bin) === 16) {
            $bin = substr($bin, 0, 8);
        }
        return bin2hex($bin);
    }

    // -----------------------------------------------------------------------

    /** @return array<int,int> horarios das falhas ainda dentro da janela */
    private static function recent(string $bucket, int $window, ?string $client): array
    {
        $key = self::key($bucket, $client);
        $now = time();
        if (self::apcu()) {
            $times = apcu_fetch($key);
        } else {
            $file = self::file($key);
            $raw = $file !== null && is_file($file) ? @file_get_contents($file) : false;
            $times = is_string($raw) ? json_decode($raw, true) : [];
        }
        return array_values(array_filter(is_array($times) ? $times : [], static fn ($t): bool => is_int($t) && $now - $t < $window));
    }

    private static function update(string $bucket, ?string $client, callable $change, int $window): void
    {
        $key = self::key($bucket, $client);
        if (self::apcu()) {
            $times = apcu_fetch($key);
            apcu_store($key, $change(is_array($times) ? $times : []), $window);
            return;
        }
        $file = self::file($key);
        if ($file === null) {
            return;   // sem pasta gravavel: segue sem o limite por IP (os outros continuam)
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($handle);
            $times = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $json = json_encode($change(is_array($times) ? $times : []));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if (random_int(1, 50) === 1) {
            self::purge(dirname($file));
        }
    }

    private static function key(string $bucket, ?string $client): string
    {
        $bucket = preg_replace('/[^a-z0-9_-]/', '', strtolower($bucket)) ?: 'geral';
        // O hash leva o caminho da instalacao: duas lojas no mesmo servidor nao se misturam.
        return 'lynch-rl:' . $bucket . ':' . hash('sha256', APP_ROOT . '|' . DB_NAME . '|' . ($client ?? self::clientId()));
    }

    private static function apcu(): bool
    {
        return PHP_SAPI !== 'cli' && function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled();
    }

    private static function file(string $key): ?string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'lynch-rl-' . substr(hash('sha256', APP_ROOT . '|' . DB_NAME), 0, 12);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $dir = '';
            }
        }
        return $dir === '' ? null : $dir . DIRECTORY_SEPARATOR . substr(str_replace(':', '-', $key), 0, 200) . '.json';
    }

    /** Remove arquivos sem uso ha mais de um dia. */
    private static function purge(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $old) {
            if (@filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }
}

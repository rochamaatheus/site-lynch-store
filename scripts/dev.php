<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
$env = $root . '/.env.test.local';
if (!is_file($env)) { throw new RuntimeException('Prepare primeiro o ambiente de teste conforme README.md.'); }
putenv('LYNCH_ENV_FILE=' . $env);
$process = proc_open([PHP_BINARY, '-S', 'localhost:8000', 'scripts/serve.php'], [STDIN, STDOUT, STDERR], $pipes, $root);
exit(proc_close($process));

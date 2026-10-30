<?php
/** Mensagens da sessao, mostradas como aviso acima da barra inferior. */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';

foreach (flash_get() as $flashItem):
    $flashType = in_array($flashItem['type'] ?? '', ['success', 'error', 'info'], true) ? $flashItem['type'] : 'info';
?>
<div class="flash <?= e($flashType) ?>" role="status"><?= e((string) ($flashItem['message'] ?? '')) ?></div>
<?php endforeach; ?>

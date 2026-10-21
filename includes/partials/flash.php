<?php
/**
 * Lynch Store - mensagens flash (fase 3: Catalogo publico)
 *
 * Renderiza (e limpa) o que estiver pendente na sessao. Nao imprime nada
 * quando nao ha mensagem - e nem abre sessao a toa: flash_get() so toca a
 * sessao se o visitante ja tiver cookie de sessao.
 *
 * As fases 4 (carrinho) e 6 (admin) sao as maiores usuarias:
 *     flash_set('success', 'Produto adicionado ao carrinho.');
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';

$flashMessages = flash_get();

if ($flashMessages === []) {
    return;
}
?>
<div class="container flash-wrap">
  <?php foreach ($flashMessages as $flashItem): ?>
    <?php
      $flashType = (string) ($flashItem['type'] ?? 'info');
      if (!in_array($flashType, ['success', 'error', 'info'], true)) {
          $flashType = 'info';
      }
    ?>
    <div class="flash <?= e($flashType) ?>" role="status">
      <span><?= e((string) ($flashItem['message'] ?? '')) ?></span>
    </div>
  <?php endforeach; ?>
</div>

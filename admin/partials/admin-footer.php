<?php
/**
 * Lynch Store - rodape das paginas do painel
 * Fecha o que admin-header.php abriu (main > .container) e deixa prontos os
 * tres pecas de retorno usadas pelo assets/js/admin.js:
 *   - area de avisos rapidos (toast), ja presente e "viva" para leitor de tela;
 *   - aviso de sem internet;
 *   - folha de confirmacao para acoes destrutivas (sem JS, vale o envio direto).
 * Nao e uma pagina: so pode ser incluido.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}
?>
  </div>
</main>
<footer class="admin-foot">
  <div class="container">
    Lynch Store &middot; painel interno &middot; <?= date('Y') ?>
  </div>
</footer>
<div class="toast-zone" role="status" aria-live="polite" data-toast-zone></div>
<div class="offline" role="alert" data-offline hidden>Sem internet. O que você tocar agora não será salvo até a conexão voltar.</div>
<dialog class="sheet" data-sheet aria-labelledby="sheet-msg">
  <form method="dialog" class="sheet-body">
    <p class="sheet-msg" id="sheet-msg" data-sheet-msg></p>
    <div class="sheet-actions">
      <button class="btn ghost" type="submit" value="cancel" data-sheet-cancel>Cancelar</button>
      <button class="btn danger-solid" type="submit" value="ok" data-sheet-ok>Confirmar</button>
    </div>
  </form>
</dialog>
</body>
</html>

<?php
/**
 * Lynch Store - rodape das paginas do painel (fase 5)
 * Fecha o que admin-header.php abriu (main > .container).
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
</body>
</html>

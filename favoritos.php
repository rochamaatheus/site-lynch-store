<?php
/**
 * Favoritos: a lista fica no aparelho (localStorage). A pagina recebe os ids por
 * GET (?ids=1,2,3) e devolve os cards prontos; nada e gravado no servidor.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/ProductRepository.php';

$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))))), 0, 60);

if (isset($_GET['ids'])) {
    header('X-Robots-Tag: noindex');
    require_once __DIR__ . '/includes/Cart.php';
    csrf_token();
    foreach ($ids !== [] ? ProductRepository::findActiveByIds($ids) : [] as $product) {
        include __DIR__ . '/includes/partials/product-card.php';
    }
    exit;
}

$pageTitle       = 'Favoritos';
$pageNoIndex     = true;
$pageDescription = 'As peças que você guardou na Lynch Store.';

require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap shop">
  <h1 class="page-title">Favoritos</h1>
  <p class="shop-count">Ficam guardados neste aparelho.</p>
  <div class="grid" data-favs-grid></div>
  <div class="bag-empty" data-favs-empty>
    <?= sunflower(0, 'bag-empty-flower') ?>
    <h2>Nada guardado ainda</h2>
    <p>Toque no coração de uma peça para encontrá-la aqui depois.</p>
    <a class="btn ink" href="<?= e(url_catalog()) ?>">Ver as peças</a>
  </div>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

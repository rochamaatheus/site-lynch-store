<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
http_response_code(404);
$pageTitle = 'Página não encontrada'; $pageNoIndex = true;
require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap bag-empty"><?= sunflower(0, 'bag-empty-flower') ?><h1 class="page-title">Essa página saiu da arara</h1><p>O endereço pode ter mudado. As peças continuam na loja.</p><a class="btn ink" href="<?= e(url_catalog()) ?>">Ver as peças</a></section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

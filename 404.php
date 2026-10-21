<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
http_response_code(404);
$pageTitle = 'Página não encontrada'; $pageNoIndex = true;
require __DIR__ . '/includes/partials/header.php';
?>
<section class="container errorpage"><h1>Essa página não está por aqui</h1><p>O endereço pode ter mudado. Encontre sua próxima peça no catálogo.</p><a class="btn primary" href="<?= e(base_url()) ?>">Voltar para a loja</a></section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

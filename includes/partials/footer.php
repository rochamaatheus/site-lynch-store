<?php
/**
 * Rodape, barra inferior do celular, gavetas (sacola e busca) e scripts.
 * Fecha o <main> aberto por header.php.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/SettingsRepository.php';

$footWa        = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$footInstagram = setting('instagram_url');
$footEmail     = setting('contact_email');
$footAbout     = setting('footer_about', 'Moda geek, kawaii e cosplay para vestir o que você ama. Fale com a gente sobre a sua próxima peça.');
$tabCurrent    = static fn (string $page): string => $navPath === $page ? ' aria-current="page"' : '';
?>
</main>

<footer class="foot" id="contato">
  <?= sunflower(null, 'foot-flower') ?>
  <div class="wrap foot-grid">
    <div>
      <p class="foot-name">Lynch Store</p>
      <p><?= e($footAbout) ?></p>
    </div>
    <nav aria-label="Rodapé">
      <h2>Comprar</h2>
      <a href="<?= e(url_catalog()) ?>">Toda a loja</a>
      <a href="<?= e(url_catalog(['mode' => 'pronta_entrega'])) ?>">Pronta entrega</a>
      <a href="<?= e(url_catalog(['mode' => 'sob_encomenda'])) ?>">Sob encomenda</a>
      <a href="<?= e(base_url('informacoes.php')) ?>">Como comprar e privacidade</a>
    </nav>
    <div>
      <h2>Falar com a loja</h2>
      <?php if ($footWa !== ''): ?><a href="<?= e($footWa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php else: ?><span>WhatsApp em breve</span><?php endif; ?>
      <?php if ($footInstagram !== ''): ?><a href="<?= e($footInstagram) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
      <?php if ($footEmail !== ''): ?><a href="mailto:<?= e($footEmail) ?>">E-mail</a><?php endif; ?>
    </div>
  </div>
  <p class="wrap foot-note">&copy; <?= e(date('Y')) ?> Lynch Store. Fotos reais do acervo.</p>
</footer>

<?php if (!$isProductPage): ?>
<nav class="tabs" aria-label="Atalhos da loja">
  <a href="<?= e(base_url()) ?>"<?= $isHome ? ' aria-current="page"' : '' ?>><?= icon('home') ?>Início</a>
  <a href="<?= e(url_catalog()) ?>"<?= $tabCurrent('loja.php') ?>><?= icon('shop') ?>Loja</a>
  <a href="<?= e(url_catalog([], 'busca')) ?>" data-open-search><?= icon('search') ?>Buscar</a>
  <a href="<?= e(base_url('favoritos.php')) ?>"<?= $tabCurrent('favoritos.php') ?>><?= icon('heart') ?>Favoritos</a>
  <a href="<?= e(base_url('carrinho.php')) ?>"<?= $isBagPage ? ' aria-current="page"' : ' data-open-bag' ?>><?= bag_bloom(cart_count()) ?>Sacola</a>
</nav>
<?php endif; ?>

<dialog class="sheet" id="sacola" aria-labelledby="sacola-title">
  <div class="sheet-grip" data-sheet-grip aria-hidden="true"></div>
  <div class="sheet-head">
    <h2 id="sacola-title">Sua sacola</h2>
    <form method="dialog"><button class="round plain" aria-label="Fechar sacola"><?= icon('close') ?></button></form>
  </div>
  <div class="sheet-body" data-bag-body></div>
</dialog>

<dialog class="sheet sheet-search" id="busca-sheet" aria-label="Buscar na loja">
  <form class="searchbox" action="<?= e(url_catalog()) ?>" method="get" role="search">
    <?= icon('search') ?>
    <label class="sr" for="busca-campo">Buscar na loja</label>
    <input id="busca-campo" name="busca" type="search" maxlength="80" placeholder="Anime, banda, personagem" autocomplete="off" data-search-input>
    <button class="round plain" type="button" data-sheet-close aria-label="Fechar busca"><?= icon('close') ?></button>
  </form>
  <div class="search-out" data-search-out aria-live="polite">
    <div class="pills">
      <?php foreach ($navCategories as $navCat): ?>
      <a class="pill" href="<?= e(url_catalog(['categoria' => $navCat['slug']])) ?>"><?= e($navCat['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</dialog>

<div class="snack" data-snack role="status" hidden><span data-snack-text></span><a href="<?= e(base_url('carrinho.php')) ?>" data-open-bag>Ver sacola</a></div>

<script src="<?= e(asset_url('assets/js/loja.js')) ?>" defer></script>
</body>
</html>

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
require_once dirname(__DIR__) . '/StoreInfo.php';

$footWa        = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$footInstagram = setting('instagram_url');
$footEmail     = setting('contact_email');
$footAbout     = setting('footer_about', 'Moda geek, kawaii e cosplay para vestir o que você ama. Fale com a gente sobre a sua próxima peça.');
$footName      = store_name();
$footSlogan    = setting('store_slogan');
$footBio       = setting('store_bio');
$footHandle    = instagram_handle($footInstagram);
$footPhone     = $footWa !== '' ? whatsapp_display() : '';
$footHours     = store_hours_lines();
$footBooking   = setting('appointment_note');
$footAddress   = store_address_lines();
$footMap       = store_maps_url();
$tabCurrent    = static fn (string $page): string => $navPath === $page ? ' aria-current="page"' : '';
?>
</main>

<footer class="foot" id="contato">
  <?= sunflower(null, 'foot-flower') ?>
  <div class="wrap foot-grid">
    <div class="foot-brand">
      <p class="foot-name"><?= e($footName) ?></p>
      <?php if ($footSlogan !== ''): ?><p class="foot-slogan"><?= e($footSlogan) ?></p><?php endif; ?>
      <p><?= e($footAbout) ?></p>
      <?php if ($footBio !== ''): ?><p class="foot-bio"><?= e($footBio) ?></p><?php endif; ?>
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
      <?php if ($footWa !== ''): ?><a href="<?= e($footWa) ?>" target="_blank" rel="noopener">WhatsApp <?= e($footPhone) ?></a><?php else: ?><span>WhatsApp em breve</span><?php endif; ?>
      <?php if ($footInstagram !== ''): ?><a href="<?= e($footInstagram) ?>" target="_blank" rel="noopener">Instagram <?= e($footHandle) ?></a><?php endif; ?>
      <?php if ($footEmail !== ''): ?><a href="mailto:<?= e($footEmail) ?>">E-mail</a><?php endif; ?>
      <?php if ($footAddress !== []): ?>
      <address class="foot-address"><?= implode('<br>', array_map('e', $footAddress)) ?></address>
      <?php if ($footMap !== ''): ?><a href="<?= e($footMap) ?>" target="_blank" rel="noopener">Como chegar</a><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($footHours !== [] || $footBooking !== ''): ?>
    <div>
      <h2>Atendimento</h2>
      <?php if ($footHours !== []): ?>
      <dl class="foot-hours">
        <?php foreach ($footHours as [$footDay, $footTime]): ?>
        <div><dt><?= e($footDay) ?></dt><dd><?= e($footTime) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php endif; ?>
      <?php if ($footBooking !== ''): ?><p class="foot-booking"><b>Atendimento com horário marcado.</b> <?= e($footBooking) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <p class="wrap foot-note">&copy; <?= e(date('Y')) ?> <?= e($footName) ?>. Fotos reais do acervo.</p>
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
    <input id="busca-campo" name="busca" type="search" maxlength="80" placeholder="Anime, banda, personagem" autocomplete="off" enterkeyhint="search" data-search-input>
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

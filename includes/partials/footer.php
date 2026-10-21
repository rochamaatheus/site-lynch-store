<?php
/**
 * Lynch Store - rodape do site publico (fase 3: Catalogo publico)
 *
 * Fecha o <main> aberto por includes/partials/header.php, imprime o rodape
 * (navegacao + contato vindos de site_settings) e carrega os scripts.
 *
 * Variaveis opcionais que a pagina pode definir ANTES do include:
 *   $pageScripts  array<string>  caminhos extras de JS (ex: 'assets/js/gallery.js')
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/SettingsRepository.php';

$pageScripts = isset($pageScripts) && is_array($pageScripts) ? $pageScripts : [];

$footWa        = whatsapp_link(setting('whatsapp_message_intro', 'Olá! Vim pelo site da Lynch Store.'));
$footInstagram = setting('instagram_url');
$footEmail     = setting('contact_email');
$footAbout     = setting(
    'footer_about',
    'Moda geek, kawaii e cosplay para vestir o que você ama. Explore o acervo e converse com a gente sobre sua próxima peça.'
);
?>
</main>

<footer id="contato">
  <!-- Fase 7: o rodape usa o container ESTREITO (.inset, 860px), nao o shell
       largo - tres colunas de links espalhadas por 1180px ficavam soltas. -->
  <div class="inset foot">
    <div>
      <div class="logo">
        <span class="mark"><img src="<?= e(asset_url('assets/lynch-logo.webp')) ?>" alt="Lynch Store" width="42" height="42"></span>
        <span><b>Lynch Store</b><small>vendas pelo WhatsApp</small></span>
      </div>
      <p><?= e($footAbout) ?></p>
    </div>
    <div>
      <h3>Navegar</h3>
      <a href="<?= e(base_url('index.php')) ?>#produtos">Produtos</a>
      <a href="<?= e(base_url('index.php')) ?>#colecoes">Coleções</a>
      <a href="<?= e(base_url('index.php')) ?>#brecho">Brechó</a>
      <a href="<?= e(base_url('informacoes.php')) ?>">Como comprar e privacidade</a>
    </div>
    <div>
      <h3>Contato</h3>
      <?php if ($footWa !== ''): ?>
        <a href="<?= e($footWa) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <?php else: ?>
        <span title="Configuração pendente" style="display:block;padding:10px 0;opacity:.55">WhatsApp (em breve)</span>
      <?php endif; ?>
      <?php if ($footInstagram !== ''): ?>
        <a href="<?= e($footInstagram) ?>" target="_blank" rel="noopener">Instagram</a>
      <?php endif; ?>
      <?php if ($footEmail !== ''): ?>
        <a href="mailto:<?= e($footEmail) ?>">E-mail</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="inset">
    <p class="foot-note">&copy; <?= e(date('Y')) ?> Lynch Store. Fotos reais do acervo da loja.</p>
  </div>
  <nav class="mobile-dock" aria-label="Atalhos da loja">
    <a href="<?= e(base_url()) ?>#produtos"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10l9-7 9 7M5 9v12h14V9M9 21v-7h6v7"/></svg>Catálogo</a>
    <a href="<?= e(base_url()) ?>#colecoes"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Coleções</a>
    <a href="<?= e(base_url('carrinho.php')) ?>"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8zM9 8a3 3 0 0 1 6 0"/></svg>Carrinho<?= cart_count() ? ' (' . e(cart_count()) . ')' : '' ?></a>
  </nav>
</footer>

<script src="<?= e(asset_url('assets/js/main.js')) ?>" defer></script>
<?php foreach ($pageScripts as $pageScript): ?>
<script src="<?= e(asset_url((string) $pageScript)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>

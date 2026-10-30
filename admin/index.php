<?php
/**
 * Lynch Store - dashboard do painel
 *
 * Fase 5: esqueleto de boas-vindas.
 * Fase 6: virou a porta de entrada do CRUD - numeros do catalogo, atalhos para
 * cada area e os avisos do que ainda falta a Ray configurar (numero de
 * WhatsApp, produtos sem foto).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';
require_once dirname(__DIR__) . '/includes/SettingsRepository.php';

$who = (string) ($admin['display_name'] ?? '') !== ''
    ? (string) $admin['display_name']
    : (string) $admin['username'];

$totalProdutos = ProductRepository::countAll();
$ativos        = ProductRepository::countAll(['status' => 'ativo']);
$inativos      = $totalProdutos - $ativos;
$categorias    = CategoryRepository::listAll();
$catAtivas     = 0;
foreach ($categorias as $c) {
    if ($c['active']) {
        $catAtivas++;
    }
}

$whats = whatsapp_number();

// Produtos ativos sem nenhuma foto: aparecem na loja com a imagem padrao da
// marca. Nao e erro, mas e a primeira coisa que a Ray vai querer resolver.
$semFoto = (int) db()->query('SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)')->fetchColumn();
$pedidosNovos = (int) db()->query("SELECT COUNT(*) FROM checkout_logs WHERE status='novo'")->fetchColumn();

$base      = APP_BASE_URL;
$pageTitle = 'Dashboard';
$navActive = 'dashboard';

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Painel</p>
    <h1>Bem-vinda, <?= e($who) ?>!</h1>
    <?php if (!empty($admin['last_login_at'])): ?>
      <p class="muted">
        Último acesso: <?= e(date('d/m/Y H:i', (int) strtotime((string) $admin['last_login_at']))) ?>
      </p>
    <?php endif; ?>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(base_url('admin/pedidos.php')) ?>"><?= $pedidosNovos ?> pedidos novos</a>
    <a class="btn" href="<?= e(base_url('admin/importar.php')) ?>">Importar planilha</a>
    <a class="btn primary" href="<?= e($base) ?>/admin/produto-form.php">+ Novo produto</a>
  </div>
</div>

<?php if ($pedidosNovos > 0): ?>
  <div class="flash info" role="status">
    <b>Você tem <?= $pedidosNovos ?> pedido<?= $pedidosNovos === 1 ? '' : 's' ?> novo<?= $pedidosNovos === 1 ? '' : 's' ?> para atender.</b>
    <a href="<?= e(base_url('admin/pedidos.php')) ?>"><b>Ver pedidos &rarr;</b></a>
  </div>
<?php endif; ?>

<?php if ($whats === ''): ?>
  <div class="flash error" role="alert">
    <b>Falta o número de WhatsApp.</b> Sem ele, o botão "Finalizar pedido" fica desativado
    e nenhum pedido chega até você.
    <a href="<?= e($base) ?>/admin/configuracoes.php"><b>Configurar agora &rarr;</b></a>
  </div>
<?php endif; ?>

<?php if ($semFoto > 0): ?>
  <div class="flash info">
    <?= $semFoto ?> produto<?= $semFoto === 1 ? '' : 's' ?> ativo<?= $semFoto === 1 ? '' : 's' ?>
    ainda sem foto. Na loja <?= $semFoto === 1 ? 'ele aparece' : 'eles aparecem' ?>
    com a imagem padrão da marca.
    <a href="<?= e($base) ?>/admin/produtos.php"><b>Ver produtos &rarr;</b></a>
  </div>
<?php endif; ?>

<div class="card">
  <h2 class="sec">O catálogo hoje</h2>
  <div class="stats">
    <div class="stat">
      <span class="lbl">Produtos na loja</span>
      <p class="stat-num"><?= $ativos ?></p>
      <span class="hint"><?= $inativos ?> desativado<?= $inativos === 1 ? '' : 's' ?> (não aparecem para o cliente)</span>
    </div>
    <div class="stat">
      <span class="lbl">Categorias ativas</span>
      <p class="stat-num"><?= $catAtivas ?></p>
      <span class="hint"><?= count($categorias) ?> cadastrada<?= count($categorias) === 1 ? '' : 's' ?> no total</span>
    </div>
    <div class="stat">
      <span class="lbl">WhatsApp</span>
      <p class="stat-text">
        <?= $whats === '' ? '<span class="badge off">não configurado</span>' : e($whats) ?>
      </p>
      <span class="hint">Número que recebe os pedidos</span>
    </div>
  </div>
</div>

<div class="card">
  <h2 class="sec">Atalhos</h2>
  <div class="form-actions flat">
    <a class="btn" href="<?= e($base) ?>/admin/produtos.php">Produtos</a>
    <a class="btn" href="<?= e($base) ?>/admin/categorias.php">Categorias</a>
    <a class="btn" href="<?= e($base) ?>/admin/configuracoes.php">Configurações</a>
    <a class="btn ghost" target="_blank" rel="noopener" href="<?= e($base) ?>/index.php">Ver a loja &nearr;</a>
    <a class="btn ghost spacer" href="<?= e($base) ?>/admin/logout.php">Sair do painel</a>
  </div>
</div>
<?php
require __DIR__ . '/partials/admin-footer.php';

<?php
/**
 * Lynch Store - inicio do painel
 *
 * Tela de "o que preciso fazer agora", pensada para o celular: cada pendencia
 * e um cartao grande e tocavel que leva a lista ja filtrada (pedidos novos,
 * esgotados, estoque baixo, promocoes que acabam logo, produtos sem foto,
 * WhatsApp faltando). Depois, atalhos grandes e um resumo do catalogo.
 * Pagina so de leitura.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/SettingsRepository.php';

$who = (string) ($admin['display_name'] ?? '') !== ''
    ? (string) $admin['display_name']
    : (string) $admin['username'];

$low        = low_stock_threshold();
$stockReady = ProductRepository::stockReady();
$counts     = ProductRepository::adminTabCounts(ProductRepository::adminFiltersFromQuery([]), $low);
$ativos     = ProductRepository::countAll(['status' => 'ativo']);
$foraLoja   = $counts['todos'] - $ativos;
$whats      = whatsapp_number();

$pdo = db();
$pedidosNovos = (int) $pdo->query("SELECT COUNT(*) FROM checkout_logs WHERE status='novo'")->fetchColumn();

// Produtos na loja sem nenhuma foto: aparecem com a imagem padrao da marca.
$semFoto = (int) $pdo->query('SELECT COUNT(*) FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)')->fetchColumn();
$semFotoLista = $semFoto > 0
    ? $pdo->query('SELECT p.id, p.name FROM products p WHERE p.active=1 AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id=p.id) ORDER BY p.id DESC LIMIT 4')->fetchAll()
    : [];

// Promocoes que terminam nos proximos 7 dias (inclui hoje).
$promoFim = [];
if ($stockReady) {
    $stmt = $pdo->prepare('SELECT id, name, sale_ends_on FROM products WHERE active=1 AND sale_price_cents IS NOT NULL AND sale_ends_on BETWEEN ? AND ? ORDER BY sale_ends_on, id LIMIT 6');
    $stmt->execute([date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))]);
    $promoFim = $stmt->fetchAll();
}

$plural = static fn (int $n, string $one, string $many): string => $n === 1 ? $one : $many;
$todo = [];
if ($whats === '') {
    $todo[] = ['alert', 'Falta o número de WhatsApp', 'Sem ele, nenhum pedido chega até você.', base_url('admin/configuracoes.php'), null];
}
if ($pedidosNovos > 0) {
    $todo[] = ['hot', $pedidosNovos . ' ' . $plural($pedidosNovos, 'pedido novo', 'pedidos novos'), 'Toque para ver e responder no WhatsApp.', base_url('admin/pedidos.php') . '?situacao=novo', $pedidosNovos];
}
if ($stockReady && $counts['esgotado'] > 0) {
    $n = $counts['esgotado'];
    $todo[] = ['', $n . ' ' . $plural($n, 'produto esgotado', 'produtos esgotados'), 'Repor estoque ou deixar como está.', base_url('admin/produtos.php') . '?aba=esgotado', $n];
}
if ($stockReady && $counts['baixo'] > 0) {
    $n = $counts['baixo'];
    $todo[] = ['', $n . ' com estoque baixo', 'Com ' . $low . ' ' . $plural($low, 'peça', 'peças') . ' ou menos.', base_url('admin/produtos.php') . '?aba=baixo', $n];
}
if ($promoFim !== []) {
    $n = count($promoFim);
    $todo[] = ['', $n . ' ' . $plural($n, 'promoção acaba', 'promoções acabam') . ' em até 7 dias', implode(', ', array_map(static fn ($r) => $r['name'] . ' (' . format_date_br($r['sale_ends_on']) . ')', array_slice($promoFim, 0, 3))), base_url('admin/produtos.php') . '?aba=promocao', $n];
}

$base      = APP_BASE_URL;
$pageTitle = 'Início';
$navActive = 'dashboard';

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <h1>Olá, <?= e($who) ?>!</h1>
    <p class="muted"><?= $todo === [] && $semFoto === 0 ? 'Tudo em dia por aqui.' : 'Veja o que precisa da sua atenção.' ?></p>
  </div>
</div>

<section aria-labelledby="h-agora">
  <h2 class="list-title" id="h-agora">Para fazer agora</h2>
  <?php if ($todo === [] && $semFoto === 0): ?>
    <div class="card"><div class="empty-state">Nenhuma pendência agora. Quando chegar pedido ou algo esgotar, aparece aqui.</div></div>
  <?php else: ?>
    <ul class="todo">
      <?php foreach ($todo as [$kind, $title, $help, $href, $num]): ?>
        <li>
          <a class="todo-item<?= $kind !== '' ? ' is-' . $kind : '' ?>" href="<?= e($href) ?>">
            <?php if ($num !== null): ?><span class="todo-num" aria-hidden="true"><?= $num ?></span><?php endif; ?>
            <span class="todo-txt"><b><?= e($title) ?></b><small><?= e($help) ?></small></span>
            <span class="todo-go" aria-hidden="true">&rsaquo;</span>
          </a>
        </li>
      <?php endforeach; ?>
      <?php if ($semFoto > 0): ?>
        <li class="todo-item todo-photos">
          <span class="todo-num" aria-hidden="true"><?= $semFoto ?></span>
          <span class="todo-txt">
            <b><?= $semFoto ?> <?= $plural($semFoto, 'produto sem foto', 'produtos sem foto') ?></b>
            <small>Na loja <?= $plural($semFoto, 'ele aparece', 'eles aparecem') ?> com a imagem padrão da marca.</small>
            <span class="todo-links">
              <?php foreach ($semFotoLista as $sf): ?>
                <a class="btn small ghost" href="<?= e($base) ?>/admin/produto-imagens.php?id=<?= (int) $sf['id'] ?>"><span>Pôr foto: <?= e(excerpt((string) $sf['name'], 26)) ?></span></a>
              <?php endforeach; ?>
            </span>
          </span>
        </li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
</section>

<section aria-labelledby="h-atalhos">
  <h2 class="list-title" id="h-atalhos">Atalhos</h2>
  <div class="shortcuts">
    <a class="shortcut primary" href="<?= e($base) ?>/admin/produto-form.php"><span aria-hidden="true">+</span> Novo produto</a>
    <a class="shortcut" href="<?= e($base) ?>/admin/cupons.php#novo"><span aria-hidden="true">+</span> Novo cupom</a>
    <a class="shortcut" href="<?= e($base) ?>/admin/pedidos.php">Ver pedidos</a>
    <a class="shortcut" href="<?= e($base) ?>/index.php" target="_blank" rel="noopener">Ver a loja<span class="sr"> (abre em outra aba)</span></a>
  </div>
</section>

<section aria-labelledby="h-resumo">
  <h2 class="list-title" id="h-resumo">A loja hoje</h2>
  <div class="stats">
    <a class="stat" href="<?= e($base) ?>/admin/produtos.php?status=ativo">
      <span class="stat-num"><?= $ativos ?></span>
      <span class="lbl"><?= $plural($ativos, 'produto na loja', 'produtos na loja') ?></span>
    </a>
    <a class="stat" href="<?= e($base) ?>/admin/produtos.php?status=inativo">
      <span class="stat-num"><?= $foraLoja ?></span>
      <span class="lbl">fora da loja</span>
    </a>
    <?php if ($stockReady): ?>
    <a class="stat" href="<?= e($base) ?>/admin/produtos.php?aba=promocao">
      <span class="stat-num"><?= $counts['promocao'] ?></span>
      <span class="lbl">em promoção</span>
    </a>
    <?php endif; ?>
    <a class="stat" href="<?= e($base) ?>/admin/produtos.php?aba=destaque">
      <span class="stat-num"><?= $counts['destaque'] ?></span>
      <span class="lbl">em destaque</span>
    </a>
  </div>
  <?php if (!empty($admin['last_login_at'])): ?>
    <p class="note">Último acesso: <?= e(date('d/m/Y \à\s H:i', (int) strtotime((string) $admin['last_login_at']))) ?></p>
  <?php endif; ?>
</section>
<?php
require __DIR__ . '/partials/admin-footer.php';

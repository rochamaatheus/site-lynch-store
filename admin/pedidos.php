<?php
/**
 * Lynch Store - pedidos (registros do clique em "Finalizar pelo WhatsApp")
 *
 * Lista em cartoes com filtro por situacao (?situacao=novo ...) e paginacao
 * por id (?before=N). Detalhe (?id=N): itens, totais, cupom e a situacao em
 * botoes grandes; cada botao grava a situacao junto com a observacao.
 * POST com CSRF, sempre terminando em redirect (303).
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
$admin = require_login();
require_once dirname(__DIR__) . '/includes/OrderRepository.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$id = max(0, (int) ($_GET['id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { flash_set('error', 'A página ficou aberta por muito tempo. Atualize e tente de novo.'); }
    else {
        try {
            $before = OrderRepository::find($id);
            if (!$before) { throw new InvalidArgumentException('Pedido não encontrado.'); }
            $newStatus = (string) ($_POST['status'] ?? '');
            OrderRepository::save($id, $newStatus, (string) ($_POST['notes'] ?? ''));
            SecurityLog::audit((int) $admin['id'], 'pedido', 'pedido #' . $id . ': ' . $newStatus);
            flash_set('success', $newStatus !== $before['status']
                ? 'Pedido #' . $id . ' agora está como "' . (OrderRepository::STATUSES[$newStatus] ?? $newStatus) . '".'
                : 'Observação do pedido #' . $id . ' salva.');
        } catch (InvalidArgumentException $e) { flash_set('error', $e->getMessage()); }
    }
    header('Location: ' . base_url('admin/pedidos.php') . '?id=' . $id, true, 303); exit;
}

/** Itens, cupom e totais do snapshot (formato antigo: so a lista; novo: objeto). */
function order_snapshot(string $json): array
{
    $snap = json_decode($json, true) ?: [];
    $items = isset($snap['items']) && is_array($snap['items']) ? $snap['items'] : (array_is_list($snap) ? $snap : []);
    return [
        'items'  => $items,
        'coupon' => isset($snap['coupon']) && is_array($snap['coupon']) ? $snap['coupon'] : null,
        'subtotal_cents' => (int) ($snap['subtotal_cents'] ?? 0),
        'direct' => !empty($snap['direct']),
    ];
}

$statusClass = ['novo' => 'hot', 'em_atendimento' => 'sun', 'concluido' => 'on', 'cancelado' => 'off'];
$situacao = is_string($_GET['situacao'] ?? null) && isset(OrderRepository::STATUSES[$_GET['situacao']]) ? $_GET['situacao'] : '';

$order = $id ? OrderRepository::find($id) : null;
if ($id && !$order) { http_response_code(404); }

$orders = [];
$porSituacao = [];
$hasMore = false;
if (!$id) {
    $beforeId = max(0, (int) ($_GET['before'] ?? 0));
    // Lista do painel: mesma pagina por id (keyset) do OrderRepository::list,
    // com o filtro de situacao e o cart_json para mostrar os nomes das pecas.
    $sql = 'SELECT id,item_count,total_cents,has_price_on_request,created_at,status,cart_json FROM checkout_logs';
    $where = [];
    $params = [];
    if ($situacao !== '') { $where[] = 'status = ?'; $params[] = $situacao; }
    if ($beforeId > 0) { $where[] = 'id < ?'; $params[] = $beforeId; }
    $stmt = db()->prepare($sql . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 31');
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    $hasMore = count($orders) > 30;
    if ($hasMore) { array_pop($orders); }
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM checkout_logs GROUP BY status')->fetchAll() as $row) {
        $porSituacao[(string) $row['status']] = (int) $row['n'];
    }
}

$pageTitle = $order ? 'Pedido #' . $order['id'] : 'Pedidos';
$navActive = 'pedidos';
require __DIR__ . '/partials/admin-header.php';
?>
<?php if ($order): ?>
<?php $snap = order_snapshot((string) $order['cart_json']); ?>
<p class="crumb"><a href="<?= e(base_url('admin/pedidos.php')) ?>"><span aria-hidden="true">&larr;</span> Pedidos</a></p>
<div class="page-head">
  <div>
    <h1>Pedido #<?= e($order['id']) ?></h1>
    <p class="muted">
      <span class="badge <?= e($statusClass[$order['status']] ?? '') ?>"><?= e(OrderRepository::STATUSES[$order['status']] ?? $order['status']) ?></span>
      Recebido em <?= e(date('d/m/Y \à\s H:i', strtotime($order['created_at']))) ?>
    </p>
  </div>
</div>

<section class="card" aria-labelledby="h-itens">
  <h2 class="sec" id="h-itens"><?= (int) $order['item_count'] ?> <?= (int) $order['item_count'] === 1 ? 'peça' : 'peças' ?></h2>
  <ul class="order-items">
    <?php foreach ($snap['items'] as $item): ?>
      <li>
        <span class="oi-name"><?= e((string) ($item['name'] ?? '')) ?></span>
        <span class="oi-qty"><?= e((string) ($item['qty'] ?? 1)) ?> &times;</span>
        <span class="oi-price"><?= e(format_price(isset($item['subtotal_cents']) ? (int) $item['subtotal_cents'] : null)) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <dl class="order-sum">
    <?php if ($snap['coupon'] !== null): ?>
      <div><dt>Subtotal</dt><dd><?= e(format_price($snap['subtotal_cents'])) ?></dd></div>
      <div><dt>Cupom <b><?= e((string) $snap['coupon']['code']) ?></b> (<?= e((string) $snap['coupon']['label']) ?>)</dt><dd>&minus;<?= e(format_price((int) $snap['coupon']['discount_cents'])) ?></dd></div>
    <?php endif; ?>
    <div class="total"><dt>Total<?= $snap['coupon'] !== null ? ' com o cupom' : '' ?></dt><dd><?= e(format_price((int) $order['total_cents'])) ?></dd></div>
  </dl>
  <?php if ($order['has_price_on_request']): ?><p class="hint">Há peças com valor a combinar: elas não entram no total.</p><?php endif; ?>
  <?php if ($snap['direct']): ?><p class="hint">Pedido feito pelo botão Comprar agora.</p><?php endif; ?>
  <p class="hint">Frete, pagamento e entrega são combinados na conversa do WhatsApp. Procure lá por "Pedido #<?= e($order['id']) ?>".</p>
  <?php
    $resumo = 'Pedido #' . $order['id'] . ' (' . date('d/m/Y H:i', strtotime($order['created_at'])) . ")\n";
    foreach ($snap['items'] as $item) { $resumo .= ($item['qty'] ?? 1) . 'x ' . ($item['name'] ?? '') . ' ' . format_price(isset($item['subtotal_cents']) ? (int) $item['subtotal_cents'] : null) . "\n"; }
    if ($snap['coupon'] !== null) { $resumo .= 'Cupom ' . $snap['coupon']['code'] . ': -' . format_price((int) $snap['coupon']['discount_cents']) . "\n"; }
    $resumo .= 'Total: ' . format_price((int) $order['total_cents']);
  ?>
  <button class="btn ghost wide" type="button" data-copy="<?= e($resumo) ?>" hidden>Copiar resumo do pedido</button>
</section>

<form method="post" class="card" aria-labelledby="h-situacao"><?= csrf_field() ?>
  <h2 class="sec" id="h-situacao">Situação</h2>
  <p class="hint">Toque para mudar. A observação abaixo é salva junto.</p>
  <div class="status-set">
    <?php foreach (OrderRepository::STATUSES as $key => $label): ?>
      <button class="status-btn is-<?= e($statusClass[$key] ?? '') ?>" type="submit" name="status" value="<?= e($key) ?>" aria-pressed="<?= $order['status'] === $key ? 'true' : 'false' ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="field">
    <label for="notes">Observação (só você vê)</label>
    <textarea id="notes" name="notes" maxlength="2000" rows="4" aria-describedby="notes-hint"><?= e($order['notes']) ?></textarea>
    <span class="hint" id="notes-hint">Ex.: pago no Pix, entrega sábado. Não anote senhas nem dados de cartão.</span>
  </div>
  <button class="btn primary wide" type="submit" name="status" value="<?= e($order['status']) ?>">Salvar observação</button>
</form>

<?php elseif ($id): ?>
<div class="card"><h1 class="sec">Pedido não encontrado</h1><p><a class="btn" href="<?= e(base_url('admin/pedidos.php')) ?>">Ver todos os pedidos</a></p></div>

<?php else: ?>
<div class="page-head">
  <div>
    <h1>Pedidos</h1>
    <p class="muted">Cada pedido é alguém que tocou em "Finalizar pelo WhatsApp". Confirme na conversa se a mensagem chegou, o pagamento e a entrega.</p>
  </div>
</div>

<nav class="tabs" aria-label="Mostrar pedidos">
  <a class="tab<?= $situacao === '' ? ' on' : '' ?>" href="<?= e(base_url('admin/pedidos.php')) ?>"<?= $situacao === '' ? ' aria-current="page"' : '' ?>>Todos <span class="tab-n"><?= array_sum($porSituacao) ?></span></a>
  <?php foreach (OrderRepository::STATUSES as $key => $label): ?>
    <a class="tab<?= $situacao === $key ? ' on' : '' ?>" href="<?= e(base_url('admin/pedidos.php') . '?situacao=' . $key) ?>"<?= $situacao === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-n"><?= $porSituacao[$key] ?? 0 ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if (!$orders): ?>
  <div class="card"><div class="empty-state"><?= $situacao !== '' ? 'Nenhum pedido nesta situação.' : 'Nenhum pedido ainda. Quando alguém finalizar uma sacola pelo WhatsApp, ele aparece aqui.' ?></div></div>
<?php else: ?>
  <ul class="olist">
  <?php foreach ($orders as $row): ?>
    <?php $s = order_snapshot((string) $row['cart_json']); $names = array_map(static fn ($i) => (string) ($i['name'] ?? ''), $s['items']); ?>
    <li>
      <a class="ocard" href="<?= e(base_url('admin/pedidos.php?id=' . $row['id'])) ?>">
        <span class="ocard-head">
          <b>Pedido #<?= e($row['id']) ?></b>
          <span class="badge <?= e($statusClass[$row['status']] ?? '') ?>"><?= e(OrderRepository::STATUSES[$row['status']] ?? $row['status']) ?></span>
        </span>
        <span class="ocard-items"><?= e(excerpt(implode(', ', $names), 90)) ?></span>
        <span class="ocard-foot">
          <span><?= e(date('d/m \à\s H:i', strtotime($row['created_at']))) ?> &middot; <?= (int) $row['item_count'] ?> <?= (int) $row['item_count'] === 1 ? 'peça' : 'peças' ?></span>
          <b><?= e(format_price((int) $row['total_cents'])) ?><?= $row['has_price_on_request'] ? ' + a combinar' : '' ?></b>
        </span>
      </a>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php if ($hasMore): ?>
    <p class="actions page-end"><a class="btn primary wide" href="<?= e(base_url('admin/pedidos.php') . '?' . http_build_query(array_filter(['situacao' => $situacao, 'before' => end($orders)['id']]))) ?>">Ver pedidos mais antigos</a></p>
  <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

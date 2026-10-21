<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
$admin = require_login();
require_once dirname(__DIR__) . '/includes/OrderRepository.php';
require_once dirname(__DIR__) . '/includes/functions.php';
$id = max(0, (int) ($_GET['id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { flash_set('error', 'Sessão expirada. Tente novamente.'); }
    else {
        try {
            if (!OrderRepository::find($id)) { throw new InvalidArgumentException('Pedido não encontrado.'); }
            OrderRepository::save($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['notes'] ?? ''));
            flash_set('success', 'Atendimento atualizado.');
        } catch (InvalidArgumentException $e) { flash_set('error', $e->getMessage()); }
    }
    header('Location: ' . base_url('admin/pedidos.php') . '?id=' . $id, true, 303); exit;
}
$order = $id ? OrderRepository::find($id) : null;
if ($id && !$order) { http_response_code(404); }
$orders = $id ? [] : OrderRepository::list(max(0, (int) ($_GET['before'] ?? 0)));
$hasMore = count($orders) > 30;
if ($hasMore) { array_pop($orders); }
$pageTitle = 'Pedidos'; $navActive = 'pedidos';
require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head"><div><p class="eyebrow">Atendimento</p><h1><?= $order ? 'Pedido #' . e($order['id']) : 'Pedidos pelo WhatsApp' ?></h1><p class="muted">Registros do clique em finalizar. Confirme envio da mensagem, pagamento e entrega na conversa.</p></div><?php if ($id): ?><a class="btn" href="<?= e(base_url('admin/pedidos.php')) ?>">Todos os pedidos</a><?php endif; ?></div>
<?php if ($order): ?>
<section class="card"><p>Recebido em <?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></p><div class="table-wrap"><table><thead><tr><th scope="col">Produto</th><th scope="col">Quantidade</th><th scope="col">Subtotal</th></tr></thead><tbody>
<?php foreach (json_decode($order['cart_json'], true) ?: [] as $item): ?><tr><td><?= e($item['name']) ?></td><td><?= e($item['qty']) ?></td><td><?= e(format_price($item['subtotal_cents'] ?? null)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p><b>Total dos itens com preço: <?= e(format_price((int) $order['total_cents'])) ?></b><?= $order['has_price_on_request'] ? '. Há itens sob consulta.' : '' ?></p><p class="hint">Frete e condições são combinados no atendimento.</p></section>
<form method="post" class="card"><?= csrf_field() ?><div class="form-grid"><div class="field"><label for="status">Situação do atendimento</label><select id="status" name="status"><?php foreach (OrderRepository::STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= $order['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="notes">Observações internas</label><textarea id="notes" name="notes" maxlength="2000" rows="4"><?= e($order['notes']) ?></textarea><span class="hint">Não registre senhas nem dados de cartão.</span></div></div><button class="btn primary">Salvar atendimento</button></form>
<?php elseif ($id): ?><div class="card"><h2>Pedido não encontrado</h2></div>
<?php elseif (!$orders): ?><div class="card"><h2>Os próximos pedidos aparecem aqui</h2><p>Quando alguém finalizar um carrinho pelo WhatsApp, você poderá acompanhar o atendimento.</p></div>
<?php else: ?><div class="card table-wrap"><table><thead><tr><th scope="col">Pedido</th><th scope="col">Data</th><th scope="col">Itens</th><th scope="col">Total estimado</th><th scope="col">Situação</th></tr></thead><tbody>
<?php foreach ($orders as $row): ?><tr><td><a class="btn" href="<?= e(base_url('admin/pedidos.php?id=' . $row['id'])) ?>">#<?= e($row['id']) ?></a></td><td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td><td><?= e($row['item_count']) ?></td><td><?= e(format_price((int) $row['total_cents'])) ?><?= $row['has_price_on_request'] ? ' + consulta' : '' ?></td><td><span class="badge"><?= e(OrderRepository::STATUSES[$row['status']] ?? $row['status']) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($hasMore): ?><a class="btn" href="<?= e(base_url('admin/pedidos.php?before=' . end($orders)['id'])) ?>">Pedidos anteriores</a><?php endif; endif; ?>
<?php require __DIR__ . '/partials/admin-footer.php'; ?>

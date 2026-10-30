<?php
/**
 * Lynch Store - cupons de desconto do painel.
 *
 * GET mostra o formulario curto (codigo sugerido, % ou R$, valor e tres campos
 * opcionais) e a lista em cartoes com status e contagem de usos. POST cria,
 * liga/desliga ou exclui (exclusao logica, com confirmacao) e volta para ca
 * (303). Tudo com CSRF; funciona sem JavaScript.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/Coupon.php';

$selfUrl = base_url('admin/cupons.php');
$ready   = CouponRepository::ready();

const COUPON_FORM_FIELDS = ['code', 'discount_type', 'discount_value', 'expires_on', 'min_order', 'max_uses'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $anchor = '';
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'A página ficou aberta por muito tempo. Atualize e repita a ação.');
    } elseif (!$ready) {
        flash_set('error', 'Os cupons ficam disponíveis assim que o banco da loja for atualizado.');
    } else {
        try {
            if ($op === 'create') {
                $data = CouponRepository::parseForm($_POST);
                $id = CouponRepository::create($data);
                flash_set('success', 'Cupom ' . $data['code'] . ' criado. Já pode ser usado na sacola.');
                $anchor = '#cupom-' . $id;
            } elseif ($op === 'activate' || $op === 'deactivate') {
                $coupon = CouponRepository::find($id) ?? throw new DomainException('Cupom não encontrado.');
                CouponRepository::setActive($id, $op === 'activate');
                flash_set('success', 'Cupom ' . $coupon['code'] . ($op === 'activate' ? ' ativado.' : ' desativado. Ele para de valer na sacola na hora.'));
                $anchor = '#cupom-' . $id;
            } elseif ($op === 'delete') {
                $coupon = CouponRepository::find($id) ?? throw new DomainException('Cupom não encontrado.');
                CouponRepository::softDelete($id);
                flash_set('success', 'Cupom ' . $coupon['code'] . ' excluído. Os pedidos que já usaram continuam registrados.');
            } else {
                flash_set('error', 'Ação inválida.');
            }
        } catch (DomainException $e) {
            flash_set('error', $e->getMessage());
            if ($op === 'create') {
                $anchor = '#novo';
                $_SESSION['coupon_err'] = $e->getMessage();
                $_SESSION['coupon_old'] = array_map(
                    static fn ($v): string => mb_substr((string) $v, 0, 40),
                    array_intersect_key($_POST, array_flip(COUPON_FORM_FIELDS))
                );
            }
        } catch (Throwable $e) {
            error_log('[lynch][cupons] ' . $e->getMessage());
            flash_set('error', 'Não foi possível salvar agora. Tente de novo.');
        }
    }
    header('Location: ' . $selfUrl . $anchor, true, 303);
    exit;
}

$old = [];
if (isset($_SESSION['coupon_old']) && is_array($_SESSION['coupon_old'])) {
    $old = $_SESSION['coupon_old'];
    unset($_SESSION['coupon_old']);
}
$couponErr = '';
if (isset($_SESSION['coupon_err']) && is_string($_SESSION['coupon_err'])) {
    $couponErr = $_SESSION['coupon_err'];
    unset($_SESSION['coupon_err']);
}
$coupons = CouponRepository::listAll();
$today   = date('Y-m-d');
$suggest = $ready ? CouponRepository::suggestCode() : '';
$oldType = ($old['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';

$statusInfo = [
    'ativo'      => ['Ativo', 'on'],
    'desativado' => ['Desativado', 'off'],
    'expirado'   => ['Expirado', 'off'],
    'esgotado'   => ['Esgotado', 'out'],
];

$pageTitle = 'Cupons';
$navActive = 'cupons';
require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <h1>Cupons</h1>
    <p class="muted">A cliente digita o código na sacola e o desconto aparece no total e na mensagem do WhatsApp. Vale sobre as peças com preço, já com as promoções.</p>
  </div>
  <?php if ($ready): ?>
  <div class="actions"><a class="btn primary" href="#novo" data-focus-target="cp-code"><span aria-hidden="true">+</span> Novo cupom</a></div>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="flash info" role="status">Os cupons ficam disponíveis assim que o banco da loja for atualizado.</div>
<?php else: ?>

<h2 class="list-title">Seus cupons <span class="tab-n"><?= count($coupons) ?></span></h2>
<?php if ($coupons === []): ?>
  <div class="card"><div class="empty-state">Nenhum cupom ainda. Toque em Novo cupom para criar o primeiro.</div></div>
<?php else: ?>
  <ul class="clist">
  <?php foreach ($coupons as $c): ?>
    <?php
      $st = CouponRepository::status($c, $today);
      [$stLabel, $stClass] = $statusInfo[$st];
    ?>
    <li class="ccard<?= $st !== 'ativo' ? ' is-off' : '' ?>" id="cupom-<?= $c['id'] ?>">
      <div class="ccard-head">
        <h3 class="ccard-code"><?= e($c['code']) ?></h3>
        <span class="badge <?= e($stClass) ?>"><?= e($stLabel) ?></span>
      </div>
      <p class="ccard-value"><?= e(CouponRepository::label($c)) ?> de desconto</p>
      <ul class="ccard-facts">
        <li><?= $c['expires_on'] !== null ? 'Vale até ' . e(format_date_br($c['expires_on'])) : 'Sem data para acabar' ?></li>
        <li><?= $c['min_order_cents'] !== null ? 'Pedido mínimo ' . e(format_price($c['min_order_cents'])) : 'Sem pedido mínimo' ?></li>
        <li><?= $c['max_uses'] !== null
            ? 'Usado ' . e($c['uses']) . ' de ' . e($c['max_uses']) . ($c['max_uses'] === 1 ? ' vez' : ' vezes')
            : 'Usado ' . e($c['uses']) . ($c['uses'] === 1 ? ' vez' : ' vezes') . ', sem limite' ?></li>
      </ul>
      <div class="ccard-actions">
        <form method="post" action="<?= e($selfUrl) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <?php if ($c['active']): ?>
            <button class="btn small ghost" type="submit" name="op" value="deactivate">Desativar</button>
          <?php else: ?>
            <button class="btn small primary" type="submit" name="op" value="activate">Ativar</button>
          <?php endif; ?>
        </form>
        <form class="ccard-del" method="post" action="<?= e($selfUrl) ?>" data-confirm="Excluir o cupom <?= e($c['code']) ?>? Ele para de valer na hora. Os pedidos que já usaram continuam registrados." data-confirm-ok="Excluir cupom">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn small quiet" type="submit" name="op" value="delete">Excluir</button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
  <p class="note">Desativar guarda o cupom para usar de novo depois. Excluir tira da lista. Expirado e Esgotado param de valer sozinhos.</p>
<?php endif; ?>

<form class="card coupon-new" id="novo" tabindex="-1" method="post" action="<?= e($selfUrl) ?>" data-busy="Criando...">
  <?= csrf_field() ?>
  <input type="hidden" name="op" value="create">
  <h2 class="sec">Novo cupom</h2>
  <p class="hint">Só o código e o desconto são obrigatórios.</p>
  <?php if ($couponErr !== ''): ?><p class="field-error"><?= e($couponErr) ?> O que você digitou foi mantido.</p><?php endif; ?>
  <div class="form-grid">
    <div class="field half">
      <label for="cp-code">Código</label>
      <div class="code-row">
        <input type="text" id="cp-code" name="code" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
               value="<?= e($old['code'] ?? $suggest) ?>" aria-describedby="cp-code-hint" data-coupon-code>
        <button class="btn ghost" type="button" data-coupon-suggest hidden>Sugerir outro</button>
      </div>
      <span class="hint" id="cp-code-hint">Já deixamos um código pronto. Pode trocar: de 3 a 20 letras ou números, sem espaço.</span>
    </div>
    <div class="field half">
      <span class="lbl" id="cp-type-lbl">Tipo de desconto</span>
      <div class="radioset" role="radiogroup" aria-labelledby="cp-type-lbl">
        <label><input type="radio" name="discount_type" value="percent"<?= $oldType === 'percent' ? ' checked' : '' ?>> Porcentagem (%)</label>
        <label><input type="radio" name="discount_type" value="fixed"<?= $oldType === 'fixed' ? ' checked' : '' ?>> Valor em reais (R$)</label>
      </div>
    </div>
    <div class="field third">
      <label for="cp-value">Desconto</label>
      <input type="text" id="cp-value" name="discount_value" required inputmode="decimal" maxlength="12" placeholder="10 ou 15,00" value="<?= e($old['discount_value'] ?? '') ?>" aria-describedby="cp-value-hint">
      <span class="hint" id="cp-value-hint">10 para 10% ou 15,00 para R$ 15,00, conforme o tipo.</span>
    </div>
    <div class="field third">
      <label for="cp-exp">Vale até (opcional)</label>
      <input type="date" id="cp-exp" name="expires_on" min="<?= e($today) ?>" value="<?= e($old['expires_on'] ?? '') ?>">
    </div>
    <div class="field third">
      <label for="cp-min">Pedido mínimo em R$ (opcional)</label>
      <input type="text" id="cp-min" name="min_order" inputmode="decimal" maxlength="12" placeholder="100,00" value="<?= e($old['min_order'] ?? '') ?>">
    </div>
    <div class="field third">
      <label for="cp-max">Limite de usos (opcional)</label>
      <input type="number" id="cp-max" name="max_uses" min="1" max="<?= CouponRepository::USES_MAX ?>" step="1" inputmode="numeric" value="<?= e($old['max_uses'] ?? '') ?>" aria-describedby="cp-max-hint">
      <span class="hint" id="cp-max-hint">Cada pedido enviado com o cupom conta 1 uso.</span>
    </div>
  </div>
  <div class="form-actions">
    <button class="btn primary wide" type="submit">Criar cupom</button>
  </div>
</form>
<?php endif; ?>
<?php
require __DIR__ . '/partials/admin-footer.php';

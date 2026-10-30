<?php
/**
 * Lynch Store - um produto na lista do painel, com as acoes rapidas.
 *
 * Espera: $p (linha hidratada de ProductRepository), $back (URL da lista para
 * voltar sem JS), $low (limite de estoque baixo), $stockReady (bool).
 * Usado por admin/produtos.php e por admin/produto-rapido.php (resposta sem
 * recarregar). Cada acao e um <form method="post"> com CSRF: funciona sem JS.
 */

declare(strict_types=1);

if (realpath(__FILE__) === realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit('403 Forbidden');
}

$pid      = (int) $p['id'];
$ativo    = (bool) $p['active'];
$foto     = (string) ($p['primary_image'] ?? '');
$esgotado = !empty($p['is_sold_out']);
$estoque  = $p['stock_qty'];
$baixo    = !$esgotado && $estoque !== null && $estoque <= $low;
$preco    = product_price_view($p);
$temPreco = !$p['price_on_request'] && $p['price_cents'] !== null;
$quick    = APP_BASE_URL . '/admin/produto-rapido.php';
$hidden   = csrf_field() . '<input type="hidden" name="id" value="' . $pid . '"><input type="hidden" name="back" value="' . e($back) . '">';
$nome     = (string) $p['name'];
?>
<li class="prow<?= $ativo ? '' : ' is-off' ?><?= $esgotado ? ' is-out' : '' ?>" id="produto-<?= $pid ?>" data-prow>
  <div class="prow-main">
    <?php if ($foto !== ''): ?>
      <img class="thumb" src="<?= e(asset_url($foto)) ?>" alt="" loading="lazy" width="52" height="65">
    <?php else: ?>
      <span class="thumb empty">sem<br>foto</span>
    <?php endif; ?>
    <div class="prow-info">
      <h2 class="prow-name"><a href="<?= e(APP_BASE_URL) ?>/admin/produto-form.php?id=<?= $pid ?>"><?= e($nome) ?></a></h2>
      <p class="prow-meta"><?= e((string) ($p['category_name'] ?? '') !== '' ? (string) $p['category_name'] : 'Sem categoria') ?> · <?= e(availability_label((string) $p['availability'])) ?></p>
      <p class="prow-price">
        <?php if ($preco['old'] !== null): ?><s><span class="sr">De </span><?= e($preco['old']) ?></s> <span class="sr">por </span><?php endif; ?>
        <b><?= e($preco['now']) ?></b>
      </p>
      <p class="prow-badges">
        <?php if (!$ativo): ?><span class="badge off">Fora da loja</span><?php endif; ?>
        <?php if ($esgotado): ?><span class="badge out">Esgotado</span><?php elseif ($baixo): ?><span class="badge low">Estoque baixo</span><?php endif; ?>
        <?php if ($preco['on_sale']): ?><span class="badge sale">Promoção <?= e($preco['percent']) ?>%<?= $p['sale_ends_on'] ? ' até ' . e(format_date_br($p['sale_ends_on'])) : '' ?></span><?php endif; ?>
        <?php if (!empty($p['sale_expired'])): ?><span class="badge off">Promoção encerrada em <?= e(format_date_br($p['sale_ends_on'])) ?></span><?php endif; ?>
        <?php if (!empty($p['featured'])): ?><span class="badge sun">Destaque</span><?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($stockReady): ?>
  <div class="prow-stock">
    <span class="lbl" id="estoque-lbl-<?= $pid ?>">Estoque</span>
    <div class="stock-ctl">
      <button class="step" type="submit" form="estoque-menos-<?= $pid ?>" aria-label="Tirar 1 do estoque de <?= e($nome) ?>"<?= $estoque === null || $estoque === 0 ? ' disabled' : '' ?>>&minus;</button>
      <form id="estoque-<?= $pid ?>" class="stock-form" method="post" action="<?= e($quick) ?>" data-quick>
        <?= $hidden ?>
        <input type="hidden" name="op" value="stock_set">
        <input class="stock-input" type="number" name="stock" min="0" max="<?= ProductRepository::STOCK_MAX ?>" step="1" inputmode="numeric"
               value="<?= $estoque === null ? '' : (int) $estoque ?>" placeholder="não controla"
               aria-labelledby="estoque-lbl-<?= $pid ?>" aria-describedby="estoque-dica-<?= $pid ?>" data-autosave>
        <button class="btn small ghost stock-save" type="submit">Salvar</button>
      </form>
      <button class="step" type="submit" form="estoque-mais-<?= $pid ?>" aria-label="Somar 1 ao estoque de <?= e($nome) ?>">+</button>
    </div>
    <span class="hint" id="estoque-dica-<?= $pid ?>">Vazio: não controla. 0: esgotado.</span>
    <form id="estoque-menos-<?= $pid ?>" method="post" action="<?= e($quick) ?>" data-quick hidden><?= $hidden ?><input type="hidden" name="op" value="stock_dec"></form>
    <form id="estoque-mais-<?= $pid ?>" method="post" action="<?= e($quick) ?>" data-quick hidden><?= $hidden ?><input type="hidden" name="op" value="stock_inc"></form>
  </div>
  <?php endif; ?>

  <form class="prow-toggles" method="post" action="<?= e($quick) ?>" data-quick>
    <?= $hidden ?>
    <button class="toggle" type="submit" name="op" value="<?= !empty($p['featured']) ? 'featured_off' : 'featured_on' ?>" aria-pressed="<?= !empty($p['featured']) ? 'true' : 'false' ?>">Destaque</button>
    <?php if ($stockReady): ?>
    <button class="toggle" type="submit" name="op" value="<?= $p['show_stock'] ? 'show_stock_off' : 'show_stock_on' ?>" aria-pressed="<?= $p['show_stock'] ? 'true' : 'false' ?>">Mostrar estoque</button>
    <?php if ($esgotado): ?>
    <button class="btn small primary" type="submit" name="op" value="reactivate">Reativar venda</button>
    <?php else: ?>
    <button class="btn small danger" type="submit" name="op" value="sold_out">Marcar como esgotado</button>
    <?php endif; ?>
    <?php endif; ?>
  </form>

  <?php if ($stockReady): ?>
  <details class="promo"<?= isset($openPromo) && $openPromo === $pid ? ' open' : '' ?>>
    <summary><?= $preco['on_sale'] ? 'Promoção ativa' : 'Promoção' ?></summary>
    <?php if (!$temPreco): ?>
      <p class="hint">Produto com "Consultar valor" não entra em promoção. <a href="<?= e(APP_BASE_URL) ?>/admin/produto-form.php?id=<?= $pid ?>">Cadastre o preço normal</a> primeiro.</p>
    <?php else: ?>
      <form class="promo-form" method="post" action="<?= e($quick) ?>" data-quick data-promo data-price="<?= (int) $p['price_cents'] ?>">
        <?= $hidden ?>
        <p class="hint">Preço normal: <b><?= e(format_price((int) $p['price_cents'])) ?></b>. Preencha o preço OU a porcentagem.</p>
        <div class="promo-grid">
          <div class="field">
            <label for="promo-preco-<?= $pid ?>">Preço promocional (R$)</label>
            <input type="text" id="promo-preco-<?= $pid ?>" name="sale_price" inputmode="decimal" placeholder="79,90" data-promo-price
                   value="<?= $p['sale_price_cents'] !== null ? e(cents_to_input($p['sale_price_cents'])) : '' ?>">
          </div>
          <div class="field">
            <label for="promo-pct-<?= $pid ?>">Desconto (%)</label>
            <input type="text" id="promo-pct-<?= $pid ?>" name="sale_percent" inputmode="numeric" placeholder="20" data-promo-pct
                   value="<?= $p['sale_price_cents'] !== null && $p['sale_price_cents'] < $p['price_cents'] ? sale_percent((int) $p['price_cents'], (int) $p['sale_price_cents']) : '' ?>">
          </div>
          <div class="field">
            <label for="promo-ate-<?= $pid ?>">Até (opcional)</label>
            <input type="date" id="promo-ate-<?= $pid ?>" name="sale_ends_on" min="<?= date('Y-m-d') ?>" value="<?= e((string) ($p['sale_ends_on'] ?? '')) ?>">
          </div>
        </div>
        <div class="promo-actions">
          <button class="btn small primary" type="submit" name="op" value="sale_set"><?= $p['sale_price_cents'] !== null ? 'Salvar promoção' : 'Colocar em promoção' ?></button>
          <?php if ($p['sale_price_cents'] !== null): ?>
          <button class="btn small ghost" type="submit" name="op" value="sale_off">Tirar promoção</button>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <div class="prow-actions">
    <a class="btn small" href="<?= e(APP_BASE_URL) ?>/admin/produto-form.php?id=<?= $pid ?>">Editar</a>
    <a class="btn small ghost" href="<?= e(APP_BASE_URL) ?>/admin/produto-imagens.php?id=<?= $pid ?>">Fotos</a>
    <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/produto-excluir.php"
          data-confirm="<?= $ativo ? 'Tirar este produto da loja? Ele some da vitrine, mas continua aqui e você pode voltar com ele quando quiser.' : 'Voltar com este produto para a loja?' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $pid ?>">
      <input type="hidden" name="action" value="<?= $ativo ? 'delete' : 'restore' ?>">
      <button class="btn small ghost" type="submit"><?= $ativo ? 'Tirar da loja' : 'Voltar para a loja' ?></button>
    </form>
  </div>
</li>

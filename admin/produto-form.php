<?php
/**
 * Lynch Store - formulario de produto do painel
 *
 * ?id ausente  -> criar
 * ?id=N        -> editar (aceita produto inativo: findAnyById, nao findById)
 *
 * Nao grava nada: e so o formulario. Quem grava e produto-salvar.php.
 *
 * Feito para o celular: fotos e o essencial (nome, preco, estoque) primeiro;
 * descricao depois; promocao e opcoes raras recolhidas. As fotos podem ser
 * escolhidas (ou tiradas na hora) ja no cadastro: produto-salvar.php grava o
 * produto e em seguida as fotos. Capa, ordem e exclusao ficam em
 * produto-imagens.php. A barra de salvar fica fixa embaixo.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';
require_once dirname(__DIR__) . '/includes/Upload.php';

$id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$produto = null;

if ($id > 0) {
    $produto = ProductRepository::findAnyById($id);
    if ($produto === null) {
        flash_set('error', 'Produto não encontrado (id ' . $id . ').');
        header('Location: ' . APP_BASE_URL . '/admin/produtos.php', true, 302);
        exit;
    }
}

$editando   = $produto !== null;
$categorias = CategoryRepository::listAll();

// Valores do formulario. Produto novo ja vem com o campo de preco aberto
// (preco e o que a Ray mais preenche); "Sem preco na loja" continua a um toque.
$vName      = (string) ($produto['name'] ?? '');
$vSlug      = (string) ($produto['slug'] ?? '');
$vShort     = (string) ($produto['short_description'] ?? '');
$vDesc      = (string) ($produto['description'] ?? '');
$vCategory  = (int) ($produto['category_id'] ?? 0);
$vAvail     = (string) ($produto['availability'] ?? 'sob_encomenda');
$vOnRequest = $editando ? (bool) $produto['price_on_request'] : false;
$vPrice     = cents_to_input($produto['price_cents'] ?? null);
$vActive    = $editando ? (bool) $produto['active'] : true;
$vFeatured  = $editando ? (bool) $produto['featured'] : false;
$vSort      = (int) ($produto['sort_order'] ?? 0);
$vStock     = isset($produto['stock_qty']) ? (string) $produto['stock_qty'] : '';
$vShowStock = $editando ? (bool) $produto['show_stock'] : false;
$vSoldOut   = $editando ? (bool) $produto['sold_out'] : false;
$vSale      = cents_to_input($produto['sale_price_cents'] ?? null);
$vSaleEnds  = (string) ($produto['sale_ends_on'] ?? '');

// Envio recusado: os campos voltam com o que ela digitou, e o erro aparece ao
// lado do campo (produto-salvar.php guarda os dois na sessao).
$errors = [];
$old = $_SESSION['product_form_old'] ?? null;
unset($_SESSION['product_form_old']);
if (is_array($old) && (int) $old['id'] === $id) {
    $errors = is_array($old['errors'] ?? null) ? $old['errors'] : [];
    $d = $old['data'];
    $vName = (string) ($d['name'] ?? ''); $vSlug = (string) ($d['slug'] ?? '');
    $vShort = (string) ($d['short_description'] ?? ''); $vDesc = (string) ($d['description'] ?? '');
    $vCategory = (int) ($d['category_id'] ?? 0); $vAvail = (string) ($d['availability'] ?? 'sob_encomenda');
    $vOnRequest = !empty($d['price_on_request']); $vPrice = (string) ($d['price'] ?? '');
    $vActive = !empty($d['active']); $vFeatured = !empty($d['featured']); $vSort = (int) ($d['sort_order'] ?? 0);
    $vStock = (string) ($d['stock_qty'] ?? ''); $vShowStock = !empty($d['show_stock']); $vSoldOut = !empty($d['sold_out']);
    $vSale = (string) ($d['sale_price'] ?? ''); $vSaleEnds = (string) ($d['sale_ends_on'] ?? '');
}
$stockReady = ProductRepository::stockReady();

$imagens = $editando ? (array) ($produto['images'] ?? []) : [];

$maxMb       = (int) (Upload::MAX_BYTES / 1048576);
$postLimitMb = post_limit_mb();

/** Mensagem de erro ao lado do campo. */
$fieldError = static function (string $key) use ($errors): string {
    return isset($errors[$key]) ? '<p class="field-error" id="err-' . e($key) . '">' . e((string) $errors[$key]) . '</p>' : '';
};
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"' : '';
$describe = static fn (string $key, string $hint): string => isset($errors[$key]) ? '' : ' aria-describedby="' . e($hint) . '"';

$temPromo = $vSale !== '' || $vSaleEnds !== '' || isset($errors['sale_price']);
$temMais  = $vSoldOut || $vSort !== 0;

$base      = APP_BASE_URL;
$pageTitle = $editando ? 'Editar produto' : 'Novo produto';
$navActive = 'produtos';
$bodyClass = 'has-savebar';

require __DIR__ . '/partials/admin-header.php';
?>
<p class="crumb"><a href="<?= e($base) ?>/admin/produtos.php"><span aria-hidden="true">&larr;</span> Produtos</a></p>

<div class="page-head">
  <div>
    <h1><?= $editando ? e($vName) : 'Novo produto' ?></h1>
    <?php if (!$editando): ?><p class="muted">Só o nome é obrigatório. O resto dá para completar depois.</p><?php endif; ?>
  </div>
  <?php if ($editando && $vActive): ?>
    <div class="actions">
      <a class="btn ghost small" target="_blank" rel="noopener" href="<?= e(product_url($vSlug)) ?>">Ver na loja<span class="sr"> (abre em outra aba)</span> <span aria-hidden="true">&nearr;</span></a>
    </div>
  <?php endif; ?>
</div>

<?php if ($errors !== []): ?>
  <div class="flash error" role="alert">Confira <?= count($errors) === 1 ? 'o campo marcado' : 'os campos marcados' ?> abaixo. O que você digitou foi mantido<?= $editando ? '' : '; só as fotos precisam ser escolhidas de novo' ?>.</div>
<?php endif; ?>

<form class="pform" method="post" enctype="multipart/form-data" action="<?= e($base) ?>/admin/produto-salvar.php<?= $editando ? '?id=' . (int) $produto['id'] : '' ?>"
      data-keepalive="<?= e($base) ?>/admin/ping.php" data-busy="Salvando..." data-product-form>
  <?= csrf_field() ?>
  <?php if ($editando): ?>
    <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
  <?php endif; ?>

  <fieldset class="card fs">
    <legend class="fs-title">Fotos</legend>
    <?php if ($imagens !== []): ?>
      <ul class="thumb-row" aria-label="Fotos cadastradas">
        <?php foreach (array_slice($imagens, 0, 8) as $i => $img): ?>
          <li class="thumb-cell">
            <img class="thumb tall" src="<?= e(asset_url((string) $img['file_path'])) ?>" alt="" loading="lazy" decoding="async">
            <?php if ($i === 0): ?><span class="thumb-tag">Capa</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="hint"><?= count($imagens) ?> foto<?= count($imagens) === 1 ? '' : 's' ?>. A capa é a que aparece na vitrine.</p>
      <p><a class="btn small ghost" href="<?= e($base) ?>/admin/produto-imagens.php?id=<?= (int) $produto['id'] ?>">Trocar capa, ordem ou apagar fotos</a></p>
    <?php endif; ?>
    <div class="field">
      <label class="pick-btn" for="f-fotos"><svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.2" fill="none" stroke="currentColor"/></svg><?= $imagens === [] ? 'Tirar ou escolher fotos' : 'Adicionar mais fotos' ?></label>
      <input class="pick-input" type="file" id="f-fotos" name="fotos[]" accept="image/*" multiple aria-describedby="h-fotos"
             data-photo-input data-max-mb="<?= $maxMb ?>"<?= $postLimitMb > 0 ? ' data-max-total-mb="' . $postLimitMb . '"' : '' ?>>
      <span class="hint" id="h-fotos">Tire na hora ou escolha da galeria, pode ser mais de uma.<?= $imagens === [] ? ' A primeira vira a capa.' : '' ?></span>
      <ul class="pick-preview" data-photo-preview aria-label="Fotos escolhidas" hidden></ul>
    </div>
  </fieldset>

  <fieldset class="card fs">
    <legend class="fs-title">O essencial</legend>
    <div class="form-grid">
      <div class="field">
        <label for="f-name">Nome do produto <span class="req">(obrigatório)</span></label>
        <input type="text" id="f-name" name="name" maxlength="180" required autocomplete="off" enterkeyhint="next"
               value="<?= e($vName) ?>"<?= $describe('name', 'h-name') ?><?= $invalid('name') ?>>
        <span class="hint" id="h-name">Ex.: Capa de mago em veludo</span>
        <?= $fieldError('name') ?>
      </div>

      <div class="field half">
        <label for="f-price">Preço</label>
        <div class="money">
          <span aria-hidden="true">R$</span>
          <input type="text" id="f-price" name="price" inputmode="decimal" autocomplete="off" enterkeyhint="next"
                 value="<?= e($vPrice) ?>" placeholder="129,90" data-money<?= $describe('price', 'h-price') ?> <?= $vOnRequest ? 'disabled' : '' ?><?= $invalid('price') ?>>
        </div>
        <span class="hint" id="h-price">Use vírgula nos centavos: 129,90.</span>
        <?= $fieldError('price') ?>
        <label class="checkline">
          <input type="checkbox" id="f-onrequest" name="price_on_request" value="1" <?= $vOnRequest ? 'checked' : '' ?>>
          Sem preço na loja (cliente consulta)
        </label>
      </div>

      <?php if ($stockReady): ?>
      <div class="field half">
        <label for="f-stock">Estoque</label>
        <div class="stock-ctl" data-stepper>
          <button class="step" type="button" data-step="-1" aria-label="Tirar 1 do estoque" hidden>&minus;</button>
          <input class="stock-input" type="text" id="f-stock" name="stock_qty" inputmode="numeric" pattern="[0-9]*" maxlength="5" autocomplete="off"
                 value="<?= e($vStock) ?>" placeholder="não controla"<?= $describe('stock_qty', 'h-stock') ?><?= $invalid('stock_qty') ?>>
          <button class="step" type="button" data-step="1" aria-label="Somar 1 ao estoque" hidden>+</button>
        </div>
        <span class="hint" id="h-stock">Quantas peças você tem. Vazio: não controla (bom para sob encomenda). Em 0, fica esgotado.</span>
        <?= $fieldError('stock_qty') ?>
        <label class="checkline">
          <input type="checkbox" name="show_stock" value="1" <?= $vShowStock ? 'checked' : '' ?>>
          Mostrar na loja ("Restam 2")
        </label>
      </div>
      <?php endif; ?>

      <div class="field half">
        <span class="lbl" id="lbl-avail">Disponibilidade</span>
        <div class="radioset" role="radiogroup" aria-labelledby="lbl-avail">
          <label>
            <input type="radio" name="availability" value="sob_encomenda"
                   <?= $vAvail !== 'pronta_entrega' ? 'checked' : '' ?>> Sob encomenda
          </label>
          <label>
            <input type="radio" name="availability" value="pronta_entrega"
                   <?= $vAvail === 'pronta_entrega' ? 'checked' : '' ?>> Pronta entrega
          </label>
        </div>
      </div>

      <div class="field half">
        <label for="f-cat">Categoria</label>
        <select id="f-cat" name="category_id">
          <option value="0">Sem categoria</option>
          <?php foreach ($categorias as $cat): ?>
            <?php
              $cid     = (int) $cat['id'];
              $inativa = !$cat['active'];
              // Categoria inativa nao e oferecida... a menos que ja seja a deste
              // produto: escondê-la faria o "Salvar" mudar a categoria sem a Ray
              // ter pedido nada.
              if ($inativa && $cid !== $vCategory) {
                  continue;
              }
            ?>
            <option value="<?= $cid ?>" <?= $cid === $vCategory ? 'selected' : '' ?>>
              <?= e((string) $cat['name']) ?><?= $inativa ? ' (categoria desativada)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <span class="lbl">Na loja</span>
        <label class="checkline">
          <input type="checkbox" name="active" value="1" <?= $vActive ? 'checked' : '' ?>>
          Aparece na loja
        </label>
        <label class="checkline">
          <input type="checkbox" name="featured" value="1" <?= $vFeatured ? 'checked' : '' ?>>
          Destaque (selo e vitrine da página inicial)
        </label>
      </div>
    </div>
  </fieldset>

  <fieldset class="card fs">
    <legend class="fs-title">Descrição</legend>
    <div class="form-grid">
      <div class="field">
        <label for="f-short">Descrição curta</label>
        <input type="text" id="f-short" name="short_description" maxlength="255" autocomplete="off"
               value="<?= e($vShort) ?>" aria-describedby="h-short">
        <span class="hint" id="h-short">Uma linha que aparece embaixo do nome.</span>
      </div>

      <div class="field">
        <label for="f-desc">Descrição completa</label>
        <textarea id="f-desc" name="description" rows="5" aria-describedby="h-desc"><?= e($vDesc) ?></textarea>
        <span class="hint" id="h-desc">Tecidos, medidas, prazo de produção, cuidados.</span>
      </div>

      <div class="field">
        <label for="f-slug">Endereço do link (opcional)</label>
        <input type="text" id="f-slug" name="slug" maxlength="200" autocomplete="off" autocapitalize="none" spellcheck="false"
               value="<?= e($vSlug) ?>" aria-describedby="h-slug">
        <span class="hint" id="h-slug">Preenchido sozinho a partir do nome. Pode deixar como está.</span>
      </div>
    </div>
  </fieldset>

  <?php if ($stockReady): ?>
  <details class="card fold"<?= $temPromo ? ' open' : '' ?>>
    <summary><span class="fs-title">Promoção</span><?= $vSale !== '' ? '<span class="badge sale">ligada</span>' : '' ?></summary>
    <div class="form-grid" data-promo data-price-input="#f-price">
      <div class="field half">
        <label for="f-sale">Preço promocional</label>
        <div class="money">
          <span aria-hidden="true">R$</span>
          <input type="text" id="f-sale" name="sale_price" inputmode="decimal" autocomplete="off" value="<?= e($vSale) ?>" placeholder="79,90" data-promo-price data-money<?= $describe('sale_price', 'h-sale') ?><?= $invalid('sale_price') ?>>
        </div>
        <span class="hint" id="h-sale">Menor que o preço normal. Vazio: sem promoção.</span>
        <?= $fieldError('sale_price') ?>
      </div>
      <div class="field half">
        <label for="f-pct">Ou desconto em %</label>
        <input type="text" id="f-pct" name="sale_percent" inputmode="numeric" autocomplete="off" placeholder="20" data-promo-pct aria-describedby="h-pct"
               value="<?= $vSale !== '' && !$vOnRequest && price_to_cents($vSale) !== null && price_to_cents($vPrice) ? e(sale_percent((int) price_to_cents($vPrice), (int) price_to_cents($vSale))) : '' ?>">
        <span class="hint" id="h-pct">Digite um ou outro: o sistema calcula o outro.</span>
      </div>
      <div class="field half">
        <label for="f-sale-ends">Promoção até (opcional)</label>
        <input type="date" id="f-sale-ends" name="sale_ends_on" value="<?= e($vSaleEnds) ?>" aria-describedby="h-ends">
        <span class="hint" id="h-ends">Último dia com o preço promocional. Vazio: até você tirar.</span>
      </div>
    </div>
  </details>
  <?php endif; ?>

  <details class="card fold"<?= $temMais ? ' open' : '' ?>>
    <summary><span class="fs-title">Mais opções</span></summary>
    <div class="form-grid">
      <?php if ($stockReady): ?>
      <div class="field">
        <label class="checkline">
          <input type="checkbox" name="sold_out" value="1" aria-describedby="h-sold" <?= $vSoldOut ? 'checked' : '' ?>>
          Esgotado ou vendido
        </label>
        <span class="hint" id="h-sold">Continua aparecendo na loja, mas não pode ir para a sacola.</span>
      </div>
      <?php endif; ?>
      <div class="field half">
        <label for="f-sort">Ordem na loja</label>
        <input type="text" id="f-sort" name="sort_order" inputmode="numeric" pattern="-?[0-9]*" maxlength="6" autocomplete="off" value="<?= $vSort ?>" aria-describedby="h-sort">
        <span class="hint" id="h-sort">Número menor aparece primeiro. Pode deixar 0.</span>
      </div>
    </div>
  </details>

  <div class="savebar" data-savebar>
    <a class="btn ghost" href="<?= e($base) ?>/admin/produtos.php">Cancelar</a>
    <button class="btn primary" type="submit" data-save-label><?= $editando ? 'Salvar alterações' : 'Salvar e cadastrar fotos' ?></button>
  </div>
</form>
<?php
require __DIR__ . '/partials/admin-footer.php';

<?php
/**
 * Lynch Store - formulario de produto do painel (fase 6)
 *
 * ?id ausente  -> criar
 * ?id=N        -> editar (aceita produto inativo: findAnyById, nao findById)
 *
 * Nao grava nada: e so o formulario. Quem grava e produto-salvar.php.
 * As fotos ficam numa tela separada (produto-imagens.php) porque uma imagem
 * precisa de um product_id que so existe depois do primeiro "Salvar".
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/CategoryRepository.php';

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

// Valores do formulario. Ao criar, o padrao segue o perfil do catalogo atual
// (sob encomenda, preco sob consulta, ativo).
$vName      = (string) ($produto['name'] ?? '');
$vSlug      = (string) ($produto['slug'] ?? '');
$vShort     = (string) ($produto['short_description'] ?? '');
$vDesc      = (string) ($produto['description'] ?? '');
$vCategory  = (int) ($produto['category_id'] ?? 0);
$vAvail     = (string) ($produto['availability'] ?? 'sob_encomenda');
$vOnRequest = $editando ? (bool) $produto['price_on_request'] : true;
$vPrice     = cents_to_input($produto['price_cents'] ?? null);
$vActive    = $editando ? (bool) $produto['active'] : true;
$vFeatured  = $editando ? (bool) $produto['featured'] : false;
$vSort      = (int) ($produto['sort_order'] ?? 0);
$old = $_SESSION['product_form_old'] ?? null;
unset($_SESSION['product_form_old']);
if (is_array($old) && (int) $old['id'] === $id) {
    $d = $old['data'];
    $vName = (string) ($d['name'] ?? ''); $vSlug = (string) ($d['slug'] ?? '');
    $vShort = (string) ($d['short_description'] ?? ''); $vDesc = (string) ($d['description'] ?? '');
    $vCategory = (int) ($d['category_id'] ?? 0); $vAvail = (string) ($d['availability'] ?? 'sob_encomenda');
    $vOnRequest = !empty($d['price_on_request']); $vPrice = (string) ($d['price'] ?? '');
    $vActive = !empty($d['active']); $vFeatured = !empty($d['featured']); $vSort = (int) ($d['sort_order'] ?? 0);
}

$imagens = $editando ? (array) ($produto['images'] ?? []) : [];

$base      = APP_BASE_URL;
$pageTitle = $editando ? 'Editar produto' : 'Novo produto';
$navActive = 'produtos';

require __DIR__ . '/partials/admin-header.php';
?>
<p class="crumb"><a href="<?= e($base) ?>/admin/produtos.php">&larr; Voltar para produtos</a></p>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= $editando ? 'Editando' : 'Cadastro' ?></p>
    <h1><?= $editando ? e($vName) : 'Novo produto' ?></h1>
  </div>
  <?php if ($editando): ?>
    <div class="actions">
      <a class="btn" href="<?= e($base) ?>/admin/produto-imagens.php?id=<?= (int) $produto['id'] ?>">
        Gerenciar fotos (<?= count($imagens) ?>)
      </a>
      <?php if ($vActive): ?>
        <a class="btn ghost" target="_blank" rel="noopener" href="<?= e(product_url($vSlug)) ?>">Ver na loja &nearr;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<form class="card" method="post" action="<?= e($base) ?>/admin/produto-salvar.php" data-keepalive="<?= e($base) ?>/admin/ping.php">
  <?= csrf_field() ?>
  <?php if ($editando): ?>
    <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
  <?php endif; ?>

  <div class="form-grid">
    <div class="field half">
      <label for="f-name">Nome do produto *</label>
      <input type="text" id="f-name" name="name" maxlength="180" required
             value="<?= e($vName) ?>" placeholder="Ex.: Capa de mago em veludo">
    </div>

    <div class="field half">
      <label for="f-slug">Endereço do link</label>
      <input type="text" id="f-slug" name="slug" maxlength="200"
             value="<?= e($vSlug) ?>" placeholder="gerado a partir do nome">
      <span class="hint">
        É o que aparece no link do produto. Deixe em branco para gerar sozinho
        a partir do nome; se já existir outro produto com o mesmo endereço, o sistema
        acrescenta um número no fim.
      </span>
    </div>

    <div class="field">
      <label for="f-short">Descrição curta</label>
      <input type="text" id="f-short" name="short_description" maxlength="255"
             value="<?= e($vShort) ?>" placeholder="Uma linha que aparece embaixo do nome no card">
    </div>

    <div class="field">
      <label for="f-desc">Descrição completa</label>
      <textarea id="f-desc" name="description" rows="7"
                placeholder="Tecidos, medidas, prazo de produção, cuidados..."><?= e($vDesc) ?></textarea>
    </div>

    <div class="field third">
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

    <div class="field third">
      <span class="lbl">Disponibilidade</span>
      <div class="radioset">
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

    <div class="field third">
      <label for="f-sort">Ordem de exibição</label>
      <input type="number" id="f-sort" name="sort_order" step="1" value="<?= $vSort ?>">
      <span class="hint">Menor aparece primeiro na loja.</span>
    </div>

    <div class="field half">
      <label for="f-price">Preço (R$)</label>
      <input type="text" id="f-price" name="price" inputmode="decimal"
             value="<?= e($vPrice) ?>" placeholder="189,90" <?= $vOnRequest ? 'disabled' : '' ?>>
      <label class="checkline">
        <input type="checkbox" id="f-onrequest" name="price_on_request" value="1" <?= $vOnRequest ? 'checked' : '' ?>>
        Consultar valor (não mostrar preço na loja)
      </label>
    </div>

    <div class="field half">
      <span class="lbl">Visibilidade</span>
      <label class="checkline">
        <input type="checkbox" name="active" value="1" <?= $vActive ? 'checked' : '' ?>>
        Ativo (aparece na loja)
      </label>
      <label class="checkline">
        <input type="checkbox" name="featured" value="1" <?= $vFeatured ? 'checked' : '' ?>>
        Destaque
      </label>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn primary" type="submit">
      <?= $editando ? 'Salvar alterações' : 'Salvar e cadastrar fotos' ?>
    </button>
    <a class="btn ghost" href="<?= e($base) ?>/admin/produtos.php">Cancelar</a>
    <?php if (!$editando): ?>
      <span class="hint spacer">Depois de salvar você já pode enviar as fotos.</span>
    <?php endif; ?>
  </div>
</form>

<?php if ($editando): ?>
<div class="card">
  <h2 class="sec">Fotos</h2>
  <p class="lede">
    <?php if ($imagens === []): ?>
      Este produto ainda não tem foto. Na loja ele aparece com a imagem padrão da marca.
    <?php else: ?>
      <?= count($imagens) ?> foto<?= count($imagens) === 1 ? '' : 's' ?> cadastrada<?= count($imagens) === 1 ? '' : 's' ?>.
      A primeira (capa) é a que aparece na vitrine.
    <?php endif; ?>
  </p>
  <?php if ($imagens !== []): ?>
    <div class="thumb-row">
      <?php foreach (array_slice($imagens, 0, 8) as $img): ?>
        <img class="thumb tall"
             src="<?= e(asset_url((string) $img['file_path'])) ?>" alt="" loading="lazy">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <a class="btn primary" href="<?= e($base) ?>/admin/produto-imagens.php?id=<?= (int) $produto['id'] ?>">
    Gerenciar fotos deste produto
  </a>
</div>
<?php endif; ?>

<?php
require __DIR__ . '/partials/admin-footer.php';

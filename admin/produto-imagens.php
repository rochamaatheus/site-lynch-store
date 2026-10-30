<?php
/**
 * Lynch Store - gerenciador de fotos de um produto (fase 6)
 *
 * Uma pagina so, cinco acoes (todas POST + CSRF, todas terminando em redirect
 * 303 para a propria pagina - F5 nunca reenvia um upload):
 *   upload   envio multiplo; cada arquivo passa por Upload::handleProductPhoto()
 *   primary  define a capa (transacao: zera todas, marca uma)
 *   delete   apaga a linha do banco E os 4 arquivos do disco
 *   reorder  um unico POST com a ordem de todas as fotos, uma transacao
 *   recrop   regera os 3 derivados a partir do -src, com outro ponto de foco
 *            (Topo/Centro/Base) - sem reenviar arquivo nenhum
 *
 * Por que handler no mesmo arquivo e nao um "produto-imagens-salvar.php": as
 * quatro acoes precisam exatamente do mesmo preludio (achar o produto, validar
 * que ele existe, montar o redirect de volta). Separar duplicaria isso quatro
 * vezes sem ganhar nada.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ProductRepository.php';
require_once dirname(__DIR__) . '/includes/Upload.php';
require_once dirname(__DIR__) . '/includes/ImageProcessor.php';

// ---------------------------------------------------------------------------
// Produto alvo (id pode vir do GET, ao abrir, ou do POST, ao agir)
// ---------------------------------------------------------------------------
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

$produto = $id > 0 ? ProductRepository::findAnyById($id) : null;
if ($produto === null) {
    flash_set('error', 'Produto não encontrado.');
    header('Location: ' . APP_BASE_URL . '/admin/produtos.php', true, 302);
    exit;
}

$selfUrl = APP_BASE_URL . '/admin/produto-imagens.php?id=' . $id;

/** Redireciona de volta para esta mesma pagina e encerra. */
function pi_back(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Acoes (POST)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (post_body_too_large()) {
        flash_set('error', 'As fotos somam mais do que o servidor aceita de uma vez (' . post_limit_mb() . ' MB). Nada foi enviado: envie 2 ou 3 fotos por vez.');
        pi_back($selfUrl);
    }
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'A página ficou aberta por muito tempo e o envio não foi aceito. Tente de novo.');
        pi_back($selfUrl);
    }

    $acao = (string) ($_POST['action'] ?? '');

    switch ($acao) {
        // -------------------------------------------------------------------
        case 'upload':
            $arquivos = isset($_FILES['fotos']) ? Upload::normalizeMultiple($_FILES['fotos']) : [];

            if ($arquivos === []) {
                flash_set('error', 'Nenhuma foto selecionada.');
                pi_back($selfUrl);
            }

            // Uma pasta por produto: uploads/products/{id}/ - facilita achar,
            // fazer backup e limpar tudo de um produto de uma vez.
            $destino = 'uploads/products/' . $id;
            $ok      = 0;
            $erros   = [];

            foreach ($arquivos as $arquivo) {
                // handleProductPhoto (e nao handle): a foto sai daqui ja
                // redimensionada e recortada em 4:5, em 3 tamanhos + o original.
                $r = Upload::handleProductPhoto($arquivo, $destino, ImageProcessor::FOCAL_TOP);

                if (!$r['ok']) {
                    $nome    = (string) ($arquivo['name'] ?? 'arquivo');
                    $erros[] = $nome . ': ' . (string) $r['error'];
                    continue;
                }

                // O texto alternativo padrao e o nome do produto: acessibilidade
                // e SEO decentes sem exigir mais um campo da Ray.
                ProductRepository::addImage(
                    $id,
                    (string) $r['path'],
                    (string) $produto['name'],
                    $r['source_path'],
                    ImageProcessor::FOCAL_TOP
                );
                $ok++;
            }

            if ($ok > 0) {
                flash_set('success', $ok . ' foto' . ($ok === 1 ? '' : 's') . ' enviada' . ($ok === 1 ? '' : 's') . ' com sucesso.');
            }
            foreach ($erros as $erro) {
                flash_set('error', 'Foto recusada: ' . $erro);
            }

            pi_back($selfUrl);
            // no break (pi_back encerra)

        // -------------------------------------------------------------------
        case 'primary':
            $imageId = (int) ($_POST['image_id'] ?? 0);

            if ($imageId > 0 && ProductRepository::setPrimaryImage($id, $imageId)) {
                flash_set('success', 'Capa atualizada. E essa foto que aparece na vitrine.');
            } else {
                flash_set('error', 'Não foi possível definir essa foto como capa.');
            }

            pi_back($selfUrl);

        // -------------------------------------------------------------------
        case 'delete':
            $imageId = (int) ($_POST['image_id'] ?? 0);
            $linha   = $imageId > 0 ? ProductRepository::findImage($imageId, $id) : null;

            if ($linha === null) {
                flash_set('error', 'Foto não encontrada.');
                pi_back($selfUrl);
            }

            if (ProductRepository::deleteImage($imageId, $id)) {
                // Os arquivos fisicos saem DEPOIS da linha: se o unlink falhar
                // (arquivo ja removido a mao, permissao), a foto ja sumiu do site
                // do mesmo jeito. Um orfao em disco nao vale um erro na cara da Ray.
                Upload::deleteProductPhoto(
                    (string) $linha['file_path'],
                    $linha['source_path'] === null ? null : (string) $linha['source_path']
                );
                flash_set('success', 'Foto removida.');
            } else {
                flash_set('error', 'Não foi possível remover a foto.');
            }

            pi_back($selfUrl);

        // -------------------------------------------------------------------
        case 'reorder':
            $ordens = $_POST['ordem'] ?? [];
            $mapa   = [];

            if (is_array($ordens)) {
                foreach ($ordens as $imageId => $valor) {
                    $imageId = (int) $imageId;
                    if ($imageId > 0) {
                        $mapa[$imageId] = (int) $valor;
                    }
                }
            }

            if ($mapa === []) {
                flash_set('error', 'Nada para reordenar.');
                pi_back($selfUrl);
            }

            // O WHERE de reorderImages() casa id AND product_id, entao um id de
            // outro produto injetado no form simplesmente nao atualiza nada.
            ProductRepository::reorderImages($id, $mapa);
            flash_set('success', 'Ordem das fotos salva.');

            pi_back($selfUrl);

        // -------------------------------------------------------------------
        case 'recrop':
            $imageId = (int) ($_POST['image_id'] ?? 0);
            $linha   = $imageId > 0 ? ProductRepository::findImage($imageId, $id) : null;

            if ($linha === null) {
                flash_set('error', 'Foto não encontrada.');
                pi_back($selfUrl);
            }

            $origem = trim((string) ($linha['source_path'] ?? ''));
            if ($origem === '') {
                flash_set('error', 'Esta foto foi enviada antes do recorte automático e não tem original guardado. Envie a foto de novo para poder ajustar o enquadramento.');
                pi_back($selfUrl);
            }

            $foco = ImageProcessor::normalizeFocal($_POST['focal_y'] ?? ImageProcessor::FOCAL_TOP);

            // Regera os 3 derivados por cima dos MESMOS nomes de arquivo: nenhuma
            // URL muda, entao nada em cache do banco/HTML precisa ser corrigido.
            $r = ImageProcessor::remakeDerivatives($origem, $foco);

            if (!$r['ok']) {
                flash_set('error', 'Não foi possível recortar a foto: ' . (string) $r['error']);
                pi_back($selfUrl);
            }

            ProductRepository::setImageFocal($imageId, $id, $foco);
            flash_set('success', 'Enquadramento atualizado para "' . ImageProcessor::focalLabel($foco) . '".');

            pi_back($selfUrl);

        // -------------------------------------------------------------------
        default:
            flash_set('error', 'Ação desconhecida.');
            pi_back($selfUrl);
    }
}

// ---------------------------------------------------------------------------
// Render (GET)
// ---------------------------------------------------------------------------
$imagens = ProductRepository::images($id);

$base      = APP_BASE_URL;
$pageTitle = 'Fotos de ' . (string) $produto['name'];
$navActive = 'produtos';

$maxMb       = (int) (Upload::MAX_BYTES / 1048576);
$postLimitMb = post_limit_mb();

/**
 * URL da foto com a data de modificacao do arquivo na query.
 *
 * O recorte reescreve o MESMO nome de arquivo (de proposito: assim nenhuma URL
 * gravada no banco muda). Sem este carimbo, a Ray clicaria em "Aplicar" e
 * continuaria vendo a versao antiga vinda do cache do navegador, achando que a
 * acao nao funcionou. So o admin precisa disso.
 */
function pi_preview_url(string $relativePath): string
{
    $abs   = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    $stamp = is_file($abs) ? (int) filemtime($abs) : 0;

    return asset_url($relativePath) . ($stamp > 0 ? '?v=' . $stamp : '');
}

$focais = [
    ImageProcessor::FOCAL_TOP    => 'Topo',
    ImageProcessor::FOCAL_CENTER => 'Centro',
    ImageProcessor::FOCAL_BOTTOM => 'Base',
];

require __DIR__ . '/partials/admin-header.php';
?>
<p class="crumb">
  <a href="<?= e($base) ?>/admin/produtos.php">Produtos</a> /
  <a href="<?= e($base) ?>/admin/produto-form.php?id=<?= $id ?>"><?= e((string) $produto['name']) ?></a> /
  Fotos
</p>

<div class="page-head">
  <div>
    <p class="eyebrow">Galeria do produto</p>
    <h1><?= e((string) $produto['name']) ?></h1>
    <p class="muted">
      <?= count($imagens) ?> foto<?= count($imagens) === 1 ? '' : 's' ?>
      &middot; <span class="badge <?= $produto['active'] ? 'on' : 'off' ?>"><?= $produto['active'] ? 'Ativo' : 'Inativo' ?></span>
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e($base) ?>/admin/produto-form.php?id=<?= $id ?>">Editar dados</a>
    <?php if ($produto['active']): ?>
      <a class="btn ghost" target="_blank" rel="noopener" href="<?= e(product_url((string) $produto['slug'])) ?>">Ver na loja &nearr;</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 class="sec">Enviar fotos</h2>
  <p class="lede">
    JPG, PNG ou WEBP, até <?= $maxMb ?> MB por arquivo. Pode selecionar várias de uma vez.
    <?php if (function_exists('imagewebp')): ?>
      As fotos são recortadas automaticamente no formato da vitrine (4:5), geradas em
      três tamanhos e convertidas para WEBP: carregam mais rápido e saem bem enquadradas.
      Se o corte cair errado, ajuste o enquadramento na tabela abaixo (sem reenviar a foto).
    <?php endif; ?>
  </p>

  <form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/admin/produto-imagens.php?id=<?= $id ?>"
        data-busy="Enviando, aguarde..." data-keepalive="<?= e($base) ?>/admin/ping.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="upload">

    <div class="field">
      <label for="f-fotos">Escolher arquivos</label>
      <input type="file" id="f-fotos" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required
             data-max-mb="<?= $maxMb ?>"<?= $postLimitMb > 0 ? ' data-max-total-mb="' . $postLimitMb . '"' : '' ?>>
    </div>

    <div class="form-actions">
      <button class="btn primary" type="submit">Enviar fotos</button>
      <span class="hint">Não feche esta página enquanto as fotos sobem.</span>
      <?php if ($imagens === []): ?>
        <span class="hint">A primeira foto enviada vira a capa automaticamente.</span>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2 class="sec">Fotos cadastradas</h2>

<?php if ($imagens === []): ?>
  <div class="empty-state">
    Este produto ainda não tem fotos. Na loja ele aparece com a imagem padrão da marca.
  </div>
<?php else: ?>
  <p class="lede">
    A capa é a foto que aparece na vitrine e nos resultados de busca. Para mudar a
    sequência da galeria, ajuste os números de ordem e clique em "Salvar ordem"
    (menor aparece primeiro).
  </p>

  <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="reorder">

    <div class="photo-grid">
    <?php foreach ($imagens as $img): ?>
      <?php $imgId = (int) $img['id']; $capa = (int) $img['is_primary'] === 1; ?>
      <div class="photo <?= $capa ? 'is-cover' : '' ?>">
        <figure>
          <img src="<?= e(pi_preview_url((string) $img['file_path'])) ?>"
               alt="<?= e((string) ($img['alt_text'] ?? '')) ?>" loading="lazy">
          <?php if ($capa): ?><span class="tag">Capa</span><?php endif; ?>
        </figure>
        <div class="body">
          <div class="row">
            <label class="ord" for="ord-<?= $imgId ?>">Ordem</label>
            <input type="number" id="ord-<?= $imgId ?>" name="ordem[<?= $imgId ?>]"
                   value="<?= (int) $img['sort_order'] ?>" step="1" class="ord-input">
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="form-actions">
      <button class="btn primary" type="submit">Salvar ordem</button>
    </div>
  </form>

  <p class="lede lede-gap">
    <strong>Enquadramento:</strong> a foto é recortada no formato da vitrine (retrato 4:5).
    "Topo" mantém a parte de cima da imagem (o padrão, bom para roupa em cabide ou manequim),
    "Centro" pega o meio e "Base" a parte de baixo. Trocar aqui refaz o corte na hora, sem
    reenviar o arquivo.
  </p>

  <!-- Capa, enquadramento e exclusao ficam FORA do form de ordem: HTML nao
       permite form aninhado, e cada uma e uma acao independente. -->
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th scope="col" class="col-photo">Foto</th>
          <th scope="col">Arquivo</th>
          <th scope="col">Ordem</th>
          <th scope="col">Capa</th>
          <th scope="col">Enquadramento</th>
          <th scope="col" class="col-actions">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($imagens as $img): ?>
        <?php
          $imgId  = (int) $img['id'];
          $capa   = (int) $img['is_primary'] === 1;
          $origem = trim((string) ($img['source_path'] ?? ''));
          $foco   = ImageProcessor::normalizeFocal($img['focal_y'] ?? ImageProcessor::FOCAL_TOP);
        ?>
        <tr>
          <td><img class="thumb" src="<?= e(pi_preview_url((string) $img['file_path'])) ?>" alt="" loading="lazy"></td>
          <td class="cell-name"><small><?= e(basename((string) $img['file_path'])) ?></small></td>
          <td><?= (int) $img['sort_order'] ?></td>
          <td><?= $capa ? '<span class="badge on">Capa</span>' : '<span class="badge off">Não</span>' ?></td>
          <td>
            <?php if ($origem === ''): ?>
              <span class="hint">Envie de novo para ajustar</span>
            <?php else: ?>
              <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php"
                    class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="image_id" value="<?= $imgId ?>">
                <input type="hidden" name="action" value="recrop">
                <select name="focal_y"
                        aria-label="Parte da foto que aparece no recorte">
                  <?php foreach ($focais as $valor => $rotulo): ?>
                    <option value="<?= (int) $valor ?>" <?= $foco === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn small" type="submit">Aplicar</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="cell-actions">
            <?php if (!$capa): ?>
              <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="image_id" value="<?= $imgId ?>">
                <input type="hidden" name="action" value="primary">
                <button class="btn small" type="submit">Tornar capa</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php"
                  data-confirm="Remover esta foto? O arquivo será apagado do servidor.">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="image_id" value="<?= $imgId ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn small danger" type="submit">Excluir</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/partials/admin-footer.php';

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
                flash_set('success', 'Capa trocada. É essa foto que aparece na vitrine.');
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
            flash_set('success', 'Ordem das fotos atualizada.');

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
            flash_set('success', 'Corte ajustado: ' . ImageProcessor::focalLabel($foco) . '.');

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
<p class="crumb"><a href="<?= e($base) ?>/admin/produto-form.php?id=<?= $id ?>"><span aria-hidden="true">&larr;</span> Voltar para o produto</a></p>

<div class="page-head">
  <div>
    <h1>Fotos</h1>
    <p class="muted">
      <?= e((string) $produto['name']) ?> &middot; <?= count($imagens) ?> foto<?= count($imagens) === 1 ? '' : 's' ?>
      <?php if (!$produto['active']): ?>&middot; <span class="badge off">Fora da loja</span><?php endif; ?>
    </p>
  </div>
</div>

<form class="card" method="post" enctype="multipart/form-data" action="<?= e($base) ?>/admin/produto-imagens.php?id=<?= $id ?>"
      data-busy="Enviando fotos..." data-keepalive="<?= e($base) ?>/admin/ping.php">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="action" value="upload">
  <h2 class="sec">Enviar fotos</h2>
  <div class="field">
    <label class="pick-btn" for="f-fotos"><svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.2" fill="none" stroke="currentColor"/></svg>Tirar ou escolher fotos</label>
    <input class="pick-input" type="file" id="f-fotos" name="fotos[]" accept="image/*" multiple data-photo-required aria-describedby="h-fotos"
           data-photo-input data-max-mb="<?= $maxMb ?>"<?= $postLimitMb > 0 ? ' data-max-total-mb="' . $postLimitMb . '"' : '' ?>>
    <span class="hint" id="h-fotos">Pode escolher várias. Elas ficam no formato da vitrine (em pé) e leves para carregar.<?= $imagens === [] ? ' A primeira vira a capa.' : '' ?></span>
    <ul class="pick-preview" data-photo-preview aria-label="Fotos escolhidas" hidden></ul>
  </div>
  <div class="form-actions">
    <button class="btn primary wide" type="submit">Enviar fotos</button>
  </div>
</form>

<h2 class="list-title">Fotos do produto <span class="tab-n"><?= count($imagens) ?></span></h2>
<?php if ($imagens === []): ?>
  <div class="card"><div class="empty-state">Nenhuma foto ainda. Na loja ele aparece com a imagem padrão da marca. Escolha as fotos acima.</div></div>
<?php else: ?>
  <p class="lede">A capa aparece na vitrine. Use as setas para mudar a ordem da galeria e "Corte" se a foto ficou cortada no lugar errado.</p>
  <?php
    // Ordem atual sem a capa (a capa e sempre a primeira da galeria). Cada
    // seta envia a ordem inteira ja trocada: um toque, sem digitar numero.
    $semCapa = array_values(array_filter($imagens, static fn ($img) => (int) $img['is_primary'] !== 1));
    $ordemTrocada = static function (int $from, int $to) use ($semCapa): array {
        $ids = array_map(static fn ($img) => (int) $img['id'], $semCapa);
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        $out = [];
        foreach ($ids as $pos => $imgId) { $out[$imgId] = $pos + 1; }
        return $out;
    };
    $posSemCapa = [];
    foreach ($semCapa as $pos => $img) { $posSemCapa[(int) $img['id']] = $pos; }
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
  ?>
  <ul class="photo-grid">
  <?php foreach ($imagens as $n => $img): ?>
    <?php
      $imgId  = (int) $img['id'];
      $capa   = (int) $img['is_primary'] === 1;
      $origem = trim((string) ($img['source_path'] ?? ''));
      $foco   = ImageProcessor::normalizeFocal($img['focal_y'] ?? ImageProcessor::FOCAL_TOP);
      $pos    = $posSemCapa[$imgId] ?? null;
      $rot    = 'foto ' . ($n + 1);
    ?>
    <li class="photo<?= $capa ? ' is-cover' : '' ?>" id="foto-<?= $imgId ?>">
      <figure>
        <img src="<?= e(pi_preview_url((string) $img['file_path'])) ?>" alt="<?= e('Foto ' . ($n + 1) . ($capa ? ', capa' : '')) ?>" loading="lazy" decoding="async">
        <?php if ($capa): ?><span class="tag">Capa</span><?php endif; ?>
      </figure>
      <div class="body">
        <?php if (!$capa): ?>
          <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
            <?= $hidden ?><input type="hidden" name="image_id" value="<?= $imgId ?>"><input type="hidden" name="action" value="primary">
            <button class="btn small wide" type="submit">Usar como capa<span class="sr"> (<?= e($rot) ?>)</span></button>
          </form>
          <?php if (count($semCapa) > 1): ?>
          <div class="move">
            <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
              <?= $hidden ?><input type="hidden" name="action" value="reorder">
              <?php if ($pos > 0): foreach ($ordemTrocada($pos, $pos - 1) as $oid => $ov): ?><input type="hidden" name="ordem[<?= $oid ?>]" value="<?= $ov ?>"><?php endforeach; endif; ?>
              <button class="step" type="submit"<?= $pos > 0 ? '' : ' disabled' ?> aria-label="Mover <?= e($rot) ?> para antes">&larr;</button>
            </form>
            <form method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
              <?= $hidden ?><input type="hidden" name="action" value="reorder">
              <?php if ($pos < count($semCapa) - 1): foreach ($ordemTrocada($pos, $pos + 1) as $oid => $ov): ?><input type="hidden" name="ordem[<?= $oid ?>]" value="<?= $ov ?>"><?php endforeach; endif; ?>
              <button class="step" type="submit"<?= $pos < count($semCapa) - 1 ? '' : ' disabled' ?> aria-label="Mover <?= e($rot) ?> para depois">&rarr;</button>
            </form>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($origem === ''): ?>
          <p class="hint">Para ajustar o corte, envie esta foto de novo.</p>
        <?php else: ?>
          <form class="focal" method="post" action="<?= e($base) ?>/admin/produto-imagens.php">
            <?= $hidden ?><input type="hidden" name="image_id" value="<?= $imgId ?>"><input type="hidden" name="action" value="recrop">
            <span class="lbl" id="corte-<?= $imgId ?>">Corte</span>
            <div class="seg" role="group" aria-labelledby="corte-<?= $imgId ?>">
              <?php foreach ($focais as $valor => $rotulo): ?>
                <button type="submit" name="focal_y" value="<?= (int) $valor ?>" aria-pressed="<?= $foco === $valor ? 'true' : 'false' ?>"><?= e($rotulo) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
        <?php endif; ?>

        <form class="photo-del" method="post" action="<?= e($base) ?>/admin/produto-imagens.php"
              data-confirm="Apagar esta foto? Ela sai da loja e não dá para desfazer." data-confirm-ok="Apagar foto">
          <?= $hidden ?><input type="hidden" name="image_id" value="<?= $imgId ?>"><input type="hidden" name="action" value="delete">
          <button class="btn small quiet wide" type="submit">Apagar foto<span class="sr"> (<?= e($rot) ?>)</span></button>
        </form>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<p class="actions page-end">
  <a class="btn" href="<?= e($base) ?>/admin/produto-form.php?id=<?= $id ?>">Voltar para o produto</a>
  <a class="btn ghost" href="<?= e($base) ?>/admin/produtos.php">Ir para a lista</a>
</p>
<?php
require __DIR__ . '/partials/admin-footer.php';

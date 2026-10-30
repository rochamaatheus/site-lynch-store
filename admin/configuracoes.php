<?php
/**
 * Lynch Store - configuracoes do site (fase 6)
 *
 * GET mostra o formulario, POST grava e redireciona de volta (303). O mesmo
 * padrao de produto-imagens.php: as duas metades compartilham o preludio
 * inteiro (ler site_settings), separar em dois arquivos so duplicaria isso.
 *
 * Chave critica: whatsapp_number. O link wa.me so aceita digitos com DDI, mas
 * a Ray vai digitar "(11) 99999-9999" - entao a normalizacao para so-digitos
 * acontece AQUI, na gravacao, e nao em cada lugar que le. WhatsAppMessage e
 * whatsapp_link() contam com isso.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/SettingsRepository.php';
require_once dirname(__DIR__) . '/includes/Upload.php';

$selfUrl = APP_BASE_URL . '/admin/configuracoes.php';

/** Redireciona de volta e encerra. */
function cfg_back(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Campos de texto que voltam preenchidos quando o envio é recusado. */
const CFG_TEXT_FIELDS = [
    'whatsapp_number', 'whatsapp_message_intro', 'hero_title', 'hero_cta_label', 'hero_subtitle',
    'contact_email', 'instagram_url', 'footer_about', 'store_policies',
];

/** Recusa o envio: guarda o que a Ray digitou para ela só corrigir o erro. */
function cfg_fail(string $url): never
{
    lynch_ensure_session();
    $_SESSION['cfg_old'] = [];
    foreach (CFG_TEXT_FIELDS as $field) {
        $_SESSION['cfg_old'][$field] = mb_substr((string) ($_POST[$field] ?? ''), 0, 5000);
    }
    cfg_back($url);
}

/**
 * Formata um numero so-digitos para leitura humana no campo do formulario.
 * Aceita 5511999999999 (DDI+DDD+9) e 11999999999 (sem DDI).
 */
function cfg_format_whatsapp(string $digits): string
{
    $len = strlen($digits);

    if ($len >= 12 && $len <= 13 && str_starts_with($digits, '55')) {
        $ddd  = substr($digits, 2, 2);
        $rest = substr($digits, 4);
        $half = strlen($rest) - 4;

        return '+55 (' . $ddd . ') ' . substr($rest, 0, $half) . '-' . substr($rest, $half);
    }

    if ($len === 10 || $len === 11) {
        $ddd  = substr($digits, 0, 2);
        $rest = substr($digits, 2);
        $half = strlen($rest) - 4;

        return '(' . $ddd . ') ' . substr($rest, 0, $half) . '-' . substr($rest, $half);
    }

    return $digits;
}

// ---------------------------------------------------------------------------
// POST - gravar
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (post_body_too_large()) {
        flash_set('error', 'O arquivo do banner é maior do que o servidor aceita (' . post_limit_mb() . ' MB). Nada foi salvo: use uma imagem menor.');
        cfg_back($selfUrl);
    }
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'A página ficou aberta por muito tempo e as alterações não foram aceitas. Recarregue a página e tente de novo.');
        cfg_back($selfUrl);
    }

    $avisos = [];

    // --- WhatsApp: SO DIGITOS no banco -------------------------------------
    $whatsBruto  = (string) ($_POST['whatsapp_number'] ?? '');
    $whatsDigits = (string) preg_replace('/\D/', '', $whatsBruto);

    if ($whatsDigits !== '') {
        // Numero brasileiro sem DDI: completa com 55 (senao o wa.me abre uma
        // conversa com um numero de outro pais e o pedido se perde).
        if (strlen($whatsDigits) === 10 || strlen($whatsDigits) === 11) {
            $whatsDigits = '55' . $whatsDigits;
            $avisos[]    = 'Adicionamos o código do Brasil (55) ao número: ' . $whatsDigits . '.';
        }

        if (strlen($whatsDigits) < 10 || strlen($whatsDigits) > 15) {
            flash_set('error', 'O número de WhatsApp "' . trim($whatsBruto) . '" não parece válido. Nada foi salvo. Use DDD e número, por exemplo (11) 99999-9999.');
            cfg_fail($selfUrl);
        }
    }

    $novos = [
        'whatsapp_number'        => $whatsDigits,
        'hero_title'             => trim((string) ($_POST['hero_title'] ?? '')),
        'hero_subtitle'          => trim((string) ($_POST['hero_subtitle'] ?? '')),
        'hero_cta_label'         => trim((string) ($_POST['hero_cta_label'] ?? '')),
        'whatsapp_message_intro' => trim((string) ($_POST['whatsapp_message_intro'] ?? '')),
    ];
    foreach (['footer_about' => 500, 'store_policies' => 5000, 'contact_email' => 180, 'instagram_url' => 255] as $field => $limit) {
        $novos[$field] = mb_substr(trim((string) ($_POST[$field] ?? '')), 0, $limit);
    }
    if ($novos['contact_email'] !== '' && !filter_var($novos['contact_email'], FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Informe um e-mail válido. Nada foi salvo.'); cfg_fail($selfUrl);
    }
    if ($novos['instagram_url'] !== '' && (!filter_var($novos['instagram_url'], FILTER_VALIDATE_URL) || !preg_match('~^https://(?:www\.)?instagram\.com/~i', $novos['instagram_url']))) {
        flash_set('error', 'Cole o endereço do perfil do Instagram, começando com https://instagram.com/. Nada foi salvo.'); cfg_fail($selfUrl);
    }

    // --- Banner do hero (opcional) -----------------------------------------
    $bannerAtual = SettingsRepository::raw('hero_banner_path');

    $oldBannerToRemove = '';
    if (!empty($_POST['remover_banner'])) {
        if ($bannerAtual !== '') {
            $oldBannerToRemove = $bannerAtual;
            $avisos[] = 'Banner removido; a loja voltou a usar a colagem padrão do hero.';
        }
        $novos['hero_banner_path'] = '';
    } elseif (isset($_FILES['hero_banner']) && (int) ($_FILES['hero_banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $r = Upload::handle($_FILES['hero_banner'], 'uploads/site');

        if ($r['ok']) {
            $novos['hero_banner_path'] = (string) $r['path'];

            // O arquivo antigo so sai do disco depois que o novo esta gravado.
            if ($bannerAtual !== '' && $bannerAtual !== $r['path']) {
                $oldBannerToRemove = $bannerAtual;
            }
        } else {
            $avisos[] = 'Banner não enviado: ' . (string) $r['error'] . ' (o banner atual foi mantido).';
        }
    }
    // Nenhum arquivo escolhido: hero_banner_path nem entra em $novos, entao o
    // banner atual e preservado. "Salvar" sem mexer no upload nunca apaga foto.

    try {
        SettingsRepository::setMany($novos);
        if ($oldBannerToRemove !== '') { Upload::deleteFile($oldBannerToRemove); }
        flash_set('success', 'Configurações salvas.');
    } catch (Throwable $e) {
        error_log('[lynch][configuracoes] ' . $e->getMessage());
        flash_set('error', 'Não foi possível salvar as configurações. Tente novamente.');
        cfg_fail($selfUrl);
    }

    foreach ($avisos as $aviso) {
        flash_set('info', $aviso);
    }

    cfg_back($selfUrl);
}

// ---------------------------------------------------------------------------
// GET - renderizar
// ---------------------------------------------------------------------------
$whatsDigits = SettingsRepository::raw('whatsapp_number');
$whatsDigits = (string) preg_replace('/\D/', '', $whatsDigits);

// Se o envio anterior foi recusado, os campos voltam com o que ela digitou.
$old = [];
if (isset($_COOKIE[session_name()])) {
    lynch_ensure_session();
    if (isset($_SESSION['cfg_old']) && is_array($_SESSION['cfg_old'])) {
        $old = $_SESSION['cfg_old'];
    }
    unset($_SESSION['cfg_old']);
}
$val = static fn (string $key, string $saved): string => array_key_exists($key, $old) ? (string) $old[$key] : $saved;

$vWhats  = $val('whatsapp_number', $whatsDigits === '' ? '' : cfg_format_whatsapp($whatsDigits));
$vTitle  = $val('hero_title', SettingsRepository::raw('hero_title'));
$vSub    = $val('hero_subtitle', SettingsRepository::raw('hero_subtitle'));
$vCta    = $val('hero_cta_label', SettingsRepository::raw('hero_cta_label'));
$vIntro  = $val('whatsapp_message_intro', SettingsRepository::raw('whatsapp_message_intro'));
$vBanner = SettingsRepository::raw('hero_banner_path');

$base      = APP_BASE_URL;
$pageTitle = 'Configurações';
$navActive = 'configuracoes';

$maxMb       = (int) (Upload::MAX_BYTES / 1048576);
$postLimitMb = post_limit_mb();

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Ajustes do site</p>
    <h1>Configurações</h1>
    <p class="muted">
      Número de WhatsApp que recebe os pedidos, textos da primeira tela, banner e contatos da loja.
    </p>
  </div>
</div>

<?php if ($whatsDigits === ''): ?>
  <div class="flash error" role="alert">
    <b>O número de WhatsApp ainda não foi configurado.</b> Enquanto ele estiver vazio,
    os botões "Falar no WhatsApp" e "Finalizar pedido" ficam desativados na loja e
    nenhum pedido chega até você.
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e($selfUrl) ?>"
      data-busy="Salvando..." data-keepalive="<?= e($base) ?>/admin/ping.php">
  <?= csrf_field() ?>

  <div class="card">
    <h2 class="sec">WhatsApp</h2>
    <div class="form-grid">
      <div class="field half">
        <label for="c-whats">Número que recebe os pedidos</label>
        <input type="text" id="c-whats" name="whatsapp_number" value="<?= e($vWhats) ?>"
               placeholder="(11) 99999-9999" inputmode="tel" autocomplete="tel">
        <span class="hint">
          Pode digitar com parênteses, espaço e traço: o sistema guarda só os números.
          Se você não colocar o código do país, o 55 (Brasil) é adicionado sozinho.
          <?php if ($whatsDigits !== ''): ?>
            <br>Salvo hoje no sistema: <b><?= e($whatsDigits) ?></b>
          <?php endif; ?>
        </span>
      </div>

      <div class="field half">
        <label for="c-intro">Primeira linha da mensagem de pedido</label>
        <input type="text" id="c-intro" name="whatsapp_message_intro" value="<?= e($vIntro) ?>"
               maxlength="255" placeholder="Olá! Quero fazer um pedido na Lynch Store:">
        <span class="hint">
          É como a conversa começa quando o cliente clica em "Finalizar pedido".
          A lista de produtos entra logo abaixo, automaticamente.
        </span>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="sec">Primeira tela (hero)</h2>
    <div class="form-grid">
      <div class="field half">
        <label for="c-title">Título</label>
        <input type="text" id="c-title" name="hero_title" value="<?= e($vTitle) ?>" maxlength="180">
      </div>
      <div class="field half">
        <label for="c-cta">Texto do botão principal</label>
        <input type="text" id="c-cta" name="hero_cta_label" value="<?= e($vCta) ?>" maxlength="60"
               placeholder="Falar no WhatsApp">
      </div>
      <div class="field">
        <label for="c-sub">Subtítulo</label>
        <textarea id="c-sub" name="hero_subtitle" rows="3"><?= e($vSub) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="sec">Banner do hero</h2>
    <p class="lede">
      Opcional. JPG, PNG ou WEBP, até <?= $maxMb ?> MB. Sem banner, a loja usa a colagem
      de fotos padrão. Deixar o campo em branco e salvar NÃO apaga o banner atual.
    </p>

    <?php if ($vBanner !== ''): ?>
      <div class="banner-current">
        <img class="banner-img" src="<?= e(asset_url($vBanner)) ?>" alt="Banner atual do hero">
        <div>
          <p class="muted">
            Banner atual: <?= e(basename($vBanner)) ?>
          </p>
          <label class="checkline">
            <input type="checkbox" name="remover_banner" value="1"> Remover o banner atual
          </label>
        </div>
      </div>
    <?php endif; ?>

    <div class="field">
      <label for="c-banner"><?= $vBanner !== '' ? 'Substituir por outra imagem' : 'Enviar imagem' ?></label>
      <input type="file" id="c-banner" name="hero_banner" accept="image/jpeg,image/png,image/webp"
             data-max-mb="<?= $maxMb ?>"<?= $postLimitMb > 0 ? ' data-max-total-mb="' . $postLimitMb . '"' : '' ?>>
    </div>
  </div>

  <div class="card">
    <h2 class="sec">Contato e informações da loja</h2>
    <div class="form-grid">
      <div class="field half">
        <label for="contact_email">E-mail de atendimento</label>
        <input id="contact_email" name="contact_email" type="email" autocomplete="email"
               value="<?= e($val('contact_email', setting('contact_email'))) ?>">
      </div>
      <div class="field half">
        <label for="instagram_url">Instagram</label>
        <input id="instagram_url" name="instagram_url" type="url" inputmode="url"
               placeholder="https://instagram.com/seuperfil"
               value="<?= e($val('instagram_url', setting('instagram_url'))) ?>">
        <span class="hint">Cole o endereço do seu perfil, como aparece no navegador.</span>
      </div>
      <div class="field">
        <label for="footer_about">Sobre a loja (rodapé)</label>
        <textarea id="footer_about" name="footer_about" maxlength="500" rows="3"><?= e($val('footer_about', setting('footer_about'))) ?></textarea>
      </div>
      <div class="field">
        <label for="store_policies">Entrega, trocas e atendimento</label>
        <textarea id="store_policies" name="store_policies" maxlength="5000" rows="6"><?= e($val('store_policies', setting('store_policies'))) ?></textarea>
        <span class="hint">Descreva as condições reais da loja. Esse texto aparece na página "Como comprar".</span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="form-actions flat">
      <button class="btn primary" type="submit">Salvar tudo</button>
      <a class="btn ghost" target="_blank" rel="noopener" href="<?= e($base) ?>/index.php">Ver a loja &nearr;</a>
    </div>
  </div>
</form>
<?php
require __DIR__ . '/partials/admin-footer.php';

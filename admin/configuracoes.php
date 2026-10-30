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
require_once dirname(__DIR__) . '/includes/StoreInfo.php';

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
    'contact_email', 'instagram_url', 'footer_about', 'store_policies', 'low_stock_threshold',
    'store_name', 'store_slogan', 'store_bio', 'appointment_note',
    'address_street', 'address_district', 'address_city', 'address_state', 'address_zip',
    'h_seg_sex_1_ini', 'h_seg_sex_1_fim', 'h_seg_sex_2_ini', 'h_seg_sex_2_fim',
    'h_sab_1_ini', 'h_sab_1_fim', 'h_sab_2_ini', 'h_sab_2_fim',
    'h_dom_1_ini', 'h_dom_1_fim', 'h_dom_2_ini', 'h_dom_2_fim',
];

/** Campos de texto da loja e o tamanho maximo de cada um. */
const CFG_STORE_FIELDS = [
    'store_name' => 80, 'store_slogan' => 120, 'store_bio' => 300, 'appointment_note' => 200,
    'address_street' => 120, 'address_district' => 80, 'address_city' => 80,
];

/**
 * Horario do formulario (h_<grupo>_<1|2>_<ini|fim>) para o JSON de opening_hours.
 * Devolve a mensagem de erro em vez do JSON quando algo nao fecha.
 *
 * @return array{0:?string,1:?string} [json, erro]
 */
function cfg_parse_hours(array $post): array
{
    $out = [];
    foreach (STORE_HOUR_GROUPS as $group => [$label]) {
        $out[$group] = [];
        for ($i = 1; $i <= 2; $i++) {
            $ini = trim((string) ($post["h_{$group}_{$i}_ini"] ?? ''));
            $fim = trim((string) ($post["h_{$group}_{$i}_fim"] ?? ''));
            if ($ini === '' && $fim === '') {
                continue;
            }
            if (!store_valid_time($ini) || !store_valid_time($fim)) {
                return [null, $label . ': preencha o início e o fim do horário, como 09:00 e 12:00.'];
            }
            if ($ini >= $fim) {
                return [null, $label . ': o horário precisa terminar depois de começar (' . $ini . ' até ' . $fim . ').'];
            }
            $last = end($out[$group]);
            if ($last !== false && $ini < $last[1]) {
                return [null, $label . ': o segundo horário precisa começar depois que o primeiro termina.'];
            }
            $out[$group][] = [$ini, $fim];
        }
    }
    return [json_encode($out, JSON_THROW_ON_ERROR), null];
}

/** Recusa o envio: guarda o que a Ray digitou para ela só corrigir o erro. */
function cfg_fail(string $url, string $field = ''): never
{
    lynch_ensure_session();
    $_SESSION['cfg_err'] = $field;
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
            cfg_fail($selfUrl, 'whatsapp_number');
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
    foreach (CFG_STORE_FIELDS as $field => $limit) {
        $novos[$field] = mb_substr(trim((string) ($_POST[$field] ?? '')), 0, $limit);
    }
    $uf = strtoupper(trim((string) ($_POST['address_state'] ?? '')));
    if ($uf !== '' && !preg_match('/^[A-Z]{2}$/D', $uf)) {
        flash_set('error', 'O estado precisa ser a sigla de 2 letras, por exemplo SC. Nada foi salvo.'); cfg_fail($selfUrl, 'address_state');
    }
    $novos['address_state'] = $uf;
    $cep = (string) preg_replace('/\D/', '', (string) ($_POST['address_zip'] ?? ''));
    if ($cep !== '' && strlen($cep) !== 8) {
        flash_set('error', 'O CEP precisa ter 8 números, por exemplo 89226-600. Nada foi salvo.'); cfg_fail($selfUrl, 'address_zip');
    }
    $novos['address_zip'] = $cep === '' ? '' : substr($cep, 0, 5) . '-' . substr($cep, 5);
    [$hoursJson, $hoursError] = cfg_parse_hours($_POST);
    if ($hoursError !== null) {
        flash_set('error', $hoursError . ' Nada foi salvo.'); cfg_fail($selfUrl, 'hours');
    }
    $novos['opening_hours'] = (string) $hoursJson;

    $lowRaw = trim((string) ($_POST['low_stock_threshold'] ?? '3'));
    if (!preg_match('/^\d{1,2}$/D', $lowRaw) || (int) $lowRaw < 1) {
        flash_set('error', 'O aviso de estoque baixo precisa ser um número de 1 a 99. Nada foi salvo.'); cfg_fail($selfUrl, 'low_stock_threshold');
    }
    $novos['low_stock_threshold'] = (string) (int) $lowRaw;
    if ($novos['contact_email'] !== '' && !filter_var($novos['contact_email'], FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Informe um e-mail válido. Nada foi salvo.'); cfg_fail($selfUrl, 'contact_email');
    }
    if ($novos['instagram_url'] !== '' && (!filter_var($novos['instagram_url'], FILTER_VALIDATE_URL) || !preg_match('~^https://(?:www\.)?instagram\.com/~i', $novos['instagram_url']))) {
        flash_set('error', 'Cole o endereço do perfil do Instagram, começando com https://instagram.com/. Nada foi salvo.'); cfg_fail($selfUrl, 'instagram_url');
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
        SecurityLog::audit((int) $admin['id'], 'config', '');
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
$errField = '';
if (isset($_COOKIE[session_name()])) {
    lynch_ensure_session();
    if (isset($_SESSION['cfg_old']) && is_array($_SESSION['cfg_old'])) {
        $old = $_SESSION['cfg_old'];
    }
    unset($_SESSION['cfg_old']);
    $errField = is_string($_SESSION['cfg_err'] ?? null) ? $_SESSION['cfg_err'] : '';
    unset($_SESSION['cfg_err']);
}
$val = static fn (string $key, string $saved): string => array_key_exists($key, $old) ? (string) $old[$key] : $saved;

$vWhats  = $val('whatsapp_number', $whatsDigits === '' ? '' : cfg_format_whatsapp($whatsDigits));
$vTitle  = $val('hero_title', SettingsRepository::raw('hero_title'));
$vSub    = $val('hero_subtitle', SettingsRepository::raw('hero_subtitle'));
$vCta    = $val('hero_cta_label', SettingsRepository::raw('hero_cta_label'));
$vIntro  = $val('whatsapp_message_intro', SettingsRepository::raw('whatsapp_message_intro'));
$vBanner = SettingsRepository::raw('hero_banner_path');
$vHours  = store_hours();
$hourVal = static function (string $group, int $i, int $side) use ($val, $vHours): string {
    $name = 'h_' . $group . '_' . $i . '_' . ($side === 0 ? 'ini' : 'fim');
    return $val($name, (string) ($vHours[$group][$i - 1][$side] ?? ''));
};

$base      = APP_BASE_URL;
$pageTitle = 'Configurações';
$navActive = 'configuracoes';
$bodyClass = 'has-savebar';
$errMark = static fn (string $field): string => $errField === $field ? ' aria-invalid="true"' : '';

$maxMb       = (int) (Upload::MAX_BYTES / 1048576);
$postLimitMb = post_limit_mb();

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
        <h1>Configurações da loja</h1>
    <p class="muted">Toque em um assunto para abrir. Depois de mudar, toque em Salvar tudo.</p>
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

  <details class="card fold" id="sec-whats" open>
    <summary><span class="fold-head"><span class="fs-title">WhatsApp</span><small>Número que recebe os pedidos e a primeira linha da mensagem.</small></span></summary>
    <div class="form-grid">
      <div class="field half">
        <label for="c-whats">Número que recebe os pedidos</label>
        <input type="text" id="c-whats" name="whatsapp_number"<?= $errMark('whatsapp_number') ?> value="<?= e($vWhats) ?>"
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
  </details>

  <details class="card fold" id="sec-hero">
    <summary><span class="fold-head"><span class="fs-title">Primeira tela da loja</span><small>Título, texto e botão que a cliente vê ao abrir a loja.</small></span></summary>
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
  </details>

  <details class="card fold" id="sec-banner">
    <summary><span class="fold-head"><span class="fs-title">Foto grande da primeira tela</span><small>Opcional. Sem foto, a loja usa a colagem padrão.</small></span></summary>
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
  </details>

  <details class="card fold" id="sec-estoque"<?= in_array($errField, ['low_stock_threshold'], true) ? ' open' : '' ?>>
    <summary><span class="fold-head"><span class="fs-title">Estoque</span><small>Quando avisar que o estoque está baixo.</small></span></summary>
    <div class="form-grid">
      <div class="field third">
        <label for="c-low">Avisar estoque baixo com</label>
        <input type="number" id="c-low" name="low_stock_threshold"<?= $errMark('low_stock_threshold') ?> min="1" max="99" step="1" inputmode="numeric"
               value="<?= e($val('low_stock_threshold', (string) low_stock_threshold())) ?>">
        <span class="hint">Com essa quantidade ou menos, o produto aparece em "Estoque baixo" no painel e a loja mostra "Restam" (se o estoque estiver visível).</span>
      </div>
    </div>
  </details>

  <details class="card fold" id="sec-loja">
    <summary><span class="fold-head"><span class="fs-title">A loja</span><small>Nome, slogan e quem faz a loja.</small></span></summary>
    <div class="form-grid">
      <div class="field half">
        <label for="c-store-name">Nome da loja</label>
        <input type="text" id="c-store-name" name="store_name" maxlength="80" value="<?= e($val('store_name', SettingsRepository::raw('store_name'))) ?>" placeholder="Lynch Store">
      </div>
      <div class="field half">
        <label for="c-slogan">Slogan</label>
        <input type="text" id="c-slogan" name="store_slogan" maxlength="120" value="<?= e($val('store_slogan', SettingsRepository::raw('store_slogan'))) ?>">
        <span class="hint">Aparece no rodapé da loja, abaixo do nome.</span>
      </div>
      <div class="field">
        <label for="c-bio">Quem faz a loja</label>
        <input type="text" id="c-bio" name="store_bio" maxlength="300" value="<?= e($val('store_bio', SettingsRepository::raw('store_bio'))) ?>" placeholder="Cosmaker e Estilista, fundadora da loja.">
      </div>
    </div>
  </details>

  <details class="card fold" id="sec-horario"<?= in_array($errField, ['hours'], true) ? ' open' : '' ?>>
    <summary><span class="fold-head"><span class="fs-title">Horário de atendimento</span><small>Dias e horários que aparecem no rodapé.</small></span></summary>
    <p class="lede">Deixe em branco o horário que não existe. Dia sem nenhum horário aparece como Fechado.</p>
    <div class="hours-grid">
      <?php foreach (STORE_HOUR_GROUPS as $group => [$groupLabel]): ?>
      <fieldset class="hours-day">
        <legend><?= e($groupLabel) ?></legend>
        <?php for ($i = 1; $i <= 2; $i++): ?>
        <div class="hours-row">
          <span class="hours-n" aria-hidden="true"><?= $i ?>º</span>
          <div class="field">
            <label for="h-<?= $group ?>-<?= $i ?>-ini">Abre<span class="sr"> (<?= e($groupLabel) ?>, <?= $i ?>º horário)</span></label>
            <input type="time" id="h-<?= $group ?>-<?= $i ?>-ini" name="h_<?= $group ?>_<?= $i ?>_ini" value="<?= e($hourVal($group, $i, 0)) ?>" step="300">
          </div>
          <div class="field">
            <label for="h-<?= $group ?>-<?= $i ?>-fim">Fecha<span class="sr"> (<?= e($groupLabel) ?>, <?= $i ?>º horário)</span></label>
            <input type="time" id="h-<?= $group ?>-<?= $i ?>-fim" name="h_<?= $group ?>_<?= $i ?>_fim" value="<?= e($hourVal($group, $i, 1)) ?>" step="300">
          </div>
        </div>
        <?php endfor; ?>
      </fieldset>
      <?php endforeach; ?>
    </div>
    <div class="form-grid lede-gap">
      <div class="field">
        <label for="c-booking">Atendimento com horário marcado</label>
        <input type="text" id="c-booking" name="appointment_note" maxlength="200" value="<?= e($val('appointment_note', SettingsRepository::raw('appointment_note'))) ?>" placeholder="Entre em contato para agendar seu horário.">
        <span class="hint">Aparece junto do horário no rodapé e na página "Como comprar".</span>
      </div>
    </div>
  </details>

  <details class="card fold" id="sec-endereco"<?= in_array($errField, ['address_state','address_zip'], true) ? ' open' : '' ?>>
    <summary><span class="fold-head"><span class="fs-title">Endereço</span><small>Rua, bairro, cidade e CEP.</small></span></summary>
    <div class="form-grid">
      <div class="field half">
        <label for="c-street">Rua e número</label>
        <input type="text" id="c-street" name="address_street" maxlength="120" autocomplete="address-line1" value="<?= e($val('address_street', SettingsRepository::raw('address_street'))) ?>">
      </div>
      <div class="field half">
        <label for="c-district">Bairro</label>
        <input type="text" id="c-district" name="address_district" maxlength="80" value="<?= e($val('address_district', SettingsRepository::raw('address_district'))) ?>">
      </div>
      <div class="field half">
        <label for="c-city">Cidade</label>
        <input type="text" id="c-city" name="address_city" maxlength="80" autocomplete="address-level2" value="<?= e($val('address_city', SettingsRepository::raw('address_city'))) ?>">
      </div>
      <div class="field quarter">
        <label for="c-uf">Estado (sigla)</label>
        <input type="text" id="c-uf" name="address_state"<?= $errMark('address_state') ?> maxlength="2" autocomplete="address-level1" autocapitalize="characters" value="<?= e($val('address_state', SettingsRepository::raw('address_state'))) ?>" placeholder="SC">
      </div>
      <div class="field quarter">
        <label for="c-cep">CEP</label>
        <input type="text" id="c-cep" name="address_zip"<?= $errMark('address_zip') ?> maxlength="9" inputmode="numeric" autocomplete="postal-code" value="<?= e($val('address_zip', SettingsRepository::raw('address_zip'))) ?>" placeholder="00000-000">
      </div>
    </div>
  </details>

  <details class="card fold" id="sec-contato"<?= in_array($errField, ['contact_email','instagram_url'], true) ? ' open' : '' ?>>
    <summary><span class="fold-head"><span class="fs-title">Contato, sobre e condições</span><small>E-mail, Instagram, texto do rodapé e condições.</small></span></summary>
    <div class="form-grid">
      <div class="field half">
        <label for="contact_email">E-mail de atendimento</label>
        <input id="contact_email" name="contact_email"<?= $errMark('contact_email') ?> type="email" autocomplete="email"
               value="<?= e($val('contact_email', setting('contact_email'))) ?>">
      </div>
      <div class="field half">
        <label for="instagram_url">Instagram</label>
        <input id="instagram_url" name="instagram_url"<?= $errMark('instagram_url') ?> type="url" inputmode="url"
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
  </details>

  <div class="savebar" data-savebar>
    <a class="btn ghost" target="_blank" rel="noopener" href="<?= e($base) ?>/index.php">Ver a loja<span class="sr"> (abre em outra aba)</span></a>
    <button class="btn primary" type="submit">Salvar tudo</button>
  </div>
</form>
<?php
require __DIR__ . '/partials/admin-footer.php';

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/StoreInfo.php';
$infoWa      = whatsapp_link();
$infoInsta   = setting('instagram_url');
$infoHours   = store_hours_lines();
$infoBooking = setting('appointment_note');
$infoAddress = store_address_lines();
$infoMap     = store_maps_url();
$infoBio     = setting('store_bio');
$pageTitle = 'Como comprar e privacidade';
$pageDescription = 'Como fazer um pedido na Lynch Store, diferença entre pronta entrega e sob encomenda, e como tratamos seus dados.';
$pageCanonical = base_url('informacoes.php');
require __DIR__ . '/includes/partials/header.php';
?>
<section class="wrap prose">
<h1 class="page-title">Compra com conversa, do seu jeito</h1>
<details open><summary>Como faço um pedido?</summary><div><p>Escolha as peças e coloque na sacola, ou toque em "Comprar agora" para pedir só uma peça. Se tiver cupom, aplique na sacola. Toque em enviar pelo WhatsApp, revise e envie a mensagem. A loja confirma disponibilidade, medidas, preço, frete e prazo antes de combinar o pagamento.</p></div></details>
<details><summary>Pronta entrega e sob encomenda</summary><div><p>Pronta entrega indica uma peça já produzida, sujeita à confirmação de disponibilidade. Nas encomendas, materiais, medidas e prazo são acertados com a loja antes da produção.</p></div></details>
<?php if ($infoHours !== [] || $infoAddress !== [] || $infoWa !== ''): ?>
<details><summary>Horário, endereço e contato</summary><div>
<?php if ($infoBio !== ''): ?><p><?= e($infoBio) ?></p><?php endif; ?>
<?php if ($infoHours !== []): ?><dl class="hours"><?php foreach ($infoHours as [$day, $time]): ?><div><dt><?= e($day) ?></dt><dd><?= e($time) ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
<?php if ($infoBooking !== ''): ?><p><b>Atendimento com horário marcado.</b> <?= e($infoBooking) ?></p><?php endif; ?>
<?php if ($infoAddress !== []): ?><div class="info-address"><address><?= implode('<br>', array_map('e', $infoAddress)) ?></address><?php if ($infoMap !== ''): ?><a class="text-link" href="<?= e($infoMap) ?>" target="_blank" rel="noopener">Como chegar</a><?php endif; ?></div><?php endif; ?>
<p class="contact-links"><?php if ($infoWa !== ''): ?><a class="btn line small" href="<?= e($infoWa) ?>" target="_blank" rel="noopener"><?= icon('chat') ?> WhatsApp <?= e(whatsapp_display()) ?></a><?php endif; ?><?php if ($infoInsta !== ''): ?><a class="btn line small" href="<?= e($infoInsta) ?>" target="_blank" rel="noopener">Instagram <?= e(instagram_handle($infoInsta)) ?></a><?php endif; ?></p>
</div></details>
<?php endif; ?>
<details><summary>Entrega, trocas e atendimento</summary><div><p><?= nl2br(e(setting('store_policies', 'Consulte as condições de entrega, troca e cancelamento pelo atendimento antes de confirmar sua compra.'))) ?></p></div></details>
<details><summary>Privacidade e cookies</summary><div><p>Usamos um cookie de sessão para manter a sacola, proteger formulários e permitir o acesso ao painel. Seus favoritos ficam guardados apenas no seu aparelho. Este site não instala ferramentas de publicidade ou análise de terceiros.</p><p>Para evitar abuso, tentativas erradas de cupom e de acesso ao painel são contadas por alguns minutos a partir de um código gerado do seu endereço de rede. O endereço em si não fica guardado.</p><p>Ao enviar o pedido, os itens e o valor estimado ficam registrados para o atendimento. O site não coleta dados de cartão. Você escolhe enviar a mensagem ao abrir o WhatsApp, que possui suas próprias condições de uso e privacidade.</p><p>Para falar sobre seus dados ou solicitar a exclusão de um atendimento, entre em contato com a loja pelos canais disponíveis no rodapé.</p></div></details>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

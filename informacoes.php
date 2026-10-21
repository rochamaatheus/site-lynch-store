<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/SettingsRepository.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Como comprar e privacidade';
$pageDescription = 'Como fazer um pedido na Lynch Store, diferença entre pronta entrega e sob encomenda, e como tratamos seus dados.';
$pageCanonical = base_url('informacoes.php');
require __DIR__ . '/includes/partials/header.php';
?>
<section class="inset info-page"><h1>Compra com conversa, do seu jeito</h1>
<details open><summary>Como faço um pedido?</summary><p>Escolha as peças, adicione ao carrinho e toque em finalizar pelo WhatsApp. Revise e envie a mensagem. A loja confirma disponibilidade, medidas, preço, frete e prazo antes de combinar o pagamento.</p></details>
<details><summary>Pronta entrega e sob encomenda</summary><p>Pronta entrega indica uma peça já produzida, sujeita à confirmação de disponibilidade. Nas encomendas, materiais, medidas e prazo são acertados com a loja antes da produção.</p></details>
<details><summary>Entrega, trocas e atendimento</summary><p><?= nl2br(e(setting('store_policies', 'Consulte as condições de entrega, troca e cancelamento pelo atendimento antes de confirmar sua compra.'))) ?></p></details>
<details><summary>Privacidade e cookies</summary><p>Usamos um cookie de sessão para manter o carrinho, proteger formulários e permitir o acesso ao painel. Este site não instala ferramentas de publicidade ou análise de terceiros.</p><p>Ao finalizar, os itens e o valor estimado ficam registrados para o atendimento. O site não coleta dados de cartão. Você escolhe enviar a mensagem ao abrir o WhatsApp, que possui suas próprias condições de uso e privacidade.</p><p>Para falar sobre seus dados ou solicitar a exclusão de um atendimento, entre em contato com a loja pelos canais disponíveis no rodapé.</p></details>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>

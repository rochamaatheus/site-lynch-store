<?php
/**
 * Lynch Store - "Mais" do painel: as areas usadas de vez em quando, que nao
 * cabem na barra de baixo do celular. Pagina so de leitura (o Sair e POST).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$admin = require_login();

require_once dirname(__DIR__) . '/includes/functions.php';

$base      = APP_BASE_URL;
$pageTitle = 'Mais opções';
$navActive = 'mais';

$links = [
    ['Categorias', 'Grupos de produtos que aparecem nos filtros da loja.', '/admin/categorias.php'],
    ['Configurações da loja', 'WhatsApp, horário, endereço, textos e banner.', '/admin/configuracoes.php'],
    ['Minha conta', 'Trocar a senha e sair do painel.', '/admin/conta.php'],
    ['Importar planilha', 'Para cadastrar muitos produtos de uma vez. Mais fácil no computador.', '/admin/importar.php'],
];

require __DIR__ . '/partials/admin-header.php';
?>
<div class="page-head">
  <div>
    <h1>Mais opções</h1>
  </div>
</div>

<ul class="menu-list">
  <?php foreach ($links as [$label, $help, $path]): ?>
    <li>
      <a href="<?= e($base . $path) ?>">
        <b><?= e($label) ?></b>
        <span><?= e($help) ?></span>
      </a>
    </li>
  <?php endforeach; ?>
  <li>
    <a href="<?= e(base_url('admin/exportar.php')) ?>">
      <b>Baixar planilha dos produtos</b>
      <span>Arquivo CSV com todo o catálogo, para guardar ou editar no computador.</span>
    </a>
  </li>
  <li>
    <a href="<?= e($base) ?>/index.php" target="_blank" rel="noopener">
      <b>Ver a loja <span aria-hidden="true">&nearr;</span></b>
      <span>Abre a loja como a cliente vê, em outra aba.</span>
    </a>
  </li>
</ul>

<details class="card help-card">
  <summary>Abrir o painel como aplicativo</summary>
  <p>Assim o painel fica com um ícone na tela inicial do celular, como um app.</p>
  <p><b>Android (Chrome):</b> toque nos três pontinhos do navegador e em "Adicionar à tela inicial" ou "Instalar app".</p>
  <p><b>iPhone (Safari):</b> toque no botão Compartilhar (quadrado com seta) e em "Adicionar à Tela de Início". No app você entra uma vez com seu usuário e senha.</p>
</details>

<form class="out-form" method="post" action="<?= e(base_url('admin/logout.php')) ?>">
  <?= csrf_field() ?>
  <button class="btn ghost wide" type="submit">Sair</button>
</form>
<?php
require __DIR__ . '/partials/admin-footer.php';

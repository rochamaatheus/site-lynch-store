const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const prior = JSON.parse(fs.readFileSync(path.join(root, '.cache/preview-build.json'), 'utf8'));
const credentials = JSON.parse(fs.readFileSync(path.join(root, '.cache/install-test.json'), 'utf8'));
const qaCredentials = JSON.parse(fs.readFileSync(path.join(root, '.qa-credentials.json'), 'utf8'));
const source = 'http://localhost:8082';
const qaSource = 'http://localhost:8000';
const prefix = new URL(prior.base).pathname.replace(/\/$/, '') + '/';
const output = path.join(root, 'dist', 'client-preview-' + new Date().toISOString().replace(/[-:]/g, '').slice(0, 15));
const screens = [
  ['login', 'Acesso ao painel', '/admin/login.php'],
  ['dashboard', 'Visão geral', '/admin/index.php'],
  ['produtos', 'Lista de produtos', '/admin/produtos.php'],
  ['produtos-2', 'Mais produtos', null],
  ['produto-novo', 'Novo produto', '/admin/produto-form.php'],
  ['produto-editar', 'Editar produto', null],
  ['produto-fotos', 'Fotos do produto', null],
  ['categorias', 'Categorias', '/admin/categorias.php'],
  ['importar', 'Importar planilha', '/admin/importar.php'],
  ['pedidos', 'Pedidos', '/admin/pedidos.php'],
  ['pedido-detalhe', 'Detalhe de pedido', null],
  ['configuracoes', 'Configurações', '/admin/configuracoes.php'],
  ['conta', 'Minha conta', '/admin/conta.php'],
];
const adminFile = name => prefix + 'painel-' + name + '.html';

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function screenIndex() {
  const publicScreens = [
    ['Página inicial', 'index.html'], ['Busca', 'busca.html'],
    ['Coleções e filtros', Object.entries(prior.routes).find(([route]) => route.startsWith('index.php?'))?.[1] || 'index.html'],
    ['Produto', Object.entries(prior.routes).find(([route]) => route.startsWith('produto.php?'))?.[1] || 'index.html'],
    ['Carrinho', 'carrinho.html'], ['Informações da loja', 'informacoes.html'],
    ['Página não encontrada', '404.html'],
  ];
  const cards = (items) => items.map(([label, file]) => `<a class="screen" href="${prefix}${escapeHtml(file)}"><span>${escapeHtml(label)}</span><span aria-hidden="true">↗</span></a>`).join('');
  return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Todas as telas | Lynch Store</title><link rel="icon" href="${prefix}assets/lynch-logo.webp"><link rel="stylesheet" href="${prefix}telas.css"></head><body><main><p class="eyebrow">Lynch Store · prévia para aprovação</p><h1>Todas as telas, em um só lugar.</h1><p class="intro">Explore a loja e veja como será o painel. As páginas administrativas são demonstrações visuais; alterações e pedidos não são salvos nesta prévia.</p><section aria-labelledby="loja"><h2 id="loja">Loja</h2><div class="screens">${cards(publicScreens)}</div><p class="hint">O catálogo completo está na página inicial. Cada produto abre sua própria página.</p></section><section aria-labelledby="painel"><h2 id="painel">Painel administrativo</h2><div class="screens">${cards(screens.map(([name, label]) => [label, 'painel-' + name + '.html']))}</div></section><p class="footer">Prévia de aprovação. A loja definitiva será instalada na hospedagem com acesso protegido ao painel.</p></main></body></html>`;
}

(async () => {
  if (!fs.existsSync(prior.output)) throw new Error('Gere primeiro a prévia pública com build-preview.php.');
  if (!fs.existsSync(path.join(prior.output, 'products.json'))) throw new Error('Catálogo da prévia ausente.');
  fs.cpSync(prior.output, output, {recursive:true});
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const page = await browser.newPage();
    await page.goto(source + '/admin/login.php');
    if (!(await page.locator('#username').count())) throw new Error('Login da instalação de teste indisponível.');
    const loginHtml = await page.content();
    await page.locator('#username').fill(credentials.admin_user);
    await page.locator('#password').fill(credentials.admin_password);
    await Promise.all([page.waitForURL('**/admin/index.php'), page.locator('button[type=submit]').click()]);
    await page.goto(source + '/admin/produtos.php');
    const nextLink = await page.getByRole('link', {name:'Próximos produtos'}).getAttribute('href');
    const editLink = await page.locator('a[href*="produto-form.php?id="]').first().getAttribute('href');
    const photoLink = await page.locator('a[href*="produto-imagens.php?id="]').first().getAttribute('href');
    if (!editLink || !photoLink) throw new Error('Produto de exemplo ausente na instalação de teste.');
    if (!nextLink) throw new Error('Segunda página de produtos ausente na instalação de teste.');
    screens.find(item => item[0] === 'produtos-2')[2] = new URL(nextLink, source).pathname + new URL(nextLink, source).search;
    screens.find(item => item[0] === 'produto-editar')[2] = new URL(editLink, source).pathname + new URL(editLink, source).search;
    screens.find(item => item[0] === 'produto-fotos')[2] = new URL(photoLink, source).pathname + new URL(photoLink, source).search;
    const orderPage = await browser.newPage();
    await orderPage.goto(qaSource + '/admin/login.php');
    await orderPage.locator('#username').fill(qaCredentials.username);
    await orderPage.locator('#password').fill(qaCredentials.password);
    await Promise.all([orderPage.waitForURL('**/admin/index.php'), orderPage.locator('button[type=submit]').click()]);
    await orderPage.goto(qaSource + '/admin/pedidos.php');
    const orderLink = await orderPage.locator('a[href*="pedidos.php?id="]').first().getAttribute('href');
    if (!orderLink) throw new Error('Pedido de exemplo ausente no banco de teste.');
    screens.find(item => item[0] === 'pedido-detalhe')[2] = new URL(orderLink, qaSource).pathname + new URL(orderLink, qaSource).search;

    for (const [name, label, route] of screens) {
      const activePage = name === 'pedido-detalhe' ? orderPage : page;
      const localSource = name === 'pedido-detalhe' ? qaSource : source;
      const html = name === 'login' ? loginHtml : (await activePage.goto(localSource + route), await activePage.content());
      const sanitized = await activePage.evaluate(({html, source, prefix, routes, name, label}) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const main = doc.querySelector('main') || doc.body;
        const notice = doc.createElement('aside');
        notice.className = 'client-preview-notice';
        notice.id = 'preview-notice';
        notice.innerHTML = '<b>Prévia para aprovação</b><span>Esta tela é demonstrativa. Nenhuma alteração é salva.</span><a href="' + prefix + 'telas.html">Ver todas as telas</a>';
        main.insertBefore(notice, main.firstChild);
        doc.querySelectorAll('input[name="csrf"], input[name="redirect"], input[name="preview_token"]').forEach(el => el.remove());
        doc.querySelectorAll('input[type="password"]').forEach(el => { el.value = ''; el.removeAttribute('value'); });
        doc.querySelectorAll('form').forEach(form => {
          form.removeAttribute('action'); form.removeAttribute('method'); form.removeAttribute('onsubmit');
          form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => button.type = 'button');
        });
        doc.querySelectorAll('[onclick],[onchange]').forEach(el => { el.removeAttribute('onclick'); el.removeAttribute('onchange'); });
        doc.querySelectorAll('a[href],link[href],img[src],script[src]').forEach(el => {
          const attr = el.hasAttribute('href') ? 'href' : 'src';
          const value = el.getAttribute(attr);
          if (!value) return;
          if (value.startsWith(prefix)) return;
          let url;
          try { url = new URL(value, source + '/'); } catch { return; }
          if (url.origin !== source) return;
          const route = url.pathname + url.search;
          let target = null;
          if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/uploads/')) target = prefix + url.pathname.slice(1);
          else if (url.pathname === '/index.php' || url.pathname === '/') target = prefix + 'index.html';
          else if (url.pathname === '/produto.php') target = prefix + (routes[route.slice(1)] || 'index.html');
          else if (url.pathname === '/admin/produto-form.php') target = prefix + (url.search ? 'painel-produto-editar.html' : 'painel-produto-novo.html');
          else if (url.pathname === '/admin/produto-imagens.php') target = prefix + 'painel-produto-fotos.html';
          else if (url.pathname === '/admin/produtos.php' && url.searchParams.has('cursor')) target = prefix + 'painel-produtos-2.html';
          else if (url.pathname === '/admin/pedidos.php' && url.searchParams.has('id')) target = prefix + 'painel-pedido-detalhe.html';
          else if (url.pathname === '/admin/login.php' || url.pathname === '/admin/logout.php') target = prefix + 'painel-login.html';
          else if (url.pathname.startsWith('/admin/')) {
            const key = url.pathname.split('/').pop().replace('.php', '');
            if (['index','produtos','categorias','importar','pedidos','configuracoes','conta'].includes(key)) target = prefix + 'painel-' + (key === 'index' ? 'dashboard' : key) + '.html';
          }
          if (target) { el.setAttribute(attr, target + (url.hash || '')); if (el.tagName === 'A') el.removeAttribute('target'); }
          else if (el.tagName === 'A') { el.setAttribute('href', '#preview-notice'); el.setAttribute('aria-disabled', 'true'); }
          else el.remove();
        });
        doc.querySelectorAll('.who').forEach(el => el.textContent = 'Conta demonstrativa');
        doc.querySelectorAll('input[name="contact_email"],input[name="whatsapp_number"],input[name="instagram_url"]').forEach(el => { el.value=''; el.removeAttribute('value'); });
        if (name === 'dashboard') {
          const h1 = doc.querySelector('h1'); if (h1) h1.textContent = 'Bem-vinda ao painel!';
          doc.querySelectorAll('.page-head .muted').forEach(el => { if (el.textContent.includes('Ultimo acesso')) el.remove(); });
        }
        if (name === 'conta') { const p = doc.querySelector('.page-head .muted'); if (p) p.textContent = 'Acesso demonstrativo ao painel.'; }
        if (name === 'pedido-detalhe') {
          const h1 = doc.querySelector('h1'); if (h1) h1.textContent = 'Pedido de exemplo';
          const section = doc.querySelector('main section.card');
          if (section) {
            const received = section.querySelector('p'); if (received) received.textContent = 'Registro ilustrativo para visualizar esta tela.';
            const tbody = section.querySelector('tbody'); if (tbody) tbody.innerHTML = '<tr><td>Peça de exemplo</td><td>1</td><td>Consultar valor</td></tr>';
            const total = section.querySelector('p b'); if (total) total.textContent = 'Total dos itens com preço: a combinar';
          }
        }
        doc.querySelectorAll('textarea[name="notes"]').forEach(el => el.textContent = '');
        const style = doc.createElement('link'); style.rel='stylesheet'; style.href=prefix+'telas.css'; doc.head.append(style);
        const script = doc.createElement('script'); script.src=prefix+'painel-preview.js'; script.defer=true; doc.body.append(script);
        doc.querySelectorAll('link[rel="canonical"],meta[property="og:url"]').forEach(el => el.remove());
        doc.title = label + ' | Lynch Store: prévia';
        return '<!doctype html>\n' + doc.documentElement.outerHTML;
      }, {html, source:localSource, prefix, routes:prior.routes, name, label});
      if (/localhost:8000|localhost:8082|name="csrf"|method="post"/i.test(sanitized)) throw new Error('Dado de sessão ou endereço local em ' + name);
      fs.writeFileSync(path.join(output, 'painel-' + name + '.html'), sanitized.replace(/[ \t]+(?=\r?\n)/g, ''));
    }
    for (const file of fs.readdirSync(output).filter(file => file.endsWith('.html') && !file.startsWith('painel-'))) {
      const pathname = path.join(output, file);
      const html = fs.readFileSync(pathname, 'utf8');
      fs.writeFileSync(pathname, html.replace(/(<aside class="preview-notice"[^>]*>[\s\S]*?<span>)([\s\S]*?)(<\/span>)/, (_, before, message, after) => before + message + ' <a href="' + prefix + 'telas.html">Ver todas as telas</a>' + after));
    }
    fs.writeFileSync(path.join(output, 'telas.html'), screenIndex());
    fs.copyFileSync(path.join(__dirname, 'preview/telas.css'), path.join(output, 'telas.css'));
    fs.copyFileSync(path.join(__dirname, 'preview/painel-preview.js'), path.join(output, 'painel-preview.js'));
    const report = {...prior, output, pages:prior.pages + screens.length + 1, adminScreens:screens.map(([name]) => 'painel-' + name + '.html'), index:'telas.html'};
    fs.writeFileSync(path.join(root, '.cache/client-preview-build.json'), JSON.stringify(report, null, 2));
    fs.writeFileSync(path.join(output, 'README.md'), '# Lynch Store: prévia para aprovação\n\nAbra telas.html para navegar por todas as telas. O catálogo e o carrinho são demonstrativos. O painel é uma cópia visual estática, sem autenticação ou gravação de dados.\n');
    console.log(JSON.stringify({output, pages:report.pages, adminScreens:report.adminScreens.length, index:report.index}, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

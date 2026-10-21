const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {createPreviewServer} = require('../scripts/serve-preview.cjs');

(async () => {
  const build = JSON.parse(fs.readFileSync('.cache/client-preview-build.json', 'utf8'));
  const credentials = JSON.parse(fs.readFileSync('.cache/install-test.json', 'utf8'));
  const qaCredentials = JSON.parse(fs.readFileSync('.qa-credentials.json', 'utf8'));
  const root = build.output;
  const prefix = new URL(build.base).pathname + '/';
  const server = createPreviewServer(root, prefix);
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port + prefix;
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const files = fs.readdirSync(root).filter(file => file.endsWith('.html'));
    assert.equal(files.length, build.pages);
    assert.equal(build.adminScreens.length, 13);
    for (const file of files) {
      const html = fs.readFileSync(path.join(root, file), 'utf8');
      assert.doesNotMatch(html, /localhost:|127\.0\.0\.1:|name="csrf"|method="post"|—/, file);
      for (const secret of [credentials.admin_user, credentials.admin_password, credentials.db_password, qaCredentials.username, qaCredentials.password].filter(Boolean)) {
        assert.ok(!html.includes(secret), `${file}: private value`);
      }
      for (const match of html.matchAll(/(?:href|src|data-src)="([^"]+)"/g)) {
        const url = new URL(match[1].replaceAll('&amp;', '&'), build.base + '/' + file);
        if (url.origin !== new URL(build.base).origin || !url.pathname.startsWith(prefix)) continue;
        const target = decodeURIComponent(url.pathname.slice(prefix.length)) || 'index.html';
        assert.ok(fs.existsSync(path.join(root, target)), `${file} -> ${target}`);
      }
    }
    const page = await browser.newPage({viewport:{width:390,height:844}});
    await page.emulateMedia({reducedMotion:'reduce'});
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('response', response => { if (response.status() >= 400) failures.push(`${response.status()} ${response.url()}`); });
    await page.goto(base + 'telas.html');
    assert.equal(await page.locator('.screens .screen').count(), 20);
    await page.getByRole('link', {name:'Visão geral'}).click();
    assert.equal(new URL(page.url()).pathname, prefix + 'painel-dashboard.html');
    await page.getByRole('navigation', {name:'Navegacao do painel'}).getByRole('link', {name:'Produtos'}).click();
    await page.getByRole('link', {name:'Editar', exact:true}).first().click();
    assert.equal(new URL(page.url()).pathname, prefix + 'painel-produto-editar.html');
    await page.locator('form button').first().click();
    assert.match(await page.locator('.client-preview-notice').innerText(), /Nenhuma alteração foi salva/);
    await page.getByRole('link', {name:'Ver todas as telas'}).click();
    assert.equal(new URL(page.url()).pathname, prefix + 'telas.html');
    await page.getByRole('link', {name:'Página inicial'}).click();
    assert.equal(new URL(page.url()).pathname, prefix + 'index.html');
    await page.getByRole('link', {name:'Ver todas as telas'}).first().click();
    assert.equal(new URL(page.url()).pathname, prefix + 'telas.html');
    for (const route of ['telas.html','painel-login.html','painel-dashboard.html','painel-produtos.html','painel-produto-editar.html','painel-produto-fotos.html','painel-configuracoes.html']) {
      for (const width of [390,1440]) {
        await page.setViewportSize({width,height:900});
        await page.goto(base + route);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${route} overflow ${width}`);
      }
    }
    for (const route of ['telas.html','painel-dashboard.html','painel-produto-editar.html']) {
      await page.goto(base + route);
      await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
      const violations = await page.evaluate(() => axe.run(document, {runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}}));
      assert.deepEqual(violations.violations.map(v => ({id:v.id, nodes:v.nodes.map(n => n.target)})), [], route);
    }
    assert.deepEqual(failures, []);
    const report = {pages:files.length, adminScreens:build.adminScreens.length, indexLinks:20, checks:['Links e recursos locais conferidos em todas as páginas','Nenhum segredo, CSRF, formulário POST ou endereço local no HTML','Navegação loja e painel funcional','Formulários administrativos sem gravação','Telas principais sem overflow em 390 e 1440 pixels','Axe WCAG A/AA sem violações no índice, painel e edição','Nenhum erro JavaScript ou recurso HTTP quebrado']};
    fs.writeFileSync('docs/client-preview-tests.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  } finally {await browser.close();server.close();}
})().catch(error => {console.error(error);process.exitCode = 1;});

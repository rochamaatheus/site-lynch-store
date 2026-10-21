const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');

(async () => {
  const build = JSON.parse(fs.readFileSync('.cache/client-preview-build.json', 'utf8'));
  const base = build.base + '/';
  const files = fs.readdirSync(build.output).filter(file => file.endsWith('.html'));
  const browser = await chromium.launch({channel:'chrome', headless:true});
  try {
    const page = await browser.newPage({viewport:{width:390,height:844}, reducedMotion:'reduce'});
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('response', response => {if (response.status() >= 400) failures.push(`${response.status()} ${response.url()}`);});
    for (let i=0;i<files.length;i+=8) {
      await Promise.all(files.slice(i,i+8).map(async file => {
        const response = await page.request.get(base + file);
        assert.equal(response.status(), 200, file);
      }));
    }
    await page.goto(base + 'telas.html');
    assert.equal(await page.locator('.screens .screen').count(), 20);
    await page.getByRole('link', {name:'Detalhe de pedido'}).click();
    assert.match(await page.locator('h1').innerText(), /Pedido de exemplo/);
    await page.getByRole('link', {name:'Ver todas as telas'}).click();
    await page.getByRole('link', {name:'Lista de produtos'}).click();
    await page.getByRole('link', {name:'Próximos produtos'}).click();
    assert.match(await page.locator('main').innerText(), /Camiseta geek 46/);
    await page.getByRole('link', {name:'Ver todas as telas'}).click();
    await page.getByRole('link', {name:'Página inicial'}).click();
    assert.ok(await page.locator('.card').count() > 0);
    assert.deepEqual(failures, []);
    const report = {at:new Date().toISOString(),url:base + 'telas.html',pages200:files.length,indexLinks:20,checks:['97 páginas online respondem HTTP 200','Índice, detalhe de pedido, paginação do painel e loja navegáveis','Nenhum erro JavaScript ou recurso HTTP quebrado']};
    fs.writeFileSync('docs/live-client-preview-tests.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode = 1;});

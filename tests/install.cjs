const fs = require('node:fs');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
(async()=>{
  const fixture=JSON.parse(fs.readFileSync('.cache/install-test.json'));
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const base='http://localhost:8082';
  const results=[];
  try{
    await page.goto(base+'/instalar.php');
    for(const [key,value] of Object.entries({key:fixture.key,url:base,host:'127.0.0.1',database:fixture.database,user:fixture.user,db_password:fixture.db_password,admin_user:fixture.admin_user,admin_password:fixture.admin_password})){
      await page.locator('[name='+key+']').fill(value);
    }
    await page.getByRole('button',{name:'Instalar loja',exact:true}).click();
    assert.match(await page.locator('body').innerText(),/Sua loja está instalada/);
    results.push('ZIP instala schema, catálogo e admin em banco vazio');
    assert.equal((await page.request.get(base+'/instalar.php')).status(),403);
    results.push('Instalador bloqueado após concluir');
    for(const route of ['/.env','/db/catalog.json','/db/schema.sql','/includes/config.php','/vendor/autoload.php','/install-secret.php','/uploads/.htaccess','/admin/partials/admin-header.php']){
      assert.equal((await page.request.get(base+route)).status(),403,route);
    }
    results.push('Apache aplica .htaccess e bloqueia todos os arquivos sensíveis');
    assert.equal((await page.request.get(base+'/nao-existe')).status(),404);
    assert.equal((await page.request.get(base+'/index.php?busca[]=x')).status(),400);
    results.push('Rota inexistente retorna 404 e parâmetros malformados retornam 400');
    const css=await page.request.get(base+'/assets/css/style.css',{headers:{'Accept-Encoding':'gzip'}});
    assert.equal(css.headers()['content-encoding'],'gzip');
    assert.match(css.headers()['cache-control'],/max-age/);
    results.push('Compressão gzip e cache de assets ativos no Apache');
    assert.match(await (await page.request.get(base+'/robots.txt')).text(),/Sitemap: http:\/\/localhost:8082\/sitemap.xml/);
    const sitemap=await page.request.get(base+'/sitemap.xml');
    assert.equal(sitemap.status(),200);
    assert.equal(((await sitemap.text()).match(/<loc>/g)||[]).length,51);
    results.push('Robots e sitemap usam domínio da instalação e 46 produtos');
    await page.goto(base+'/admin/login.php');
    await page.locator('[name=username]').fill(fixture.admin_user);
    await page.locator('[name=password]').fill(fixture.admin_password);
    await page.getByRole('button',{name:'Entrar',exact:true}).click();
    assert.match(page.url(),/admin\/index.php/);
    await page.goto(base+'/admin/importar.php');
    assert.match(await page.locator('h1').innerText(),/Importar produtos/);
    results.push('Admin novo e importador funcionam sem Composer no servidor');
    await page.goto(base+'/produto.php?slug=peca-de-cosplay-01');
    assert.ok(await page.locator('[data-gallery-image]').evaluate(img=>img.complete && img.naturalWidth>0));
    results.push('Fotos do catálogo presentes no pacote');
    fs.writeFileSync('docs/install-tests.json',JSON.stringify({database:fixture.database,passed:results,count:results.length,at:new Date().toISOString()},null,2));
    console.log(JSON.stringify(results,null,2));
  }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exit(1)});

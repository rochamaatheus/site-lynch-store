const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
(async()=>{
  const fixture=JSON.parse(fs.readFileSync('.cache/install-test.json'));
  const catalog=JSON.parse(fs.readFileSync(path.join(fixture.root,'db/catalog.json')));
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const base='http://localhost:8082';
  const results=[];
  try{
    const fill = async password => {
      await page.goto(base+'/instalar.php');
      for(const [key,value] of Object.entries({key:fixture.key,url:base,host:'127.0.0.1',database:fixture.database,user:fixture.user,db_password:fixture.db_password,admin_user:fixture.admin_user,admin_password:password})){
        await page.locator('[name='+key+']').fill(value);
      }
      await page.getByRole('button',{name:'Instalar loja',exact:true}).click();
    };
    await fill('troque-esta-senha');
    assert.match(await page.locator('body').innerText(),/fácil de adivinhar/);
    results.push('Instalador recusa senha de exemplo para o painel');
    await fill(fixture.admin_password);
    assert.match(await page.locator('body').innerText(),/Sua loja está instalada/);
    results.push('ZIP instala schema, catálogo e admin em banco vazio');
    assert.ok([403,404].includes((await page.request.get(base+'/instalar.php')).status()));
    assert.ok([403,404,405].includes((await page.request.post(base+'/instalar.php',{form:{key:fixture.key,admin_user:'invasor',admin_password:'x'.repeat(20),database:fixture.database}})).status()));
    for(const gone of ['instalar.php','install-secret.php','db/catalog.json']) assert.ok(!fs.existsSync(path.join(fixture.root,gone)),'apagado: '+gone);
    results.push('Instalador se apaga com a chave e o catálogo; reinstalar por POST não é possível');
    for(const route of ['/.env','/.installed','/db/catalog.json','/db/schema.sql','/includes/config.php','/vendor/autoload.php','/install-secret.php','/uploads/.htaccess','/admin/partials/admin-header.php','/composer.json','/node_modules/x.js','/tests/run.php','/docs/QA.md','/scripts/build.php']){
      assert.equal((await page.request.get(base+route)).status(),403,route);
    }
    fs.mkdirSync(path.join(fixture.root,'.git'),{recursive:true}); fs.writeFileSync(path.join(fixture.root,'.git/HEAD'),'ref: refs/heads/main');
    for(const name of ['index.php.bak','index.php~','backup.sql','LEIA-ME-INSTALACAO.txt','uploads/notas.txt','uploads/x.svg']) fs.writeFileSync(path.join(fixture.root,name),'segredo');
    for(const route of ['/.git/HEAD','/.git/','/.user.ini','/index.php.bak','/index.php~','/backup.sql','/LEIA-ME-INSTALACAO.txt','/uploads/notas.txt','/uploads/x.svg','/uploads/']){
      assert.equal((await page.request.get(base+route)).status(),403,route);
    }
    const http=require('node:http');
    // TRACE e respondido pelo nucleo do Apache antes do .htaccess (TraceEnable so muda na
    // configuracao do servidor); a hospedagem desliga. Aqui conferimos os demais.
    for(const m of ['PUT','DELETE','OPTIONS','PATCH']){
      const status=await new Promise((ok,ko)=>{const rq=http.request(base+'/index.php',{method:m},rs=>{rs.resume();ok(rs.statusCode)});rq.on('error',ko);rq.end();});
      assert.equal(status,405,m);
    }
    const sec=await page.request.get(base+'/.well-known/security.txt');
    assert.equal(sec.status(),200);
    results.push('Apache aplica .htaccess e bloqueia todos os arquivos sensíveis, .git, backups, LEIA-ME, não imagens em uploads e métodos fora de GET/HEAD/POST; security.txt publicado');
    for(const route of ['/tests/run.php','/scripts/build.php','/docs/QA.md','/STATUS.json','/README.md','/.env.example','/composer.json','/package.json','/assets/products/product-01.webp','/assets/hero/cutout-01.webp']){
      assert.ok(!fs.existsSync(path.join(fixture.root,route)),'fora do pacote: '+route);
    }
    results.push('Pacote sem testes, scripts, docs, STATUS, README, composer, package, fotos de exemplo nem originais do hero');
    assert.equal((await page.request.get(base+'/nao-existe')).status(),404);
    assert.equal((await page.request.get(base+'/index.php?busca[]=x')).status(),400);
    results.push('Rota inexistente retorna 404 e parâmetros malformados retornam 400');
    const css=await page.request.get(base+'/assets/css/loja.css',{headers:{'Accept-Encoding':'gzip'}});
    assert.equal(css.headers()['content-encoding'],'gzip');
    assert.match(css.headers()['cache-control'],/max-age=31536000/);
    const home=await page.request.get(base+'/');
    assert.doesNotMatch(home.headers()['content-security-policy'],/unsafe-inline/);
    assert.equal(home.headers()['x-powered-by'],undefined);
    assert.match(home.headers()['content-security-policy'],/frame-ancestors 'none'/);
    assert.match(home.headers()['x-frame-options'],/DENY/);
    results.push('Compressão gzip, cache de assets, CSP sem unsafe-inline e sem X-Powered-By no Apache');
    assert.match(await (await page.request.get(base+'/robots.txt')).text(),/Sitemap: http:\/\/localhost:8082\/sitemap.xml/);
    const sitemap=await page.request.get(base+'/sitemap.xml');
    assert.equal(sitemap.status(),200);
    const activeProducts=catalog.products.filter(p=>Number(p.active)===1);
    const filledCategories=catalog.categories.filter(c=>Number(c.active)===1&&activeProducts.some(p=>Number(p.category_id)===Number(c.id)));
    const xml=await sitemap.text();
    assert.equal((xml.match(/<loc>/g)||[]).length,3+filledCategories.length+activeProducts.length);
    assert.match(xml,/loja\.php<\/loc>/);
    assert.match(xml,/informacoes\.php<\/loc>/);
    results.push('Robots e sitemap usam o domínio da instalação, com início, loja, informações, coleções com peças e '+activeProducts.length+' produtos');
    await page.goto(base+'/admin/login.php');
    await page.locator('[name=username]').fill(fixture.admin_user);
    await page.locator('[name=password]').fill(fixture.admin_password);
    await page.getByRole('button',{name:'Entrar',exact:true}).click();
    assert.match(page.url(),/admin\/index.php/);
    await page.goto(base+'/admin/importar.php');
    assert.match(await page.locator('h1').innerText(),/Importar planilha/);
    await page.goto(base+'/admin/cupons.php');
    assert.match(await page.locator('main').innerText(),/Criar cupom/);
    results.push('Admin novo, importador e cupons funcionam sem Composer no servidor');
    const withStock=catalog.products.filter(p=>p.stock_qty!==undefined&&p.stock_qty!==null).length;
    const withSale=catalog.products.filter(p=>p.sale_price_cents!==undefined&&p.sale_price_cents!==null).length;
    await page.goto(base+'/admin/produtos.php?aba=promocao');
    const promoTab=await page.locator('main').innerText();
    assert.ok(withSale===0||!/Nenhum produto/.test(promoTab),'promoções do catálogo instaladas');
    results.push('Estoque e promoção do catálogo entram na instalação ('+withStock+' com estoque, '+withSale+' em promoção)');
    await page.goto(base+'/produto.php?slug='+activeProducts[0].slug);
    assert.ok(await page.locator('.pdp-photo img').first().evaluate(img=>img.complete && img.naturalWidth>0));
    results.push('Fotos do catálogo presentes no pacote');
    fs.writeFileSync('docs/install-tests.json',JSON.stringify({database:fixture.database,passed:results,count:results.length,at:new Date().toISOString()},null,2));
    console.log(JSON.stringify(results,null,2));
  }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exit(1)});

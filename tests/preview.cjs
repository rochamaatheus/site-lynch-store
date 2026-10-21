const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {createPreviewServer} = require('../scripts/serve-preview.cjs');
(async () => {
  const build=JSON.parse(fs.readFileSync('.cache/preview-build.json'));
  const prefix=new URL(build.base).pathname+'/', root=build.output;
  const server=createPreviewServer(root,prefix);
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port+prefix;
  const results=[], errors=[], failed=[], links=new Set();
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  page.on('pageerror',e=>errors.push(e.message));
  page.on('response',r=>{if(r.status()>=400)failed.push(r.url());});
  try {
    const products=JSON.parse(fs.readFileSync(path.join(root,'products.json')));
    assert.equal(products.length,46);
    for(const file of fs.readdirSync(root).filter(f=>f.endsWith('.html'))){
      const html=fs.readFileSync(path.join(root,file),'utf8');
      assert.doesNotMatch(html, /localhost|127\.0\.0\.1|name="csrf"|method="post"|\.env|DB_PASS/);
      assert.match(html,/noindex, nofollow/);
      for(const match of html.matchAll(/(?:href|src|data-src)="([^"]+)"/g)){
        const url=new URL(match[1].replaceAll('&amp;','&'),build.base+'/'+file);
        if(url.origin===new URL(build.base).origin && url.pathname.startsWith(prefix)){
          const target=decodeURIComponent(url.pathname.slice(prefix.length))||'index.html';
          assert.ok(fs.existsSync(path.join(root,target)),file+' -> '+target);
          links.add(target);
        }
      }
    }
    results.push('46 produtos; todas as páginas e recursos locais existem; sem PHP, POST, CSRF ou endereços locais no HTML');
    await page.goto(base);
    await page.getByRole('button',{name:'Abrir menu',exact:true}).click();
    assert.equal(await page.locator('main').evaluate(el=>el.inert),true);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('main').evaluate(el=>el.inert),false);
    await page.locator('#busca-mobile').fill('cosplay');
    await page.locator('.mobile-search button').click();
    await page.waitForSelector('#preview-results .card');
    assert.ok(await page.locator('#preview-results .card').count()>0);
    assert.match(await page.locator('#search-status').innerText(),/peças encontradas/);
    results.push('Busca mobile com resultados e menu acessível');
    await page.locator('#preview-results .buy').first().click();
    await page.locator('.preview-toast a').click();
    await page.waitForSelector('.preview-cart-item');
    assert.equal(await page.locator('.qty-input').inputValue(),'1');
    await page.getByRole('button',{name:/Aumentar quantidade/}).click();
    await page.waitForFunction(()=>document.querySelector('.qty-input')?.value==='2');
    await page.reload();
    await page.waitForFunction(()=>document.querySelector('.qty-input')?.value==='2');
    await page.getByRole('button',{name:'Simular finalização'}).click();
    assert.match(await page.locator('.preview-toast').innerText(),/Nenhum|Demonstração concluída/);
    assert.equal(new URL(page.url()).pathname,prefix+'carrinho.html');
    results.push('Carrinho adiciona, altera e persiste quantidade; finalização não envia pedido');
    const routes=['index.html','busca.html?busca=cosplay','carrinho.html','produto-peca-de-cosplay-01.html','informacoes.html','404.html'];
    for(const route of routes){
      for(const width of [320,390,768,1440]){
        await page.setViewportSize({width,height:900});
        await page.goto(base+route);
        if(route.startsWith('busca'))await page.waitForSelector('#preview-results .card');
        if(route==='carrinho.html')await page.waitForSelector('.preview-cart-item');
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),route+' overflow '+width);
      }
      await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
      const axe=await page.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}}));
      assert.deepEqual(axe.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})),[],route);
    }
    results.push('6 telas em 320/390/768/1440px sem overflow; axe WCAG A/AA sem violações');
    await page.setViewportSize({width:390,height:844});
    await page.goto(base+'carrinho.html');
    await page.waitForSelector('.preview-cart-item');
    await page.screenshot({path:'docs/qa-preview-cart-mobile.png',fullPage:true});
    await page.getByRole('button',{name:/^Remover /}).click();
    await page.waitForSelector('.empty');
    results.push('Remoção restaura estado vazio do carrinho');
    await page.goto(base+'busca.html?busca='+encodeURIComponent('<script>alert(1)</script>'));
    await page.waitForFunction(()=>document.getElementById('search-status')?.textContent.includes('Nenhuma'));
    assert.equal(await page.locator('#preview-results .card').count(),0);
    results.push('Busca vazia e entrada HTML tratadas como texto');
    await page.goto(base+'produto-peca-de-cosplay-01.html');
    await page.evaluate((other)=>{
      const stage=document.querySelector('[data-gallery]');
      const button=document.createElement('button');button.dataset.thumb='';button.dataset.src=other;button.dataset.alt='Outra foto de teste';button.textContent='Trocar foto';stage.after(button);
    },products.find(p=>!p.url.includes('peca-de-cosplay-01')).image);
    await page.addScriptTag({url:base+'assets/js/gallery.js'});
    await page.getByRole('button',{name:'Trocar foto',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-gallery-image]').alt==='Outra foto de teste');
    assert.equal(await page.locator('[data-thumb]').last().getAttribute('aria-pressed'),'true');
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.getByRole('button',{name:'Trocar foto',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('[data-gallery]').hasAttribute('aria-busy'));
    assert.equal(await page.locator('[data-gallery-image]').evaluate(el=>el.getAnimations().length),0);
    assert.equal(await page.locator('.btn').first().evaluate(el=>getComputedStyle(el).transitionDuration),'0s');
    results.push('Galeria troca foto e estado acessível; movimento reduzido desativa animações');
    await page.goto(base);
    await page.screenshot({path:'docs/qa-preview-home-mobile.png',fullPage:true});
    const nojs=await browser.newPage({javaScriptEnabled:false});
    await nojs.goto(base);
    await nojs.locator('.collection').first().click();
    await nojs.waitForFunction(()=>!document.getAnimations().some(animation=>animation.playState==='running'));
    assert.ok(await nojs.locator('.product-grid .card').count()>0);
    await nojs.locator('.pic-link').first().click();
    assert.ok(await nojs.locator('h1').innerText());
    await nojs.close();
    results.push('Coleções e produtos navegáveis sem JavaScript');
    assert.deepEqual(errors,[]);
    assert.deepEqual(failed,[]);
    results.push('Nenhum erro JavaScript ou recurso HTTP quebrado');
    fs.writeFileSync('docs/preview-tests.json',JSON.stringify({at:new Date().toISOString(),pages:build.pages,products:products.length,linkedResources:links.size,results},null,2));
    console.log(JSON.stringify({pages:build.pages,linkedResources:links.size,results},null,2));
  } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exit(1);});

const fs=require('node:fs');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
  const build=JSON.parse(fs.readFileSync('.cache/preview-build.json'));
  const base=build.base+'/';
  assert.equal(base,'https://rochamaatheus.github.io/site-lynch-store/');
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const errors=[],failed=[];
  page.on('pageerror',error=>errors.push(error.message));
  page.on('response',response=>{if(response.status()>=400)failed.push({url:response.url(),status:response.status()});});
  try{
    const routes=[...new Set([...Object.values(build.routes),'busca.html'])];
    for(let i=0;i<routes.length;i+=5){
      await Promise.all(routes.slice(i,i+5).map(async route=>{
        const response=await page.request.get(base+route);
        assert.equal(response.status(),200,route);
        assert.match(await response.text(),/id="preview-notice"/,route);
      }));
    }
    const catalog=await (await page.request.get(base+'products.json')).json();
    assert.equal(catalog.length,46);
    const response=await page.goto(base);
    assert.equal(response.status(),200);
    assert.match(await page.locator('#preview-notice').innerText(),/Prévia para a Ray/);
    await page.screenshot({path:'docs/qa-live-home-mobile.png'});
    await page.locator('#busca-mobile').fill('cosplay');
    await page.locator('.mobile-search button').click();
    await page.waitForSelector('#preview-results .card');
    await page.locator('#preview-results .pic-link').first().click();
    await page.locator('.pcta-form button').click();
    await page.locator('.preview-toast a').click();
    await page.waitForSelector('.preview-cart-item');
    await page.waitForFunction(()=>!document.getAnimations().some(animation=>animation.playState==='running'));
    await page.getByRole('button',{name:'Simular finalização'}).click();
    await page.waitForFunction(()=>document.querySelector('.preview-toast').textContent.includes('Demonstração concluída'));
    assert.match(await page.locator('.preview-toast').innerText(),/Demonstração concluída/);
    assert.equal(new URL(page.url()).origin,new URL(base).origin);
    assert.equal(await page.locator('.qty-input').inputValue(),'1');
    await page.screenshot({path:'docs/qa-live-cart-mobile.png'});
    assert.deepEqual(errors,[]);assert.deepEqual(failed,[]);
    const report={at:new Date().toISOString(),url:base,pages200:routes.length,products:catalog.length,checks:['Páginas do deploy respondem HTTP 200 com a marca da prévia','Busca mobile, produto e adição ao carrinho verificados online','Finalização demonstrativa permanece no site e não envia pedido','Nenhum erro JavaScript ou recurso quebrado nos fluxos online']};
    fs.writeFileSync('docs/live-preview-tests.json',JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});

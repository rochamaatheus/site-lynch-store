// Ensaios de seguranca por HTTP contra a copia de TESTE (nunca producao).
// Confere cabecalhos, metodos, arquivos internos, parametros malformados, XSS
// guardado, CSRF/Origin, defesas do envio de pedido e concorrencia real (varias
// instancias php -S ao mesmo tempo, mesmo banco e mesma pasta de sessoes).
//
//   $env:LYNCH_ENV_FILE="<env de teste>"; $env:QA_URL="http://localhost:8020"; node tests/security.cjs
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const base = process.env.QA_URL || 'http://localhost:8020';
if (!/^http:\/\/(localhost|127\.0\.0\.1):/.test(base)) throw new Error('Somente ambiente local.');
const root = path.join(__dirname, '..');
const extraPorts = (process.env.QA_EXTRA_PORTS || '8031,8032,8033').split(',').map(Number);
const results = [];
const failures = [];
const sleep = ms => new Promise(r => setTimeout(r, ms));
const helper = (...args) => JSON.parse(execFileSync('php', [path.join(__dirname, 'security-helper.php'), ...args], {cwd: root, encoding: 'utf8'}));

class Client {
  constructor(url = base) { this.url = url; this.jar = new Map(); }
  cookieHeader() { return [...this.jar].map(([k, v]) => k + '=' + v).join('; '); }
  async req(route, {method = 'GET', form, headers = {}, url} = {}) {
    const h = {...headers};
    if (this.jar.size) h.cookie = this.cookieHeader();
    let body;
    if (form) { body = new URLSearchParams(form).toString(); h['content-type'] = 'application/x-www-form-urlencoded'; }
    const res = await fetch((url || this.url) + route, {method, headers: h, body, redirect: 'manual'});
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';');
      const i = pair.indexOf('=');
      const name = pair.slice(0, i), value = pair.slice(i + 1);
      if (/expires=Thu, 01 Jan 1970|Max-Age=0/i.test(c) || value === 'deleted') this.jar.delete(name); else this.jar.set(name, value);
    }
    res.text_ = await res.text();
    return res;
  }
  async csrf(route) {
    const r = await this.req(route);
    const m = r.text_.match(/name="csrf" value="([a-f0-9]+)"/g);
    if (!m) throw new Error('sem csrf em ' + route);
    return m[m.length - 1].match(/value="([a-f0-9]+)"/)[1];
  }
}
const check = async (label, fn) => {
  try { await fn(); results.push(label); } catch (e) { failures.push(label + ' :: ' + e.message.split('\n')[0]); }
};

(async () => {
  const servers = [];
  try {
    helper('clear-limits');
    const sx = Date.now().toString(36);
    const data = helper('setup', sx);
    const productPage = '/produto.php?slug=qa-seg-' + sx.replace(/[^a-z0-9]/g, '') + '-preco';
    const creds = JSON.parse(fs.readFileSync(path.join(root, '.qa-credentials.json'), 'utf8'));

    // ---------------- 1. Cabecalhos e cookies ----------------
    await check('Cabeçalhos da loja: frame-ancestors none, DENY, COOP, CORP, no-store, sem CORS e sem X-Powered-By', async () => {
      const r = await new Client().req('/');
      const h = r.headers;
      assert.match(h.get('content-security-policy'), /frame-ancestors 'none'/);
      assert.match(h.get('content-security-policy'), /style-src 'self' 'sha256-SREix9zPMZHrSuo8zRSjb672r1gsHIh96MJuaZq6iJo='/);
      assert.doesNotMatch(h.get('content-security-policy'), /unsafe-inline|unsafe-eval/);
      assert.equal(h.get('x-frame-options'), 'DENY');
      assert.equal(h.get('cross-origin-opener-policy'), 'same-origin');
      assert.equal(h.get('cross-origin-resource-policy'), 'same-origin');
      assert.match(h.get('cache-control'), /no-store/);
      assert.equal(h.get('access-control-allow-origin'), null);
      assert.equal(h.get('x-powered-by'), null);
      const ck = h.getSetCookie().join('\n');
      assert.match(ck, /LYNCHSESSID=[^;]+; path=\/; HttpOnly; SameSite=Lax/);
    });
    await check('Painel com sessão própria SameSite=Strict, separada da loja', async () => {
      const r = await new Client().req('/admin/login.php');
      const ck = r.headers.getSetCookie().join('\n');
      assert.match(ck, /LYNCHADM=[^;]+; path=\/; HttpOnly; SameSite=Strict/);
      assert.doesNotMatch(ck, /LYNCHSESSID/);
    });

    // ---------------- 2. Metodos e arquivos internos ----------------
    await check('TRACE, PUT, DELETE e OPTIONS recebem 405', async () => {
      const http = require('node:http');
      for (const m of ['TRACE', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']) {
        const status = await new Promise((ok, ko) => { const rq = http.request(base + '/', {method: m}, rs => { rs.resume(); ok(rs.statusCode); }); rq.on('error', ko); rq.end(); });
        assert.equal(status, 405, m);
      }
    });
    await check('Arquivos internos, backups e ocultos bloqueados; security.txt servido', async () => {
      for (const p of ['/.env', '/.git/HEAD', '/.user.ini', '/includes/config.php', '/includes/SecurityLog.php', '/db/schema.sql', '/vendor/autoload.php', '/tests/security-helper.php', '/scripts/build.php', '/docs/QA.md', '/STATUS.json', '/README.md', '/composer.json', '/package.json', '/index.php.bak', '/index.php~', '/x.sql', '/LEIA-ME-INSTALACAO.txt', '/uploads/.htaccess', '/uploads/x.php', '/admin/partials/admin-header.php']) {
        const r = await fetch(base + p, {redirect: 'manual'});
        assert.equal(r.status, 403, p);
      }
      const st = await fetch(base + '/.well-known/security.txt');
      assert.equal(st.status, 200);
      assert.match(await st.text(), /^Contact: /m);
    });

    // ---------------- 3. Entrada malformada, SQL e Host ----------------
    await check('Parâmetros em array (poluição) recusados com 400', async () => {
      assert.equal((await fetch(base + '/loja.php?busca[]=x')).status, 400);
      const c = new Client();
      const token = await c.csrf(productPage);
      const r = await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', 'product_id[]': '1'}});
      assert.equal(r.status, 400);
    });
    await check('Busca e filtros com SQL, curinga e cursor forjado não quebram nem vazam erro', async () => {
      for (const q of ["' OR 1=1-- -", '%', '_', '\\', "1' UNION SELECT password_hash FROM admin_users-- -", 'a'.repeat(5000), '%00', 'é́', '＇ OR 1=1']) {
        for (const route of ['/loja.php?busca=' + encodeURIComponent(q), '/busca.php?q=' + encodeURIComponent(q)]) {
          const r = await fetch(base + route);
          const t = await r.text();
          assert.ok(r.status === 200, route + ' ' + r.status);
          assert.doesNotMatch(t, /SQLSTATE|PDOException|Fatal error|Warning:|\$2y\$|\$argon2/);
        }
      }
      assert.equal(await (await fetch(base + '/busca.php?q=' + encodeURIComponent('%%'))).text(), '[]', 'curinga do LIKE escapado');
      for (const route of ['/loja.php?ordem=' + encodeURIComponent('preco_asc;DROP TABLE products'), '/loja.php?mostrar=99999999', '/loja.php?mostrar=-5', '/loja.php?categoria=' + encodeURIComponent("x' OR '1'='1"), '/produto.php?slug=' + encodeURIComponent("' OR 1=1-- -")]) {
        const r = await fetch(base + route);
        assert.ok([200, 404].includes(r.status), route + ' ' + r.status);
        assert.doesNotMatch(await r.text(), /SQLSTATE|Fatal error/);
      }
    });
    await check('Cabeçalho Host forjado não entra em links nem redirecionamentos', async () => {
      const r = await fetch(base + '/', {headers: {Host: 'evil.test', 'X-Forwarded-Host': 'evil.test'}});
      assert.doesNotMatch(await r.text(), /evil\.test/);
      const lg = await fetch(base + '/admin/index.php', {headers: {Host: 'evil.test'}, redirect: 'manual'});
      assert.doesNotMatch(lg.headers.get('location') || '', /evil\.test/);
    });
    await check('Redirecionamento aberto e CRLF recusados no login e na sacola', async () => {
      const c = new Client();
      const r = await c.req('/admin/login.php?redirect=' + encodeURIComponent('//evil.test/x'));
      assert.doesNotMatch(r.text_, /evil\.test/);
      const token = await c.csrf(productPage);
      const a = await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', product_id: String(data.product), redirect_to: '/x\r\nSet-Cookie: a=b'}});
      assert.equal(a.status, 303);
      assert.doesNotMatch(a.headers.get('location') || '', /Set-Cookie|evil/);
      const b = await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', product_id: String(data.product), redirect_to: 'https://evil.test/'}});
      assert.doesNotMatch(b.headers.get('location') || '', /evil/);
    });

    // ---------------- 4. XSS guardado (nome/descricao de produto) ----------------
    await check('Texto malicioso salvo no produto sai escapado na página, no JSON-LD, na busca e na sacola', async () => {
      const p = await fetch(base + '/produto.php?slug=' + data.xss_slug);
      const t = await p.text();
      assert.equal(p.status, 200);
      assert.doesNotMatch(t, /<script>alert|<img src=x|<svg onload/);
      const ld = t.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
      assert.ok(ld && !/[<>]/.test(ld[1]), 'JSON-LD sem < ou > crus');
      const s = await (await fetch(base + '/busca.php?q=' + encodeURIComponent('QA <script>'))).text();
      assert.doesNotMatch(s, /<script>/);
      const loja = await (await fetch(base + '/loja.php?busca=QA')).text();
      assert.doesNotMatch(loja, /<script>alert|<img src=x/);
    });

    // ---------------- 5. CSRF e origem ----------------
    await check('Token certo vindo de outro site (Origin/Sec-Fetch-Site) é recusado', async () => {
      const c = new Client();
      const token = await c.csrf(productPage);
      await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', product_id: String(data.product)}, headers: {Origin: 'https://evil.test'}});
      await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', product_id: String(data.product)}, headers: {'Sec-Fetch-Site': 'cross-site'}});
      const bag = await c.req('/carrinho.php');
      assert.doesNotMatch(bag.text_, /QA seg preco/);
      const ok = await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'add', product_id: String(data.product)}, headers: {Origin: base, 'Sec-Fetch-Site': 'same-origin'}});
      assert.equal(ok.status, 303);
      assert.match((await c.req('/carrinho.php')).text_, /QA seg preco/);
    });
    await check('Logout exige POST com token; GET não desloga', async () => {
      assert.equal((await fetch(base + '/admin/logout.php')).status, 405);
    });

    // ---------------- 6. Painel sem sessão, método errado e IDs ruins ----------------
    await check('Painel sem sessão: toda página e ação manda para o login', async () => {
      for (const p of ['index.php', 'produtos.php', 'produto-form.php', 'produto-salvar.php', 'produto-rapido.php', 'produto-imagens.php?id=1', 'produto-excluir.php', 'categorias.php', 'categoria-salvar.php', 'categoria-excluir.php', 'cupons.php', 'configuracoes.php', 'importar.php', 'exportar.php', 'pedidos.php', 'conta.php', 'mais.php', 'ping.php']) {
        for (const method of ['GET', 'POST']) {
          const r = await fetch(base + '/admin/' + p, {method, redirect: 'manual', body: method === 'POST' ? 'id=1&op=stock_dec' : undefined, headers: method === 'POST' ? {'content-type': 'application/x-www-form-urlencoded'} : {}});
          assert.equal(r.status, 302, method + ' ' + p);
          assert.match(r.headers.get('location'), /\/admin\/login\.php/);
        }
      }
    });

    const admin = new Client();
    await check('Login do painel troca o ID da sessão (fixação) e grava a auditoria', async () => {
      const token = await admin.csrf('/admin/login.php');
      const before = [...admin.jar].find(([k]) => /LYNCHADM/.test(k))[1];
      const r = await admin.req('/admin/login.php', {method: 'POST', form: {csrf: token, username: creds.username, password: creds.password}});
      assert.equal(r.status, 302);
      assert.match(r.headers.get('location'), /admin\/index\.php/);
      const after = [...admin.jar].find(([k]) => /LYNCHADM/.test(k))[1];
      assert.notEqual(before, after);
      assert.ok([...admin.jar].some(([k]) => /lynch_aparelho/.test(k)), 'cookie de aparelho conhecido');
      const conta = await admin.req('/admin/conta.php');
      assert.match(conta.text_, /Entrou no painel/);
    });
    await check('Mensagem de erro de login igual para usuário inexistente e senha errada', async () => {
      const a = new Client(); const t1 = await a.csrf('/admin/login.php');
      const r1 = await a.req('/admin/login.php', {method: 'POST', form: {csrf: t1, username: 'nao-existe-' + sx, password: 'qualquer-coisa-123'}});
      const b = new Client(); const t2 = await b.csrf('/admin/login.php');
      const r2 = await b.req('/admin/login.php', {method: 'POST', form: {csrf: t2, username: creds.username, password: 'senha-errada-' + sx}});
      const msg = t => (t.match(/<p class="alert" role="alert">([^<]+)</) || [])[1];
      assert.ok(msg(r1.text_));
      assert.equal(msg(r1.text_), msg(r2.text_));
      helper('clear-limits');
    });
    await check('Ação rápida: GET não altera, id inexistente/negativo e operação desconhecida recusados', async () => {
      const g = await admin.req('/admin/produto-rapido.php?id=' + data.stock_product + '&op=stock_dec');
      assert.equal(g.status, 303);
      const token = await admin.csrf('/admin/produtos.php');
      for (const form of [{id: '-1', op: 'stock_dec'}, {id: '999999999', op: 'stock_dec'}, {id: String(data.stock_product), op: 'drop'}, {id: String(data.stock_product), op: 'stock_set', stock: '-5'}, {id: String(data.stock_product), op: 'stock_set', stock: '99999999999'}]) {
        const r = await admin.req('/admin/produto-rapido.php', {method: 'POST', form: {csrf: token, ...form}, headers: {'X-Lynch-Ajax': '1'}});
        assert.equal(r.status, 422, JSON.stringify(form));
      }
      const noCsrf = await admin.req('/admin/produto-rapido.php', {method: 'POST', form: {id: String(data.stock_product), op: 'stock_dec'}, headers: {'X-Lynch-Ajax': '1'}});
      assert.equal(noCsrf.status, 422);
      assert.equal(helper('read', data.coupon, String(data.stock_product), String(data.last_order)).stock, 1);
    });

    // ---------------- 7. Envio do pedido: robo, isca e limite ----------------
    const fillBag = async (c, qty = 1, coupon) => {
      const token = await c.csrf(productPage);
      await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'update', product_id: String(data.product), qty: String(qty)}});
      if (coupon) await c.req('/carrinho.php', {method: 'POST', form: {csrf: token, action: 'coupon_apply', coupon}});
      return token;
    };
    await check('Sessão recém-criada que envia na hora e campo isca preenchido não geram pedido', async () => {
      const c = new Client();
      const token = await fillBag(c);
      const fast = await c.req('/finalizar.php', {method: 'POST', form: {csrf: token}});
      assert.doesNotMatch(fast.headers.get('location') || '', /wa\.me/);
      await sleep(3200);
      const bot = await c.req('/finalizar.php', {method: 'POST', form: {csrf: token, site_url: 'http://spam.test'}});
      assert.doesNotMatch(bot.headers.get('location') || '', /wa\.me/);
      const ok = await c.req('/finalizar.php', {method: 'POST', form: {csrf: token, site_url: ''}});
      assert.match(ok.headers.get('location') || '', /^https:\/\/wa\.me\//);
    });
    await check('Limite de pedidos novos por sessão (10 por hora); repetir o mesmo envio não conta', async () => {
      helper('clear-limits');
      const c = new Client();
      await fillBag(c);
      await sleep(3200);
      let made = 0, blocked = false;
      for (let q = 1; q <= 12; q++) {
        const token = await fillBag(c, q);
        const r = await c.req('/finalizar.php', {method: 'POST', form: {csrf: token}});
        if (/wa\.me/.test(r.headers.get('location') || '')) made++; else { blocked = true; break; }
        const again = await c.req('/finalizar.php', {method: 'POST', form: {csrf: token}});
        assert.match(again.headers.get('location') || '', /wa\.me/, 'repetição continua valendo');
      }
      assert.equal(made, 10);
      assert.ok(blocked);
      const bag = await c.req('/carrinho.php');
      assert.match(bag.text_, /muitos pedidos/);
      assert.match(bag.text_, /QA seg preco/, 'sacola mantida');
    });

    // ---------------- 8. Concorrencia real ----------------
    for (const port of extraPorts) {
      const p = spawn('php', ['-S', 'localhost:' + port, 'scripts/serve.php'], {cwd: root, env: process.env, stdio: 'ignore'});
      servers.push(p);
    }
    await sleep(1200);
    const urls = [base, ...extraPorts.map(p => 'http://localhost:' + p)];

    await check('Cupom de uso único: 8 envios simultâneos em 4 servidores, só 1 leva o desconto', async () => {
      helper('clear-limits');
      const clients = [];
      for (let i = 0; i < 8; i++) {
        const c = new Client(urls[i % urls.length]);
        c.token = await fillBag(c, 1, data.coupon);
        assert.match((await c.req('/carrinho.php')).text_, new RegExp(data.coupon));
        clients.push(c);
      }
      await sleep(3200);
      const res = await Promise.all(clients.map(c => c.req('/finalizar.php', {method: 'POST', form: {csrf: c.token}})));
      const withCoupon = res.filter(r => /wa\.me/.test(r.headers.get('location') || '') && decodeURIComponent(r.headers.get('location')).includes('Cupom ' + data.coupon));
      assert.equal(withCoupon.length, 1, 'pedidos com o cupom: ' + withCoupon.length);
      assert.equal(helper('read', data.coupon, String(data.stock_product), String(data.last_order)).coupon_uses, 1);
    });
    await check('Estoque do último item: 8 baixas simultâneas pelo painel deixam 0, nunca negativo', async () => {
      const admins = [];
      for (let i = 0; i < 4; i++) {
        const a = new Client(urls[i % urls.length]);
        const t = await a.csrf('/admin/login.php');
        await a.req('/admin/login.php', {method: 'POST', form: {csrf: t, username: creds.username, password: creds.password}});
        a.token = await a.csrf('/admin/produtos.php');
        admins.push(a);
      }
      const reqs = [];
      for (let i = 0; i < 8; i++) {
        const a = admins[i % admins.length];
        reqs.push(a.req('/admin/produto-rapido.php', {method: 'POST', form: {csrf: a.token, id: String(data.stock_product), op: 'stock_dec'}, headers: {'X-Lynch-Ajax': '1'}, url: urls[i % urls.length]}));
      }
      await Promise.all(reqs);
      assert.equal(Number(helper('read', data.coupon, String(data.stock_product), String(data.last_order)).stock), 0);
    });
  } finally {
    for (const p of servers) p.kill();
    try { helper('clear-limits'); } catch {}
  }
  fs.writeFileSync(path.join(root, 'docs/security-tests.json'), JSON.stringify({passed: results, failed: failures, count: results.length, at: new Date().toISOString()}, null, 2));
  console.log(JSON.stringify({passed: results, failed: failures}, null, 2));
  if (failures.length) process.exit(1);
})().catch(e => { console.error(e); process.exit(1); });

(function () {
  'use strict';
  var base = document.body.dataset.previewBase;
  var key = 'lynch-preview-cart-v1';
  var cart = {};
  var catalog;
  var timeout;
  function read() {
    try {
      var saved = JSON.parse(localStorage.getItem(key) || '{}');
      if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
        cart = {};
        Object.keys(saved).slice(0, 100).forEach(function (id) {
          if (/^[1-9][0-9]{0,9}$/.test(id) && Number.isInteger(saved[id]) && saved[id] > 0 && saved[id] <= 99) cart[id] = saved[id];
        });
      }
    } catch (_) { cart = {}; }
  }
  function save() {
    try { localStorage.setItem(key, JSON.stringify(cart)); } catch (_) {}
    badges();
  }
  function badges() {
    var count = Object.values(cart).reduce(function (a, b) { return a + b; }, 0);
    document.querySelectorAll('.cart-count').forEach(function (badge) {
      badge.textContent = String(count);
      badge.dataset.count = String(count);
      badge.closest('a').setAttribute('aria-label', 'Ver carrinho (' + (count ? count + ' itens' : 'vazio') + ')');
    });
    document.querySelectorAll('.mobile-dock a').forEach(function (a) {
      if (a.getAttribute('href') === base + 'carrinho.html') {
        Array.from(a.childNodes).filter(function (n) { return n.nodeType === 3; }).forEach(function (n) { n.textContent = 'Carrinho' + (count ? ' (' + count + ')' : ''); });
      }
    });
  }
  function notify(message, withLink) {
    var toast = document.querySelector('.preview-toast');
    toast.replaceChildren(document.createTextNode(message));
    if (withLink) {
      var link = document.createElement('a'); link.href = base + 'carrinho.html'; link.textContent = 'Ver carrinho'; toast.appendChild(link);
    }
    toast.classList.add('show');
    clearTimeout(timeout);
    timeout = setTimeout(function () { toast.classList.remove('show'); }, 5000);
  }
  function products() {
    if (!catalog) catalog = fetch(base + 'products.json').then(function (r) { if (!r.ok) throw new Error('Catálogo indisponível'); return r.json(); });
    return catalog;
  }
  function normalize(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
  function node(tag, className, text) {
    var el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el;
  }
  function link(text, href, className) { var el = node('a', className, text); el.href = href; return el; }
  function money(cents) { return new Intl.NumberFormat('pt-BR', {style:'currency',currency:'BRL'}).format(cents / 100); }
  async function renderCart() {
    var items = await products();
    var valid = new Map(items.map(function (p) { return [String(p.id), p]; }));
    Object.keys(cart).forEach(function (id) { if (!valid.has(id)) delete cart[id]; });
    save();
    var section = document.querySelector('.cart-page');
    if (!section) return;
    section.querySelectorAll('.empty,.cart-grid,.preview-cart-status').forEach(function (el) { el.remove(); });
    var selected = items.filter(function (p) { return cart[p.id]; });
    if (!selected.length) {
      var empty = node('div', 'empty'); empty.append(node('h2', '', 'Seu carrinho está vazio'), node('p', '', 'Escolha uma peça para experimentar o carrinho desta prévia.'), link('Ver produtos', base + 'index.html#produtos', 'btn primary')); section.appendChild(empty); return;
    }
    var layout = node('div', 'cart-grid'), list = node('div', 'cart-lines');
    selected.forEach(function (p) {
      var row = node('article', 'cart-row preview-cart-item');
      var thumb = link('', p.url, 'cart-thumb'), img = node('img'); img.src = p.image; img.alt = p.name; img.width = 120; img.height = 150; thumb.appendChild(img);
      var info = node('div', 'cart-info'), title = node('h3'); title.appendChild(link(p.name, p.url)); info.append(title, node('span','cart-meta',p.meta), node('span','cart-unit',p.price));
      var controls = node('div','cart-qty'), label = node('label','visually-hidden','Quantidade de ' + p.name), input = node('input','qty-input');
      input.type='number'; input.min='1'; input.max='99'; input.value=String(cart[p.id]); input.id='qty-'+p.id; input.inputMode='numeric'; label.htmlFor=input.id;
      function update(value) {
        if (!Number.isInteger(value) || value < 1 || value > 99) { input.value=String(cart[p.id]); notify('Escolha uma quantidade de 1 a 99.'); return; }
        cart[p.id]=value; save(); renderCart().then(function () { document.getElementById(input.id)?.focus(); }); notify('Quantidade atualizada.');
      }
      var minus=node('button','qty-step','−'), plus=node('button','qty-step','+');
      minus.type=plus.type='button'; minus.setAttribute('aria-label','Diminuir quantidade de '+p.name); plus.setAttribute('aria-label','Aumentar quantidade de '+p.name);
      minus.disabled=cart[p.id]===1; plus.disabled=cart[p.id]===99;
      minus.addEventListener('click',function(){update(cart[p.id]-1);}); plus.addEventListener('click',function(){update(cart[p.id]+1);}); input.addEventListener('change',function(){update(Number(input.value));});
      controls.append(minus,label,input,plus);
      var end=node('div','cart-line-end'), remove=node('button','cart-remove','Remover');
      remove.type='button'; remove.setAttribute('aria-label','Remover '+p.name); remove.addEventListener('click',function(){delete cart[p.id];save();renderCart().then(function(){section.querySelector('.cart-remove,.empty a')?.focus();});notify('Peça removida.');});
      end.append(node('span','cart-subtotal',p.cents===null?'Consultar valor':money(p.cents*cart[p.id])),remove);
      row.append(thumb,info,controls,end); list.appendChild(row);
    });
    var aside=node('aside','cart-summary'); aside.appendChild(node('h2','','Resumo'));
    var fixed=selected.filter(function(p){return p.cents!==null;}), total=fixed.reduce(function(sum,p){return sum+p.cents*cart[p.id];},0), totalRow=node('div','cart-total');
    totalRow.append(node('span','','Total estimado'),node('b','',fixed.length?money(total):'A combinar')); aside.appendChild(totalRow);
    if(selected.some(function(p){return p.cents===null;}))aside.appendChild(node('p','cart-note','Peças com valor sob consulta não entram no total.'));
    aside.appendChild(node('p','preview-cart-message','Este carrinho é uma demonstração para aprovação. Nenhum pedido será enviado.'));
    var checkout=node('button','btn primary cart-checkout','Simular finalização'); checkout.type='button';
    checkout.addEventListener('click',function(){notify('Demonstração concluída. Na loja publicada, esta etapa abrirá o WhatsApp com as peças escolhidas.');});
    aside.append(checkout,link('Continuar explorando',base+'index.html#produtos','btn'));
    layout.append(list,aside); section.appendChild(layout);
  }
  async function search() {
    var query=(new URLSearchParams(location.search).get('busca')||'').slice(0,80).trim();
    document.querySelectorAll('[name=busca]').forEach(function(input){input.value=query;});
    document.querySelector('.preview-search h1').textContent=query?'Resultados para “'+query+'”':'Todas as peças';
    var terms=normalize(query).split(/\s+/).filter(Boolean), found=(await products()).filter(function(p){var hay=normalize(p.name+' '+p.meta);return terms.every(function(term){return hay.includes(term);});});
    document.getElementById('search-status').textContent=found.length?found.length+' peças encontradas.':'Nenhuma peça encontrada. Tente outro nome ou explore as coleções.';
    var grid=document.getElementById('preview-results');
    found.forEach(function(p){var template=document.createElement('template');template.innerHTML=p.card;grid.appendChild(template.content);});
    if(!found.length)grid.appendChild(link('Ver todas as peças',base+'busca.html','btn primary'));
  }
  read(); badges();
  document.addEventListener('submit',function(event){
    var form=event.target.closest('[data-preview-add]');if(!form)return;event.preventDefault();
    var id=form.querySelector('[name=product_id]').value;
    if(!/^[1-9][0-9]{0,9}$/.test(id))return;
    if((cart[id]||0)>=99){notify('O limite por peça é 99 unidades.');return;}
    cart[id]=(cart[id]||0)+1;save();notify('Peça adicionada.',true);
  });
  document.addEventListener('click',function(event){
    if(event.target.closest('[data-preview-checkout]')){event.preventDefault();notify('O atendimento será ativado na publicação definitiva.');}
  });
  window.addEventListener('storage',function(event){if(event.key===key){read();badges();if(document.body.dataset.previewPage==='carrinho')renderCart();}});
  window.addEventListener('pageshow',function(event){if(event.persisted){read();badges();if(document.body.dataset.previewPage==='carrinho')renderCart();}});
  var task=document.body.dataset.previewPage==='busca'?search():document.body.dataset.previewPage==='carrinho'?renderCart():Promise.resolve();
  task.catch(function(){var status=document.getElementById('search-status');if(status)status.textContent='Não foi possível carregar o catálogo. Recarregue a página.';notify('Não foi possível carregar o catálogo. Recarregue a página.');});
})();

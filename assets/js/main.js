/* ---------------------------------------------------------------------------
   Lynch Store - interacoes de UI da vitrine (fases 3 e 7)

   O que sobrou do JS do site estatico: SO o drawer mobile. A grade de produtos,
   os filtros e a paginacao agora sao renderizados no servidor (index.php) e
   navegam por links de verdade - sem JS o catalogo continua inteiro.
   A fase 7 acrescentou o header de dois estados (tambem opcional: sem JS o
   header simplesmente fica no estado completo).

   Nada aqui e obrigatorio para o site funcionar.
--------------------------------------------------------------------------- */

/* Header de dois estados (fase 7).
   Um sentinel de 1px logo depois do <header> e observado por
   IntersectionObserver: enquanto ele estiver visivel, a pagina esta no topo;
   quando sai da viewport, o header ganha .on (mais baixo, sombra e borda).
   Nada de listener de scroll nem leitura de scrollY - o navegador avisa. */
(function () {
  'use strict';

  var header = document.querySelector('header');
  var sentinel = document.querySelector('[data-head-sentinel]');

  if (!header || !sentinel || typeof window.IntersectionObserver !== 'function') {
    return;
  }

  var observer = new IntersectionObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      header.classList.toggle('on', !entries[i].isIntersecting);
    }
  }, { threshold: 0 });

  observer.observe(sentinel);
})();

(function () {
  'use strict';

  var drawer = document.querySelector('[data-drawer]');
  if (!drawer) {
    return;
  }

  var openers = document.querySelectorAll('[data-menu-open]');
  var lastFocus = null;

  function setMenu(open) {
    if (open) { lastFocus = document.activeElement; }
    drawer.inert = !open;
    drawer.classList.toggle('open', open);
    document.querySelector('main').inert = open;
    document.querySelector('header').inert = open;
    document.querySelector('footer').inert = open;
    document.body.style.overflow = open ? 'hidden' : '';
    for (var i = 0; i < openers.length; i++) {
      openers[i].setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      var firstLink = drawer.querySelector('a, button');
      if (firstLink) {
        firstLink.focus();
      }
    } else if (lastFocus) {
      lastFocus.focus();
    }
  }

  for (var i = 0; i < openers.length; i++) {
    openers[i].addEventListener('click', function () {
      setMenu(true);
    });
  }

  var closers = drawer.querySelectorAll('[data-menu-close]');
  for (var j = 0; j < closers.length; j++) {
    closers[j].addEventListener('click', function () {
      setMenu(false);
    });
  }

  // Clique no fundo escuro (fora do painel) fecha
  drawer.addEventListener('click', function (event) {
    if (event.target === drawer) {
      setMenu(false);
    }
  });

  // Esc fecha
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Tab' && drawer.classList.contains('open')) {
      var targets = Array.from(drawer.querySelectorAll('a,button,input')).filter(function (el) { return el.getClientRects().length > 0; });
      var first = targets[0], last = targets[targets.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    if (event.key === 'Escape' && drawer.classList.contains('open')) {
      setMenu(false);
    }
  });

  // Links do menu levam a outra pagina (ou a uma ancora): fecha ao clicar
  var links = drawer.querySelectorAll('a');
  for (var k = 0; k < links.length; k++) {
    links[k].addEventListener('click', function () {
      setMenu(false);
    });
  }
})();
(function(){
  var button=document.querySelector('[data-share]');
  if(!button || (!navigator.share && !navigator.clipboard))return;
  button.hidden=false;
  button.addEventListener('click',async function(){
    try{
      var url=document.querySelector('link[rel="canonical"]').href;
      if(navigator.share){await navigator.share({title:document.title,url:url});}
      else{await navigator.clipboard.writeText(url);document.querySelector('[data-share-status]').textContent='Link copiado';}
    }catch(error){if(error.name!=='AbortError')document.querySelector('[data-share-status]').textContent='Copie o endereço desta página para compartilhar.';}
  });
})();

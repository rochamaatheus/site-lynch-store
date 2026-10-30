/* Lynch Store 2.0: camada de interacao. A loja funciona inteira sem este arquivo:
   tudo aqui parte de links e formularios reais. */
(function () {
  'use strict';

  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var BAG_URL = (function () { var a = $('a[href*="carrinho.php"]'); return a ? a.getAttribute('href').split('?')[0] : 'carrinho.php'; })();

  /* ---- Arara: as pecas balancam com a velocidade do arraste (mola amortecida) ---- */
  (function rack() {
    var el = $('[data-rack]');
    if (!el || calm) return;
    var items = $$('[data-hanging]', el);
    var angle = 0, speed = 0, lastX = el.scrollLeft, running = false;

    function frame() {
      var x = el.scrollLeft;
      var push = (x - lastX) * 0.09;
      lastX = x;
      speed += (-angle * 0.05) + push * 0.42;
      speed *= 0.94;
      angle += speed;
      angle = Math.max(-11, Math.min(11, angle));
      items.forEach(function (item, i) {
        item.style.setProperty('--swing', (angle * (1 - (i % 3) * 0.14)).toFixed(2) + 'deg');
      });
      if (Math.abs(angle) > 0.03 || Math.abs(speed) > 0.03) {
        requestAnimationFrame(frame);
      } else {
        running = false;
        items.forEach(function (item) { item.style.setProperty('--swing', '0deg'); });
      }
    }
    function wake() { if (!running) { running = true; requestAnimationFrame(frame); } }
    el.addEventListener('scroll', wake, { passive: true });
    items.forEach(function (item) {
      item.addEventListener('pointerdown', function () { speed += 1.2; wake(); }, { passive: true });
    });
  })();

  /* ---- Girassol do topo e fita metrica acompanham a rolagem da pagina ---- */
  (function scrollLinked() {
    var sun = $('.hero-sun');
    var tape = $('[data-tape]');
    if (calm || (!sun && !tape)) return;
    var queued = false;
    function paint() {
      queued = false;
      var y = window.scrollY;
      if (sun) sun.style.setProperty('--turn', (y * 0.14).toFixed(1) + 'deg');
      if (tape) tape.style.transform = 'translate3d(' + (-y * 0.35).toFixed(1) + 'px,0,0)';
    }
    window.addEventListener('scroll', function () {
      if (!queued) { queued = true; requestAnimationFrame(paint); }
    }, { passive: true });
  })();

  /* ---- Sacola ---- */
  function setBloom(count) {
    $$('[data-bloom]').forEach(function (bloom) {
      var before = parseInt(bloom.getAttribute('data-count'), 10) || 0;
      bloom.setAttribute('data-count', String(count));
      $('[data-bloom-n]', bloom).textContent = String(count);
      $$('.petal', bloom).forEach(function (petal, i) { petal.classList.toggle('on', i < count); });
      if (count > before) {
        bloom.classList.remove('grew');
        void bloom.getBoundingClientRect();
        bloom.classList.add('grew');
      }
    });
    $$('[data-open-bag]').forEach(function (link) {
      link.setAttribute('aria-label', 'Abrir sacola, ' + count + (count === 1 ? ' peça' : ' peças'));
    });
  }

  var snackTimer;
  function snack(text, showLink) {
    var box = $('[data-snack]');
    if (!box) return;
    $('[data-snack-text]', box).textContent = text;
    $('a', box).hidden = !showLink;
    box.hidden = false;
    box.classList.remove('out');
    clearTimeout(snackTimer);
    snackTimer = setTimeout(function () {
      box.classList.add('out');
      setTimeout(function () { box.hidden = true; }, 220);
    }, 3200);
  }

  function post(form, submitter) {
    var data = new FormData(form);
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);
    /* getAttribute: os forms tem um campo chamado "action", que esconde form.action. */
    return fetch(form.getAttribute('action'), {
      method: 'POST', body: data, credentials: 'same-origin',
      headers: { 'X-Lynch-Ajax': '1' }
    }).then(function (res) {
      if (!res.ok) throw new Error('status ' + res.status);
      return res.json();
    });
  }

  function paintBag(state) {
    setBloom(state.count);
    $$('[data-bag-body]').forEach(function (body) { body.innerHTML = state.html; });
  }

  /* A foto voa ate o girassol; so transform e opacity. */
  function fly(form) {
    if (calm || !form.closest) return;
    var img = $('img', form.closest('.card') || $('.pdp') || form);
    var target = $$('[data-bloom]').filter(function (b) { return b.getClientRects().length; }).pop();
    if (!img || !target || !img.animate) return;
    var from = img.getBoundingClientRect();
    var to = target.getBoundingClientRect();
    var ghost = img.cloneNode();
    ghost.removeAttribute('srcset');
    ghost.className = 'ghost';
    ghost.style.cssText = 'left:' + from.left + 'px;top:' + from.top + 'px;width:' + from.width + 'px;height:' + from.height + 'px';
    document.body.appendChild(ghost);
    var dx = to.left + to.width / 2 - (from.left + from.width / 2);
    var dy = to.top + to.height / 2 - (from.top + from.height / 2);
    var scale = Math.max(0.1, 40 / from.width);
    ghost.animate([
      { transform: 'none', opacity: 1, borderRadius: '10px' },
      { transform: 'translate(' + dx * 0.45 + 'px,' + (dy * 0.45 - 60) + 'px) scale(' + (scale + 0.3) + ') rotate(-10deg)', opacity: 1, offset: 0.55 },
      { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + scale + ') rotate(12deg)', opacity: 0.2, borderRadius: '50%' }
    ], { duration: 620, easing: 'cubic-bezier(.45,.05,.3,1)' }).onfinish = function () { ghost.remove(); };
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var submitter = event.submitter;

    if (form.matches('[data-add-form]')) {
      event.preventDefault();
      var card = form.closest('.card, .pdp');
      var tag = card && $('.tag', card);
      fly(form);
      if (tag && !calm) { tag.classList.remove('swing'); void tag.offsetWidth; tag.classList.add('swing'); }
      if (submitter) submitter.classList.add('busy');
      post(form, submitter).then(function (state) {
        paintBag(state);
        var failed = state.message && state.message.type === 'error';
        snack(state.message ? state.message.message : 'Peça na sacola.', !failed);
      }).catch(function () { form.submit(); }).finally(function () {
        if (submitter) submitter.classList.remove('busy');
      });
      return;
    }

    if (form.matches('[data-cart-form]')) {
      event.preventDefault();
      var line = form.closest('.bag-line');
      if (line) line.classList.add('pending');
      post(form, submitter).then(paintBag).catch(function () {
        /* form.submit() nao levaria o botao clicado: recarrega o estado real. */
        window.location.href = BAG_URL;
      });
      return;
    }

    if (form.matches('[data-autosubmit]')) return;
  });

  $$('[data-autosubmit] select').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
  });

  /* ---- Gavetas (dialog nativo: foco preso e Esc de graca) ---- */
  function openSheet(dialog) {
    dialog.classList.remove('leaving');
    dialog.style.transform = '';
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('sheet-open');
  }
  function closeSheet(dialog) {
    if (!dialog.open) return;
    if (calm) { dialog.close(); return; }
    dialog.classList.add('leaving');
    setTimeout(function () { dialog.close(); dialog.classList.remove('leaving'); dialog.style.transform = ''; }, 200);
  }
  $$('dialog.sheet').forEach(function (dialog) {
    dialog.addEventListener('close', function () { document.documentElement.classList.remove('sheet-open'); });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeSheet(dialog);
      if (event.target.closest('[data-sheet-close]')) closeSheet(dialog);
    });
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); closeSheet(dialog); });
    /* Campo de busca consome o Esc para se limpar: a gaveta fecha do mesmo jeito. */
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closeSheet(dialog); }
    });
    var dialogClose = $('form[method="dialog"]', dialog);
    if (dialogClose) dialogClose.addEventListener('submit', function (event) { event.preventDefault(); closeSheet(dialog); });

    /* Puxar para baixo fecha, como nas gavetas nativas do celular. */
    var grip = $('[data-sheet-grip]', dialog);
    if (!grip) return;
    var startY = 0, dragging = false;
    grip.addEventListener('pointerdown', function (e) { startY = e.clientY; dragging = true; grip.setPointerCapture(e.pointerId); dialog.style.transition = 'none'; });
    grip.addEventListener('pointermove', function (e) { if (dragging) dialog.style.transform = 'translateY(' + Math.max(0, e.clientY - startY) + 'px)'; });
    function release(e) {
      if (!dragging) return;
      dragging = false;
      dialog.style.transition = '';
      if (e.clientY - startY > 90) closeSheet(dialog); else dialog.style.transform = '';
    }
    grip.addEventListener('pointerup', release);
    grip.addEventListener('pointercancel', release);
  });

  var bagSheet = $('#sacola');
  var searchSheet = $('#busca-sheet');
  document.addEventListener('click', function (event) {
    var bagLink = event.target.closest('[data-open-bag]');
    if (bagLink && bagSheet) {
      event.preventDefault();
      var body = $('[data-bag-body]', bagSheet);
      if (!body.innerHTML.trim()) body.innerHTML = '<div class="bag-loading">' + ($('.sunflower').outerHTML) + '</div>';
      openSheet(bagSheet);
      fetch(BAG_URL + '?sheet=1', { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) { body.innerHTML = html; });
      return;
    }
    var searchLink = event.target.closest('[data-open-search]');
    if (searchLink && searchSheet) {
      event.preventDefault();
      openSheet(searchSheet);
      $('[data-search-input]', searchSheet).focus();
    }
  });
  if (window.location.hash === '#busca') { var field = $('#busca-loja'); if (field) field.focus(); }

  /* ---- Busca instantanea ---- */
  (function search() {
    if (!searchSheet) return;
    var input = $('[data-search-input]', searchSheet);
    var out = $('[data-search-out]', searchSheet);
    var initial = out.innerHTML;
    var endpoint = input.form.getAttribute('action').replace('loja.php', 'busca.php');
    var timer, token = 0;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var term = input.value.trim();
      if (term.length < 2) { token++; out.innerHTML = initial; return; }
      timer = setTimeout(function () {
        var mine = ++token;
        fetch(endpoint + '?q=' + encodeURIComponent(term)).then(function (r) { return r.json(); }).then(function (rows) {
          if (mine !== token) return;
          out.textContent = '';
          if (!rows.length) {
            var none = document.createElement('p');
            none.className = 'search-none';
            none.textContent = 'Nenhuma peça com esse nome. Tente outra palavra.';
            out.appendChild(none);
            return;
          }
          rows.forEach(function (row, i) {
            var a = document.createElement('a');
            a.className = 'hit';
            a.href = row.url;
            a.style.setProperty('--i', i);
            var frame = document.createElement('span');
            frame.className = 'photo';
            var img = document.createElement('img');
            img.src = row.image; img.alt = ''; img.width = 56; img.height = 70;
            frame.appendChild(img);
            var name = document.createElement('b');
            name.textContent = row.name;
            var price = document.createElement('small');
            price.textContent = row.price;
            a.appendChild(frame); a.appendChild(name); a.appendChild(price);
            out.appendChild(a);
          });
        }).catch(function () {});
      }, 180);
    });
  })();

  /* ---- Favoritos (so no aparelho) ---- */
  var FAV_KEY = 'lynch-favoritos';
  function readFavs() {
    try { return JSON.parse(localStorage.getItem(FAV_KEY) || '[]').map(Number).filter(Boolean); } catch (e) { return []; }
  }
  function syncFavs(root) {
    var favs = readFavs();
    $$('[data-fav]', root).forEach(function (button) {
      button.hidden = false;
      button.setAttribute('aria-pressed', String(favs.indexOf(Number(button.getAttribute('data-fav'))) !== -1));
    });
  }
  syncFavs();
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-fav]');
    if (!button) return;
    var id = Number(button.getAttribute('data-fav'));
    var favs = readFavs();
    var at = favs.indexOf(id);
    if (at === -1) favs.unshift(id); else favs.splice(at, 1);
    try { localStorage.setItem(FAV_KEY, JSON.stringify(favs.slice(0, 60))); } catch (e) {}
    button.setAttribute('aria-pressed', String(at === -1));
    snack(at === -1 ? 'Guardada nos favoritos.' : 'Tirada dos favoritos.', false);
    var page = $('[data-favs-grid]');
    if (page && at !== -1) { var card = button.closest('.card'); if (card) card.remove(); if (!favs.length) $('[data-favs-empty]').hidden = false; }
  });
  (function favPage() {
    var grid = $('[data-favs-grid]');
    if (!grid) return;
    var favs = readFavs();
    if (!favs.length) return;
    $('[data-favs-empty]').hidden = true;
    fetch('favoritos.php?ids=' + favs.join(','), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
      grid.innerHTML = html;
      syncFavs(grid);
      if (!grid.children.length) $('[data-favs-empty]').hidden = false;
    });
  })();

  /* ---- Ver mais sem recarregar: a pagina continua onde estava ---- */
  document.addEventListener('click', function (event) {
    var more = event.target.closest('[data-more]');
    if (!more) return;
    event.preventDefault();
    more.classList.add('busy');
    fetch(more.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var grid = $('[data-grid]');
      var fresh = $$('[data-grid] .card', doc).slice(grid.children.length);
      fresh.forEach(function (card) { grid.appendChild(document.adoptNode(card)); });
      syncFavs(grid);
      var nextWrap = $('[data-more-wrap]', doc);
      var wrap = $('[data-more-wrap]');
      if (nextWrap) wrap.replaceWith(document.adoptNode(nextWrap)); else wrap.remove();
    }).catch(function () { window.location.href = more.href; });
  });

  /* ---- Galeria: pontos seguem o deslize; desktop com zoom no ponteiro ---- */
  (function gallery() {
    var el = $('[data-gallery]');
    if (!el) return;
    var dots = $$('[data-dots] i');
    if (dots.length) {
      el.addEventListener('scroll', function () {
        var at = Math.round(el.scrollLeft / el.clientWidth);
        dots.forEach(function (dot, i) { dot.classList.toggle('on', i === at); });
      }, { passive: true });
    }
  })();

  /* ---- Compartilhar ---- */
  (function share() {
    var button = $('[data-share]');
    if (!button || (!navigator.share && !navigator.clipboard)) return;
    button.hidden = false;
    button.addEventListener('click', function () {
      var canonical = $('link[rel="canonical"]');
      var url = canonical ? canonical.href : location.href;
      if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
      navigator.clipboard.writeText(url).then(function () { snack('Link copiado.', false); });
    });
  })();

  /* Aviso do servidor some sozinho para nao cobrir a barra. */
  $$('.flash').forEach(function (flash) {
    setTimeout(function () { flash.classList.add('out'); setTimeout(function () { flash.remove(); }, 220); }, 4000);
  });
})();

/* Lynch Store 2.0: camada de interacao. A loja funciona inteira sem este arquivo:
   tudo aqui parte de links e formularios reais. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var BAG_URL = (function () { var a = $('a[href*="carrinho.php"]'); return a ? a.getAttribute('href').split('?')[0] : 'carrinho.php'; })();

  /* ---- Arara: pendulo pesado. A velocidade do arraste vira uma forca suavizada,
     cada peca segue com atraso proprio e o angulo satura sem bater em limite. ---- */
  (function rack() {
    var el = $('[data-rack]');
    if (!el) return;
    var items = $$('[data-hanging]', el).map(function (node, i) {
      return { node: node, value: 0, follow: 0.16 - (i % 4) * 0.025 };
    });
    var MAX = 6.5;
    var angle = 0, speed = 0, force = 0, lastX = 0, lastT = 0, running = false;

    function frame(now) {
      var step = lastT ? Math.min((now - lastT) / 16.67, 3) : 1;
      lastT = now;
      var x = el.scrollLeft;
      var moved = Math.max(-40, Math.min(40, (x - lastX) / step));
      lastX = x;
      force += (moved * 0.018 - force) * Math.min(1, 0.1 * step);
      speed += (force - angle * 0.022) * step;
      speed *= Math.pow(0.955, step);
      angle += speed * step;
      var shown = MAX * Math.tanh(angle / MAX);
      var busy = Math.abs(angle) > 0.02 || Math.abs(speed) > 0.01 || Math.abs(force) > 0.005;
      items.forEach(function (item) {
        item.value += (shown - item.value) * Math.min(1, item.follow * step);
        if (Math.abs(shown - item.value) > 0.01) busy = true;
        item.node.style.setProperty('--swing', item.value.toFixed(2) + 'deg');
      });
      if (busy) {
        requestAnimationFrame(frame);
      } else {
        running = false; lastT = 0; angle = 0; speed = 0; force = 0;
        items.forEach(function (item) { item.value = 0; item.node.style.setProperty('--swing', '0deg'); });
      }
    }
    function wake() { if (!running) { running = true; requestAnimationFrame(frame); } }
    el.addEventListener('scroll', wake, { passive: true });
    items.forEach(function (item) {
      item.node.addEventListener('pointerdown', function () { speed += 0.22; wake(); }, { passive: true });
    });

    /* Convite: a arara corre devagar e volta, mostrando que da para arrastar. */
    var touched = false;
    ['pointerdown', 'wheel', 'touchstart'].forEach(function (type) {
      el.addEventListener(type, function () { touched = true; }, { passive: true, once: true });
    });
    function glide(to, ms, done) {
      var from = el.scrollLeft, start = 0;
      (function tick(now) {
        if (touched) return;
        start = start || now;
        var t = Math.min(1, (now - start) / ms);
        el.scrollLeft = from + (to - from) * (0.5 - Math.cos(Math.PI * t) / 2);
        if (t < 1) requestAnimationFrame(tick); else if (done) done();
      })(performance.now());
    }
    setTimeout(function () {
      if (touched || el.scrollWidth <= el.clientWidth + 20) return;
      glide(80, 1300, function () { setTimeout(function () { glide(0, 1500); }, 250); });
    }, 2000);
  })();

  /* ---- Girassol do topo e fita metrica acompanham a rolagem da pagina ---- */
  (function scrollLinked() {
    var sun = $('.hero-sun');
    var tape = $('[data-tape]');
    if (!sun && !tape) return;
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

  /* ---- Foto do card vira a foto do produto (view transition entre paginas).
     A pagina de produto ja nasce com o nome no HTML; aqui so marcamos o card clicado. ---- */
  (function morph() {
    var clicked = null;
    document.addEventListener('click', function (event) {
      var link = event.target.closest('.card a');
      if (link) clicked = $('[data-morph]', link.closest('.card'));
    });
    window.addEventListener('pageswap', function (event) {
      if (!event.viewTransition) return;
      /* Rumo a pagina sem transicao (painel), o navegador aborta: nao e erro. */
      var vt = event.viewTransition, skip = function () {};
      vt.ready.catch(skip); vt.finished.catch(skip); vt.updateCallbackDone.catch(skip);
      /* Nome repetido cancela a transicao inteira. 'none' (e nao '') porque a
         foto principal do produto recebe o nome pelo CSS. */
      $$('[data-morph]').forEach(function (el) { el.style.viewTransitionName = 'none'; });
      if (clicked) clicked.style.viewTransitionName = 'peca';
    });
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        $$('.card [data-morph]').forEach(function (el) { el.style.viewTransitionName = 'none'; });
        var main = $('.pdp-photo[data-morph]');
        if (main) main.style.viewTransitionName = 'peca';
      }
    });
  })();

  /* ---- Pecas sobem na grade: fallback onde nao ha animation-timeline ---- */
  var watchCards = (function () {
    if (window.CSS && CSS.supports('animation-timeline: view()')) return function () {};
    if (!('IntersectionObserver' in window)) return function () {};
    var seen = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { entry.target.classList.remove('rise'); seen.unobserve(entry.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    return function (root) {
      $$('.grid .card', root).forEach(function (card) {
        if (card.dataset.watched) return;
        card.dataset.watched = '1';
        if (card.getBoundingClientRect().top > window.innerHeight) { card.classList.add('rise'); seen.observe(card); }
      });
    };
  })();
  watchCards();

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
      if (link.hasAttribute('aria-label')) link.setAttribute('aria-label', 'Sacola com ' + count + (count === 1 ? ' peça' : ' peças'));
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

  /* Sem "only", repinta toda sacola aberta; com ele, so a que enviou o formulario
     (a tela do Comprar agora nao pode desenhar por cima da gaveta da sacola). */
  function paintBag(state, only) {
    setBloom(state.count);
    (only ? [only] : $$('[data-bag-body]')).forEach(function (body) { body.innerHTML = state.html; });
  }

  /* A foto voa ate o girassol; so transform e opacity. */
  function fly(form) {
    if (!form.closest) return;
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
      if (tag) { tag.classList.remove('swing'); void tag.offsetWidth; tag.classList.add('swing'); }
      if (submitter) submitter.classList.add('busy');
      post(form, submitter).then(function (state) {
        paintBag(state);
        var failed = state.message && state.message.type === 'error';
        if (submitter && !failed) {
          submitter.classList.add('done');
          setTimeout(function () { submitter.classList.remove('done'); }, 1300);
        }
        snack(state.message ? state.message.message : 'Peça na sacola.', !failed);
      }).catch(function () { form.submit(); }).finally(function () {
        if (submitter) submitter.classList.remove('busy');
      });
      return;
    }

    if (form.matches('[data-cart-form]')) {
      event.preventDefault();
      var line = form.closest('.bag-line');
      var removing = submitter && (submitter.classList.contains('link-btn') || submitter.value === '0');
      if (line) line.classList.add(removing ? 'leaving' : 'pending');
      var bagBody = form.closest('[data-bag-body]');
      var couponAction = form.matches('.coupon-form, .coupon-remove');
      post(form, submitter).then(function (state) {
        /* Da tempo de a linha sair antes de redesenhar a lista. */
        setTimeout(function () {
          paintBag(state, bagBody);
          /* Depois do cupom, o foco volta ao campo (ou ao Remover cupom). */
          var target = couponAction && bagBody && $('[data-coupon-focus]', bagBody);
          if (target) target.focus({ preventScroll: true });
        }, removing ? 240 : 0);
      }).catch(function () {
        /* form.submit() nao levaria o botao clicado: recarrega o estado real. */
        window.location.href = BAG_URL + ($('input[name="bag"][value="direct"]', form) ? '?direta=1' : '');
      });
      return;
    }

    if (form.matches('[data-autosubmit]')) return;

    /* POST que troca de pagina (Comprar agora, enviar pelo WhatsApp): um toque so,
       com o botao mostrando que esta indo. */
    if ((form.getAttribute('method') || '').toLowerCase() === 'post') {
      if (form.dataset.sending) { event.preventDefault(); return; }
      lockForm(form, submitter);
    }
  });

  var locked = [];
  function lockForm(form, button) {
    form.dataset.sending = '1';
    button = button || $('button[type="submit"], button:not([type])', form);
    var entry = { form: form, button: button, html: button ? button.innerHTML : '' };
    if (button) {
      button.classList.add('busy');
      button.setAttribute('aria-busy', 'true');
      var label = button.getAttribute('data-sending');
      var text = label && Array.prototype.slice.call(button.childNodes).reverse().filter(function (n) { return n.nodeType === 3 && n.textContent.trim(); })[0];
      if (text) text.textContent = ' ' + label;
    }
    locked.push(entry);
    /* O WhatsApp abre por cima e a pagina fica onde estava: libera depois. */
    entry.timer = setTimeout(function () { unlock(entry); }, 8000);
  }
  function unlock(entry) {
    clearTimeout(entry.timer);
    delete entry.form.dataset.sending;
    if (entry.button) {
      entry.button.classList.remove('busy');
      entry.button.removeAttribute('aria-busy');
      entry.button.innerHTML = entry.html;
    }
    locked = locked.filter(function (e) { return e !== entry; });
  }
  function unlockAll() { locked.slice().forEach(unlock); }
  window.addEventListener('pageshow', function (event) { if (event.persisted) unlockAll(); });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && locked.length) setTimeout(unlockAll, 600);
  });

  $$('[data-autosubmit] select').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
  });

  /* ---- Gavetas (dialog nativo: foco preso e Esc de graca) ----
     Cada gaveta aberta ganha uma entrada no historico: o voltar do celular
     (gesto do iPhone, botao do Android) fecha a gaveta em vez de sair da pagina. */
  var sheetState = function (dialog) { return !!(history.state && history.state.sheet === dialog.id); };
  function openSheet(dialog) {
    dialog.classList.remove('leaving');
    dialog.style.transform = '';
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('sheet-open');
    if (!sheetState(dialog)) { try { history.pushState({ sheet: dialog.id }, ''); } catch (e) {} }
  }
  function closeSheet(dialog, fromHistory) {
    if (!dialog.open || dialog.classList.contains('leaving')) return;
    dialog.classList.add('leaving');
    if (!fromHistory && sheetState(dialog)) history.back();
    setTimeout(function () { dialog.close(); dialog.classList.remove('leaving'); dialog.style.transform = ''; }, 200);
  }
  window.addEventListener('popstate', function () {
    $$('dialog.sheet[open]').forEach(function (dialog) { if (!sheetState(dialog)) closeSheet(dialog, true); });
  });
  /* Saindo da gaveta para outra pagina, a entrada dela da lugar a nova: um voltar
     so leva de volta a pagina de origem. */
  function leaveSheet(dialog, url) {
    if (sheetState(dialog)) { window.location.replace(url); return true; }
    return false;
  }
  $$('dialog.sheet').forEach(function (dialog) {
    dialog.addEventListener('close', function () { document.documentElement.classList.remove('sheet-open'); });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeSheet(dialog);
      if (event.target.closest('[data-sheet-close]')) closeSheet(dialog);
      var link = event.target.closest('a[href]');
      if (link && !link.target && !event.defaultPrevented && !(event.ctrlKey || event.metaKey || event.shiftKey) && leaveSheet(dialog, link.href)) event.preventDefault();
    });
    var getForm = $('form[method="get"]', dialog);
    if (getForm) getForm.addEventListener('submit', function (event) {
      var url = getForm.getAttribute('action') + '?' + new URLSearchParams(new FormData(getForm)).toString();
      if (leaveSheet(dialog, url)) event.preventDefault();
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
      watchCards();
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
      watchCards();
      var nextWrap = $('[data-more-wrap]', doc);
      var wrap = $('[data-more-wrap]');
      if (nextWrap) wrap.replaceWith(document.adoptNode(nextWrap)); else wrap.remove();
    }).catch(function () { window.location.href = more.href; });
  });

  /* ---- Galeria: pontos seguem o deslize; desktop com zoom no ponteiro ---- */
  (function gallery() {
    var el = $('[data-gallery]');
    if (!el) return;
    /* Lupa: no computador, a foto amplia seguindo o ponteiro. */
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      $$('.pdp-photo', el).forEach(function (photo) {
        var img = $('img', photo), box = null;
        photo.addEventListener('pointerenter', function () { box = photo.getBoundingClientRect(); });
        photo.addEventListener('pointermove', function (e) {
          box = box || photo.getBoundingClientRect();
          img.style.transformOrigin = ((e.clientX - box.left) / box.width * 100).toFixed(1) + '% ' + ((e.clientY - box.top) / box.height * 100).toFixed(1) + '%';
        });
      });
    }
    var dots = $$('[data-dots] i');
    if (dots.length) {
      el.addEventListener('scroll', function () {
        var at = Math.round(el.scrollLeft / el.clientWidth);
        dots.forEach(function (dot, i) { dot.classList.toggle('on', i === at); });
      }, { passive: true });
    }
  })();

  /* ---- Chips de filtro: a borda esmaece do lado que ainda tem chips ---- */
  $$('.filters .pills').forEach(function (row) {
    var queued = false;
    function paint() {
      queued = false;
      var max = row.scrollWidth - row.clientWidth;
      row.classList.toggle('more-l', row.scrollLeft > 4);
      row.classList.toggle('more-r', max - row.scrollLeft > 4);
    }
    function ask() { if (!queued) { queued = true; requestAnimationFrame(paint); } }
    row.addEventListener('scroll', ask, { passive: true });
    window.addEventListener('resize', ask, { passive: true });
    /* O filtro escolhido aparece na tela, mesmo no fim da fileira. */
    var on = $('.pill.on', row);
    if (on && on.offsetLeft + on.offsetWidth > row.clientWidth) row.scrollLeft = on.offsetLeft - 24;
    paint();
  });

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

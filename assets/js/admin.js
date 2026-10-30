/* Lynch Store - painel
   Tudo progressivo: sem JavaScript os formulários continuam funcionando.
   Fica em arquivo próprio (e não inline) para a política de segurança do
   site poder bloquear script embutido na página.
   Pensado para o celular: confirmação em folha embaixo, botões que não
   aceitam toque duplo, fotos reduzidas antes de subir (4G e limite de 5 MB),
   ações rápidas com resposta imediata e Desfazer, aviso de sem internet e
   barra de salvar que acompanha o teclado. */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  root.classList.add('js');
  var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
  var $ = function (sel, ctx) { return (ctx || doc).querySelector(sel); };
  var $$ = function (sel, ctx) { return (ctx || doc).querySelectorAll(sel); };
  var mb = function (bytes) { return (bytes / 1048576).toFixed(1).replace('.', ','); };

  /* ---------- Avisos rápidos (toast), com botão opcional ---------- */
  var zone = $('[data-toast-zone]');
  var toastTimer;
  function toast(message, ok, action) {
    if (!zone) {
      zone = doc.createElement('div');
      zone.className = 'toast-zone';
      zone.setAttribute('role', 'status');
      zone.setAttribute('aria-live', 'polite');
      doc.body.appendChild(zone);
    }
    var box = $('[data-toast]', zone);
    if (!box) {
      box = doc.createElement('div');
      box.setAttribute('data-toast', '');
      zone.appendChild(box);
    }
    box.className = 'flash toast ' + (ok ? 'success' : 'error');
    box.textContent = '';
    var msg = doc.createElement('span');
    msg.className = 'toast-msg';
    msg.textContent = message;
    box.appendChild(msg);
    if (action) {
      var btn = doc.createElement('button');
      btn.type = 'button';
      btn.className = 'btn small ghost';
      btn.textContent = action.label;
      btn.addEventListener('click', function () { box.hidden = true; action.run(); });
      box.appendChild(btn);
    }
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { box.hidden = true; }, action ? 7000 : 4000);
  }

  /* ---------- Sem internet ---------- */
  var offline = $('[data-offline]');
  function syncOnline() { if (offline) { offline.hidden = navigator.onLine !== false; } }
  window.addEventListener('online', function () { syncOnline(); toast('Internet de volta.', true); });
  window.addEventListener('offline', syncOnline);
  syncOnline();

  /* ---------- Folha de confirmação para ações destrutivas ----------
     <form data-confirm="Texto" data-confirm-ok="Excluir cupom">. Sem suporte a
     <dialog>, cai no confirm() do navegador. */
  var sheet = $('[data-sheet]');
  function askConfirm(form, done) {
    var message = form.getAttribute('data-confirm');
    if (!sheet || typeof sheet.showModal !== 'function') { done(window.confirm(message)); return; }
    var ok = $('[data-sheet-ok]', sheet);
    $('[data-sheet-msg]', sheet).textContent = message;
    ok.textContent = form.getAttribute('data-confirm-ok') || 'Confirmar';
    ok.className = 'btn ' + (form.hasAttribute('data-confirm-safe') ? 'primary' : 'danger-solid');
    sheet.returnValue = '';
    var onClose = function () {
      sheet.removeEventListener('close', onClose);
      done(sheet.returnValue === 'ok');
    };
    sheet.addEventListener('close', onClose);
    sheet.showModal();
    $('[data-sheet-cancel]', sheet).focus();
  }
  if (sheet) {
    /* Toque fora da folha fecha como Cancelar. */
    sheet.addEventListener('click', function (event) {
      if (event.target === sheet) { sheet.close('cancel'); }
    });
  }

  /* ---------- Envio de formulários ---------- */
  function setBusy(form, label) {
    form.setAttribute('aria-busy', 'true');
    each($$('button[type="submit"], button:not([type])', form), function (button) {
      button.disabled = true;
    });
    var main = form.querySelector('[data-save-label]') || form.querySelector('.btn.primary[type="submit"]') || form.querySelector('button[type="submit"]');
    if (main && label) {
      main.setAttribute('data-label', main.innerHTML);
      main.textContent = label;
    }
  }
  function clearBusy(form) {
    form.removeAttribute('aria-busy');
    each($$('button[type="submit"], button:not([type])', form), function (button) {
      button.disabled = false;
      if (button.hasAttribute('data-label')) {
        button.innerHTML = button.getAttribute('data-label');
        button.removeAttribute('data-label');
      }
    });
  }

  doc.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method === 'dialog') { return; }
    var submitter = event.submitter || null;

    /* Toque duplo: o segundo envio não sai. */
    if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }

    if (form.hasAttribute('data-confirm') && !form.hasAttribute('data-confirmed')) {
      event.preventDefault();
      askConfirm(form, function (yes) {
        if (!yes) { return; }
        form.setAttribute('data-confirmed', '');
        if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); }
        else { form.submit(); }
        form.removeAttribute('data-confirmed');
      });
      return;
    }

    if (form.hasAttribute('data-product-form') && !checkProductForm(form)) { event.preventDefault(); return; }

    /* Fotos ainda sendo preparadas: espera e envia sozinho. */
    var pending = waitPhotos(form);
    if (pending) {
      event.preventDefault();
      setBusy(form, 'Preparando fotos...');
      pending.then(function () {
        clearBusy(form);
        if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); } else { form.submit(); }
      });
      return;
    }
    var needPhoto = $('[data-photo-required]', form);
    if (needPhoto && !(needPhoto.files && needPhoto.files.length)) {
      event.preventDefault();
      var empty = $('[data-photo-note]', form);
      if (empty) { empty.className = 'field-error pick-status'; empty.textContent = 'Escolha pelo menos uma foto antes de enviar.'; empty.focus(); }
      return;
    }
    if (form.hasAttribute('data-photo-blocked')) {
      event.preventDefault();
      var note = $('[data-photo-note]', form);
      if (note) { note.focus(); }
      return;
    }

    if (form.hasAttribute('data-quick') || (form.method || '').toLowerCase() !== 'post') { return; }
    if (event.defaultPrevented) { return; }
    var hasPhotos = false;
    each($$('[data-photo-input]', form), function (input) { if (input.files && input.files.length) { hasPhotos = true; } });
    var label = hasPhotos ? 'Enviando fotos...' : (form.getAttribute('data-busy') || 'Salvando...');
    /* Desabilita depois do envio já montado (o botão clicado segue no POST). */
    setTimeout(function () { setBusy(form, label); }, 0);
  });

  /* Voltar pelo histórico traz a página do cache: destrava os botões. */
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) { return; }
    each($$('form[aria-busy="true"]'), clearBusy);
  });

  /* ---------- Mostrar/ocultar senha ---------- */
  each($$('[data-pw-toggle]'), function (button) {
    var field = doc.getElementById(button.getAttribute('data-pw-toggle'));
    if (!field) { return; }
    var label = (button.getAttribute('aria-label') || 'Mostrar senha').replace(/^Mostrar\s*/, '');
    button.hidden = false;
    button.addEventListener('click', function () {
      var show = field.type === 'password';
      field.type = show ? 'text' : 'password';
      button.textContent = show ? 'Ocultar' : 'Mostrar';
      button.setAttribute('aria-label', (show ? 'Ocultar ' : 'Mostrar ') + label);
      field.focus();
    });
  });

  /* ---------- Sessão viva em formulários longos ---------- */
  var keepForm = $('form[data-keepalive]');
  if (keepForm && window.fetch) {
    var pingUrl = keepForm.getAttribute('data-keepalive');
    var warned = false;
    var ping = function () {
      if (doc.hidden || warned || navigator.onLine === false) { return; }
      window.fetch(pingUrl, { credentials: 'same-origin', cache: 'no-store', redirect: 'manual' })
        .then(function (response) {
          if (response.status === 204) { return; }
          warned = true;
          var alertBox = doc.createElement('div');
          alertBox.className = 'flash error';
          alertBox.setAttribute('role', 'alert');
          alertBox.textContent = 'Sua sessão expirou. Antes de salvar, abra o painel em outra aba, entre de novo e volte aqui. Assim o que você digitou não se perde.';
          keepForm.parentNode.insertBefore(alertBox, keepForm);
        })
        .catch(function () { /* sem rede agora: tenta de novo no próximo ciclo */ });
    };
    window.setInterval(ping, 5 * 60 * 1000);
    doc.addEventListener('visibilitychange', function () { if (!doc.hidden) { ping(); } });
  }

  /* ---------- Dinheiro: aceita 129,90 / 129.90 / R$ 129,90 ---------- */
  function toCents(text) {
    var raw = String(text || '').replace(/R\$\s*/i, '').replace(/\s/g, '');
    if (!raw) { return null; }
    if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(raw) || /^\d+,\d{1,2}$/.test(raw)) {
      raw = raw.replace(/\./g, '').replace(',', '.');
    } else if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
      return NaN;
    }
    var n = Number(raw);
    return isFinite(n) && n > 0 ? Math.round(n * 100) : NaN;
  }
  function centsText(cents) { return (cents / 100).toFixed(2).replace('.', ','); }
  doc.addEventListener('blur', function (event) {
    var field = event.target;
    if (!field.matches || !field.matches('[data-money]')) { return; }
    var cents = toCents(field.value);
    if (cents && !isNaN(cents)) { field.value = centsText(cents); }
  }, true);

  /* ---------- Cadastro de produto ---------- */
  var nome = doc.getElementById('f-name');
  var slug = doc.getElementById('f-slug');
  var preco = doc.getElementById('f-price');
  var chk = doc.getElementById('f-onrequest');

  function slugify(texto) {
    return (texto || '')
      .toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 200);
  }
  if (nome && slug) {
    /* Só sobrescreve enquanto o endereço estiver espelhando o nome. */
    var espelhando = slug.value === '' || slug.value === slugify(nome.value);
    slug.addEventListener('input', function () { espelhando = false; });
    nome.addEventListener('input', function () { if (espelhando) { slug.value = slugify(nome.value); } });
    slug.addEventListener('blur', function () { if (slug.value !== '') { slug.value = slugify(slug.value); } });
  }
  if (preco && chk) {
    var syncPreco = function () {
      preco.disabled = chk.checked;
      if (chk.checked) { preco.value = ''; clearFieldError(preco); }
    };
    chk.addEventListener('change', syncPreco);
    syncPreco();
  }

  function fieldBox(input) { return input.closest('.field'); }
  function clearFieldError(input) {
    var box = fieldBox(input);
    var old = box && $('.field-error[data-client]', box);
    if (old) { old.remove(); }
    if (input.getAttribute('aria-invalid') === 'true') {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-errormessage');
    }
  }
  function showFieldError(input, message) {
    clearFieldError(input);
    var box = fieldBox(input);
    var p = doc.createElement('p');
    p.className = 'field-error';
    p.setAttribute('data-client', '');
    p.id = (input.id || 'campo') + '-erro';
    p.textContent = message;
    var anchor = input.closest('.money, .stock-ctl') || input;
    anchor.insertAdjacentElement('afterend', p);
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-errormessage', p.id);
    var details = input.closest('details');
    if (details) { details.open = true; }
    input.focus();
    if (box) { box.scrollIntoView({ block: 'center' }); }
  }
  /* Erros que dá para ver antes do envio: assim as fotos escolhidas não se perdem. */
  function checkProductForm(form) {
    var name = $('#f-name', form);
    if (name && name.value.trim() === '') { showFieldError(name, 'Escreva o nome do produto.'); return false; }
    if (preco && !preco.disabled) {
      var c = toCents(preco.value);
      if (preco.value.trim() === '') { showFieldError(preco, 'Digite o preço, como 129,90, ou marque "Sem preço na loja".'); return false; }
      if (c === null || isNaN(c)) { showFieldError(preco, 'Esse preço não ficou claro. Escreva assim: 129,90.'); return false; }
    }
    var stock = $('#f-stock', form);
    if (stock && stock.value.trim() !== '' && !/^\d{1,5}$/.test(stock.value.trim())) {
      showFieldError(stock, 'Estoque é só número inteiro, como 3. Vazio: não controla.'); return false;
    }
    var sale = $('#f-sale', form);
    if (sale && sale.value.trim() !== '') {
      var sc = toCents(sale.value);
      var pc = preco && !preco.disabled ? toCents(preco.value) : null;
      if (sc === null || isNaN(sc)) { showFieldError(sale, 'Preço promocional não ficou claro. Escreva assim: 79,90.'); return false; }
      if (!pc || isNaN(pc)) { showFieldError(sale, 'Para ter promoção, o produto precisa de preço normal.'); return false; }
      if (sc >= pc) { showFieldError(sale, 'A promoção precisa ser menor que o preço normal (' + centsText(pc) + ').'); return false; }
    }
    return true;
  }
  doc.addEventListener('input', function (event) {
    var t = event.target;
    if (t.getAttribute && t.getAttribute('aria-invalid') === 'true') { clearFieldError(t); }
  });

  /* Enter num campo de texto vai para o próximo campo, em vez de salvar sem querer. */
  doc.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.isComposing) { return; }
    var t = event.target;
    if (!(t instanceof HTMLInputElement) || /^(checkbox|radio|file|submit|button|search)$/.test(t.type)) { return; }
    var form = t.form;
    if (!form || !form.querySelector('[data-savebar]')) { return; }
    event.preventDefault();
    var fields = Array.prototype.filter.call(form.elements, function (el) {
      return !el.disabled && el.type !== 'hidden' && el.offsetParent !== null && /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !/^(checkbox|radio|file)$/.test(el.type);
    });
    var next = fields[fields.indexOf(t) + 1];
    if (next) { next.focus(); } else { t.blur(); }
  });

  /* Estoque no formulário: botões de + e - ao lado do campo. */
  each($$('[data-stepper]'), function (box) {
    var input = $('input', box);
    each($$('[data-step]', box), function (button) {
      button.hidden = false;
      button.addEventListener('click', function () {
        var now = parseInt(input.value, 10);
        if (isNaN(now)) { now = 0; }
        input.value = String(Math.max(0, now + Number(button.getAttribute('data-step'))));
        clearFieldError(input);
      });
    });
  });

  /* ---------- Fotos: prévia, redução e remoção antes de enviar ---------- */
  var MAX_SIDE = 2000;
  var canSetFiles = (function () { try { return !!new DataTransfer().items; } catch (e) { return false; } })();

  function decode(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return decodeImg(file); });
    }
    return decodeImg(file);
  }
  function decodeImg(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () {
        var img = new Image();
        img.onload = function () { resolve(img); };
        img.onerror = reject;
        img.src = reader.result;
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }
  function processFile(file) {
    return decode(file).then(function (img) {
      var w = img.width || img.naturalWidth;
      var h = img.height || img.naturalHeight;
      var thumb = doc.createElement('canvas');
      thumb.width = 160; thumb.height = Math.round(160 * h / w) || 200;
      thumb.getContext('2d').drawImage(img, 0, 0, thumb.width, thumb.height);
      var thumbUrl = thumb.toDataURL('image/jpeg', 0.7);
      var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
      var okType = /^image\/(jpeg|png|webp)$/.test(file.type);
      if (scale === 1 && okType && file.size <= 1.5 * 1048576) {
        if (img.close) { img.close(); }
        return { file: file, thumb: thumbUrl };
      }
      var canvas = doc.createElement('canvas');
      canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      if (img.close) { img.close(); }
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          if (!blob || (okType && blob.size >= file.size)) { resolve({ file: file, thumb: thumbUrl }); return; }
          var name = (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
          resolve({ file: new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }), thumb: thumbUrl, shrunk: true });
        }, 'image/jpeg', 0.86);
      });
    }).catch(function () { return { file: file, thumb: null }; });
  }

  each($$('[data-photo-input]'), function (input) {
    var form = input.form;
    var list = $('[data-photo-preview]', input.closest('.field') || form);
    var maxEach = parseFloat(input.getAttribute('data-max-mb') || '0') * 1048576;
    var maxTotal = parseFloat(input.getAttribute('data-max-total-mb') || '0') * 1048576;
    var coverFirst = !$('.thumb-row', form) && !doc.querySelector('.photo-grid');
    var items = [];
    var note = doc.createElement('p');
    note.className = 'hint pick-status';
    note.setAttribute('data-photo-note', '');
    note.setAttribute('tabindex', '-1');
    note.setAttribute('role', 'status');
    input.insertAdjacentElement('afterend', note);

    function sync() {
      if (canSetFiles) {
        var dt = new DataTransfer();
        items.forEach(function (it) { dt.items.add(it.file); });
        input.files = dt.files;
      }
      var total = 0;
      var problems = [];
      items.forEach(function (it) {
        total += it.file.size;
        if (maxEach && it.file.size > maxEach) { problems.push(it.file.name + ' ainda tem ' + mb(it.file.size) + ' MB (limite de ' + input.getAttribute('data-max-mb') + ' MB por foto). Remova essa.'); }
      });
      if (maxTotal && total > maxTotal) { problems.push('Juntas, as fotos somam ' + mb(total) + ' MB e o limite é ' + input.getAttribute('data-max-total-mb') + ' MB. Remova algumas e envie o resto depois.'); }
      if (problems.length) { form.setAttribute('data-photo-blocked', ''); note.className = 'field-error pick-status'; note.textContent = problems.join(' '); }
      else {
        form.removeAttribute('data-photo-blocked');
        note.className = 'hint pick-status';
        note.textContent = items.length ? items.length + (items.length === 1 ? ' foto pronta' : ' fotos prontas') + ' para enviar (' + mb(total) + ' MB).' : '';
      }
      render();
    }
    function render() {
      if (!list) { return; }
      list.textContent = '';
      list.hidden = items.length === 0;
      items.forEach(function (it, i) {
        var li = doc.createElement('li');
        var img = doc.createElement('img');
        img.alt = 'Foto escolhida ' + (i + 1);
        if (it.thumb) { img.src = it.thumb; }
        li.appendChild(img);
        if (i === 0 && coverFirst) {
          var tag = doc.createElement('span');
          tag.className = 'pick-cover';
          tag.textContent = 'Capa';
          li.appendChild(tag);
        }
        var size = doc.createElement('span');
        size.className = 'pick-size';
        size.textContent = mb(it.file.size) + ' MB';
        li.appendChild(size);
        if (canSetFiles) {
          var rm = doc.createElement('button');
          rm.type = 'button';
          rm.className = 'btn small ghost';
          rm.textContent = 'Remover';
          rm.setAttribute('aria-label', 'Remover foto escolhida ' + (i + 1));
          rm.addEventListener('click', function () {
            items.splice(i, 1);
            sync();
            (list.querySelector('button') || input).focus();
          });
          li.appendChild(rm);
        }
        list.appendChild(li);
      });
    }

    input.addEventListener('change', function () {
      var picked = Array.prototype.slice.call(input.files || []);
      if (!picked.length) { if (canSetFiles) { sync(); } return; }
      if (!canSetFiles) { items = []; }
      note.className = 'hint pick-status';
      note.textContent = 'Preparando ' + (picked.length === 1 ? 'a foto' : picked.length + ' fotos') + '...';
      /* Só segura o envio se alguma foto precisa ser reduzida (grande ou em
         formato que o servidor não aceita); senão a prévia chega depois. */
      var heavy = picked.some(function (f) { return f.size > 1.5 * 1048576 || !/^image\/(jpeg|png|webp)$/.test(f.type); });
      var work = Promise.all(picked.map(processFile)).then(function (done) {
        items = items.concat(done);
        input._pending = null;
        if (!canSetFiles) {
          /* Navegador antigo: envia os arquivos originais, só mostra a prévia. */
          items = done.map(function (d, i) { return { file: picked[i], thumb: d.thumb }; });
        }
        sync();
      });
      input._pending = heavy ? work : null;
    });
  });
  function waitPhotos(form) {
    var waits = [];
    each($$('[data-photo-input]', form), function (input) { if (input._pending) { waits.push(input._pending); } });
    return waits.length ? Promise.all(waits) : null;
  }

  /* ---------- Promoção: preencher preço calcula a %, e vice-versa ---------- */
  doc.addEventListener('input', function (event) {
    var field = event.target;
    var box = field.closest && field.closest('[data-promo]');
    if (!box) { return; }
    var baseInput = box.getAttribute('data-price-input') ? $(box.getAttribute('data-price-input')) : null;
    var base = baseInput ? toCents(baseInput.value) : Number(box.getAttribute('data-price'));
    var priceField = $('[data-promo-price]', box);
    var pctField = $('[data-promo-pct]', box);
    if (!base || isNaN(base) || !priceField || !pctField) { return; }
    if (field === priceField) {
      var cents = toCents(priceField.value);
      pctField.value = cents && !isNaN(cents) && cents < base ? String(Math.round((base - cents) * 100 / base)) : '';
    } else if (field === pctField) {
      var pct = Number(String(pctField.value).replace(',', '.').replace('%', ''));
      priceField.value = pct > 0 && pct < 100 ? centsText(Math.round(base * (100 - pct) / 100)) : '';
    }
  });

  /* ---------- Ações rápidas da lista de produtos ----------
     Resposta imediata na tela, gravação em seguida. Se falhar, a linha volta
     como estava e o aviso diz o porquê. Sem JavaScript o mesmo formulário faz
     POST normal e volta para a lista. */
  var INVERSE = { featured_on: 'featured_off', featured_off: 'featured_on', show_stock_on: 'show_stock_off', show_stock_off: 'show_stock_on', sold_out: 'reactivate', reactivate: 'sold_out' };

  function optimistic(row, op, submitter) {
    if (!row) { return; }
    if (submitter && submitter.classList.contains('toggle')) {
      submitter.setAttribute('aria-pressed', submitter.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
    }
    var stock = $('.stock-input', row);
    if (stock && (op === 'stock_inc' || op === 'stock_dec')) {
      var now = parseInt(stock.value, 10);
      if (isNaN(now)) { now = 0; }
      stock.value = String(Math.max(0, now + (op === 'stock_inc' ? 1 : -1)));
    }
    if (op === 'sold_out') { row.classList.add('is-out'); }
    if (op === 'reactivate') { row.classList.remove('is-out'); }
  }

  /* Uma fila por produto: toques seguidos (+ + +, Destaque e logo Mostrar
     estoque) saem em ordem, sem se perder nem atropelar. */
  var queues = {};
  function sendQuick(form, data, id, opts) {
    var action = form.getAttribute('action');
    queues[id] = (queues[id] || Promise.resolve()).then(function () { return runQuick(form, action, data, id, opts || {}); });
    return queues[id];
  }
  function runQuick(form, action, data, id, opts) {
    var row = doc.getElementById('produto-' + id);
    var snapshot = opts.snapshot || (row ? row.cloneNode(true) : null);
    var rollback = function () {
      var now = doc.getElementById('produto-' + id);
      if (now && snapshot) { now.replaceWith(snapshot); }
    };
    if (navigator.onLine === false) {
      rollback();
      toast('Sem internet. Nada foi salvo. Tente de novo quando a conexão voltar.', false);
      return null;
    }
    if (row) { row.setAttribute('aria-busy', 'true'); }
    return window.fetch(action, {
      method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Lynch-Ajax': '1' }
    }).then(function (res) {
      return res.json().then(function (state) { return state; }, function () {
        /* Não veio JSON (sessão caiu e o servidor mandou o login): envio normal. */
        var err = new Error('html'); err.html = true; throw err;
      });
    }).then(function (state) {
      var current = doc.getElementById('produto-' + id);
      if (state.html && current) {
        var holder = doc.createElement('ul');
        holder.innerHTML = state.html;
        var fresh = holder.firstElementChild;
        current.replaceWith(fresh);
        if (opts.focusKey) { restoreFocus(fresh, opts.focusKey); }
      }
      if (state.counts) {
        Object.keys(state.counts).forEach(function (key) {
          var badge = $('[data-count="' + key + '"]');
          if (badge) { badge.textContent = state.counts[key]; }
        });
      }
      var undoAction = state.ok && opts.undo ? { label: 'Desfazer', run: opts.undo } : null;
      toast(state.message || (state.ok ? 'Salvo.' : 'Não foi possível salvar.'), !!state.ok, undoAction);
    }).catch(function (error) {
      if (error && error.html) { if (form.isConnected) { form.submit(); } else { window.location.reload(); } return; }
      rollback();
      toast(navigator.onLine === false
        ? 'Sem internet. Nada foi salvo. Tente de novo quando a conexão voltar.'
        : 'Não deu para salvar agora (conexão instável). Nada mudou. Tente de novo.', false);
    }).then(function () {
      var current = doc.getElementById('produto-' + id);
      if (current) { current.removeAttribute('aria-busy'); }
    });
  }

  doc.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-quick') || event.defaultPrevented || !window.fetch) { return; }
    event.preventDefault();
    var data = new FormData(form);
    var submitter = event.submitter;
    if (submitter && submitter.name) { data.set(submitter.name, submitter.value); }
    var op = String(data.get('op') || '');
    var id = String(data.get('id'));
    var row = doc.getElementById('produto-' + id);
    var focusKey = submitter && submitter.name ? submitter.name + '=' + submitter.value.replace(/_(on|off)$/, '') : null;
    if (submitter && submitter.classList.contains('step')) { focusKey = 'step=' + ((form.getAttribute('id') || '').indexOf('menos') !== -1 ? '0' : '1'); }
    if (!focusKey && doc.activeElement && doc.activeElement.name) { focusKey = 'field=' + doc.activeElement.name; }

    /* Desfazer: o contrário da ação, ou o estoque de antes. */
    var undo = null;
    var stockInput = row ? $('.stock-input', row) : null;
    var before = stockInput ? stockInput.defaultValue : null;
    var undoOp = INVERSE[op];
    if (/^stock_(inc|dec|set)$/.test(op) && stockInput) { undoOp = 'stock_set'; }
    if (undoOp) {
      undo = function () {
        var base = doc.getElementById('produto-' + id);
        var baseForm = base ? $('.prow-toggles', base) : null;
        if (!baseForm) { return; }
        var undoData = new FormData(baseForm);
        undoData.set('op', undoOp);
        if (undoOp === 'stock_set') { undoData.set('stock', before || ''); }
        var snap = base.cloneNode(true);
        optimistic(base, undoOp, null);
        sendQuick(baseForm, undoData, id, { snapshot: snap });
      };
    }
    var snapshot = row ? row.cloneNode(true) : null;
    optimistic(row, op, submitter);
    sendQuick(form, data, id, { focusKey: focusKey, undo: undo, snapshot: snapshot });
  });

  /* Depois de redesenhar, o foco volta para o controle equivalente. */
  function restoreFocus(row, key) {
    if (!key) { return; }
    var target = null;
    if (key.indexOf('step=') === 0) {
      target = row.querySelectorAll('.step')[Number(key.slice(5))] || null;
      if (target && target.disabled) { target = $('.stock-input', row); }
    } else if (key.indexOf('field=') === 0) {
      target = $('[name="' + key.slice(6) + '"]', row);
    } else {
      var parts = key.split('=');
      each($$('button[name="' + parts[0] + '"]', row), function (b) {
        if (!target && b.value.replace(/_(on|off)$/, '') === parts[1]) { target = b; }
      });
      if (!target && (parts[1] === 'sold_out' || parts[1] === 'reactivate')) {
        target = $('button[value="sold_out"], button[value="reactivate"]', row);
      }
    }
    if (!target && /stock_(inc|dec)/.test(key)) { target = $('.stock-input', row); }
    if (target && !target.disabled) { target.focus({ preventScroll: true }); }
  }

  /* Estoque digitado salva sozinho ao sair do campo ou apertar Enter. */
  doc.addEventListener('change', function (event) {
    var field = event.target;
    if (field.matches && field.matches('[data-autosave]') && field.form && field.form.requestSubmit) {
      field.form.requestSubmit();
    }
  });

  /* ---------- Cupom: código maiúsculo e "Sugerir outro" ---------- */
  var codeField = $('[data-coupon-code]');
  var suggestBtn = $('[data-coupon-suggest]');
  if (codeField) {
    codeField.addEventListener('input', function () {
      var at = codeField.selectionStart;
      var clean = codeField.value.toUpperCase().replace(/\s+/g, '');
      if (clean !== codeField.value) { codeField.value = clean; try { codeField.setSelectionRange(at, at); } catch (e) { /* campo sem cursor */ } }
    });
  }
  if (codeField && suggestBtn && window.crypto && window.crypto.getRandomValues) {
    suggestBtn.hidden = false;
    suggestBtn.addEventListener('click', function () {
      var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      var bytes = new Uint8Array(4);
      window.crypto.getRandomValues(bytes);
      var code = 'LYNCH';
      for (var i = 0; i < bytes.length; i++) { code += alphabet[bytes[i] % alphabet.length]; }
      codeField.value = code;
    });
  }

  /* ---------- Atalhos que levam a um formulário: foco no primeiro campo ---------- */
  doc.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a[data-focus-target]');
    if (!link) { return; }
    var target = doc.getElementById(link.getAttribute('data-focus-target'));
    if (target) { setTimeout(function () { target.focus(); }, 60); }
  });

  /* ---------- Copiar resumo do pedido ---------- */
  each($$('[data-copy]'), function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var text = button.getAttribute('data-copy');
      var done = function () { toast('Resumo copiado. Cole na conversa ou nas suas anotações.', true); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text) ? done() : toast('Não deu para copiar neste aparelho.', false); });
      } else if (fallbackCopy(text)) { done(); } else { toast('Não deu para copiar neste aparelho.', false); }
    });
  });
  function fallbackCopy(text) {
    var area = doc.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.className = 'sr';
    doc.body.appendChild(area);
    area.select();
    var ok = false;
    try { ok = doc.execCommand('copy'); } catch (e) { ok = false; }
    area.remove();
    return ok;
  }

  /* ---------- Abas em chips: a escolhida fica à vista ---------- */
  each($$('.tabs'), function (tabs) {
    var on = $('.tab.on', tabs);
    if (on && tabs.scrollWidth > tabs.clientWidth) {
      tabs.scrollLeft = on.offsetLeft - (tabs.clientWidth - on.offsetWidth) / 2;
    }
  });

  /* ---------- Teclado aberto no celular ----------
     O menu de baixo some enquanto digita, e a barra de salvar sobe junto com
     o teclado (iPhone não encolhe a página, só a parte visível). */
  var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  var typing = function (el) {
    return el && ((el.tagName === 'INPUT' && !/^(checkbox|radio|file|button|submit|range|color)$/.test(el.type)) || el.tagName === 'TEXTAREA');
  };
  if (coarse) {
    doc.addEventListener('focusin', function (event) { if (typing(event.target)) { doc.body.classList.add('kb-open'); } });
    doc.addEventListener('focusout', function () {
      setTimeout(function () { if (!typing(doc.activeElement)) { doc.body.classList.remove('kb-open'); } }, 80);
    });
  }
  var vv = window.visualViewport;
  if (vv && $('[data-savebar]')) {
    var syncKb = function () {
      var hidden = window.innerHeight - vv.height - vv.offsetTop;
      root.style.setProperty('--kb', (hidden > 80 ? Math.round(hidden) : 0) + 'px');
    };
    vv.addEventListener('resize', syncKb);
    vv.addEventListener('scroll', syncKb);
  }
})();

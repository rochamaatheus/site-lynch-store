/* Lynch Store - painel
   Tudo progressivo: sem JavaScript os formulários continuam funcionando.
   Fica em arquivo próprio (e não inline) para a política de segurança do
   site poder bloquear script embutido na página. */
(function () {
  'use strict';

  /* Confirmação antes de ações destrutivas: <form data-confirm="Texto">.
     O texto vem do servidor já escapado e é lido como texto puro aqui. */
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) { return; }

    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }

    /* Evita toque duplo em envios demorados: <form data-busy="Enviando...">. */
    var busy = form.getAttribute('data-busy');
    if (busy === null || event.defaultPrevented) { return; }

    var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    window.setTimeout(function () {
      Array.prototype.forEach.call(buttons, function (button) {
        button.disabled = true;
        if (button.tagName === 'BUTTON' && busy !== '') {
          button.setAttribute('data-label', button.textContent);
          button.textContent = busy;
        }
      });
      form.setAttribute('aria-busy', 'true');
    }, 0);
  });

  /* Voltar pelo histórico traz a página do cache: destrava os botões. */
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) { return; }
    Array.prototype.forEach.call(document.querySelectorAll('form[aria-busy="true"]'), function (form) {
      form.removeAttribute('aria-busy');
      Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], input[type="submit"]'), function (button) {
        button.disabled = false;
        if (button.hasAttribute('data-label')) {
          button.textContent = button.getAttribute('data-label');
          button.removeAttribute('data-label');
        }
      });
    });
  });

  /* Foto grande demais: avisa antes de enviar, com o nome do arquivo.
     <input type="file" data-max-mb="5" data-max-total-mb="12"> */
  Array.prototype.forEach.call(document.querySelectorAll('input[type="file"][data-max-mb]'), function (input) {
    input.addEventListener('change', function () {
      var maxEach = parseFloat(input.getAttribute('data-max-mb')) * 1048576;
      var maxTotal = parseFloat(input.getAttribute('data-max-total-mb') || '0') * 1048576;
      var files = Array.prototype.slice.call(input.files || []);
      var problems = [];
      var total = 0;

      files.forEach(function (file) {
        total += file.size;
        if (file.size > maxEach) {
          problems.push(file.name + ' tem ' + (file.size / 1048576).toFixed(1).replace('.', ',') + ' MB (o limite é ' + input.getAttribute('data-max-mb') + ' MB por foto).');
        }
      });
      if (maxTotal > 0 && total > maxTotal) {
        problems.push('Juntas, as fotos somam ' + (total / 1048576).toFixed(1).replace('.', ',') + ' MB. Envie em partes, de 2 ou 3 por vez.');
      }

      var note = input.parentNode.querySelector('[data-file-note]');
      if (!note) {
        note = document.createElement('p');
        note.className = 'hint';
        note.setAttribute('data-file-note', '');
        note.setAttribute('role', 'alert');
        input.parentNode.appendChild(note);
      }
      if (problems.length) {
        note.textContent = problems.join(' ');
        input.value = '';
      } else {
        note.textContent = '';
      }
    });
  });

  /* Mostrar/ocultar senha: <button data-pw-toggle="id-do-campo" hidden>. */
  Array.prototype.forEach.call(document.querySelectorAll('[data-pw-toggle]'), function (button) {
    var field = document.getElementById(button.getAttribute('data-pw-toggle'));
    if (!field) { return; }
    button.hidden = false;
    button.addEventListener('click', function () {
      var show = field.type === 'password';
      field.type = show ? 'text' : 'password';
      button.textContent = show ? 'Ocultar' : 'Mostrar';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });

  /* Mantém a sessão viva enquanto um formulário longo está aberto, para a
     Ray não perder o que digitou: <form data-keepalive="URL">. Se a sessão já
     tiver caído, avisa na hora, antes de ela tentar salvar. */
  var keepForm = document.querySelector('form[data-keepalive]');
  if (keepForm && window.fetch) {
    var pingUrl = keepForm.getAttribute('data-keepalive');
    var warned = false;
    var ping = function () {
      if (document.hidden || warned) { return; }
      window.fetch(pingUrl, { credentials: 'same-origin', cache: 'no-store', redirect: 'manual' })
        .then(function (response) {
          if (response.status === 204) { return; }
          warned = true;
          var alertBox = document.createElement('div');
          alertBox.className = 'flash error';
          alertBox.setAttribute('role', 'alert');
          alertBox.textContent = 'Sua sessão expirou. Antes de salvar, abra o painel em outra aba, entre de novo e volte aqui. Assim o que você digitou não se perde.';
          keepForm.parentNode.insertBefore(alertBox, keepForm);
        })
        .catch(function () { /* sem rede agora: tenta de novo no próximo ciclo */ });
    };
    window.setInterval(ping, 5 * 60 * 1000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { ping(); }
    });
  }

  /* Cadastro de produto: endereço (slug) automático e campo de preço. */
  var nome = document.getElementById('f-name');
  var slug = document.getElementById('f-slug');
  var preco = document.getElementById('f-price');
  var chk = document.getElementById('f-onrequest');

  function slugify(texto) {
    return (texto || '')
      .toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 200);
  }

  if (nome && slug) {
    /* Só sobrescreve enquanto o endereço estiver espelhando o nome. Assim que
       a Ray edita o endereço na mão, o espelho é desligado e não volta. */
    var espelhando = slug.value === '' || slug.value === slugify(nome.value);
    slug.addEventListener('input', function () { espelhando = false; });
    nome.addEventListener('input', function () {
      if (espelhando) { slug.value = slugify(nome.value); }
    });
    slug.addEventListener('blur', function () {
      if (slug.value !== '') { slug.value = slugify(slug.value); }
    });
  }

  if (preco && chk) {
    var syncPreco = function () {
      preco.disabled = chk.checked;
      if (chk.checked) { preco.value = ''; }
    };
    chk.addEventListener('change', syncPreco);
    syncPreco();
  }

  /* ---------- Estoque e promoção ---------- */
  document.documentElement.classList.add('js');

  function toCents(text) {
    var raw = String(text || '').replace(/R\$\s*/, '').replace(/\s/g, '');
    if (!raw) { return null; }
    if (raw.indexOf(',') !== -1) { raw = raw.replace(/\./g, '').replace(',', '.'); }
    var n = Number(raw);
    return isFinite(n) && n > 0 ? Math.round(n * 100) : null;
  }
  function centsText(cents) { return (cents / 100).toFixed(2).replace('.', ','); }

  /* Preço promocional e % de desconto: preencher um calcula o outro. */
  document.addEventListener('input', function (event) {
    var field = event.target;
    var form = field.closest && field.closest('[data-promo]');
    if (!form) { return; }
    var baseInput = form.getAttribute('data-price-input') ? document.querySelector(form.getAttribute('data-price-input')) : null;
    var base = baseInput ? toCents(baseInput.value) : Number(form.getAttribute('data-price'));
    var priceField = form.querySelector('[data-promo-price]');
    var pctField = form.querySelector('[data-promo-pct]');
    if (!base || !priceField || !pctField) { return; }
    if (field === priceField) {
      var cents = toCents(priceField.value);
      pctField.value = cents && cents < base ? String(Math.round((base - cents) * 100 / base)) : '';
    } else if (field === pctField) {
      var pct = Number(String(pctField.value).replace(',', '.').replace('%', ''));
      priceField.value = pct > 0 && pct < 100 ? centsText(Math.round(base * (100 - pct) / 100)) : '';
    }
  });

  var statusBox = document.querySelector('[data-quick-status]');
  var toastTimer;
  function toast(message, ok) {
    if (statusBox) { statusBox.textContent = message; }
    var box = document.querySelector('[data-toast]');
    if (!box) {
      box = document.createElement('div');
      box.setAttribute('data-toast', '');
      box.setAttribute('aria-hidden', 'true');
      document.body.appendChild(box);
    }
    box.className = 'flash toast ' + (ok ? 'success' : 'error');
    box.textContent = message;
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { box.hidden = true; }, 3600);
  }

  /* Ações rápidas da lista: salva sem recarregar e redesenha só o produto.
     Sem JavaScript o mesmo formulário faz POST normal e volta para a lista. */
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-quick') || event.defaultPrevented || !window.fetch) { return; }
    event.preventDefault();
    if (form.getAttribute('data-sending')) { return; }
    form.setAttribute('data-sending', '1');
    var data = new FormData(form);
    var submitter = event.submitter;
    if (submitter && submitter.name) { data.set(submitter.name, submitter.value); }
    var id = data.get('id');
    var row = document.getElementById('produto-' + id);
    var focusKey = submitter && submitter.name ? submitter.name + '=' + submitter.value.replace(/_(on|off)$/, '') : null;
    if (submitter && submitter.classList.contains('step')) { focusKey = 'step=' + ((form.getAttribute('id') || '').indexOf('menos') !== -1 ? '0' : '1'); }
    if (!focusKey && document.activeElement && document.activeElement.name) { focusKey = 'field=' + document.activeElement.name; }
    if (row) { row.setAttribute('aria-busy', 'true'); }

    window.fetch(form.getAttribute('action'), {
      method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Lynch-Ajax': '1' }
    }).then(function (res) {
      return res.json().catch(function () { throw new Error('status ' + res.status); });
    }).then(function (state) {
      if (state.html && row) {
        var holder = document.createElement('ul');
        holder.innerHTML = state.html;
        var fresh = holder.firstElementChild;
        row.replaceWith(fresh);
        row = fresh;
        restoreFocus(fresh, focusKey);
      }
      if (state.counts) {
        Object.keys(state.counts).forEach(function (key) {
          var badge = document.querySelector('[data-count="' + key + '"]');
          if (badge) { badge.textContent = state.counts[key]; }
        });
      }
      toast(state.message || (state.ok ? 'Salvo.' : 'Não foi possível salvar.'), !!state.ok);
    }).catch(function () {
      form.submit();
    }).finally(function () {
      if (row) { row.removeAttribute('aria-busy'); }
      form.removeAttribute('data-sending');
    });
  });

  /* Depois de redesenhar, o foco volta para o controle equivalente. */
  function restoreFocus(row, key) {
    if (!key) { return; }
    var target = null;
    if (key.indexOf('step=') === 0) {
      target = row.querySelectorAll('.step')[Number(key.slice(5))] || null;
      if (target && target.disabled) { target = row.querySelector('.stock-input'); }
    } else if (key.indexOf('field=') === 0) {
      target = row.querySelector('[name="' + key.slice(6) + '"]');
    } else {
      var parts = key.split('=');
      var buttons = row.querySelectorAll('button[name="' + parts[0] + '"]');
      Array.prototype.forEach.call(buttons, function (b) {
        if (!target && b.value.replace(/_(on|off)$/, '') === parts[1]) { target = b; }
      });
      if (!target && (parts[1] === 'sold_out' || parts[1] === 'reactivate')) {
        target = row.querySelector('button[value="sold_out"], button[value="reactivate"]');
      }
    }
    if (!target && /stock_(inc|dec)/.test(key)) { target = row.querySelector('.stock-input'); }
    if (target && !target.disabled) { target.focus({ preventScroll: true }); }
  }

  /* Estoque digitado salva sozinho ao sair do campo ou apertar Enter. */
  document.addEventListener('change', function (event) {
    var field = event.target;
    if (field.matches && field.matches('[data-autosave]') && field.form && field.form.requestSubmit) {
      field.form.requestSubmit();
    }
  });
})();

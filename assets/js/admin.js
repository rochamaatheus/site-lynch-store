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
})();

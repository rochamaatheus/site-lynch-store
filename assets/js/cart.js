/* ---------------------------------------------------------------------------
   Lynch Store - carrinho (fase 4)

   BONUS, nunca requisito: carrinho.php funciona 100% sem este arquivo. Cada
   linha do carrinho ja e um <form method="post"> normal com campo de
   quantidade e botao "Atualizar". O que o JS faz e so trocar isso por um
   stepper (- 1 +) que envia o formulario sozinho.

   Por isso os botoes +/- vem com o atributo hidden no HTML: se este script nao
   rodar (JS desligado, erro de rede, navegador antigo), eles continuam
   invisiveis e o cliente ve o campo numerico + "Atualizar", que funcionam.
--------------------------------------------------------------------------- */
(function () {
  'use strict';

  var forms = document.querySelectorAll('[data-cart-form]');
  if (!forms.length) return;

  Array.prototype.forEach.call(forms, function (form) {
    var input = form.querySelector('[data-cart-qty]');
    var apply = form.querySelector('[data-cart-apply]');
    var steps = form.querySelectorAll('[data-cart-step]');
    if (!input) return;

    var min = parseInt(input.getAttribute('min'), 10);
    var max = parseInt(input.getAttribute('max'), 10);
    if (isNaN(min)) min = 0;
    if (isNaN(max)) max = 99;

    var timer = null;
    var submitting = false;

    function currentValue() {
      var value = parseInt(input.value, 10);
      return isNaN(value) ? min : value;
    }

    function send() {
      if (submitting) return;
      submitting = true;
      // requestSubmit respeita validacao e o submit handler; o fallback cobre
      // navegadores sem ele.
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }

    function scheduleSend() {
      window.clearTimeout(timer);
      // Espera o cliente parar de clicar antes de recarregar a pagina - assim
      // "+ + +" vira UMA atualizacao para 3, e nao tres recargas.
      timer = window.setTimeout(send, 550);
    }

    // Com JS o "Atualizar" vira redundante.
    if (apply) {
      apply.hidden = true;
    }

    Array.prototype.forEach.call(steps, function (button) {
      button.hidden = false;

      button.addEventListener('click', function () {
        var delta = parseInt(button.getAttribute('data-cart-step'), 10) || 0;
        var next = currentValue() + delta;

        if (next < min) next = min;
        if (next > max) next = max;

        if (next === currentValue()) return;

        input.value = String(next);
        scheduleSend();
      });
    });

    // Digitou no campo e saiu / apertou Enter: mesma coisa.
    input.addEventListener('change', function () {
      var value = currentValue();
      if (value < min) input.value = String(min);
      if (value > max) input.value = String(max);
      window.clearTimeout(timer);
      send();
    });
  });
})();

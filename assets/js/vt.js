/* Foto do card "voa" ate a galeria do produto (view transition entre paginas).
   Precisa carregar bloqueando no <head> para ouvir pagereveal a tempo. Em navegador
   sem suporte os eventos nunca disparam e a navegacao segue normal. */
(function () {
  'use strict';
  var KEY = 'lynch-morph';
  var last = null;

  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('[data-morph] a, .card-name a');
    if (!link) return;
    var card = link.closest('.card');
    var frame = card ? card.querySelector('[data-morph]') : link.closest('[data-morph]');
    if (frame) last = frame;
  });

  window.addEventListener('pageswap', function (event) {
    if (!event.viewTransition || !last) return;
    last.style.viewTransitionName = 'peca';
    try { sessionStorage.setItem(KEY, last.getAttribute('data-morph')); } catch (e) {}
    var frame = last;
    event.viewTransition.finished.finally(function () { frame.style.viewTransitionName = ''; });
    last = null;
  });

  window.addEventListener('pagereveal', function (event) {
    var slug = null;
    try { slug = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); } catch (e) {}
    if (!event.viewTransition || !slug) return;
    var target = document.querySelector('[data-morph="' + CSS.escape(slug) + '"]');
    if (!target) return;
    target.style.viewTransitionName = 'peca';
    event.viewTransition.ready.finally(function () { target.style.viewTransitionName = ''; });
  });
})();

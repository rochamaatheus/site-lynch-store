/* ---------------------------------------------------------------------------
   Lynch Store - galeria da pagina de produto (fase 3)

   Porte de setGalleryImage / zoomMove / zoomIn / zoomOut do index.html estatico,
   adaptado para imagens REAIS vindas do banco:
     - a foto principal e um <img> de verdade (alt/SEO/lazy), nao mais um
       background-image, entao o zoom usa transform + transform-origin;
     - as miniaturas so existem quando o produto tem mais de uma foto - se nao
       houver, este script simplesmente nao encontra nada e sai.

   Sem JS a pagina continua correta: mostra a foto de capa e todas as
   miniaturas ja renderizadas pelo servidor.
--------------------------------------------------------------------------- */
(function () {
  'use strict';

  var stage = document.querySelector('[data-gallery]');
  if (!stage) {
    return;
  }

  var mainImage = stage.querySelector('[data-gallery-image]');
  var thumbs = document.querySelectorAll('[data-thumb]');
  var requestId = 0;
  var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var animation;

  // ---- Troca de foto pelas miniaturas ------------------------------------
  function setGalleryImage(button) {
    var src = button.getAttribute('data-src');
    if (!src || !mainImage) {
      return;
    }

    var current = ++requestId;
    var nextImage = new Image();
    stage.setAttribute('aria-busy', 'true');
    nextImage.onload = function () {
      if (current !== requestId) return;
      if (animation) animation.cancel();
      mainImage.setAttribute('src', src);
      mainImage.removeAttribute('srcset');
      mainImage.setAttribute('alt', button.getAttribute('data-alt') || '');
      zoomOut();
      stage.removeAttribute('aria-busy');
      for (var i = 0; i < thumbs.length; i++) {
        var active = thumbs[i] === button;
        thumbs[i].classList.toggle('active', active);
        thumbs[i].setAttribute('aria-pressed', String(active));
      }
      if (!motion.matches && mainImage.animate) {
        animation = mainImage.animate([{opacity:.35},{opacity:1}], {duration:180,easing:'ease-out'});
      }
    };
    nextImage.onerror = function () {
      if (current === requestId) stage.removeAttribute('aria-busy');
    };
    nextImage.src = src;
  }

  for (var i = 0; i < thumbs.length; i++) {
    thumbs[i].setAttribute('aria-pressed', String(thumbs[i].classList.contains('active')));
    thumbs[i].addEventListener('click', function (event) {
      setGalleryImage(event.currentTarget);
    });
  }

  // ---- Zoom on hover ------------------------------------------------------
  // Em telas de toque nao ha hover: o CSS ja neutraliza o transform
  // (@media (hover:none)), entao nem registramos os listeners.
  if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) {
    return;
  }

  function zoomMove(event) {
    var rect = stage.getBoundingClientRect();
    var x = ((event.clientX - rect.left) / rect.width) * 100;
    var y = ((event.clientY - rect.top) / rect.height) * 100;
    stage.style.setProperty('--ox', Math.max(0, Math.min(100, x)) + '%');
    stage.style.setProperty('--oy', Math.max(0, Math.min(100, y)) + '%');
  }

  function zoomIn() {
    stage.classList.add('zoomed');
  }

  function zoomOut() {
    stage.classList.remove('zoomed');
    stage.style.setProperty('--ox', '50%');
    stage.style.setProperty('--oy', '50%');
  }

  stage.addEventListener('mouseenter', zoomIn);
  stage.addEventListener('mousemove', zoomMove);
  stage.addEventListener('mouseleave', zoomOut);
})();

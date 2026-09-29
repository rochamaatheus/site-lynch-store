document.addEventListener('click', event => {
  const button = event.target.closest('form button');
  if (!button) return;
  const notice = document.querySelector('.client-preview-notice span');
  if (notice) notice.textContent = 'Esta é uma demonstração visual. Nenhuma alteração foi salva.';
});
document.addEventListener('submit', event => event.preventDefault());

/**
 * Единая инициализация темы до первого paint.
 * Ключ localStorage: aq_theme (dark | light).
 * Миграция со старого dz-theme для страницы ДЗ.
 */
(function () {
  var root = document.getElementById('html-root') || document.documentElement;
  var t = localStorage.getItem('aq_theme');
  if (t !== 'light' && t !== 'dark') {
    t = localStorage.getItem('dz-theme') === 'light' ? 'light' : 'dark';
  }
  root.setAttribute('data-theme', t);
})();

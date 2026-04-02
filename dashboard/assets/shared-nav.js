/* ============================================================
   shared-nav.js — SPA-навигация, prefetch, плавные переходы
   Подключается на всех страницах ПЕРЕД закрывающим </body>
   ============================================================ */
(function () {
  'use strict';

  // ── Список страниц для prefetch ────────────────────────────
  const PAGES = ['index.php', 'manager.php', 'churn.php', 'churn_fact.php', 'weekly.php'];

  // ── Prefetch всех страниц сразу после загрузки ─────────────
  function prefetchPages() {
    const current = location.pathname.split('/').pop() || 'index.php';
    PAGES.forEach(page => {
      if (page === current) return;
      if (document.querySelector(`link[rel="prefetch"][href*="${page}"]`)) return;
      const link = document.createElement('link');
      link.rel  = 'prefetch';
      link.href = page;
      link.as   = 'document';
      document.head.appendChild(link);
    });
  }

  // ── Плавный переход: fade-out → navigate ──────────────────
  function addTransitions() {
    document.addEventListener('click', e => {
      const a = e.target.closest('a[href]');
      if (!a) return;
      const href = a.getAttribute('href');
      if (!href) return;
      // Только внутренние .php ссылки, без якорей и внешних URL
      if (href.startsWith('http') || href.startsWith('//') || href.startsWith('#')) return;
      if (!href.endsWith('.php') && !href.match(/\.php\?/)) return;
      // Не перехватываем Ctrl/Cmd+клик (открыть в новой вкладке)
      if (e.ctrlKey || e.metaKey || e.shiftKey) return;

      e.preventDefault();
      document.body.classList.add('page-exit');
      setTimeout(() => { window.location.href = href; }, 180);
    });
  }

  // ── Вход: fade-in при загрузке страницы ───────────────────
  function addEnterAnimation() {
    document.body.classList.add('page-enter');
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        document.body.classList.remove('page-enter');
      });
    });
  }

  // ── CSS для переходов (инжектируется один раз) ────────────
  function injectTransitionCss() {
    if (document.getElementById('aq-nav-transitions')) return;
    const style = document.createElement('style');
    style.id = 'aq-nav-transitions';
    style.textContent = `
      body { transition: opacity .18s ease; }
      body.page-enter { opacity: 0; }
      body.page-exit  { opacity: 0; pointer-events: none; }
    `;
    document.head.appendChild(style);
  }

  // ── Инициализация ─────────────────────────────────────────
  function init() {
    injectTransitionCss();
    addEnterAnimation();
    addTransitions();
    // Prefetch через 1 секунду после загрузки чтобы не мешать основному контенту
    setTimeout(prefetchPages, 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

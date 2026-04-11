/* ============================================================
   AqToast — лёгкие уведомления для всех страниц AnyQuery
   Использование: AqToast.ok('Данные обновлены')
                  AqToast.err('Ошибка сети')
                  AqToast.info('Синхронизация…')
   ============================================================ */
(function () {
  'use strict';

  const DURATION = { ok: 3500, err: 6000, info: 2500 };

  function getRoot() {
    let root = document.getElementById('aq-toast-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'aq-toast-root';
      root.setAttribute('aria-live', 'polite');
      root.setAttribute('aria-atomic', 'false');
      document.body.appendChild(root);
    }
    return root;
  }

  function show(msg, type, duration) {
    const root = getRoot();
    const t = document.createElement('div');
    t.className = 'aq-toast aq-toast-' + (type || 'info');
    t.textContent = String(msg || '');
    root.appendChild(t);

    requestAnimationFrame(() => {
      requestAnimationFrame(() => t.classList.add('aq-toast-in'));
    });

    const ms = duration != null ? duration : (DURATION[type] || DURATION.info);
    const remove = () => {
      t.classList.remove('aq-toast-in');
      t.addEventListener('transitionend', () => { try { t.remove(); } catch (_) {} }, { once: true });
      setTimeout(() => { try { t.remove(); } catch (_) {} }, 500);
    };
    setTimeout(remove, ms);

    t.addEventListener('click', remove);
  }

  window.AqToast = {
    show,
    ok:   (msg, dur) => show(msg, 'ok', dur),
    err:  (msg, dur) => show(msg, 'err', dur),
    info: (msg, dur) => show(msg, 'info', dur),
  };
})();

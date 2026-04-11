/* ============================================================
   settings.js — страница настроек AnyQuery Dashboard
   ============================================================ */
(function () {
  'use strict';

  function getThemeBtn() { return document.getElementById('btn-theme'); }
  function applyTheme(dark) {
    document.getElementById('html-root')?.setAttribute('data-theme', dark ? 'dark' : 'light');
    const btn = getThemeBtn();
    if (btn) btn.title = dark ? 'Светлая тема' : 'Тёмная тема';
  }
  function bindTheme() {
    const btn = getThemeBtn();
    if (!btn) return;
    const cur = document.getElementById('html-root')?.getAttribute('data-theme') || 'dark';
    applyTheme(cur === 'dark');
    btn.addEventListener('click', () => {
      const isDark = document.getElementById('html-root')?.getAttribute('data-theme') === 'dark';
      applyTheme(!isDark);
      try { localStorage.setItem('aq_theme', !isDark ? 'dark' : 'light'); } catch (_) {}
    });
  }

  function showAlert(msg, type) {
    const el = document.getElementById('st-alert');
    if (!el) return;
    el.textContent = msg;
    el.className = 'st-alert st-alert-' + (type || 'info');
    el.hidden = false;
    setTimeout(() => { el.hidden = true; }, 5000);
  }

  function toggleSecretVisibility(input) {
    input.type = input.type === 'password' ? 'text' : 'password';
  }

  function addToggleButtons() {
    document.querySelectorAll('.st-input-secret').forEach(input => {
      if (input.disabled) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'st-toggle-secret';
      btn.title = 'Показать / скрыть';
      btn.textContent = '👁';
      btn.addEventListener('click', () => toggleSecretVisibility(input));
      const wrap = document.createElement('div');
      wrap.className = 'st-input-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      wrap.appendChild(btn);
    });
  }

  async function handleSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('st-btn-save');
    const status = document.getElementById('st-save-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    if (btn) { btn.disabled = true; btn.textContent = 'Сохраняем…'; }
    if (status) { status.textContent = ''; status.className = 'st-save-status'; }

    const data = {};
    new FormData(form).forEach((val, key) => {
      if (key !== 'csrf_token') data[key] = val;
    });

    try {
      const r = await fetch('settings_save_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify(data),
      });
      const j = await r.json();
      if (j.ok) {
        if (status) {
          status.textContent = '✓ Сохранено';
          status.className = 'st-save-status st-save-ok';
        }
        showAlert('Настройки сохранены в .env.local. Изменения вступят в силу при следующем запросе.', 'success');
        window.AqToast?.ok('Настройки сохранены');
      } else {
        const msg = j.error || 'Ошибка сохранения';
        if (status) {
          status.textContent = '✗ ' + msg;
          status.className = 'st-save-status st-save-err';
        }
        showAlert(msg, 'error');
        window.AqToast?.err(msg);
      }
    } catch (err) {
      const msg = 'Сеть или сервер: ' + String(err && err.message ? err.message : err);
      if (status) {
        status.textContent = '✗ Ошибка';
        status.className = 'st-save-status st-save-err';
      }
      showAlert(msg, 'error');
      window.AqToast?.err(msg);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Сохранить настройки'; }
    }
  }

  async function handleTest(btn) {
    const type    = btn.getAttribute('data-test') || '';
    const fieldId = btn.getAttribute('data-field') || '';
    const resultEl = document.getElementById('test-result-' + type);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Read current value from field (may be unsaved)
    const fieldEl = document.getElementById(fieldId);
    const key = fieldEl ? fieldEl.value.trim() : '';

    btn.disabled = true;
    btn.textContent = 'Проверяем…';
    if (resultEl) { resultEl.textContent = ''; resultEl.className = 'st-test-result'; }

    try {
      const r = await fetch('settings_test_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ type, key }),
      });
      const j = await r.json();
      if (resultEl) {
        resultEl.textContent = (j.ok ? '✓ ' : '✗ ') + (j.msg || '');
        resultEl.className = 'st-test-result ' + (j.ok ? 'st-test-ok' : 'st-test-err');
      }
    } catch (e) {
      if (resultEl) {
        resultEl.textContent = '✗ Сеть: ' + String(e?.message || e);
        resultEl.className = 'st-test-result st-test-err';
      }
    } finally {
      btn.disabled = false;
      btn.textContent = 'Проверить' + (type === 'airtable' ? ' подключение' : '');
    }
  }

  function init() {
    bindTheme();
    addToggleButtons();
    const form = document.getElementById('st-form');
    if (form) form.addEventListener('submit', handleSubmit);
    document.querySelectorAll('.st-btn-test').forEach(btn => {
      btn.addEventListener('click', () => handleTest(btn));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

(function () {
  'use strict';

  const esc = (s) =>
    String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

  function cssVar(name, fallback) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function chartPalette() {
    const a = cssVar('--accent', '#3d8bfd');
    return [
      a,
      cssVar('--danger', '#ff453a'),
      cssVar('--warn', '#f5a623'),
      cssVar('--ok', '#34c759'),
      '#7B61FF',
      '#5c9ded',
      '#a78bfa',
      '#f472b6',
    ];
  }

  let chartInstances = [];

  function destroyCharts() {
    chartInstances.forEach((c) => {
      try {
        c.destroy();
      } catch (_) {}
    });
    chartInstances = [];
  }

  function buildCharts(payload) {
    destroyCharts();
    const ch = payload.charts || {};
    const pal = chartPalette();
    const grid = cssVar('--border', 'rgba(128,128,128,.2)');
    const text = cssVar('--text', '#e8edf4');
    const muted = cssVar('--muted', '#8b97a8');

    const commonOpts = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          labels: { color: text, font: { size: 11 } },
        },
      },
      scales: {},
    };

    const aging = ch.dzAging;
    if (aging && aging.labels && document.getElementById('chart-aging')) {
      chartInstances.push(
        new Chart(document.getElementById('chart-aging'), {
          type: 'bar',
          data: {
            labels: aging.labels,
            datasets: [
              {
                label: '₽',
                data: aging.values,
                backgroundColor: pal.map((c, i) => c + '99'),
                borderColor: pal,
                borderWidth: 1,
              },
            ],
          },
          options: {
            ...commonOpts,
            scales: {
              x: { ticks: { color: muted }, grid: { color: grid } },
              y: {
                ticks: { color: muted },
                grid: { color: grid },
              },
            },
          },
        })
      );
    }

    const seg = ch.churnBySegment;
    if (seg && seg.labels && seg.labels.length && document.getElementById('chart-segment')) {
      chartInstances.push(
        new Chart(document.getElementById('chart-segment'), {
          type: 'doughnut',
          data: {
            labels: seg.labels,
            datasets: [
              {
                data: seg.values,
                backgroundColor: pal.slice(0, seg.labels.length),
                borderColor: cssVar('--surface', '#141b26'),
                borderWidth: 2,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'right', labels: { color: text, font: { size: 11 } } },
            },
          },
        })
      );
    }

    const mgr = ch.dzByManager;
    if (mgr && mgr.labels && mgr.labels.length && document.getElementById('chart-managers')) {
      chartInstances.push(
        new Chart(document.getElementById('chart-managers'), {
          type: 'bar',
          data: {
            labels: mgr.labels,
            datasets: [
              {
                label: '₽',
                data: mgr.values,
                backgroundColor: pal[0] + '88',
                borderColor: pal[0],
                borderWidth: 1,
              },
            ],
          },
          options: {
            indexAxis: 'y',
            ...commonOpts,
            scales: {
              x: { ticks: { color: muted }, grid: { color: grid } },
              y: { ticks: { color: muted }, grid: { display: false } },
            },
          },
        })
      );
    }

    const fm = ch.factMonthly;
    const wrap = document.getElementById('ai-card-monthly-wrap');
    const canvas = document.getElementById('chart-monthly');
    if (fm && fm.labels && fm.labels.length && canvas && wrap) {
      wrap.hidden = false;
      chartInstances.push(
        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: fm.labels,
            datasets: [
              {
                label: 'Churn',
                data: fm.churn || [],
                backgroundColor: pal[1] + 'aa',
                stack: 'a',
              },
              {
                label: 'Downsell',
                data: fm.downsell || [],
                backgroundColor: pal[2] + 'aa',
                stack: 'a',
              },
            ],
          },
          options: {
            ...commonOpts,
            scales: {
              x: { stacked: true, ticks: { color: muted, maxRotation: 45 }, grid: { color: grid } },
              y: { stacked: true, ticks: { color: muted }, grid: { color: grid } },
            },
          },
        })
      );
    } else if (wrap) {
      wrap.hidden = true;
    }
  }

  function syncThemeBtn() {
    const root = document.getElementById('html-root');
    const btn = document.getElementById('btn-theme');
    if (!root || !btn) return;
    const dark = root.getAttribute('data-theme') === 'dark';
    btn.textContent = dark ? '☀️' : '🌙';
    btn.title = dark ? 'Светлая тема' : 'Тёмная тема';
    btn.setAttribute('aria-label', dark ? 'Переключить на светлую тему' : 'Переключить на тёмную тему');
  }

  function bindTheme() {
    const root = document.getElementById('html-root');
    const btn = document.getElementById('btn-theme');
    if (!btn || !root) return;
    btn.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try {
        localStorage.setItem('aq_theme', next);
        localStorage.removeItem('dz-theme');
      } catch (_) {}
      syncThemeBtn();
      const raw = document.getElementById('ai-bootstrap');
      if (raw) {
        try {
          const payload = JSON.parse(raw.textContent || '{}');
          buildCharts(payload);
        } catch (_) {}
      }
    });
    syncThemeBtn();
  }

  function renderMarkdown(text) {
    const out = document.getElementById('ai-output');
    if (!out) return;
    if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
      out.innerHTML = DOMPurify.sanitize(marked.parse(text, { breaks: true }));
    } else {
      out.innerHTML = '<pre style="white-space:pre-wrap;margin:0">' + esc(text) + '</pre>';
    }
    out.hidden = false;
  }

  async function generate() {
    const btn = document.getElementById('btn-generate');
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!btn || !st) return;
    btn.disabled = true;
    st.textContent = 'Запрос к модели… (до 1–2 мин при большом контексте)';
    st.className = 'ai-card-hint';
    try {
      const r = await fetch('ai_insights_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({}),
      });
      const j = await r.json();
      if (!j.ok) {
        st.textContent = j.error || 'Ошибка';
        st.classList.add('ai-status-err');
        return;
      }
      st.textContent = 'Готово.';
      st.classList.add('ai-status-ok');
      renderMarkdown(j.text || '');
    } catch (e) {
      st.textContent = 'Сеть или сервер: ' + esc(String(e && e.message ? e.message : e));
      st.classList.add('ai-status-err');
    } finally {
      btn.disabled = false;
    }
  }

  function init() {
    const raw = document.getElementById('ai-bootstrap');
    if (!raw) return;
    let payload = {};
    try {
      payload = JSON.parse(raw.textContent || '{}');
    } catch (_) {}
    buildCharts(payload);
    bindTheme();

    document.getElementById('btn-generate')?.addEventListener('click', generate);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

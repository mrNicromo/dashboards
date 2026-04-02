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
  let historyChartInstance = null;

  function destroyCharts() {
    chartInstances.forEach((c) => {
      try {
        c.destroy();
      } catch (_) {}
    });
    chartInstances = [];
  }

  function destroyHistoryChart() {
    if (historyChartInstance) {
      try {
        historyChartInstance.destroy();
      } catch (_) {}
      historyChartInstance = null;
    }
  }

  function updateHistoryUi(count, hasSeries) {
    const cnt = document.getElementById('ai-history-count');
    const empty = document.getElementById('ai-history-empty');
    const wrap = document.getElementById('ai-history-canvas-wrap');
    if (cnt) cnt.textContent = String(count);
    if (empty) empty.hidden = hasSeries && count > 0;
    if (wrap) wrap.hidden = !(hasSeries && count > 0);
  }

  function buildHistoryChart(hc) {
    destroyHistoryChart();
    const canvas = document.getElementById('chart-history');
    if (!canvas || typeof Chart === 'undefined') return;
    const labels = hc?.labels || [];
    const count = hc?.count ?? labels.length;
    const hasSeries = labels.length > 0;
    updateHistoryUi(count, hasSeries);
    if (!hasSeries) return;

    const pal = chartPalette();
    const grid = cssVar('--border', 'rgba(128,128,128,.2)');
    const text = cssVar('--text', '#e8edf4');
    const muted = cssVar('--muted', '#8b97a8');

    const fy = hc.factTotalYtd || [];
    const hasFact = fy.some((v) => v != null && !Number.isNaN(v));

    const datasets = [
      {
        label: 'ДЗ всего',
        data: hc.dzTotal || [],
        borderColor: pal[0],
        backgroundColor: pal[0] + '22',
        tension: 0.2,
        fill: false,
        yAxisID: 'y',
      },
      {
        label: 'Churn риск MRR',
        data: hc.churnRisk || [],
        borderColor: pal[1],
        backgroundColor: pal[1] + '22',
        tension: 0.2,
        fill: false,
        yAxisID: 'y',
      },
      {
        label: 'Prob=3 MRR',
        data: hc.churnProb3 || [],
        borderColor: pal[2],
        backgroundColor: pal[2] + '22',
        tension: 0.2,
        fill: false,
        yAxisID: 'y',
      },
    ];
    if (hasFact) {
      datasets.push({
        label: 'Потери YTD',
        data: fy.map((v) => (v == null ? null : v)),
        borderColor: pal[3],
        backgroundColor: pal[3] + '22',
        tension: 0.2,
        fill: false,
        yAxisID: 'y1',
        spanGaps: true,
      });
    }

    const scales = {
      x: {
        ticks: { color: muted, maxRotation: 45 },
        grid: { color: grid },
      },
      y: {
        position: 'left',
        ticks: { color: muted },
        grid: { color: grid },
        title: { display: true, text: '₽', color: muted, font: { size: 10 } },
      },
    };
    if (hasFact) {
      scales.y1 = {
        position: 'right',
        ticks: { color: muted },
        grid: { drawOnChartArea: false },
        title: { display: true, text: 'YTD ₽', color: muted, font: { size: 10 } },
      };
    }

    historyChartInstance = new Chart(canvas, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: text, font: { size: 11 } } },
        },
        scales,
      },
    });
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
      const av = (aging.values || []).map((x) => Number(x));
      const agingMax = av.length ? Math.max(...av, 0) : 0;
      chartInstances.push(
        new Chart(document.getElementById('chart-aging'), {
          type: 'bar',
          data: {
            labels: aging.labels,
            datasets: [
              {
                label: '₽',
                data: aging.values,
                backgroundColor: pal.map((c) => c + '99'),
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
                beginAtZero: true,
                max: agingMax < 1 ? 1 : undefined,
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
    const mgrCanvas = document.getElementById('chart-managers');
    if (mgrCanvas) {
      const hasMgr = mgr && mgr.labels && mgr.labels.length > 0;
      const mLabels = hasMgr ? mgr.labels : ['нет данных в кэше'];
      const mVals = hasMgr ? mgr.values : [0];
      const mMax = Math.max(...(mVals || []).map((x) => Number(x)), 0);
      chartInstances.push(
        new Chart(mgrCanvas, {
          type: 'bar',
          data: {
            labels: mLabels,
            datasets: [
              {
                label: '₽',
                data: mVals,
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
              x: {
                beginAtZero: true,
                max: !hasMgr || mMax < 1 ? 1 : undefined,
                ticks: { color: muted },
                grid: { color: grid },
              },
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

  function mergeBootstrapHistory(historyChart, historyCount) {
    const raw = document.getElementById('ai-bootstrap');
    if (!raw) return;
    try {
      const p = JSON.parse(raw.textContent || '{}');
      if (historyChart) p.historyChart = historyChart;
      if (historyCount != null) p.historyCount = historyCount;
      raw.textContent = JSON.stringify(p);
    } catch (_) {}
  }

  function mergeBootstrapCharts(charts, chartHints) {
    const raw = document.getElementById('ai-bootstrap');
    if (!raw || !charts) return;
    try {
      const p = JSON.parse(raw.textContent || '{}');
      p.charts = charts;
      if (chartHints && typeof chartHints === 'object') {
        p.chartHints = chartHints;
      }
      p.chartsNeedAsyncRefresh = false;
      raw.textContent = JSON.stringify(p);
    } catch (_) {}
  }

  function applyChartHints(payload) {
    const h = payload.chartHints || {};
    function setFoot(id, msg) {
      const el = document.getElementById(id);
      if (!el) return;
      const m = String(msg || '').trim();
      if (m) {
        el.textContent = m;
        el.hidden = false;
      } else {
        el.textContent = '';
        el.hidden = true;
      }
    }
    setFoot('ai-hint-aging', h.aging);
    setFoot('ai-hint-managers', h.managers);
  }

  async function refreshChartsFromApi(payload) {
    const syncEl = document.getElementById('ai-charts-sync-status');
    const csrf =
      document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || payload.csrf || '';
    if (!csrf) {
      if (syncEl) {
        syncEl.textContent = 'Нет CSRF — обновите страницу.';
        syncEl.hidden = false;
      }
      return;
    }
    if (syncEl) {
      syncEl.textContent = 'Синхронизация с Airtable для графиков…';
      syncEl.hidden = false;
    }
    try {
      const r = await fetch('ai_insights_charts_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({}),
      });
      const j = await r.json();
      if (!j.ok) {
        if (syncEl) {
          syncEl.textContent =
            'Графики: ' + (j.error || 'ошибка') + ' Откройте ДЗ или главную для прогрева кэша.';
          syncEl.hidden = false;
        }
        return;
      }
      mergeBootstrapCharts(j.charts, j.chartHints);
      let next = payload;
      const raw = document.getElementById('ai-bootstrap');
      if (raw) {
        try {
          next = JSON.parse(raw.textContent || '{}');
        } catch (_) {}
      }
      buildCharts(next);
      applyChartHints(next);
      if (syncEl) {
        syncEl.textContent = 'Графики обновлены из Airtable.';
        syncEl.hidden = false;
        setTimeout(() => {
          syncEl.hidden = true;
        }, 4000);
      }
    } catch (e) {
      if (syncEl) {
        syncEl.textContent =
          'Сеть: не удалось подгрузить графики. ' + esc(String(e && e.message ? e.message : e));
        syncEl.hidden = false;
      }
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
          applyChartHints(payload);
          buildHistoryChart(payload.historyChart);
        } catch (_) {}
      }
    });
    syncThemeBtn();
  }

  function renderMarkdown(text) {
    const out = document.getElementById('ai-output');
    if (!out) return;
    out.classList.remove('ai-markdown-empty');
    if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
      out.innerHTML = DOMPurify.sanitize(marked.parse(text, { breaks: true }));
    } else {
      out.innerHTML = '<pre style="white-space:pre-wrap;margin:0">' + esc(text) + '</pre>';
    }
  }

  async function generate() {
    const btn = document.getElementById('btn-generate');
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!btn || !st) return;
    btn.disabled = true;
    st.textContent = 'Синхронизация с Airtable и запрос к модели…';
    st.className = 'ai-card-hint';
    try {
      const r = await fetch('ai_insights_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({ forceRefresh: true }),
      });
      let j = {};
      try {
        j = await r.json();
      } catch (parseErr) {
        st.textContent = 'Ответ сервера не JSON (HTTP ' + r.status + '). Проверьте деплой и логи.';
        st.classList.add('ai-status-err');
        return;
      }
      if (!j.ok) {
        st.textContent = j.error || 'Ошибка';
        st.classList.add('ai-status-err');
        return;
      }
      const prov = j.provider ? ' · ' + j.provider : '';
      const fresh = j.dataRefreshedFromAirtable
        ? ' Данные из Airtable обновлены перед анализом.'
        : j.usedRecentCache
          ? ' Использован недавний кэш (полная синхронизация не требовалась).'
          : '';
      st.textContent = 'Готово. В истории снимков: ' + (j.historyCount ?? '—') + '.' + prov + fresh;
      st.classList.add('ai-status-ok');
      st.classList.remove('ai-status-err');

      if (j.charts) {
        mergeBootstrapCharts(j.charts, j.chartHints);
        const raw = document.getElementById('ai-bootstrap');
        let next = {};
        if (raw) {
          try {
            next = JSON.parse(raw.textContent || '{}');
          } catch (_) {}
        }
        buildCharts(next);
        applyChartHints(next);
      }

      const txt = String(j.text || '').trim();
      if (txt) {
        st.classList.remove('ai-status-err');
        renderMarkdown(j.text);
      } else {
        const out = document.getElementById('ai-output');
        if (out) {
          out.classList.add('ai-markdown-empty');
          out.innerHTML =
            '<p class="ai-output-placeholder">Модель вернула пустой текст. Проверьте ключи Gemini/Groq, квоту и логи сервера.</p>';
        }
        st.textContent += ' Пустой ответ модели.';
        st.classList.remove('ai-status-ok');
        st.classList.add('ai-status-err');
      }
      if (j.historyChart) {
        buildHistoryChart(j.historyChart);
        mergeBootstrapHistory(j.historyChart, j.historyCount);
      }
    } catch (e) {
      st.textContent = 'Сеть или сервер: ' + esc(String(e && e.message ? e.message : e));
      st.classList.add('ai-status-err');
    } finally {
      btn.disabled = false;
    }
  }

  async function saveSnapshot() {
    const btn = document.getElementById('btn-snapshot');
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!btn || !st) return;
    btn.disabled = true;
    st.textContent = 'Сохранение снимка…';
    st.className = 'ai-card-hint';
    try {
      const r = await fetch('ai_insights_snapshot_api.php', {
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
      st.textContent = 'Снимок сохранён. В истории: ' + (j.historyCount ?? '—') + '.';
      st.classList.add('ai-status-ok');
      if (j.historyChart) {
        buildHistoryChart(j.historyChart);
        mergeBootstrapHistory(j.historyChart, j.historyCount);
      }
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
    applyChartHints(payload);
    if (payload.historyChart && payload.historyChart.labels && payload.historyChart.labels.length) {
      buildHistoryChart(payload.historyChart);
    } else {
      updateHistoryUi(payload.historyCount || 0, false);
    }
    bindTheme();

    document.getElementById('btn-generate')?.addEventListener('click', generate);
    document.getElementById('btn-snapshot')?.addEventListener('click', saveSnapshot);

    if (payload.chartsNeedAsyncRefresh) {
      refreshChartsFromApi(payload);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

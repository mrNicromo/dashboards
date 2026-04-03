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
  /** Сырой markdown последнего успешного ответа (копирование/скачивание). */
  let lastMarkdownRaw = '';
  /** Текущий стриминг — для отмены */
  let activeStreamController = null;

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

    // Потери по продуктам
    const fp = ch.factByProduct;
    const wrapProd = document.getElementById('ai-card-product-wrap');
    const canvasProd = document.getElementById('chart-product');
    if (fp && fp.labels && fp.labels.length && canvasProd && wrapProd) {
      wrapProd.hidden = false;
      chartInstances.push(
        new Chart(canvasProd, {
          type: 'bar',
          data: {
            labels: fp.labels,
            datasets: [
              { label: 'Churn', data: fp.churn || [], backgroundColor: pal[1] + 'cc', stack: 'a' },
              { label: 'Downsell', data: fp.downsell || [], backgroundColor: pal[2] + 'cc', stack: 'a' },
            ],
          },
          options: {
            ...commonOpts,
            scales: {
              x: { stacked: true, ticks: { color: muted }, grid: { color: grid } },
              y: { stacked: true, ticks: { color: muted }, grid: { color: grid } },
            },
          },
        })
      );
    } else if (wrapProd) {
      wrapProd.hidden = true;
    }

    // ENT vs SMB по месяцам
    const fsm = ch.factSegMonthly;
    const wrapSeg = document.getElementById('ai-card-seg-monthly-wrap');
    const canvasSeg = document.getElementById('chart-seg-monthly');
    if (fsm && fsm.labels && fsm.labels.length && canvasSeg && wrapSeg) {
      wrapSeg.hidden = false;
      chartInstances.push(
        new Chart(canvasSeg, {
          type: 'line',
          data: {
            labels: fsm.labels,
            datasets: [
              {
                label: 'ENT',
                data: fsm.ent || [],
                borderColor: pal[0],
                backgroundColor: pal[0] + '22',
                tension: 0.3,
                fill: true,
              },
              {
                label: 'SMB',
                data: fsm.smb || [],
                borderColor: pal[2],
                backgroundColor: pal[2] + '22',
                tension: 0.3,
                fill: true,
              },
            ],
          },
          options: {
            ...commonOpts,
            scales: {
              x: { ticks: { color: muted, maxRotation: 45 }, grid: { color: grid } },
              y: { ticks: { color: muted }, grid: { color: grid } },
            },
          },
        })
      );
    } else if (wrapSeg) {
      wrapSeg.hidden = true;
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
      const r = await fetch('ai_insights_refresh_api.php', {
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

  const LS_KEY = 'aq_ai_insights_last_v1';
  const COLLAPSE_LEN = 12000;

  function persistLastAnalysis(text, meta) {
    try {
      localStorage.setItem(LS_KEY, JSON.stringify({ text, savedAt: Date.now(), ...meta }));
    } catch (_) {}
  }

  function loadLastAnalysis() {
    try {
      const raw = localStorage.getItem(LS_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (_) {
      return null;
    }
  }

  function applyNumberWarnings(warnings) {
    const el = document.getElementById('ai-number-warn');
    if (!el) return;
    if (!warnings || !warnings.length) {
      el.hidden = true;
      el.innerHTML = '';
      return;
    }
    el.hidden = false;
    el.innerHTML =
      '<strong>Проверка чисел (эвристика):</strong> возможны ложные срабатывания.<ul>' +
      warnings.map((w) => '<li>' + esc(w) + '</li>').join('') +
      '</ul>';
  }

  function afterRenderAdjustLayout(text) {
    const t = String(text || '');
    const long = t.length >= COLLAPSE_LEN;
    const wrap = document.getElementById('ai-output-wrap');
    const btn = document.getElementById('btn-ai-expand');
    if (!wrap || !btn) return;
    if (!long) {
      wrap.classList.add('ai-output-expanded');
      btn.hidden = true;
      return;
    }
    wrap.classList.remove('ai-output-expanded');
    btn.hidden = false;
    btn.textContent = 'Развернуть полностью';
  }

  function showOutputToolbar(show) {
    const tb = document.getElementById('ai-output-toolbar');
    if (tb) tb.hidden = !show;
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
    afterRenderAdjustLayout(text);
  }

  function getCustomQuestion() {
    return (document.getElementById('ai-custom-question')?.value || '').trim();
  }

  function setGeneratingState(busy) {
    const ids = ['btn-generate', 'btn-generate-stream', 'btn-snapshot'];
    ids.forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.disabled = busy;
    });
  }

  /** Потоковая генерация через SSE */
  async function generateStream() {
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!st) return;

    if (activeStreamController) {
      activeStreamController.abort();
      activeStreamController = null;
    }

    setGeneratingState(true);
    st.textContent = '1/2 Синхронизация с Airtable…';
    st.className = 'ai-card-hint';
    showOutputToolbar(false);
    applyNumberWarnings([]);

    const out = document.getElementById('ai-output');
    if (out) {
      out.classList.remove('ai-markdown-empty');
      out.innerHTML = '<p class="ai-stream-cursor">▌</p>';
    }

    const controller = new AbortController();
    activeStreamController = controller;

    let streamText = '';
    let done = false;

    try {
      const resp = await fetch('ai_insights_stream_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ customQuestion: getCustomQuestion() }),
        signal: controller.signal,
      });

      if (!resp.ok || !resp.body) {
        let errMsg = 'HTTP ' + resp.status;
        try {
          const j = await resp.json();
          errMsg = j.error || errMsg;
        } catch (_) {}
        st.textContent = errMsg;
        st.classList.add('ai-status-err');
        if (out) {
          out.classList.add('ai-markdown-empty');
          out.innerHTML = '<p class="ai-output-placeholder">' + esc(errMsg) + '</p>';
        }
        return;
      }

      const reader = resp.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';
      let currentEvent = '';

      while (true) {
        const { value, done: rdDone } = await reader.read();
        if (rdDone) break;
        buf += decoder.decode(value, { stream: true });

        const lines = buf.split('\n');
        buf = lines.pop() ?? '';

        for (const rawLine of lines) {
          const line = rawLine.replace(/\r$/, '');
          if (line.startsWith('event: ')) {
            currentEvent = line.slice(7);
          } else if (line.startsWith('data: ')) {
            let data;
            try {
              data = JSON.parse(line.slice(6));
            } catch (_) {
              continue;
            }
            if (currentEvent === 'status') {
              st.textContent = data.msg || '';
            } else if (currentEvent === 'text') {
              streamText += data.t || '';
              if (out) {
                out.innerHTML =
                  (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined'
                    ? DOMPurify.sanitize(marked.parse(streamText, { breaks: true }))
                    : '<pre style="white-space:pre-wrap;margin:0">' + esc(streamText) + '</pre>') +
                  '<span class="ai-stream-cursor">▌</span>';
              }
            } else if (currentEvent === 'error') {
              st.textContent = data.error || 'Ошибка стриминга';
              st.classList.add('ai-status-err');
              if (out) {
                out.classList.add('ai-markdown-empty');
                out.innerHTML = '<p class="ai-output-placeholder">' + esc(data.error || '') + '</p>';
              }
              done = true;
              break;
            } else if (currentEvent === 'done') {
              done = true;
              // Финальный рендер без курсора
              lastMarkdownRaw = streamText;
              if (out) {
                renderMarkdown(streamText);
              }
              applyNumberWarnings(data.numberWarnings || []);
              persistLastAnalysis(streamText, {
                promptVersion: data.promptVersion,
                llmModel: data.llmModel,
                provider: data.provider,
              });
              showOutputToolbar(true);
              const rw = document.getElementById('ai-restore-wrap');
              if (rw) rw.hidden = true;

              const prov = data.provider || '';
              const lm = data.llmModel ? ' · ' + data.llmModel : '';
              const tm = data.llmMs != null ? ' · LLM ' + data.llmMs + ' ms' : '';
              st.textContent =
                'Готово (стриминг). В истории: ' +
                (data.historyCount ?? '—') +
                '. ' +
                prov +
                lm +
                tm;
              st.classList.add('ai-status-ok');
              st.classList.remove('ai-status-err');

              if (data.charts) {
                mergeBootstrapCharts(data.charts, data.chartHints);
                const raw = document.getElementById('ai-bootstrap');
                let next = {};
                if (raw) {
                  try { next = JSON.parse(raw.textContent || '{}'); } catch (_) {}
                }
                buildCharts(next);
                applyChartHints(next);
              }
              if (data.historyChart) {
                buildHistoryChart(data.historyChart);
                mergeBootstrapHistory(data.historyChart, data.historyCount);
              }
            }
          }
        }
        if (done) break;
      }
    } catch (e) {
      if (e && e.name === 'AbortError') return;
      st.textContent = 'Сеть или сервер: ' + esc(String(e && e.message ? e.message : e));
      st.classList.add('ai-status-err');
    } finally {
      activeStreamController = null;
      setGeneratingState(false);
    }
  }

  // ── Error status display ──────────────────────────────────────────────────
  function clearErrorStatus() {
    const wrap = document.getElementById('ai-error-status-wrap');
    if (wrap) { wrap.innerHTML = ''; wrap.hidden = true; }
  }

  function renderErrorMeta(errMeta) {
    if (!errMeta || !errMeta.type) return;
    const wrap = document.getElementById('ai-error-status-wrap');
    if (!wrap) return;
    const iconMap = {
      rate_limit: '⏳', no_auth: '🔑', no_access: '🚫',
      filtered_table: '🔽', view_not_found: '👁', table_not_found: '❌',
      llm_quota: '📊', llm_bad_key: '🔑', llm_unavailable: '🔌', unknown: '⚠️',
    };
    const isWarn = ['filtered_table', 'view_not_found'].includes(errMeta.type);
    const icon = iconMap[errMeta.type] || '⚠️';
    const linkHtml = errMeta.link
      ? `<a class="ai-error-card-link" href="${esc(errMeta.link)}" target="_blank" rel="noopener">Открыть таблицу в Airtable →</a>`
      : '';
    wrap.innerHTML = `<div class="ai-error-card ${isWarn ? 'ai-error-card-warn' : ''}">
      <div class="ai-error-card-icon">${icon}</div>
      <div class="ai-error-card-body">
        <div class="ai-error-card-title">${esc(errMeta.message)}</div>
        <div class="ai-error-card-detail">${esc(errMeta.detail)}</div>
        <div class="ai-error-card-action">💡 ${esc(errMeta.action)}</div>
        ${linkHtml}
      </div>
    </div>`;
    wrap.hidden = false;
  }

  // ── Analyze all dashboards (force no-cache) ───────────────────────────────
  async function analyzeAll() {
    const btn = document.getElementById('btn-analyze-all');
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!btn || !st) return;
    setGeneratingState(true);
    clearErrorStatus();
    st.textContent = '1/2 Сброс кэша и синхронизация всех дашбордов из Airtable…';
    st.className = 'ai-card-hint';
    showOutputToolbar(false);
    applyNumberWarnings([]);
    try {
      const r1 = await fetch('ai_insights_refresh_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ force: true }),
      });
      let j1 = {};
      try { j1 = await r1.json(); } catch (_) {
        st.textContent = 'Ответ синхронизации не JSON (HTTP ' + r1.status + ').';
        st.classList.add('ai-status-err');
        return;
      }
      if (!j1.ok) {
        if (j1.errorMeta) renderErrorMeta(j1.errorMeta);
        st.textContent = j1.error || 'Ошибка синхронизации';
        st.classList.add('ai-status-err');
        return;
      }
      if (j1.charts) {
        mergeBootstrapCharts(j1.charts, j1.chartHints);
        const raw = document.getElementById('ai-bootstrap');
        let next = {};
        if (raw) { try { next = JSON.parse(raw.textContent || '{}'); } catch (_) {} }
        buildCharts(next);
        applyChartHints(next);
      }

      st.textContent = '2/2 Запрос к модели (краткий обзор всех дашбордов)…';
      const r2 = await fetch('ai_insights_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          skipRefresh: true,
          customQuestion: 'Дай краткий обзор всех дашбордов: ДЗ, Churn-риск, фактические потери за год. Выдели 3 ключевых риска и 2 приоритетных действия.',
        }),
      });
      let j = {};
      try { j = await r2.json(); } catch (parseErr) {
        st.textContent = 'Ответ сервера не JSON (HTTP ' + r2.status + ').';
        st.classList.add('ai-status-err');
        return;
      }
      if (!j.ok) {
        if (j.errorMeta) renderErrorMeta(j.errorMeta);
        st.textContent = j.error || 'Ошибка запроса к модели';
        st.classList.add('ai-status-err');
        return;
      }
      clearErrorStatus();
      const prov = j.provider ? j.provider : '';
      st.textContent = 'Готово (все дашборды). ' + prov;
      st.classList.add('ai-status-ok');
      st.classList.remove('ai-status-err');
      if (j.analysis) {
        renderMarkdown(j.analysis);
        lastMarkdownRaw = j.analysis;
        showOutputToolbar(true);
      }
      if (j.historyCount != null) updateHistoryUi(j.historyCount, true);
      if (j.charts) {
        mergeBootstrapCharts(j.charts, j.chartHints);
        const raw = document.getElementById('ai-bootstrap');
        let next = {};
        if (raw) { try { next = JSON.parse(raw.textContent || '{}'); } catch (_) {} }
        buildCharts(next); applyChartHints(next);
      }
      if (j.numberWarnings) applyNumberWarnings(j.numberWarnings);
    } catch (e) {
      st.textContent = 'Сетевая ошибка: ' + String(e);
      st.classList.add('ai-status-err');
    } finally {
      setGeneratingState(false);
    }
  }

  async function generate() {
    const btn = document.getElementById('btn-generate');
    const snap = document.getElementById('btn-snapshot');
    const st = document.getElementById('ai-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!btn || !st) return;
    setGeneratingState(true);
    clearErrorStatus();
    st.textContent = '1/2 Синхронизация с Airtable (API)…';
    st.className = 'ai-card-hint';
    showOutputToolbar(false);
    applyNumberWarnings([]);
    try {
      const r1 = await fetch('ai_insights_refresh_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({}),
      });
      let j1 = {};
      try {
        j1 = await r1.json();
      } catch (_) {
        st.textContent = 'Ответ синхронизации не JSON (HTTP ' + r1.status + ').';
        st.classList.add('ai-status-err');
        return;
      }
      if (!j1.ok) {
        if (j1.errorMeta) renderErrorMeta(j1.errorMeta);
        st.textContent =
          j1.error ||
          (r1.status === 423
            ? 'Другая операция уже выполняется.'
            : r1.status === 429
              ? 'Слишком много запросов. Подождите.'
              : 'Ошибка синхронизации');
        st.classList.add('ai-status-err');
        return;
      }
      clearErrorStatus();
      if (j1.charts) {
        mergeBootstrapCharts(j1.charts, j1.chartHints);
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

      st.textContent = '2/2 Запрос к модели…';
      const r2 = await fetch('ai_insights_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({ skipRefresh: true, customQuestion: getCustomQuestion() }),
      });
      let j = {};
      try {
        j = await r2.json();
      } catch (parseErr) {
        st.textContent = 'Ответ сервера не JSON (HTTP ' + r2.status + '). Проверьте деплой и логи.';
        st.classList.add('ai-status-err');
        return;
      }
      if (!j.ok) {
        if (j.errorMeta) renderErrorMeta(j.errorMeta);
        st.textContent =
          j.error ||
          (r2.status === 423
            ? 'Другая операция уже выполняется.'
            : r2.status === 429
              ? 'Лимит запросов к модели. Подождите.'
              : 'Ошибка');
        st.classList.add('ai-status-err');
        return;
      }
      const prov = j.provider ? j.provider : '';
      const lm = j.llmModel ? ' · ' + j.llmModel : '';
      const tm =
        j.refreshMs != null && j.llmMs != null
          ? ' · sync ' + j.refreshMs + ' ms · LLM ' + j.llmMs + ' ms'
          : '';
      st.textContent =
        'Готово. В истории снимков: ' +
        (j.historyCount ?? '—') +
        '. ' +
        prov +
        lm +
        tm +
        ' Графики соответствуют этому же снимку.';
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
        lastMarkdownRaw = String(j.text || '');
        renderMarkdown(j.text);
        applyNumberWarnings(j.numberWarnings || []);
        persistLastAnalysis(j.text, {
          promptVersion: j.promptVersion,
          llmModel: j.llmModel,
          provider: j.provider,
        });
        showOutputToolbar(true);
        const rw = document.getElementById('ai-restore-wrap');
        if (rw) rw.hidden = true;
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
        applyNumberWarnings([]);
        showOutputToolbar(false);
      }
      if (j.historyChart) {
        buildHistoryChart(j.historyChart);
        mergeBootstrapHistory(j.historyChart, j.historyCount);
      }
    } catch (e) {
      st.textContent = 'Сеть или сервер: ' + esc(String(e && e.message ? e.message : e));
      st.classList.add('ai-status-err');
    } finally {
      setGeneratingState(false);
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
      setGeneratingState(false);
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
    document.getElementById('btn-generate-stream')?.addEventListener('click', generateStream);
    document.getElementById('btn-snapshot')?.addEventListener('click', saveSnapshot);
    document.getElementById('btn-analyze-all')?.addEventListener('click', analyzeAll);

    // Custom question toggle
    document.getElementById('btn-custom-question-toggle')?.addEventListener('click', () => {
      const body = document.getElementById('ai-custom-question-body');
      const btn = document.getElementById('btn-custom-question-toggle');
      if (!body || !btn) return;
      const open = !body.hidden;
      body.hidden = open;
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      btn.textContent = open ? '＋ Добавить свой вопрос к данным' : '− Скрыть вопрос';
    });

    // Comparison UI
    initCompareUi(payload);

    // Авто-анализ запускается если прошло больше порога (включает синхронизацию Airtable внутри).
    // Если авто-анализ — НЕ запускаем отдельный refreshChartsFromApi, чтобы не конкурировать за лок.
    if (payload.autoSnapshotNeeded && payload.hasAiKey) {
      const st = document.getElementById('ai-status');
      if (st) {
        st.textContent = 'Автоматический AI-анализ запущен (раз в ' + (payload.autoSnapshotHours || 24) + 'ч)…';
        st.className = 'ai-card-hint';
      }
      setTimeout(() => generateStream(), 1500);
    } else if (payload.chartsNeedAsyncRefresh) {
      // Только обновление графиков без AI
      refreshChartsFromApi(payload);
    }

    document.getElementById('btn-ai-expand')?.addEventListener('click', () => {
      const wrap = document.getElementById('ai-output-wrap');
      const btn = document.getElementById('btn-ai-expand');
      if (!wrap || !btn || btn.hidden) return;
      const ex = wrap.classList.toggle('ai-output-expanded');
      btn.textContent = ex ? 'Свернуть' : 'Развернуть полностью';
    });

    document.getElementById('btn-ai-copy')?.addEventListener('click', async () => {
      const raw = lastMarkdownRaw || '';
      if (!raw.trim()) return;
      try {
        await navigator.clipboard.writeText(raw);
        const stEl = document.getElementById('ai-status');
        if (stEl) {
          stEl.textContent = 'Markdown скопирован в буфер.';
          stEl.classList.add('ai-status-ok');
        }
      } catch (_) {}
    });

    document.getElementById('btn-ai-dl')?.addEventListener('click', () => {
      const raw = lastMarkdownRaw || '';
      if (!raw.trim()) return;
      const blob = new Blob([raw], { type: 'text/markdown;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'ai-insights-' + new Date().toISOString().slice(0, 10) + '.md';
      a.click();
      URL.revokeObjectURL(a.href);
    });

    // Авто-показ последнего сохранённого анализа из серверной истории
    const serverLast = payload.lastAnalysis;
    if (serverLast && serverLast.text && !payload.autoSnapshotNeeded) {
      lastMarkdownRaw = String(serverLast.text);
      renderMarkdown(serverLast.text);
      showOutputToolbar(true);
      const stEl = document.getElementById('ai-status');
      if (stEl) {
        const dt = serverLast.t ? new Date(serverLast.t * 1000).toLocaleString('ru-RU') : '';
        stEl.textContent = 'Последний анализ' + (dt ? ' от ' + dt : '') + ' (из истории сервера).';
        stEl.className = 'ai-card-hint';
      }
    } else {
      // Фолбэк: localStorage
      const last = loadLastAnalysis();
      const restoreWrap = document.getElementById('ai-restore-wrap');
      if (last && last.text && restoreWrap && !payload.autoSnapshotNeeded) {
        restoreWrap.hidden = false;
        document.getElementById('btn-ai-restore')?.addEventListener('click', () => {
          lastMarkdownRaw = String(last.text || '');
          renderMarkdown(last.text);
          showOutputToolbar(true);
          restoreWrap.hidden = true;
          const stEl = document.getElementById('ai-status');
          if (stEl) {
            stEl.textContent = 'Показан сохранённый локально анализ (не новый запрос к серверу).';
            stEl.className = 'ai-card-hint';
          }
        });
      }
    }
  }

  function fmtRub(v) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    if (isNaN(n)) return '—';
    if (Math.abs(n) >= 1_000_000) return (Math.round(n / 100_000) / 10).toFixed(1) + 'M';
    if (Math.abs(n) >= 1_000) return Math.round(n / 1000) + 'K';
    return String(Math.round(n));
  }

  function fmtDelta(diff, pct) {
    if (diff == null) return '—';
    const sign = diff > 0 ? '+' : '';
    const pctStr = pct != null ? ' (' + (pct > 0 ? '+' : '') + pct + '%)' : '';
    return sign + fmtRub(diff) + pctStr;
  }

  function buildCompareSelect(selectEl, items) {
    selectEl.innerHTML = '';
    items.forEach((item, i) => {
      const opt = document.createElement('option');
      opt.value = String(i);
      const t = item.t ? item.t.replace('T', ' ').replace('Z', ' UTC').slice(0, 19) : '—';
      const dz = item.m?.dzTotal != null ? ' ДЗ ' + fmtRub(item.m.dzTotal) : '';
      const mark = item.hasAnalysis ? ' ✦' : '';
      opt.textContent = t + dz + mark;
      selectEl.appendChild(opt);
    });
  }

  function initCompareUi(payload) {
    const items = payload.historyMeta || [];
    if (items.length < 2) return;
    const section = document.getElementById('ai-compare-section');
    if (section) section.hidden = false;
    const selA = document.getElementById('ai-compare-a');
    const selB = document.getElementById('ai-compare-b');
    if (!selA || !selB) return;
    buildCompareSelect(selA, items);
    buildCompareSelect(selB, items);
    // По умолчанию A=0 (новейший), B=1
    selA.value = '0';
    selB.value = items.length > 1 ? '1' : '0';

    document.getElementById('btn-compare')?.addEventListener('click', async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const btn = document.getElementById('btn-compare');
      const result = document.getElementById('ai-compare-result');
      if (!result) return;
      if (btn) btn.disabled = true;
      result.hidden = false;
      result.innerHTML = '<p class="ai-card-hint">Загрузка…</p>';
      try {
        const r = await fetch('ai_insights_compare_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ idx1: Number(selA.value), idx2: Number(selB.value) }),
        });
        const j = await r.json();
        if (!j.ok) {
          result.innerHTML = '<p style="color:var(--danger)">' + esc(j.error || 'Ошибка') + '</p>';
          return;
        }
        renderCompareResult(result, j);
      } catch (e) {
        result.innerHTML = '<p style="color:var(--danger)">' + esc(String(e?.message || e)) + '</p>';
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  function renderCompareResult(container, data) {
    const tA = (data.a?.t || '').replace('T', ' ').replace('Z', ' UTC').slice(0, 19);
    const tB = (data.b?.t || '').replace('T', ' ').replace('Z', ' UTC').slice(0, 19);
    const rows = (data.delta || [])
      .filter((d) => d.a != null || d.b != null)
      .map((d) => {
        const deltaClass =
          d.diff == null ? '' : d.diff > 0 ? ' ai-delta-up' : d.diff < 0 ? ' ai-delta-down' : '';
        return (
          '<tr>' +
          '<td class="ai-cmp-label">' + esc(d.label) + '</td>' +
          '<td class="ai-cmp-val">' + fmtRub(d.a) + '</td>' +
          '<td class="ai-cmp-val">' + fmtRub(d.b) + '</td>' +
          '<td class="ai-cmp-delta' + deltaClass + '">' + fmtDelta(d.diff, d.pct) + '</td>' +
          '</tr>'
        );
      })
      .join('');
    container.innerHTML =
      '<table class="ai-compare-table">' +
      '<thead><tr><th>Метрика</th><th>A: ' + esc(tA) + '</th><th>B: ' + esc(tB) + '</th><th>Δ A − B</th></tr></thead>' +
      '<tbody>' + rows + '</tbody>' +
      '</table>' +
      (data.a?.hasAnalysis
        ? '<details class="ai-cmp-analysis"><summary>Анализ снимка A</summary><div class="ai-markdown">' +
          (typeof DOMPurify !== 'undefined' && typeof marked !== 'undefined'
            ? DOMPurify.sanitize(marked.parse(data.a.analysis || '', { breaks: true }))
            : esc(data.a.analysis || '')) +
          '</div></details>'
        : '') +
      (data.b?.hasAnalysis
        ? '<details class="ai-cmp-analysis"><summary>Анализ снимка B</summary><div class="ai-markdown">' +
          (typeof DOMPurify !== 'undefined' && typeof marked !== 'undefined'
            ? DOMPurify.sanitize(marked.parse(data.b.analysis || '', { breaks: true }))
            : esc(data.b.analysis || '')) +
          '</div></details>'
        : '');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

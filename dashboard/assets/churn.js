/* =========================================================
   churn.js v3 — Дашборд «Угроза Churn»
   Источник: Airtable view «Угроза Churn» (viwBPiUGNh0PMLeV1)
   ========================================================= */
(function () {
  'use strict';

  const AUTO_MS    = 5 * 60 * 1000;
  const PROB_LABEL = { 1: 'Низкий', 2: 'Средний', 3: 'Высокий' };
  const PROB_CSS   = { 1: 'p1', 2: 'p2', 3: 'p3' };
  const PROB_COLOR = { 1: '#FFD60A', 2: '#FF9F0A', 3: '#FF453A' };
  const SEG_ORDER  = ['ENT','SME+','SME','SME-','SMB','SS'];
  const PROD_COLOR = {
    AnyQuery:'#7B61FF', AnyCollections:'#FF9F0A', AnyRecs:'#34C759',
    AnyImages:'#30D5C8', AnyReviews:'#5AC8FA', Rees46:'#BF5AF2', APP:'#FF6B6B',
  };

  // ── Состояние ─────────────────────────────────────────────
  const state = {
    data:    null,
    loading: false,
    sort:    { col: 'mrr', dir: 'desc' },
    filters: { segment: '', prob: '', product: '', csm: '' },
    expandedRows: new Set(),
    pivotTab: 'segment',  // 'segment' | 'csm' | 'vertical' | 'product'
  };

  // ── In-Progress (localStorage) ────────────────────────────
  const IP_KEY = 'churn_inprogress_v1';
  const loadInProgress = () => { try { return new Set(JSON.parse(localStorage.getItem(IP_KEY) || '[]')); } catch { return new Set(); } };
  const saveInProgress = set => { try { localStorage.setItem(IP_KEY, JSON.stringify([...set])); } catch {} };
  let inProgress = loadInProgress();

  // ── KPI prev snapshot (E1 badge) ─────────────────────────
  const CHURN_SNAP_KEY = 'churn_kpi_snap_v1';
  const loadChurnSnap = () => { try { return JSON.parse(localStorage.getItem(CHURN_SNAP_KEY) || 'null'); } catch { return null; } };
  const saveChurnSnap = d => { try { localStorage.setItem(CHURN_SNAP_KEY, JSON.stringify({ count: d.count, risk: d.totalRisk, prob3: d.prob3count, updatedAt: d.updatedAt })); } catch {} };

  // ── Тренд (localStorage) ──────────────────────────────────
  const TREND_KEY = 'churn_trend_v1';
  function loadTrend()   { try { return JSON.parse(localStorage.getItem(TREND_KEY) || 'null'); } catch { return null; } }
  function saveTrend(d)  { try { localStorage.setItem(TREND_KEY, JSON.stringify({ count: d.count || 0, risk: d.totalRisk || 0, prob3: d.prob3count || 0, date: new Date().toISOString().slice(0,10) })); } catch {} }

  // ── Копирование сводки (C5) ───────────────────────────────
  function buildChurnSummary() {
    const d = state.data;
    if (!d) return '';
    const lines = [];
    lines.push(`Угроза Churn — ${d.updatedAt}`);
    lines.push(`Клиентов в риске: ${d.count} | MRR под угрозой: ${fmtR(d.totalRisk || 0)}`);
    lines.push(`Высокий риск (Prob 3): ${d.prob3count} клиентов, ${fmtR(d.prob3mrr || 0)}`);
    lines.push('');
    const p3 = (d.clients || []).filter(c => c.probability === 3).slice(0, 5);
    if (p3.length) {
      lines.push('Клиенты Prob=3:');
      p3.forEach(c => lines.push(`  ${c.account} [${c.segment}] — ${fmtR(c.mrrAtRisk)}`));
    }
    return lines.join('\n');
  }
  async function doCopy() {
    const btn = document.getElementById('btn-copy');
    try {
      await navigator.clipboard.writeText(buildChurnSummary());
      if (btn) { btn.textContent = '✓ Скопировано'; setTimeout(() => btn.textContent = '📋 Сводка', 2000); }
    } catch {
      if (btn) { btn.textContent = '✗ Ошибка'; setTimeout(() => btn.textContent = '📋 Сводка', 2000); }
    }
  }

  // ── CSV-экспорт клиентской таблицы (M1) ──────────────────
  function exportCsv() {
    if (!state.data?.clients) return;
    const FACTOR = { 3: 1.0, 2: 0.6, 1: 0.3 };
    const clients = state.data.clients;
    const f = state.filters;
    const rows = clients.filter(c =>
      (!f.segment || c.segment === f.segment) &&
      (!f.prob    || String(c.probability) === f.prob) &&
      (!f.product || (c.products || []).includes(f.product)) &&
      (!f.csm     || c.csm === f.csm)
    );
    const headers = ['#','Клиент','Сегмент','CSM','Вероятность','MRR в риске','Прогноз потери','Общий MRR','Продукты','Вертикаль'];
    const data = rows.map((c, i) => [
      i + 1,
      c.account,
      c.segment || '',
      c.csm || '',
      c.probability != null ? `Prob ${c.probability}` : '',
      Math.round(c.mrrAtRisk || 0),
      Math.round((c.mrrAtRisk || 0) * (FACTOR[c.probability] ?? 0.3)),
      Math.round(c.totalMrr || 0),
      (c.products || []).join('; '),
      c.vertical || '',
    ]);
    const date = new Date().toISOString().slice(0,10);
    (window.AqUtils || { downloadCsv: () => {} }).downloadCsv(`churn-clients-${date}.csv`, headers, data);
  }

  // ── Утилиты ───────────────────────────────────────────────
  const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const fmtR = v => Math.round(v).toLocaleString('ru-RU') + '\u00a0₽';
  const fmtK = v => v >= 1_000_000 ? (v/1_000_000).toFixed(1)+'М' : v >= 1_000 ? (v/1_000).toFixed(0)+'К' : Math.round(v).toString();
  const pct  = (a, b) => b > 0 ? (a / b * 100).toFixed(0) + '%' : '—';

  function probBadge(p) {
    if (p == null) return `<span class="pb pb-null">—</span>`;
    return `<span class="pb pb-${PROB_CSS[p]}" title="Вероятность ухода: ${PROB_LABEL[p]}">
      <span class="pb-dot"></span>${PROB_LABEL[p]}
    </span>`;
  }

  function segBadge(s) {
    if (!s) return '—';
    const key = s.replace(/\s+/g, '');
    const cls = key.replace('+', '-plus').replace(/-(?!plus)/, '-minus');
    return `<span class="sb sb-${cls}">${esc(s)}</span>`;
  }

  function prodTag(p) {
    const col = PROD_COLOR[p] || '#888';
    return `<span class="pt" style="--pc:${col}">${esc(p)}</span>`;
  }

  function prodTags(arr) {
    return (arr||[]).map(prodTag).join('');
  }

  // Продукты: max 2 видимых + "+N" с CSS-попапом (M3)
  function prodTagsTruncated(arr) {
    if (!arr || !arr.length) return '—';
    if (arr.length <= 2) return prodTags(arr);
    const visible = arr.slice(0, 2);
    const rest    = arr.slice(2);
    // Используем CSS ::after попап вместо title, чтобы работало на тачскрине
    const tipHtml = rest.map(p => `<span class="pt-popup-item">${esc(p)}</span>`).join('');
    return prodTags(visible) + `<span class="pt-more" tabindex="0" aria-label="Ещё продуктов: ${rest.join(', ')}">+${rest.length}<span class="pt-popup">${tipHtml}</span></span>`;
  }

  // Tooltip wrapper
  function tt(label, body) {
    return `<span class="tt-wrap" tabindex="0">
      ${label}<span class="tt-box">${body}</span>
    </span>`;
  }

  // ── Progress bar ──────────────────────────────────────────
  let _progressTimer = null;
  function startProgress() {
    let bar = document.getElementById('aq-pbar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'aq-pbar';
      bar.innerHTML = '<div id="aq-pbar-fill"></div>';
      document.body.prepend(bar);
    }
    bar.style.display = '';
    bar.style.opacity = '1';
    let p = 0;
    const fill = document.getElementById('aq-pbar-fill');
    if (fill) { fill.style.transition = 'none'; fill.style.width = '0%'; }
    clearInterval(_progressTimer);
    _progressTimer = setInterval(() => {
      p = p < 30 ? p + 3 : p < 65 ? p + 1.5 : p < 90 ? p + 0.4 : p;
      if (fill) fill.style.width = Math.min(p, 90) + '%';
    }, 100);
  }
  function endProgress() {
    clearInterval(_progressTimer);
    const fill = document.getElementById('aq-pbar-fill');
    if (fill) { fill.style.transition = 'width .2s ease'; fill.style.width = '100%'; }
    setTimeout(() => {
      const bar = document.getElementById('aq-pbar');
      if (bar) { bar.style.opacity = '0'; setTimeout(() => { if (bar) bar.style.display = 'none'; bar.style.opacity = '1'; }, 350); }
    }, 250);
  }

  // ── Главный render ────────────────────────────────────────
  function render() {
    const app = document.getElementById('app');
    if (!app) return;

    if (state.loading && !state.data) {
      app.innerHTML = `
        <div class="ch-loading">
          <div class="ch-spinner-dots"><span></span><span></span><span></span></div>
          <div class="ch-loading-title">Загружаем данные из Airtable…</div>
          <div class="ch-loading-sub">Обычно 5–15 секунд при первом запуске</div>
        </div>`;
      return;
    }
    if (!state.data) return;

    const d = state.data;

    app.innerHTML = `
      ${buildTopbar(d)}
      <div class="ch-wrap">
        ${buildUrgentBanner(d)}
        ${buildSituationBoard(d)}
        ${buildActionItems(d)}
        ${buildClientTable(d)}
        ${buildAnalyticsGrid(d)}
        ${buildPivotTables(d)}
        ${buildCsmMatrix(d)}
      </div>`;

    saveTrend(d);
    saveChurnSnap(d);
    attachEvents();
    // Динамически выравниваем sticky-шапку таблицы под реальную высоту topbar+filter-bar
    requestAnimationFrame(updateStickyOffsets);
  }

  // ── Динамический offset для sticky thead (НЕ в #ch-clients) ───
  // #ch-clients использует bounded scroll-контейнер, поэтому thead
  // залипает на top:0 внутри него — динамический пересчёт не нужен.
  function updateStickyOffsets() {
    const topbar    = document.querySelector('.ch-topbar');
    if (!topbar) return;
    const topbarH = Math.ceil(topbar.getBoundingClientRect().height);
    // Обновляем filter-bar sticky top = высота topbar
    const filterBar = document.querySelector('#ch-clients .ch-filter-bar');
    if (filterBar) filterBar.style.top = topbarH + 'px';
  }

  // ── Topbar с вкладками (E1) ──────────────────────────────
  function buildTopbar(d) {
    const isDark = (document.getElementById('html-root')?.getAttribute('data-theme') || 'dark') === 'dark';
    const snap = loadChurnSnap();
    const freshBadge = d.updatedAt
      ? `<span class="ch-fresh-badge" title="Данные актуальны на ${esc(d.updatedAt)}">🕐 ${esc(d.updatedAt.slice(11,16))}</span>`
      : '';
    return `
      <nav class="ch-topbar">
        <div class="ch-topbar-left">
          <div class="ch-logo"><span class="ch-logo-box">AQ</span><span class="ch-logo-text">anyquery</span></div>
          <div class="ch-nav-tabs">
            <a class="ch-nav-tab" href="index.php">🏠 Главная</a>
            <span class="ch-nav-tab ch-nav-tab-active">⚠ Угроза Churn</span>
            <a class="ch-nav-tab" href="churn_fact.php">📉 Потери</a>
            <a class="ch-nav-tab" href="manager.php">💰 ДЗ</a>
          </div>
        </div>
        <div class="ch-topbar-right">
          ${freshBadge}
          <button class="ch-btn" id="btn-csv" title="Экспорт таблицы клиентов в CSV (Excel)" aria-label="Скачать таблицу клиентов в формате CSV">⬇ CSV</button>
          <button class="ch-btn" id="btn-copy" title="Скопировать сводку в буфер" aria-label="Скопировать сводку отчёта в буфер обмена">📋 Сводка</button>
          <button class="ch-btn${state.loading?' ch-btn-spin':''}" id="btn-refresh" title="Принудительно обновить данные из Airtable" aria-label="Обновить данные из Airtable">
            <span class="spin-icon" aria-hidden="true">⟳</span> Обновить
          </button>
          <button class="ch-btn ch-btn-icon" id="btn-theme" title="${isDark?'Светлая тема':'Тёмная тема'}" aria-label="${isDark?'Переключить на светлую тему':'Переключить на тёмную тему'}">${isDark?'☀':'🌙'}</button>
        </div>
      </nav>`;
  }

  // ── Urgent banner: все Prob=3 (E2) ───────────────────────
  function buildUrgentBanner(d) {
    const clients = d.clients || [];
    const crit    = clients.filter(c => c.probability === 3);
    if (!crit.length) return '';
    const totalMrr = crit.reduce((s, c) => s + c.mrrAtRisk, 0);
    const totalMrr2 = d.totalRisk || 0;
    const pctOfTotal = totalMrr2 > 0 ? (totalMrr / totalMrr2 * 100).toFixed(0) : '—';
    const visible  = crit.slice(0, 3);
    const moreN    = crit.length > 3 ? crit.length - 3 : 0;

    const items = visible.map(c => `
      <span class="ch-urgent-item">
        <strong>${esc(c.account)}</strong>
        <span class="ch-urgent-seg">${segBadge(c.segment)}</span>
        <span class="ch-urgent-mrr">${fmtR(c.mrrAtRisk)}</span>
      </span>`).join('');
    const moreHtml = moreN > 0 ? `<span class="ch-urgent-more">+ещё ${moreN}</span>` : '';

    return `
      <div class="ch-urgent-banner">
        <span class="ch-urgent-icon">🚨</span>
        <div class="ch-urgent-body">
          <div class="ch-urgent-head">
            <strong>Срочно: ${crit.length} клиентов Prob=3</strong>
            — под угрозой <strong>${fmtR(totalMrr)}</strong> (${pctOfTotal}% от общего риска)
          </div>
          <div class="ch-urgent-clients">${items}${moreHtml}</div>
        </div>
      </div>`;
  }

  // ── Situation Board — главный "светофор" ──────────────────
  function buildSituationBoard(d) {
    const totalRisk  = d.totalRisk  || 0;
    const prob3mrr   = d.prob3mrr   || 0;
    const prob3count = d.prob3count || 0;
    const count      = d.count      || 0;
    const entCount   = d.entCount   || 0;
    const entProb3   = d.entProb3   || 0;
    const forecast3  = d.forecast3  || 0;
    const forecast6  = d.forecast6  || 0;

    // Уровень тревоги: красный если prob3 > 50% общего риска или ENT prob3 > 5
    const critPct = totalRisk > 0 ? prob3mrr / totalRisk : 0;
    const level   = critPct > 0.5 || entProb3 >= 5 ? 'red' : critPct > 0.3 || entProb3 >= 2 ? 'yellow' : 'green';
    const levelLabel = { red: '🔴 Критично', yellow: '🟡 Внимание', green: '🟢 Под контролем' }[level];
    const levelHint  = { red: 'Более 50% риска — высокая вероятность. Срочно требуется эскалация.', yellow: 'Умеренный риск. Держите под наблюдением.', green: 'Ситуация управляемая.' }[level];

    // Тренд с прошлой сессии (localStorage)
    const prev = loadTrend();
    let trendHtml = '';
    if (prev && prev.date) {
      const deltaCount = count - (prev.count || 0);
      const deltaRisk  = totalRisk - (prev.risk || 0);
      const sign  = v => v > 0 ? '+' : '';
      const dcls  = deltaCount > 0 ? 'trend-up' : deltaCount < 0 ? 'trend-down' : 'trend-same';
      const dRcls = deltaRisk  > 0 ? 'trend-up' : deltaRisk  < 0 ? 'trend-down' : 'trend-same';
      trendHtml = `
        <div class="ch-trend-row" title="Сравнение с последним просмотром ${esc(prev.date)}">
          <span class="ch-trend-lbl">нед/нед vs ${esc(prev.date)}:</span>
          <span class="ch-trend-badge ${dcls}">${sign(deltaCount)}${deltaCount} кл.</span>
          <span class="ch-trend-badge ${dRcls}">${sign(deltaRisk)}${fmtK(deltaRisk)} MRR</span>
        </div>`;
    }

    const kpis = [
      {
        val: fmtR(totalRisk),
        lbl: 'Всего MRR под угрозой',
        sub: `${count} клиентов`,
        cls: 'kpi-warn',
        tip: 'Сумма MRR всех клиентов в вью «Угроза Churn». Источник: Airtable, поля AQ/AC/Recs MRR по тем продуктам, где Customer Stage = Угроза Churn. Если поле стадии не заполнено — берётся весь MRR клиента.',
      },
      {
        val: fmtR(prob3mrr),
        lbl: 'MRR — Вероятность 3',
        sub: `${prob3count} клиентов · ${pct(prob3mrr, totalRisk)} от риска`,
        cls: 'kpi-danger',
        tip: 'Сумма MRR только клиентов с полем «Вероятность угрозы» = 3 (Высокий). Самый критичный сегмент — работаем в первую очередь.',
      },
      {
        val: String(entCount),
        lbl: 'Enterprise в риске',
        sub: `Prob=3: ${entProb3} ENT клиентов`,
        cls: entProb3 >= 3 ? 'kpi-danger' : 'kpi-warn',
        tip: 'ENT = клиент с суммарным MRR ≥ 100 000 ₽ или сегмент ENT в Airtable. Prob=3 ENT — самый приоритетный для retention-action.',
      },
      {
        val: fmtR(forecast3),
        lbl: 'Прогноз потерь 3 мес.',
        sub: 'Вероятность 2 + 3',
        cls: 'kpi-neutral',
        tip: 'prob3mrr + prob2mrr — сумма MRR клиентов с вероятностью 2 и 3. Оценка потерь выручки в горизонте 3 месяцев при текущей динамике.',
      },
      {
        val: fmtR(forecast6),
        lbl: 'Прогноз потерь 6 мес.',
        sub: 'Все уровни риска (1+2+3)',
        cls: 'kpi-neutral',
        tip: 'Полный totalRisk — весь MRR под угрозой на горизонте 6 месяцев (все клиенты вью независимо от вероятности).',
      },
    ];

    const cards = kpis.map(k => `
      <div class="kpi-card ${k.cls}">
        <div class="kpi-lbl">${tt(k.lbl, k.tip)}</div>
        <div class="kpi-val">${k.val}</div>
        <div class="kpi-sub">${k.sub}</div>
      </div>`).join('');

    // Прогресс-бар prob3 внутри totalRisk (E2: + итог и % от MRR)
    const prob3w = (critPct * 100).toFixed(1);
    const prob2mrr = (forecast3 - prob3mrr);
    const prob2w = totalRisk > 0 ? (prob2mrr / totalRisk * 100).toFixed(1) : 0;

    // % от общего MRR (если есть totalMrr в данных)
    const allMrr = (d.clients || []).reduce((s, c) => s + (c.totalMrr || 0), 0);
    const riskOfMrrPct = allMrr > 0 ? (totalRisk / allMrr * 100).toFixed(1) : null;

    return `
      <div class="ch-situation">
        <div class="ch-sit-left">
          <div class="ch-health ch-health-${level}" title="${levelHint}">
            <span class="ch-health-icon">${levelLabel}</span>
          </div>
          ${trendHtml}
          <div class="ch-risk-bar-wrap">
            <div class="ch-risk-bar-label">
              ${tt('Структура риска по вероятности', 'Визуальное распределение MRR под угрозой: красный = вероятность 3 (наибольший приоритет), оранжевый = вероятность 2, жёлтый = вероятность 1. Общий = ' + fmtR(totalRisk))}
            </div>
            <div class="ch-risk-bar">
              <div class="ch-risk-seg ch-risk-p3" style="width:${prob3w}%" title="Prob 3: ${fmtR(prob3mrr)} · ${prob3w}%"></div>
              <div class="ch-risk-seg ch-risk-p2" style="width:${prob2w}%" title="Prob 2: ${fmtR(prob2mrr)} · ${prob2w}%"></div>
              <div class="ch-risk-seg ch-risk-p1" style="width:${Math.max(0,100-+prob3w-+prob2w).toFixed(1)}%" title="Prob 1"></div>
            </div>
            <div class="ch-risk-bar-legend">
              <span style="color:${PROB_COLOR[3]}">■ Высокий ${fmtK(prob3mrr)}</span>
              <span style="color:${PROB_COLOR[2]}">■ Средний ${fmtK(prob2mrr)}</span>
              <span style="color:${PROB_COLOR[1]}">■ Низкий ${fmtK(Math.max(0,totalRisk-forecast3))}</span>
            </div>
            <div class="ch-risk-total">
              Всего X₽ в риске: <strong>${fmtR(totalRisk)}</strong>
              ${riskOfMrrPct !== null ? `<span class="ch-risk-of-mrr">${riskOfMrrPct}% от портфеля MRR</span>` : ''}
            </div>
          </div>
        </div>
        <div class="ch-kpi-row">${cards}</div>
      </div>`;
  }

  // ── Action Items — "Что делать сегодня" ───────────────────
  function buildActionItems(d) {
    const clients = d.clients || [];
    // ENT + prob 3
    const entCrit  = clients.filter(c => c.segment === 'ENT' && c.probability === 3);
    // Prob 3 не-ENT
    const smbCrit  = clients.filter(c => c.segment !== 'ENT' && c.probability === 3);
    // Prob 2 ENT
    const entMed   = clients.filter(c => c.segment === 'ENT' && c.probability === 2);

    if (!entCrit.length && !smbCrit.length && !entMed.length) return '';

    function actionRow(c, urgency) {
      const riskPct = c.totalMrr > 0 ? (c.mrrAtRisk / c.totalMrr * 100).toFixed(0) : 100;
      return `
        <div class="action-row action-${urgency}" title="MRR в риске: ${fmtR(c.mrrAtRisk)} из ${fmtR(c.totalMrr)} общего MRR">
          <div class="action-account">${esc(c.account)}</div>
          <div class="action-meta">
            ${segBadge(c.segment)}
            ${probBadge(c.probability)}
            <span class="action-csm">${esc(c.csm)}</span>
          </div>
          <div class="action-mrr">${fmtR(c.mrrAtRisk)}</div>
          <div class="action-bar-wrap" title="${riskPct}% MRR под угрозой">
            <div class="action-bar" style="width:${Math.min(riskPct,100)}%"></div>
          </div>
          <div class="action-prods">${prodTags(c.products)}</div>
        </div>`;
    }

    return `
      <div class="ch-action-section">
        <div class="ch-action-head">
          <h2 class="ch-sec-title">
            ${tt('⚡ Фокус — требуют действий', 'Клиенты, требующие приоритетного внимания на этой неделе. Сортировка: сначала ENT prob=3 (максимальный риск потери выручки), затем остальные prob=3, затем ENT prob=2. Источник: поле «Вероятность угрозы» и сегмент в Airtable.')}
          </h2>
        </div>
        <div class="ch-action-grid">
          ${entCrit.length ? `
            <div class="ch-action-col">
              <div class="ch-action-col-head ch-col-red">🔴 ENT · Prob 3
                <span class="ch-badge-count">${entCrit.length}</span>
                <span class="ch-col-mrr">${fmtR(entCrit.reduce((s,c)=>s+c.mrrAtRisk,0))}</span>
              </div>
              ${entCrit.map(c => actionRow(c, 'critical')).join('')}
            </div>` : ''}
          ${smbCrit.length ? `
            <div class="ch-action-col">
              <div class="ch-action-col-head ch-col-orange">🟠 SMB/SME · Prob 3
                <span class="ch-badge-count">${smbCrit.length}</span>
                <span class="ch-col-mrr">${fmtR(smbCrit.reduce((s,c)=>s+c.mrrAtRisk,0))}</span>
              </div>
              ${smbCrit.slice(0,8).map(c => actionRow(c, 'high')).join('')}
              ${smbCrit.length > 8 ? `<div class="ch-more">+${smbCrit.length-8} ещё</div>` : ''}
            </div>` : ''}
          ${entMed.length ? `
            <div class="ch-action-col">
              <div class="ch-action-col-head ch-col-yellow">🟡 ENT · Prob 2
                <span class="ch-badge-count">${entMed.length}</span>
                <span class="ch-col-mrr">${fmtR(entMed.reduce((s,c)=>s+c.mrrAtRisk,0))}</span>
              </div>
              ${entMed.map(c => actionRow(c, 'medium')).join('')}
            </div>` : ''}
        </div>
      </div>`;
  }

  // ── Главная таблица клиентов ──────────────────────────────
  function buildClientTable(d) {
    const all   = d.clients || [];
    const csms  = [...new Set(all.map(c => c.csm))].filter(Boolean).sort();
    const prods = [...new Set(all.flatMap(c => c.products||[]))].filter(Boolean).sort();
    const f     = state.filters;

    let rows = all.filter(c => {
      if (f.segment && c.segment !== f.segment) return false;
      if (f.prob    && String(c.probability) !== f.prob) return false;
      if (f.product && !(c.products||[]).includes(f.product)) return false;
      if (f.csm     && c.csm !== f.csm) return false;
      return true;
    });

    // Sorting
    const s = state.sort;
    rows = [...rows].sort((a, b) => {
      let va, vb;
      if (s.col === 'mrr')   { va = a.mrrAtRisk; vb = b.mrrAtRisk; }
      else if (s.col === 'total') { va = a.totalMrr; vb = b.totalMrr; }
      else if (s.col === 'prob')  { va = a.probability??0; vb = b.probability??0; }
      else if (s.col === 'acct')  { va = a.account.toLowerCase(); vb = b.account.toLowerCase(); }
      else if (s.col === 'seg')   { const ia = SEG_ORDER.indexOf(a.segment); const ib = SEG_ORDER.indexOf(b.segment); va = ia < 0 ? 99 : ia; vb = ib < 0 ? 99 : ib; }
      else { va = a.mrrAtRisk; vb = b.mrrAtRisk; }
      if (va < vb) return s.dir === 'asc' ? -1 : 1;
      if (va > vb) return s.dir === 'asc' ? 1 : -1;
      return 0;
    });

    const totalFiltered = rows.reduce((sum, c) => sum + c.mrrAtRisk, 0);
    const maxMrr        = Math.max(...rows.map(c => c.mrrAtRisk), 1);
    const activeFiltersCount = [f.segment, f.prob, f.product, f.csm].filter(Boolean).length;
    const hasFilters    = activeFiltersCount > 0;

    function thSort(col, label, tip, stickyAcct = false) {
      const active = s.col === col;
      const arrow  = active ? (s.dir === 'desc' ? ' ↓' : ' ↑') : '';
      const extra  = stickyAcct ? ' th-sticky-acct' : '';
      return `<th class="sortable${active?' sort-active':''}${extra}" data-sort="${col}" title="${tip}">${label}${arrow}</th>`;
    }

    // Коэффициент прогноза: prob3=100%, prob2=60%, prob1=30%
    const FORECAST_FACTOR = { 3: 1.0, 2: 0.6, 1: 0.3 };
    // Цветной фон ячейки прогноза по вероятности (E3)
    const FORECAST_BG = { 3: 'rgba(255,69,58,0.12)', 2: 'rgba(255,159,10,0.10)', 1: 'rgba(255,214,10,0.08)' };

    const tableRows = rows.map((c, i) => {
      const riskW        = (c.mrrAtRisk / maxMrr * 100).toFixed(1);
      const riskPct      = c.totalMrr > 0 ? (c.mrrAtRisk / c.totalMrr * 100).toFixed(0) : 100;
      const shareOf      = totalFiltered > 0 ? (c.mrrAtRisk / totalFiltered * 100).toFixed(1) : '0';
      const rowCls       = c.probability === 3 ? 'tr-p3' : c.probability === 2 ? 'tr-p2' : '';
      const factor       = FORECAST_FACTOR[c.probability] ?? 0.3;
      const forecastLoss = c.mrrAtRisk * factor;
      const forecastBg   = FORECAST_BG[c.probability] || '';
      const isIP         = inProgress.has(c.account);
      return `
        <tr class="${rowCls}${isIP?' tr-inprogress':''}">
          <td class="td-idx">${i+1}</td>
          <td class="td-acct td-sticky-left">
            <div class="acct-cell">
              <span class="acct-name">${esc(c.account)}</span>
              <label class="ip-label" title="В работе">
                <input type="checkbox" class="ip-check" data-account="${esc(c.account)}"${isIP?' checked':''}>
                <span class="ip-tag">In progress</span>
              </label>
            </div>
          </td>
          <td class="td-seg">${segBadge(c.segment)}</td>
          <td class="td-csm">${esc(c.csm)}</td>
          <td class="td-prob">${probBadge(c.probability)}</td>
          <td class="td-mrr">
            <div class="mrr-cell">
              <span class="mrr-val">${fmtR(c.mrrAtRisk)}</span>
              <div class="mrr-bar-row">
                <div class="mrr-bar" style="width:${riskW}%;background:${PROB_COLOR[c.probability]||'var(--accent)'}"></div>
                <span class="mrr-share">${shareOf}%</span>
              </div>
            </div>
          </td>
          <td class="td-forecast" title="MRR × коэффициент вероятности (P3=100%, P2=60%, P1=30%)"
            style="background:${forecastBg};color:${PROB_COLOR[c.probability]||'var(--churn-muted)'}">
            ${fmtR(forecastLoss)}
            <span style="font-size:0.7rem;color:var(--churn-muted)"> ×${(factor*100).toFixed(0)}%</span>
          </td>
          <td class="td-total-mrr" title="Полный MRR клиента по всем продуктам. Риск = ${riskPct}% от общего MRR">
            ${fmtR(c.totalMrr)}
            <span class="risk-pct-badge ${+riskPct>=100?'rpb-full':+riskPct>=50?'rpb-high':''}">${riskPct}%</span>
          </td>
          <td class="td-prods">${prodTagsTruncated(c.products)}</td>
          <td class="td-vert muted">${esc(c.vertical)}</td>
        </tr>`;
    }).join('');

    return `
      <div class="ch-section" id="ch-clients">
        <div class="ch-sec-head">
          <div>
            <h2 class="ch-sec-title">
              ${tt('🎯 Все клиенты под угрозой', 'Полный список клиентов из Airtable-вью «Угроза Churn» (viwBPiUGNh0PMLeV1). MRR в риске = сумма MRR по продуктам, где Customer Stage = «Угроза Churn». Если стадия не выставлена явно — учитывается весь MRR клиента. Источник вероятности: поле «Вероятность угрозы» в Airtable.')}
            </h2>
            <div class="ch-sec-sub">
              ${hasFilters
                ? `Отфильтровано: ${rows.length} из ${all.length} · <span class="ch-filter-count" title="Активные фильтры">🔍 ${activeFiltersCount} фильтр${activeFiltersCount > 1 ? 'а' : ''}</span>`
                : `${rows.length} клиентов`}
              · Итого в риске: <strong style="color:var(--churn-accent)">${fmtR(totalFiltered)}</strong>
              ${hasFilters ? `<button class="btn-reset" id="cf-reset">✕ Сбросить фильтры</button>` : ''}
            </div>
          </div>
        </div>

        <div class="ch-filter-bar">
          <select id="cf-segment" class="ch-sel${f.segment?' ch-sel-active':''}" title="Фильтр по сегменту">
            <option value="">Все сегменты</option>
            ${SEG_ORDER.map(s => `<option value="${s}"${f.segment===s?' selected':''}>${s}</option>`).join('')}
          </select>
          <select id="cf-prob" class="ch-sel${f.prob?' ch-sel-active':''}" title="Фильтр по уровню риска">
            <option value="">Все вероятности</option>
            <option value="3"${f.prob==='3'?' selected':''}>🔴 Prob 3 — Высокий</option>
            <option value="2"${f.prob==='2'?' selected':''}>🟠 Prob 2 — Средний</option>
            <option value="1"${f.prob==='1'?' selected':''}>🟡 Prob 1 — Низкий</option>
          </select>
          <select id="cf-product" class="ch-sel${f.product?' ch-sel-active':''}" title="Фильтр по продукту">
            <option value="">Все продукты</option>
            ${prods.map(p => `<option value="${p}"${f.product===p?' selected':''}>${p}</option>`).join('')}
          </select>
          <select id="cf-csm" class="ch-sel${f.csm?' ch-sel-active':''}" title="Фильтр по менеджеру CSM">
            <option value="">Все CSM</option>
            ${csms.map(m => `<option value="${esc(m)}"${f.csm===m?' selected':''}>${esc(m)}</option>`).join('')}
          </select>
          <div class="ch-filter-chips">
            <button class="chip${!hasFilters?' chip-active':''}" data-quick="">Все</button>
            <button class="chip${f.prob==='3'&&!f.segment?' chip-active':''}" data-quick-prob="3">🔴 Prob 3</button>
            <button class="chip${f.segment==='ENT'&&!f.prob?' chip-active':''}" data-quick-seg="ENT">ENT</button>
            <button class="chip${f.segment==='ENT'&&f.prob==='3'?' chip-active':''}" data-quick-entseg="">ENT + Prob3</button>
          </div>
        </div>

        <div class="ch-table-wrap">
          <table class="ch-table">
            <thead>
              <tr>
                <th class="td-idx">#</th>
                ${thSort('acct','Клиент','Сортировать по названию клиента (Airtable: поле Account)', true)}
                ${thSort('seg','Сегмент','Сортировать по сегменту. ENT ≥ 100К MRR. Источник: поле Segment в Airtable, иначе рассчитывается по MRR')}
                <th>CSM</th>
                ${thSort('prob','Вероятность','Сортировать по уровню риска. Источник: поле «Вероятность угрозы» в Airtable')}
                ${thSort('mrr','MRR в риске','Сортировать по MRR под угрозой. Сумма MRR продуктов со стадией «Угроза Churn»')}
                <th>${tt('Прогноз потери','MRR в риске × коэффициент вероятности: P3=100%, P2=60%, P1=30%. Взвешенная оценка потерь выручки.')}</th>
                ${thSort('total','Общий MRR','Сортировать по полному MRR клиента. % = доля MRR под угрозой от общего')}
                <th>${tt('Продукты','Продукты с активной угрозой churn. Источник: поля Customer Stage AQ/AC/Recs/AnyImages/AnyReviews/APP в Airtable')}</th>
                <th>${tt('Вертикаль','Индустрия клиента. Источник: поле «Вертикаль» в Airtable')}</th>
              </tr>
            </thead>
            <tbody>
              ${tableRows || `<tr><td colspan="9" class="empty-state">Нет клиентов по выбранным фильтрам</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  // ── Аналитический грид ────────────────────────────────────
  function buildAnalyticsGrid(d) {
    return `
      <div class="ch-analytics-grid">
        ${buildCsmPanel(d)}
        ${buildSegmentPanel(d)}
        ${buildProductPanel(d)}
        ${buildVerticalPanel(d)}
      </div>`;
  }

  function buildCsmPanel(d) {
    if (!d.byCsm?.length) return '';
    const max = Math.max(...d.byCsm.map(c => c.mrr), 1);
    const rows = d.byCsm.map(c => {
      const w3  = (((c.prob[3]||0) / max) * 100).toFixed(1);
      const w2  = (((c.prob[2]||0) / max) * 100).toFixed(1);
      const w1  = (((c.prob[1]||0) / max) * 100).toFixed(1);
      return `
        <div class="an-row">
          <div class="an-name">${esc(c.csm)}</div>
          <div class="an-bars">
            <div class="an-bar-seg" style="width:${w3}%;background:${PROB_COLOR[3]}" title="Prob 3: ${fmtR(c.prob[3]||0)}"></div>
            <div class="an-bar-seg" style="width:${w2}%;background:${PROB_COLOR[2]}" title="Prob 2: ${fmtR(c.prob[2]||0)}"></div>
            <div class="an-bar-seg" style="width:${w1}%;background:${PROB_COLOR[1]}" title="Prob 1: ${fmtR(c.prob[1]||0)}"></div>
          </div>
          <div class="an-val">${fmtK(c.mrr)}</div>
          <div class="an-count">${c.count} кл.</div>
          <div class="an-prob3 ${c.prob3count>2?'an-crit':''}">${c.prob3count} P3</div>
        </div>`;
    }).join('');

    return `
      <div class="ch-an-card">
        <div class="ch-an-head">
          ${tt('👤 По менеджерам (CSM)','MRR под угрозой по каждому CSM. Источник: поле CSM NEW (или CSM) в Airtable, нормализован в русское имя. Стека: красный=prob3, оранжевый=prob2, жёлтый=prob1. «P3» = кол-во клиентов с prob=3 у данного CSM.')}
        </div>
        <div class="an-rows">${rows}</div>
      </div>`;
  }

  function buildSegmentPanel(d) {
    if (!d.bySegment?.length) return '';
    const max = Math.max(...d.bySegment.map(s => s.mrr), 1);
    const rows = d.bySegment.map(s => {
      const w3 = (((s.prob[3]||0)/max)*100).toFixed(1);
      const w2 = (((s.prob[2]||0)/max)*100).toFixed(1);
      const w1 = (((s.prob[1]||0)/max)*100).toFixed(1);
      // Красная метка если P3 > 50% от MRR сегмента (E4)
      const p3share = s.mrr > 0 ? (s.prob[3]||0) / s.mrr : 0;
      const critMark = p3share > 0.5 ? `<span class="an-crit-mark" title="P3 &gt; 50% от MRR сегмента">⚠</span>` : '';
      return `
        <div class="an-row">
          <div class="an-name">${segBadge(s.segment)}${critMark}</div>
          <div class="an-bars">
            <div class="an-bar-seg" style="width:${w3}%;background:${PROB_COLOR[3]}"></div>
            <div class="an-bar-seg" style="width:${w2}%;background:${PROB_COLOR[2]}"></div>
            <div class="an-bar-seg" style="width:${w1}%;background:${PROB_COLOR[1]}"></div>
          </div>
          <div class="an-val">${fmtK(s.mrr)}</div>
          <div class="an-count">${s.count} кл.</div>
        </div>`;
    }).join('');

    return `
      <div class="ch-an-card">
        <div class="ch-an-head">
          ${tt('📊 По сегментам','MRR под угрозой по сегментам клиентов. ENT ≥ 100К MRR. Источник: поле Segment в Airtable, иначе рассчитывается автоматически по MRR порогам.')}
        </div>
        <div class="an-rows">${rows}</div>
      </div>`;
  }

  function buildProductPanel(d) {
    if (!d.byProduct?.length) return '';
    const max = Math.max(...d.byProduct.map(p => p.mrr), 1);
    const rows = d.byProduct.map(p => {
      const w   = ((p.mrr/max)*100).toFixed(1);
      const col = PROD_COLOR[p.product] || '#7B61FF';
      return `
        <div class="an-row">
          <div class="an-name">${prodTag(p.product)}</div>
          <div class="an-bars">
            <div class="an-bar-seg" style="width:${w}%;background:${col}"></div>
          </div>
          <div class="an-val">${fmtK(p.mrr)}</div>
          <div class="an-count">${p.count} кл.</div>
        </div>`;
    }).join('');

    return `
      <div class="ch-an-card">
        <div class="ch-an-head">
          ${tt('📦 По продуктам','MRR под угрозой по продуктам. Источник: поля Customer Stage AQ/AC/Recs/AnyImages/AnyReviews/APP в Airtable — учитываются только продукты со стадией «Угроза Churn».')}
        </div>
        <div class="an-rows">${rows}</div>
      </div>`;
  }

  function buildVerticalPanel(d) {
    if (!d.byVertical?.length) return '';
    const top = d.byVertical.slice(0, 10);
    const max = Math.max(...top.map(v => v.mrr), 1);
    const rows = top.map(v => {
      const w3 = (((v.prob[3]||0)/max)*100).toFixed(1);
      const w2 = (((v.prob[2]||0)/max)*100).toFixed(1);
      const w1 = (((v.prob[1]||0)/max)*100).toFixed(1);
      return `
        <div class="an-row">
          <div class="an-name" style="font-size:.75rem">${esc(v.vertical)}</div>
          <div class="an-bars">
            <div class="an-bar-seg" style="width:${w3}%;background:${PROB_COLOR[3]}"></div>
            <div class="an-bar-seg" style="width:${w2}%;background:${PROB_COLOR[2]}"></div>
            <div class="an-bar-seg" style="width:${w1}%;background:${PROB_COLOR[1]}"></div>
          </div>
          <div class="an-val">${fmtK(v.mrr)}</div>
          <div class="an-count">${v.count} кл.</div>
        </div>`;
    }).join('');

    return `
      <div class="ch-an-card">
        <div class="ch-an-head">
          ${tt('🏷 По вертикалям','MRR под угрозой по индустриям. Источник: поле «Вертикаль» в Airtable. Показаны топ-10 вертикалей.')}
        </div>
        <div class="an-rows">${rows}</div>
      </div>`;
  }

  // ── Пивот-таблицы #1–4 (Блок 2 ТЗ) ──────────────────────
  function buildPivotTables(d) {
    const PROBS = [3, 2, 1];
    const pHdr  = PROBS.map(p => `<th style="color:${PROB_COLOR[p]};text-align:right">Prob ${p}</th>`).join('');
    const fmtCell = v => v > 0 ? `<td style="text-align:right;font-variant-numeric:tabular-nums">${fmtK(v)}</td>` : `<td class="mx-empty">—</td>`;

    // Таблица #1: строка=Segment, колонки=вероятность 1/2/3
    function table1() {
      if (!d.bySegment?.length) return '';
      const rows = d.bySegment.map(s => `<tr>
        <td>${segBadge(s.segment)}</td>
        ${PROBS.map(p => fmtCell(s.prob?.[p] ?? 0)).join('')}
        <td style="text-align:right;font-weight:700">${fmtK(s.mrr)}</td>
        <td style="color:var(--muted);font-size:0.75rem;text-align:center">${s.count}</td>
      </tr>`).join('');
      return `
        <div class="ch-pivot-card">
          <div class="ch-pivot-head">${tt('📊 Таблица 1 — По сегментам × вероятность','Сумма MRR под угрозой: строки = сегмент, колонки = вероятность 1/2/3')}</div>
          <div class="ch-table-wrap"><table class="ch-table">
            <thead><tr><th>Сегмент</th>${pHdr}<th style="text-align:right">Итого</th><th style="text-align:center">Кл.</th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>
        </div>`;
    }

    // Таблица #2: строка=Segment+вероятность, колонки=Products
    function table2() {
      const sp = d.bySegmentProduct;
      if (!sp?.length) return '';
      const allProds = Object.keys(
        sp.reduce((acc, r) => { Object.keys(r.products||{}).forEach(p => acc[p]=1); return acc; }, {})
      );
      if (!allProds.length) return '';
      const pHdrs = allProds.map(p => `<th style="text-align:right;font-size:0.73rem">${esc(p)}</th>`).join('');
      const rows = sp.map(r => `<tr>
        <td style="white-space:nowrap">${segBadge(r.segment)}</td>
        <td style="color:${PROB_COLOR[r.prob]||'var(--muted)'}">${r.prob ?? '—'}</td>
        ${allProds.map(p => fmtCell(r.products?.[p] ?? 0)).join('')}
        <td style="text-align:right;font-weight:700">${fmtK(r.total)}</td>
      </tr>`).join('');
      return `
        <div class="ch-pivot-card" style="overflow-x:auto">
          <div class="ch-pivot-head">${tt('📦 Таблица 2 — Сегмент × вероятность × продукт','MRR под угрозой по каждому продукту в разрезе сегмент+вероятность')}</div>
          <div class="ch-table-wrap"><table class="ch-table" style="min-width:600px">
            <thead><tr><th>Сегмент</th><th>Prob</th>${pHdrs}<th style="text-align:right">Итого</th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>
        </div>`;
    }

    // Таблица #3: строки=CSM, колонки=вероятность 1/2/3
    function table3() {
      if (!d.byCsm?.length) return '';
      const rows = d.byCsm.map(c => `<tr>
        <td style="white-space:nowrap">${esc(c.csm)}</td>
        ${PROBS.map(p => fmtCell(c.prob?.[p] ?? 0)).join('')}
        <td style="text-align:right;font-weight:700">${fmtK(c.mrr)}</td>
        <td style="color:${PROB_COLOR[3]};font-size:0.75rem;text-align:center">${c.prob3count} P3</td>
      </tr>`).join('');
      return `
        <div class="ch-pivot-card">
          <div class="ch-pivot-head">${tt('👤 Таблица 3 — По CSM × вероятность','Сумма MRR под угрозой: строки = CSM, колонки = вероятность 1/2/3')}</div>
          <div class="ch-table-wrap"><table class="ch-table">
            <thead><tr><th>CSM</th>${pHdr}<th style="text-align:right">Итого</th><th style="text-align:center">P3 кл.</th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>
        </div>`;
    }

    // Таблица #4: строки=Вертикаль, колонки=вероятность 1/2/3
    function table4() {
      if (!d.byVertical?.length) return '';
      const top = d.byVertical.slice(0, 12);
      const rows = top.map(v => `<tr>
        <td style="font-size:0.78rem">${esc(v.vertical)}</td>
        ${PROBS.map(p => fmtCell(v.prob?.[p] ?? 0)).join('')}
        <td style="text-align:right;font-weight:700">${fmtK(v.mrr)}</td>
      </tr>`).join('');
      return `
        <div class="ch-pivot-card">
          <div class="ch-pivot-head">${tt('🏷 Таблица 4 — По вертикали × вероятность','Сумма MRR под угрозой: строки = вертикаль, колонки = вероятность 1/2/3')}</div>
          <div class="ch-table-wrap"><table class="ch-table">
            <thead><tr><th>Вертикаль</th>${pHdr}<th style="text-align:right">Итого</th></tr></thead>
            <tbody>${rows}</tbody>
          </table></div>
        </div>`;
    }

    const t1 = table1(), t2 = table2(), t3 = table3(), t4 = table4();
    if (!t1 && !t2 && !t3 && !t4) return '';

    // Tabs: segment | csm | vertical | product (E4)
    const tab = state.pivotTab || 'segment';
    const tabs = [
      { id: 'segment',  label: 'По сегменту', html: t1 },
      { id: 'csm',      label: 'По CSM',      html: t3 },
      { id: 'vertical', label: 'По верт.',    html: t4 },
      { id: 'product',  label: 'Продукт×Сег', html: t2 },
    ].filter(t => t.html);

    const tabBtns = tabs.map(t =>
      `<button class="pivot-tab${t.id===tab?' pivot-tab-active':''}" data-pivot-tab="${t.id}">${t.label}</button>`
    ).join('');
    const activeHtml = tabs.find(t => t.id === tab)?.html || tabs[0]?.html || '';

    return `
      <div class="ch-section ch-pivot-section">
        <div class="ch-sec-head">
          <h2 class="ch-sec-title">
            ${tt('📋 Пивот-таблицы по вероятности','Детальные сводные таблицы: строки — измерение, столбцы — уровень вероятности угрозы. Значения в сокращённом формате (К/М ₽).')}
          </h2>
          <div class="pivot-tabs">${tabBtns}</div>
        </div>
        <div class="pivot-tab-content active">
          ${activeHtml}
        </div>
      </div>`;
  }

  // ── CSM × Сегмент матрица ─────────────────────────────────
  function buildCsmMatrix(d) {
    const clients = d.clients || [];
    if (!clients.length) return '';

    const csms       = [...new Set(clients.map(c => c.csm))].filter(Boolean).sort();
    const activeSeg  = [...new Set(clients.map(c => c.segment))].filter(Boolean)
                        .sort((a,b) => { const ia = SEG_ORDER.indexOf(a); const ib = SEG_ORDER.indexOf(b); return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib); });
    const probs      = [3, 2, 1];

    // Матрица: csm → segment → prob → mrr
    const mx = {};
    for (const c of clients) {
      const csm = c.csm || '—';
      const seg = c.segment;
      const p   = c.probability;
      if (!mx[csm]) mx[csm] = {};
      if (!mx[csm][seg]) mx[csm][seg] = { 1:0, 2:0, 3:0 };
      if (p) mx[csm][seg][p] = (mx[csm][seg][p]||0) + c.mrrAtRisk;
    }

    const csmTotals = csms.map(csm => ({
      csm,
      total: Object.values(mx[csm]||{}).reduce((s,g)=>s+(g[1]||0)+(g[2]||0)+(g[3]||0), 0),
    })).sort((a,b) => b.total - a.total);

    const headers = activeSeg.flatMap(seg => probs.map(p => ({seg,p})));

    const hCells = headers.map(h => `
      <th class="mx-th" style="color:${PROB_COLOR[h.p]}" title="${h.seg} · Prob ${h.p}">
        ${h.seg}<br><small>${h.p}</small>
      </th>`).join('');

    // Находим CSM с максимальным P3 MRR для приоритетной строки (E4)
    const csmP3totals = csmTotals.map(({ csm }) => ({
      csm,
      p3: Object.values(mx[csm] || {}).reduce((s, g) => s + (g[3] || 0), 0),
    }));
    const maxP3csm = csmP3totals.reduce((best, c) => c.p3 > best.p3 ? c : best, { csm: '', p3: 0 });

    const mxRows = csmTotals.map(({csm, total}) => {
      const cells = headers.map(h => {
        const v = mx[csm]?.[h.seg]?.[h.p] || 0;
        const bright = v > 500_000 ? 'mx-hot' : v > 100_000 ? 'mx-warm' : '';
        return v > 0
          ? `<td class="mx-cell ${bright}" style="color:${PROB_COLOR[h.p]}" title="${csm} / ${h.seg} / Prob ${h.p}: ${fmtR(v)}">${fmtK(v)}</td>`
          : `<td class="mx-cell mx-empty">—</td>`;
      }).join('');
      const isPriority = maxP3csm.p3 > 0 && csm === maxP3csm.csm;
      const priBadge = isPriority ? `<span class="mx-priority-badge" title="Наибольший P3 MRR среди CSM">⚠ Приоритет</span>` : '';
      return `<tr class="${isPriority?'mx-priority-row':''}">
        <td class="mx-name">${esc(csm)}${priBadge}</td>
        ${cells}
        <td class="mx-total">${fmtR(total)}</td>
      </tr>`;
    }).join('');

    return `
      <div class="ch-section ch-matrix">
        <div class="ch-sec-head">
          <h2 class="ch-sec-title">
            ${tt('🗂 Матрица CSM × Сегмент × Вероятность','Сводная матрица: строки = менеджеры CSM, столбцы = сегмент × вероятность. Ячейка = сумма MRR под угрозой. 🔥 Горячие ячейки (> 500К) выделены красным фоном. Позволяет за один взгляд увидеть, у какого менеджера и в каком сегменте концентрируется риск.')}
          </h2>
          <div class="ch-matrix-legend">
            <span style="color:${PROB_COLOR[3]}">■ Prob 3</span>
            <span style="color:${PROB_COLOR[2]}">■ Prob 2</span>
            <span style="color:${PROB_COLOR[1]}">■ Prob 1</span>
          </div>
        </div>
        <div class="ch-table-wrap">
          <table class="ch-table ch-mx-table">
            <thead><tr>
              <th class="mx-name-th">CSM</th>
              ${hCells}
              <th class="mx-total-th">Итого</th>
            </tr></thead>
            <tbody>${mxRows}</tbody>
          </table>
        </div>
      </div>`;
  }

  // ── Events ────────────────────────────────────────────────
  function attachEvents() {
    document.getElementById('btn-refresh')?.addEventListener('click', doRefresh);
    document.getElementById('btn-copy')?.addEventListener('click', doCopy);
    document.getElementById('btn-csv')?.addEventListener('click', exportCsv);
    document.getElementById('btn-theme')?.addEventListener('click', () => {
      const root = document.getElementById('html-root');
      const next = root?.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root?.setAttribute('data-theme', next);
      localStorage.setItem('aq_theme', next);
      render();
    });

    // In-Progress checkboxes (E3)
    document.querySelectorAll('.ip-check').forEach(chk => {
      chk.addEventListener('change', e => {
        const account = e.target.dataset.account;
        if (e.target.checked) inProgress.add(account);
        else inProgress.delete(account);
        saveInProgress(inProgress);
        // Обновляем только строку без полного ре-рендера
        const tr = e.target.closest('tr');
        if (tr) {
          if (e.target.checked) tr.classList.add('tr-inprogress');
          else tr.classList.remove('tr-inprogress');
        }
      });
    });

    // Pivot tabs (E4)
    document.querySelectorAll('[data-pivot-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.pivotTab = btn.dataset.pivotTab;
        render();
        requestAnimationFrame(() => document.querySelector('.ch-pivot-section')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
      });
    });

    // Filters
    ['cf-segment','cf-prob','cf-product','cf-csm'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', e => {
        const key = { 'cf-segment':'segment','cf-prob':'prob','cf-product':'product','cf-csm':'csm' }[id];
        if (key) { state.filters[key] = e.target.value; filtersToUrl(); render(); }
      });
    });

    document.getElementById('cf-reset')?.addEventListener('click', () => {
      state.filters = { segment:'', prob:'', product:'', csm:'' };
      filtersToUrl(); render();
    });

    // Quick filter chips
    document.querySelectorAll('[data-quick]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.filters = { segment:'', prob:'', product:'', csm:'' };
        filtersToUrl(); render();
      });
    });
    document.querySelectorAll('[data-quick-prob]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.filters = { segment:'', prob: btn.dataset.quickProb, product:'', csm:'' };
        filtersToUrl(); render();
      });
    });
    document.querySelectorAll('[data-quick-seg]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.filters = { segment: btn.dataset.quickSeg, prob:'', product:'', csm:'' };
        filtersToUrl(); render();
      });
    });
    document.querySelectorAll('[data-quick-entseg]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.filters = { segment:'ENT', prob:'3', product:'', csm:'' };
        filtersToUrl(); render();
      });
    });

    // Column sorting
    document.querySelectorAll('[data-sort]').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.dataset.sort;
        if (state.sort.col === col) state.sort.dir = state.sort.dir === 'desc' ? 'asc' : 'desc';
        else { state.sort.col = col; state.sort.dir = col === 'acct' ? 'asc' : 'desc'; }
        filtersToUrl(); render();
        requestAnimationFrame(() => document.getElementById('ch-clients')?.scrollIntoView({ behavior:'smooth', block:'nearest' }));
      });
    });

    // Tooltips — close on outside click
    document.addEventListener('click', e => {
      if (!e.target.closest('.tt-wrap')) {
        document.querySelectorAll('.tt-wrap').forEach(el => el.classList.remove('tt-open'));
      }
    });
    document.querySelectorAll('.tt-wrap').forEach(el => {
      el.addEventListener('click', e => { e.stopPropagation(); el.classList.toggle('tt-open'); });
    });
  }

  // ── CSRF helper ───────────────────────────────────────────
  const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  // ── Refresh ───────────────────────────────────────────────
  async function doRefresh() {
    if (state.loading) return;
    state.loading = true;
    startProgress();
    render();
    try {
      const res  = await fetch('churn_api.php', {
        cache: 'no-store',
        headers: { 'X-CSRF-Token': csrfToken() },
      });
      const json = await res.json();
      if (json.ok && json.data) state.data = json.data;
      else showError(json.error || 'Ошибка получения данных');
    } catch(e) { showError(e.message); }
    finally { state.loading = false; endProgress(); render(); }
  }

  function showError(msg) {
    const app = document.getElementById('app');
    if (!app) return;
    const el = document.createElement('div');
    el.className = 'ch-error';
    el.innerHTML = `⚠ ${esc(msg)} <button onclick="this.parentNode.remove()">✕</button>`;
    app.prepend(el);
    setTimeout(() => el.remove(), 8000);
  }

  // ── Auto-refresh ──────────────────────────────────────────
  let autoTimer = null;
  function scheduleAuto() {
    clearTimeout(autoTimer);
    autoTimer = setTimeout(async () => {
      if (!document.hidden) await doRefresh();
      scheduleAuto();
    }, AUTO_MS);
  }

  // ── URL-фильтры (H1) ──────────────────────────────────────
  // Читаем ?segment=ENT&prob=3&product=AQ&csm=Иван из URL
  function filtersFromUrl() {
    const p = new URLSearchParams(location.search);
    const f = state.filters;
    if (p.has('segment')) f.segment = p.get('segment');
    if (p.has('prob'))    f.prob    = p.get('prob');
    if (p.has('product')) f.product = p.get('product');
    if (p.has('csm'))     f.csm     = p.get('csm');
    if (p.has('sort'))    { const [col, dir] = p.get('sort').split(':'); if (col) { state.sort.col = col; state.sort.dir = dir || 'desc'; } }
  }

  // Сохраняем текущие фильтры в URL без перезагрузки страницы
  function filtersToUrl() {
    const p = new URLSearchParams();
    const f = state.filters;
    if (f.segment) p.set('segment', f.segment);
    if (f.prob)    p.set('prob',    f.prob);
    if (f.product) p.set('product', f.product);
    if (f.csm)     p.set('csm',     f.csm);
    if (state.sort.col !== 'mrr' || state.sort.dir !== 'desc')
      p.set('sort', `${state.sort.col}:${state.sort.dir}`);
    const qs = p.toString();
    history.replaceState(null, '', qs ? '?' + qs : location.pathname);
  }

  // ── Init ──────────────────────────────────────────────────
  function init() {
    filtersFromUrl();
    const el = document.getElementById('churn-bootstrap');
    if (el && el.textContent.trim()) {
      try { state.data = JSON.parse(el.textContent); }
      catch(e) { showError('Ошибка разбора данных: ' + e.message); }
    }
    render();
    scheduleAuto();

    if (!state.data) {
      // Кэша нет — грузим асинхронно сразу, страница уже показана
      doRefresh();
    } else if (state.data._stale) {
      // Данные устарели — фоновое обновление через 2 сек
      setTimeout(() => { if (!state.loading) doRefresh(); }, 2000);
    }
  }

  // Пересчёт при изменении размера окна (filter-bar может переноситься)
  window.addEventListener('resize', updateStickyOffsets, { passive: true });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

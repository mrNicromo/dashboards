/* =========================================================
   churn_fact.js — Дашборд «Потери выручки (Churn + DownSell)»
   ========================================================= */
(function () {
  'use strict';

  const AUTO_MS = 5 * 60 * 1000;

  // ── Утилиты ───────────────────────────────────────────────
  const esc  = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const fmtR = v => Math.round(v).toLocaleString('ru-RU') + ' ₽';
  const fmtN = v => Math.round(v).toLocaleString('ru-RU');
  const fmtShort = v => {
    if (v >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'М';
    if (v >= 1_000)     return (v / 1_000).toFixed(0) + 'К';
    return Math.round(v).toString();
  };
  const pct  = (v, total) => total > 0 ? (v / total * 100).toFixed(1) + '%' : '—';
  const sign = v => v >= 0 ? '+' : '';
  const devCls = v => v <= 0 ? 'dev-ok' : 'dev-bad'; // for targets: under = green, over = red

  // ── Тултип-иконка ─────────────────────────────────────────
  // Рендерит ℹ кружок. Тултип показывается через JS (position:fixed),
  // потому что cf-card имеет overflow:hidden и обрезает CSS ::after.
  const tip = text => `<i class="tip-icon" data-tip="${esc(text)}">i</i>`;

  // Глобальный tooltip div — создаётся один раз
  let _tipEl = null;
  function initTooltip() {
    if (_tipEl) return;
    _tipEl = document.createElement('div');
    _tipEl.id = 'cf-tooltip';
    _tipEl.style.cssText = [
      'position:fixed','z-index:9999','pointer-events:none',
      'background:#1a1a28','color:#e0e0e0','border:1px solid rgba(255,255,255,.13)',
      'padding:8px 12px','border-radius:8px','font-size:0.73rem','line-height:1.55',
      'white-space:pre-wrap','max-width:300px','box-shadow:0 4px 18px rgba(0,0,0,.5)',
      'opacity:0','transition:opacity .12s','text-align:left'
    ].join(';');
    document.body.appendChild(_tipEl);

    document.addEventListener('mouseover', e => {
      // Работает для .tip-icon (иконки) и [data-tip] (SVG-столбцы, строки таблиц)
      const el = e.target.closest('[data-tip]');
      if (!el) return;
      const txt = el.getAttribute('data-tip');
      if (!txt) return;
      _tipEl.textContent = txt;
      _tipEl.style.opacity = '1';
      // SVG-элементы: позиционируем по курсору мыши; HTML-элементы — по bounding rect
      if (el.closest('svg')) {
        positionTipAtCursor(e);
      } else {
        positionTip(e, el);
      }
    });
    document.addEventListener('mousemove', e => {
      if (_tipEl.style.opacity === '0') return;
      const el = e.target.closest('[data-tip]');
      if (!el) return;
      if (el.closest('svg')) positionTipAtCursor(e);
      else positionTip(e, el);
    });
    document.addEventListener('mouseout', e => {
      if (e.target.closest('[data-tip]')) _tipEl.style.opacity = '0';
    });
  }
  function positionTipAtCursor(e) {
    const tw  = _tipEl.offsetWidth  || 220;
    const th  = _tipEl.offsetHeight || 70;
    const vw  = window.innerWidth;
    let x = e.clientX - tw / 2;
    let y = e.clientY - th - 14;
    if (x + tw > vw - 8) x = vw - tw - 8;
    if (x < 8) x = 8;
    if (y < 8) y = e.clientY + 16;
    _tipEl.style.left = x + 'px';
    _tipEl.style.top  = y + 'px';
  }
  function positionTip(e, el) {
    const r   = el.getBoundingClientRect();
    const tw  = _tipEl.offsetWidth  || 260;
    const th  = _tipEl.offsetHeight || 80;
    const vw  = window.innerWidth;
    let x = r.left + r.width / 2 - tw / 2;
    let y = r.top - th - 10;
    if (x + tw > vw - 8) x = vw - tw - 8;
    if (x < 8) x = 8;
    if (y < 8) y = r.bottom + 10;
    _tipEl.style.left = x + 'px';
    _tipEl.style.top  = y + 'px';
  }

  // ── Состояние ─────────────────────────────────────────────
  const state = {
    data:     null,
    loading:  false,
    tab:      'churn',  // 'churn' | 'downsell'
    loadedAt: null,     // Date.now() при последней загрузке — для индикатора свежести
    // F4: диапазон месяцев — null = все
    monthFrom: null,   // '2025-01'
    monthTo:   null,   // '2025-06'
  };

  // ── Индикатор свежести («X мин назад») ───────────────────
  function formatAge(ts) {
    const sec = Math.floor((Date.now() - ts) / 1000);
    if (sec < 60)   return 'только что';
    if (sec < 3600) return Math.floor(sec / 60) + '\u00a0мин назад';
    return Math.floor(sec / 3600) + '\u00a0ч назад';
  }

  let _freshnessTimer = null;
  function startFreshnessTimer() {
    clearInterval(_freshnessTimer);
    _freshnessTimer = setInterval(() => {
      const el = document.getElementById('cf-freshness');
      if (el && state.loadedAt) el.textContent = formatAge(state.loadedAt);
    }, 30_000);
  }

  // ── Фильтрация byMonth по диапазону (F4) ─────────────────
  function filterMonths(months) {
    if (!months?.length) return [];
    return months.filter(m =>
      (!state.monthFrom || m.month >= state.monthFrom) &&
      (!state.monthTo   || m.month <= state.monthTo)
    );
  }

  // ── Вычисление агрегатов для диапазона (F3) ──────────────
  function calcPeriodTotals(months) {
    return months.reduce((acc, m) => {
      acc.churn    += m.churn    || 0;
      acc.downsell += m.downsell || 0;
      acc.total    += (m.churn || 0) + (m.downsell || 0);
      return acc;
    }, { churn: 0, downsell: 0, total: 0 });
  }

  // Предыдущий период той же длины (для сравнения F3)
  function prevPeriodTotals(months, from, to) {
    if (!months?.length || !from || !to) return null;
    // вычисляем длину текущего периода в месяцах
    const allMonths = months.map(m => m.month).sort();
    const idxFrom = allMonths.indexOf(from);
    const idxTo   = allMonths.indexOf(to);
    if (idxFrom < 0 || idxTo < 0) return null;
    const len  = idxTo - idxFrom + 1;
    const pFrom = allMonths[idxFrom - len];
    const pTo   = allMonths[idxFrom - 1];
    if (!pFrom || !pTo) return null;
    const prevMonths = months.filter(m => m.month >= pFrom && m.month <= pTo);
    return { totals: calcPeriodTotals(prevMonths), from: pFrom, to: pTo };
  }

  // ── KPI snapshot для дельты (E5a) ────────────────────────
  const CF_SNAP_KEY = 'cf_kpi_snap_v1';
  function loadCfSnap()  { try { return JSON.parse(localStorage.getItem(CF_SNAP_KEY) || 'null'); } catch { return null; } }
  function saveCfSnap(d) {
    try {
      localStorage.setItem(CF_SNAP_KEY, JSON.stringify({
        month:       d.updatedAt ? d.updatedAt.slice(0, 7) : '',
        churnYtd:    d.churnYtd    || 0,
        downsellYtd: d.downsellYtd || 0,
        totalYtd:    d.totalYtd    || 0,
        smbYtd:      d.smbYtd      || 0,
        entYtd:      d.entYtd      || 0,
      }));
    } catch {}
  }

  // ── Светофор отклонения (E5c) ────────────────────────────
  function trafficLight(devPct) {
    const abs = Math.abs(devPct);
    if (abs <= 5)  return { cls: 'tl-green',  icon: '🟢', label: 'В норме' };
    if (abs <= 15) return { cls: 'tl-yellow', icon: '🟡', label: 'Внимание' };
    return          { cls: 'tl-red',   icon: '🔴', label: 'Критично' };
  }

  // ── Рендер ────────────────────────────────────────────────
  function render() {
    const app = document.getElementById('app');
    if (!app) return;
    if (state.loading && !state.data) {
      app.innerHTML = `
        <div class="cf-loading">
          <div class="ch-spinner-dots"><span></span><span></span><span></span></div>
          <div class="ch-loading-title">Загружаем данные из Airtable…</div>
          <div class="ch-loading-sub">Обычно 5–15 секунд при первом запуске</div>
        </div>`;
      return;
    }
    if (!state.data) return;
    const d = state.data;
    // F4: применяем диапазон месяцев
    const filteredMonths = filterMonths(d.byMonth);
    const dFiltered = { ...d, byMonth: filteredMonths };
    const staleBanner = (d._stale || d._backup)
      ? `<div class="cf-stale-banner">⚠ Airtable недоступен — резервные данные от ${esc(d.updatedAt || '?')}</div>`
      : '';
    app.innerHTML = `
      ${buildTopbar(d)}
      ${staleBanner}
      <div class="cf-wrap">
        ${buildPeriodFilter(d)}
        ${buildKpis(d, filteredMonths)}
        ${buildForecastBlock(d)}
        ${buildWaterfallChart(d)}
        ${buildMonthlyChart(dFiltered)}
        ${buildSegmentChart(dFiltered)}
        ${buildMidRow(d)}
        ${buildTables(d)}
      </div>`;
    attachEvents();
    startFreshnessTimer();
    if (state.data) saveCfSnap(state.data);
  }

  // ── Topbar ────────────────────────────────────────────────
  function buildTopbar(d) {
    const dark = document.getElementById('html-root')?.getAttribute('data-theme') === 'dark';
    const freshAge = state.loadedAt ? formatAge(state.loadedAt) : '';
    return `
      <div class="cf-topbar${state.loading && state.data ? ' cf-topbar--loading' : ''}">
        <div class="cf-topbar-left">
          <div class="aq-logo"><span class="aq-logo-box">AQ</span><span class="aq-logo-text">anyquery</span></div>
          <nav class="cf-nav-tabs">
            <a class="cf-nav-tab" href="index.php">🏠 Главная</a>
            <a class="cf-nav-tab" href="churn.php">⚠ Угроза Churn</a>
            <span class="cf-nav-tab cf-nav-tab-active">📉 Потери</span>
            <a class="cf-nav-tab" href="manager.php">💰 ДЗ</a>
            <a class="cf-nav-tab" href="ai_insights.php">🤖 AI</a>
          </nav>
        </div>
        <div class="cf-topbar-right">
          ${state.loadedAt ? `<span class="cf-fresh-badge" id="cf-freshness" title="Данные обновлены: ${esc(d.updatedAt)}">${freshAge || esc(d.updatedAt.slice(11,16))}</span>` : ''}
          ${state.loading && state.data ? '<span class="cf-spin-dot" title="Обновление…"></span>' : ''}
          <button class="btn-topbar btn-icon${state.loading ? ' is-loading' : ''}" id="btn-refresh" aria-label="Обновить данные из Airtable" title="Обновить">⟳</button>
          <button class="btn-topbar btn-icon" id="btn-theme" aria-label="Тема" title="${dark ? 'Светлая тема' : 'Тёмная тема'}">${dark ? '☀' : '🌙'}</button>
        </div>
      </div>`;
  }

  // ── Фильтр диапазона месяцев (F4) ────────────────────────
  function buildPeriodFilter(d) {
    const months = (d.byMonth || []).map(m => m.month).sort();
    if (months.length < 2) return '';
    const MONTH_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь',
                      'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const label = m => {
      const [y, mo] = m.split('-');
      return `${MONTH_RU[parseInt(mo) - 1]} ${y}`;
    };
    const opts = m => months.map(v => `<option value="${v}"${v===m?' selected':''}>${label(v)}</option>`).join('');
    const from = state.monthFrom || months[0];
    const to   = state.monthTo   || months[months.length - 1];

    // Считаем итоги для выбранного и предыдущего периода (F3)
    const filtered  = filterMonths(d.byMonth);
    const curr      = calcPeriodTotals(filtered);
    const prevInfo  = state.monthFrom && state.monthTo ? prevPeriodTotals(d.byMonth, state.monthFrom, state.monthTo) : null;

    const deltaSpan = (curr, prev, label) => {
      if (prev == null || prev === 0) return '';
      const d = curr - prev;
      const pct = (d / prev * 100).toFixed(1);
      const cls = d > 0 ? 'delta-up' : 'delta-down';
      return `<span class="cf-period-delta ${cls}">${d > 0 ? '▲' : '▼'} ${fmtR(Math.abs(d))} (${d > 0 ? '+' : ''}${pct}%) vs ${label}</span>`;
    };
    const prevLabel = prevInfo ? `${label(prevInfo.from)}–${label(prevInfo.to)}` : '';
    const isFiltered = state.monthFrom || state.monthTo;

    return `
      <div class="cf-period-bar">
        <div class="cf-period-left">
          <span class="cf-period-label">📅 Период:</span>
          <select class="cf-period-sel" id="cf-month-from" aria-label="Начало периода">
            ${opts(from)}
          </select>
          <span class="cf-period-sep">—</span>
          <select class="cf-period-sel" id="cf-month-to" aria-label="Конец периода">
            ${opts(to)}
          </select>
          ${isFiltered ? `<button class="cf-period-reset" id="cf-period-reset" title="Сбросить фильтр периода">✕</button>` : ''}
        </div>
        ${isFiltered ? `
        <div class="cf-period-summary">
          <span class="cf-period-total">Churn: <strong>${fmtR(curr.churn)}</strong></span>
          <span class="cf-period-total">DownSell: <strong>${fmtR(curr.downsell)}</strong></span>
          <span class="cf-period-total">Итого: <strong>${fmtR(curr.total)}</strong></span>
          ${prevInfo ? deltaSpan(curr.total, prevInfo.totals.total, prevLabel) : ''}
        </div>` : ''}
      </div>`;
  }

  // ── KPI блок (Блок 1 + Блок 8) ───────────────────────────
  function buildKpis(d, filteredMonths) {
    const devTotal = d.targetTotal > 0 ? ((d.totalYtd - d.targetTotal) / d.targetTotal * 100) : 0;
    const devSmb   = d.targetSmb   > 0 ? ((d.smbYtd   - d.targetSmb)   / d.targetSmb   * 100) : 0;
    const devEnt   = d.targetEnt   > 0 ? ((d.entYtd   - d.targetEnt)   / d.targetEnt   * 100) : 0;

    // Дельта vs предыдущий снимок (E5a)
    const snap = loadCfSnap();
    const hasDelta = snap && snap.month && snap.month !== (d.updatedAt || '').slice(0, 7);
    function deltaHtml(curr, prev) {
      if (!hasDelta || prev == null) return '';
      const delta = curr - prev;
      if (Math.abs(delta) < 1) return '';
      const up = delta > 0;
      return `<span class="cf-kpi-delta ${up?'delta-up':'delta-down'}" title="Изм. vs ${snap.month}">
        ${up ? '▲' : '▼'} ${fmtR(Math.abs(delta))}
      </span>`;
    }

    const kpi = (icon, lbl, val, sub, cls, tipText, delta = '') => `
      <div class="cf-kpi ${cls}">
        <div class="cf-kpi-icon">${icon}</div>
        <div class="cf-kpi-body">
          <div class="cf-kpi-lbl">${lbl}${tipText ? tip(tipText) : ''}</div>
          <div class="cf-kpi-val">${val}${delta}</div>
          ${sub ? `<div class="cf-kpi-sub">${sub}</div>` : ''}
        </div>
      </div>`;

    // Badge актуальности данных (E1)
    const freshBadge = d.updatedAt
      ? `<span class="cf-fresh-badge" title="Данные актуальны на ${esc(d.updatedAt)}">🕐 ${esc(d.updatedAt.slice(11,16))}</span>`
      : '';

    // F3/F4: если задан диапазон — показываем KPI по периоду + сравнение с предыдущим
    const isFiltered = (state.monthFrom || state.monthTo) && filteredMonths?.length;
    const periodTotals  = isFiltered ? calcPeriodTotals(filteredMonths) : null;
    const prevInfo      = isFiltered && state.monthFrom && state.monthTo
      ? prevPeriodTotals(d.byMonth, state.monthFrom, state.monthTo) : null;

    function periodDelta(currVal, prevVal) {
      if (!prevInfo || prevVal == null) return '';
      const delta = currVal - prevVal;
      if (Math.abs(delta) < 1) return '';
      const up = delta > 0;
      return `<span class="cf-kpi-delta ${up?'delta-up':'delta-down'}" title="vs предыдущий период (${prevInfo.from}–${prevInfo.to})">
        ${up ? '▲' : '▼'} ${fmtR(Math.abs(delta))}
      </span>`;
    }

    const churnVal    = isFiltered ? periodTotals.churn    : d.churnYtd;
    const dsVal       = isFiltered ? periodTotals.downsell  : d.downsellYtd;
    const totalVal    = isFiltered ? periodTotals.total     : d.totalYtd;
    const periodLabel = isFiltered ? `Период` : `YTD ${d.year}`;

    const prevTotals = prevInfo?.totals;

    return `
      <div class="cf-section-label">📊 Факт ${periodLabel} ${freshBadge} ${tip('YTD (Year-to-Date) = накопленные потери с начала года по текущий месяц.\nВключает: Churn (уход клиентов) + DownSell (постоянное снижение MRR).\nОбновляется каждые 15 минут из Airtable + Google Sheets.')}</div>
      <div class="cf-kpis">
        ${kpi('🔴',`Churn ${periodLabel}`,       fmtR(churnVal),  `${pct(churnVal, totalVal)} от общих потерь`, 'kpi-danger', 'Сумма MRR клиентов, ушедших в текущем году.\nИсточник: Airtable (6 вьюшек — AQ, AC, RECS, AnyImages, AnyReviews, Rees46)', isFiltered ? periodDelta(churnVal, prevTotals?.churn) : deltaHtml(d.churnYtd, snap?.churnYtd))}
        ${kpi('🟠',`DownSell ${periodLabel}`,    fmtR(dsVal),     `${pct(dsVal, totalVal)} от общих потерь`, 'kpi-warn', 'Снижение MRR по действующим клиентам (постоянные скидки).\nИсточник: Google Sheets, вкладка UpSale/DownSell.\nФильтр: Тип = Постоянная, Изменение > 0', isFiltered ? periodDelta(dsVal, prevTotals?.downsell) : deltaHtml(d.downsellYtd, snap?.downsellYtd))}
        ${kpi('💸',`Total потери ${periodLabel}`, fmtR(totalVal),  isFiltered ? `за ${filteredMonths.length} мес.` : `Таргет: ${fmtR(d.targetTotal)}`, 'kpi-danger', 'Формула: Churn YTD + DownSell YTD\nВсе потери выручки за период', isFiltered ? periodDelta(totalVal, prevTotals?.total) : deltaHtml(d.totalYtd, snap?.totalYtd))}
        ${!isFiltered ? kpi(devTotal<=0?'✅':'🚨','Отклонение от плана',
          `<span class="${devCls(devTotal)}">${sign(devTotal)}${devTotal.toFixed(1)}%</span>`,
          `${sign(d.totalYtd - d.targetTotal)}${fmtR(d.totalYtd - d.targetTotal)}`,
          devTotal<=0?'kpi-ok':'kpi-danger',
          'Формула: (Total YTD − Таргет) / Таргет × 100%\nТаргет на год: 8 200 000 ₽\n✅ Ниже нуля = потери меньше плана (хорошо)') : ''}
      </div>
      <div class="cf-section-label" style="margin-top:8px">Контроль по сегментам ${tip('Разбивка потерь по бизнес-сегментам.\nENT (Enterprise): крупные клиенты, таргет 2 400 000 ₽/год.\nSMB/SME: малый и средний бизнес, таргет 5 800 000 ₽/год.\nСегмент берётся из поля Segment в Airtable.')}</div>
      <div class="cf-kpis cf-kpis-seg">
        ${kpi('📦','SMB/SME — потери YTD',   fmtR(d.smbYtd), `Таргет: ${fmtR(d.targetSmb)}`, 'kpi-neutral', 'Потери клиентов сегментов: SS, SMB, SME-, SME, SME+.\nОпределяется по полю Segment в Airtable.\nТаргет: 5 800 000 ₽/год', deltaHtml(d.smbYtd, snap?.smbYtd))}
        ${kpi(devSmb<=0?'✅':'🚨','SMB/SME отклонение',
          `<span class="${devCls(devSmb)}">${sign(devSmb)}${devSmb.toFixed(1)}%</span>`,
          `Таргет 5 800 000 ₽`, devSmb<=0?'kpi-ok':'kpi-danger', 'Формула: (SMB YTD − 5 800 000) / 5 800 000 × 100%\n✅ Ниже нуля = потери меньше плана')}
        ${kpi('🏢','Enterprise — потери YTD', fmtR(d.entYtd), `Таргет: ${fmtR(d.targetEnt)}`, 'kpi-neutral', 'Потери Enterprise клиентов (Segment = ENT в Airtable).\nТаргет: 2 400 000 ₽/год', deltaHtml(d.entYtd, snap?.entYtd))}
        ${kpi(devEnt<=0?'✅':'🚨','ENT отклонение',
          `<span class="${devCls(devEnt)}">${sign(devEnt)}${devEnt.toFixed(1)}%</span>`,
          `Таргет 2 400 000 ₽`, devEnt<=0?'kpi-ok':'kpi-danger', 'Формула: (ENT YTD − 2 400 000) / 2 400 000 × 100%\n✅ Ниже нуля = потери меньше плана')}
      </div>`;
  }

  // ── Прогноз года (Блок 13) ────────────────────────────────
  function buildForecastBlock(d) {
    const fcast       = d.forecastYear  || 0;
    const devFcast    = d.targetTotal > 0 ? ((fcast - d.targetTotal) / d.targetTotal * 100) : 0;
    const prob3risk   = d.prob3risk     || 0;

    // ENT / SMB split
    const forecastEnt = d.forecastEnt   || 0;
    const forecastSmb = d.forecastSmb   || 0;
    const devEntPct   = d.devEntPct     ?? null;
    const devSmbPct   = d.devSmbPct     ?? null;
    const targetEnt   = d.targetEnt     || 0;
    const targetSmb   = d.targetSmb     || 0;
    const entYtd      = d.entYtd        || 0;
    const smbYtd      = d.smbYtd        || 0;
    const prob3Ent    = d.prob3riskEnt  != null ? d.prob3riskEnt  : Math.max(0, forecastEnt - entYtd);
    const prob3Smb    = d.prob3riskSmb  != null ? d.prob3riskSmb  : Math.max(0, forecastSmb - smbYtd);

    const segRows = (forecastEnt > 0 || forecastSmb > 0) ? `
      <div class="cf-forecast-seg-row">
        <div class="cf-forecast-seg-card">
          <div class="cf-forecast-seg-label">ENT прогноз</div>
          <div class="cf-forecast-seg-val">${fmtR(forecastEnt)}</div>
          <div class="cf-forecast-seg-sub">YTD ${fmtR(entYtd)} + Риск ${fmtR(prob3Ent)}</div>
          ${devEntPct !== null && targetEnt > 0 ? (() => {
            const tl = trafficLight(devEntPct);
            return `<div class="cf-forecast-seg-dev ${devCls(devEntPct)}">
              <span class="tl-indicator ${tl.cls}" title="${tl.label}">${tl.icon}</span>
              ${sign(devEntPct)}${devEntPct.toFixed(1)}% от плана ${fmtR(targetEnt)}
            </div>`;
          })() : ''}
        </div>
        <div class="cf-forecast-seg-card">
          <div class="cf-forecast-seg-label">SMB прогноз</div>
          <div class="cf-forecast-seg-val">${fmtR(forecastSmb)}</div>
          <div class="cf-forecast-seg-sub">YTD ${fmtR(smbYtd)} + Риск ${fmtR(prob3Smb)}</div>
          ${devSmbPct !== null && targetSmb > 0 ? (() => {
            const tl = trafficLight(devSmbPct);
            return `<div class="cf-forecast-seg-dev ${devCls(devSmbPct)}">
              <span class="tl-indicator ${tl.cls}" title="${tl.label}">${tl.icon}</span>
              ${sign(devSmbPct)}${devSmbPct.toFixed(1)}% от плана ${fmtR(targetSmb)}
            </div>`;
          })() : ''}
        </div>
      </div>` : '';

    return `
      <div class="cf-forecast-block">
        <div class="cf-forecast-title">🔮 Прогноз потерь на конец ${d.year} ${tip('Прогноз = Total YTD + MRR клиентов с prob=3 из «Угроза Churn».\nОбновляется при каждой загрузке страницы.\nprob=3 → 100% риска, prob=2 → 60%, prob=1 → 30%.')}</div>
        <div class="cf-forecast-row">
          <div class="cf-forecast-kpi">
            <div class="cf-forecast-lbl">Прогноз года ${tip('Формула: Total YTD (Churn + DownSell факт) + MRR всех клиентов с вероятностью угрозы = 3.\nИсточник prob=3: страница «Угроза Churn» (Airtable).')}</div>
            <div class="cf-forecast-val">${fmtR(fcast)}</div>
            <div class="cf-forecast-sub">YTD ${fmtR(d.totalYtd)} + Риск prob=3 ${fmtR(prob3risk)}</div>
          </div>
          <div class="cf-forecast-kpi">
            <div class="cf-forecast-lbl">Отклонение прогноза от плана ${tip('(Прогноз года − Таргет) / Таргет × 100%.\nТаргет: 8 200 000 ₽/год.\n✅ Ниже нуля = прогноз лучше плана.')}</div>
            <div class="cf-forecast-val ${devCls(devFcast)}">${sign(devFcast)}${devFcast.toFixed(1)}%</div>
            <div class="cf-forecast-sub">${sign(fcast - d.targetTotal)}${fmtR(fcast - d.targetTotal)} от таргета ${fmtR(d.targetTotal)}</div>
          </div>
          <div class="cf-forecast-formula">
            <div class="cf-formula-title">Формула прогноза:</div>
            <div class="cf-formula-body">
              Прогноз = Total YTD + MRR (Угроза prob=3)<br>
              = ${fmtR(d.totalYtd)} + ${fmtR(prob3risk)}<br>
              = <strong>${fmtR(fcast)}</strong>
            </div>
          </div>
        </div>
        ${segRows}
      </div>`;
  }

  // ── Месячная динамика (Блок 9) ────────────────────────────
  function buildMonthlyChart(d) {
    const months = d.byMonth || [];
    if (!months.length) return '';

    const maxVal = Math.max(...months.map(m => m.churn + m.downsell), 1);
    const W = 700, H = 200;
    const PAD = { t: 20, r: 75, b: 40, l: 60 }; // r=75 даёт место для подписи «план/мес»
    const chartW = W - PAD.l - PAD.r;
    const chartH = H - PAD.t - PAD.b;
    const barW   = Math.floor(chartW / months.length) - 3;

    const MONTH_NAMES = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
    const nowMonth = new Date().toISOString().slice(0,7);

    const bars = months.map((m, i) => {
      const x        = PAD.l + i * (chartW / months.length);
      const isFuture = m.month > nowMonth;
      const churnH   = (m.churn    / maxVal) * chartH;
      const dsH      = (m.downsell / maxVal) * chartH;
      const mLabel   = MONTH_NAMES[(parseInt(m.month.slice(5,7)) - 1) % 12];
      const total    = m.churn + m.downsell;
      const opacity  = isFuture ? '0.35' : '0.85';
      const monName  = MONTH_NAMES[(parseInt(m.month.slice(5,7)) - 1) % 12] + ' ' + m.month.slice(0,4);
      const tipText  = [
        monName + (isFuture ? ' (будущий)' : ''),
        '🔴 Churn:    ' + (m.churn > 0 ? fmtR(m.churn) : '—'),
        '🟠 DownSell: ' + (m.downsell > 0 ? fmtR(m.downsell) : '—'),
        '─────────────────',
        '💸 Итого:    ' + (total > 0 ? fmtR(total) : '—'),
      ].join('\n');
      return `
        <g data-tip="${esc(tipText)}" data-month="${m.month}" class="cf-bar-group" style="cursor:pointer">
          <rect x="${x.toFixed(1)}" y="${PAD.t}" width="${barW}" height="${chartH}" fill="transparent"/>
          ${dsH > 0 ? `<rect x="${x.toFixed(1)}" y="${(PAD.t + chartH - churnH - dsH).toFixed(1)}" width="${barW}" height="${dsH.toFixed(1)}" fill="#FF9F0A" opacity="${opacity}" rx="1"/>` : ''}
          ${churnH > 0 ? `<rect x="${x.toFixed(1)}" y="${(PAD.t + chartH - churnH).toFixed(1)}" width="${barW}" height="${churnH.toFixed(1)}" fill="#FF453A" opacity="${opacity}" rx="1"/>` : ''}
          <text x="${(x + barW/2).toFixed(1)}" y="${H - PAD.b + 14}" text-anchor="middle" font-size="10" fill="var(--muted)">${mLabel}</text>
          ${total > 0 ? `<text x="${(x + barW/2).toFixed(1)}" y="${(PAD.t + chartH - churnH - dsH - 4).toFixed(1)}" text-anchor="middle" font-size="9" fill="var(--muted)" opacity="${opacity}">${fmtShort(total)}</text>` : ''}
        </g>`;
    }).join('');

    // Y-axis grid lines
    const yLines = [0.25, 0.5, 0.75, 1.0].map(f => {
      const y = PAD.t + chartH * (1 - f);
      return `<line x1="${PAD.l}" y1="${y.toFixed(1)}" x2="${PAD.l + chartW}" y2="${y.toFixed(1)}" stroke="var(--border,rgba(255,255,255,0.08))" stroke-width="1"/>
              <text x="${PAD.l - 5}" y="${(y+4).toFixed(1)}" text-anchor="end" font-size="9" fill="var(--muted)">${fmtShort(maxVal*f)}</text>`;
    }).join('');

    // Plan line: monthly target = targetTotal / 12
    const monthlyTarget = (d.targetTotal || 0) / 12;
    const planY = PAD.t + chartH * (1 - Math.min(monthlyTarget / maxVal, 1));
    const planLine = monthlyTarget > 0 && monthlyTarget <= maxVal ? `
      <line x1="${PAD.l}" y1="${planY.toFixed(1)}" x2="${PAD.l + chartW}" y2="${planY.toFixed(1)}"
        stroke="#34C759" stroke-width="1.5" stroke-dasharray="6,3" opacity="0.7"/>
      <text x="${PAD.l + chartW + 5}" y="${(planY + 2).toFixed(1)}" font-size="8" fill="#34C759" opacity="0.8">план/мес</text>
      <text x="${PAD.l + chartW + 5}" y="${(planY + 12).toFixed(1)}" font-size="9" fill="#34C759" opacity="1" font-weight="600">${fmtShort(monthlyTarget)}</text>` : '';

    return `
      <div class="cf-section" style="margin-top:16px">
        <div class="cf-section-head">
          <h2>📅 Динамика потерь по месяцам ${tip('Помесячная динамика Churn + DownSell.\nПунктирная линия = Таргет / 12 месяцев (плановые потери в месяц).\nПрозрачные столбцы = будущие месяцы (данных ещё нет).\nИсточник: Airtable (Churn) + Google Sheets (DownSell).')}</h2>
        </div>
        <div style="display:flex;gap:16px;padding:6px 16px;font-size:0.75rem;flex-wrap:wrap">
          <span style="color:#FF453A">▌ Churn</span>
          <span style="color:#FF9F0A">▌ DownSell</span>
          <span style="color:#34C759">╌ План/мес (таргет ÷ 12)</span>
          <span style="color:var(--muted)">Прозрачные = будущие месяцы</span>
        </div>
        <div style="padding:0 12px 12px;overflow-x:auto">
          <svg viewBox="0 0 ${W} ${H}" width="100%" style="min-width:${W}px">
            ${yLines}
            ${planLine}
            ${bars}
          </svg>
        </div>
      </div>`;
  }

  // ── Динамика по сегментам ENT vs SMB/SME (Блок 9.2) ─────
  function buildSegmentChart(d) {
    const months = d.byMonth || [];
    const bySeg  = d.byMonthSegment || {};  // {month: {ent, smb}}
    if (!months.length) return '';

    const hasSegData = Object.values(bySeg).some(v => (v.ent || 0) + (v.smb || 0) > 0);
    if (!hasSegData) return '';

    const W = 700, H = 180;
    const PAD = { t: 20, r: 75, b: 40, l: 60 }; // r=75 даёт место для «ENT план» / «SMB план»
    const chartW = W - PAD.l - PAD.r;
    const chartH = H - PAD.t - PAD.b;
    const barSlot = chartW / months.length;
    const barW    = Math.max(4, Math.floor(barSlot * 0.4));

    const maxVal = Math.max(...months.map(m => {
      const s = bySeg[m.month] || {};
      return (s.ent || 0) + (s.smb || 0);
    }), 1);

    const MONTH_NAMES = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
    const nowMonth = new Date().toISOString().slice(0,7);

    // Monthly targets for plan line
    const entMonthly = (d.targetEnt || 0) / 12;
    const smbMonthly = (d.targetSmb || 0) / 12;

    const bars = months.map((m, i) => {
      const s        = bySeg[m.month] || { ent: 0, smb: 0 };
      const entV     = s.ent || 0;
      const smbV     = s.smb || 0;
      const isFuture = m.month > nowMonth;
      const op       = isFuture ? '0.35' : '0.85';
      const baseX    = PAD.l + i * barSlot + barSlot * 0.1;
      const entH     = (entV / maxVal) * chartH;
      const smbH     = (smbV / maxVal) * chartH;
      const mLabel   = MONTH_NAMES[(parseInt(m.month.slice(5,7)) - 1) % 12];
      const monName  = mLabel + ' ' + m.month.slice(0,4);
      const total    = entV + smbV;
      const tipText  = [
        monName + (isFuture ? ' (будущий)' : ''),
        '🏢 ENT:      ' + (entV > 0 ? fmtR(entV) : '—'),
        '📦 SMB/SME: ' + (smbV > 0 ? fmtR(smbV) : '—'),
        '─────────────────',
        '💸 Итого:   ' + (total > 0 ? fmtR(total) : '—'),
      ].join('\n');
      return `
        <g data-tip="${esc(tipText)}" style="cursor:pointer">
          <rect x="${baseX.toFixed(1)}" y="${PAD.t}" width="${(barW * 2 + 2).toFixed(1)}" height="${chartH}" fill="transparent"/>
          ${entH > 0 ? `<rect x="${baseX.toFixed(1)}" y="${(PAD.t + chartH - entH).toFixed(1)}" width="${barW}" height="${entH.toFixed(1)}" fill="#7B61FF" opacity="${op}" rx="1"/>` : ''}
          ${smbH > 0 ? `<rect x="${(baseX + barW + 2).toFixed(1)}" y="${(PAD.t + chartH - smbH).toFixed(1)}" width="${barW}" height="${smbH.toFixed(1)}" fill="#FF9F0A" opacity="${op}" rx="1"/>` : ''}
          <text x="${(baseX + barW).toFixed(1)}" y="${H - PAD.b + 14}" text-anchor="middle" font-size="10" fill="var(--muted)">${mLabel}</text>
        </g>`;
    }).join('');

    // Y-axis
    const yLines = [0.5, 1.0].map(f => {
      const y = PAD.t + chartH * (1 - f);
      return `<line x1="${PAD.l}" y1="${y.toFixed(1)}" x2="${PAD.l + chartW}" y2="${y.toFixed(1)}" stroke="var(--border,rgba(255,255,255,0.08))" stroke-width="1"/>
              <text x="${PAD.l - 5}" y="${(y+4).toFixed(1)}" text-anchor="end" font-size="9" fill="var(--muted)">${fmtShort(maxVal*f)}</text>`;
    }).join('');

    // Plan lines — если лейблы ENT/SMB накладываются, разносим по вертикали
    const entPlanY = entMonthly > 0 ? PAD.t + chartH * (1 - Math.min(entMonthly / maxVal, 1)) : null;
    const smbPlanY = smbMonthly > 0 ? PAD.t + chartH * (1 - Math.min(smbMonthly / maxVal, 1)) : null;
    const LABEL_H  = 26; // высота блока «лейбл + значение»
    // Рассчитываем Y для лейблов с учётом возможного перекрытия
    let entLblY = entPlanY;
    let smbLblY = smbPlanY;
    if (entPlanY !== null && smbPlanY !== null && Math.abs(entPlanY - smbPlanY) < LABEL_H) {
      // Ставим ENT выше (меньшая потеря = выше линия), SMB ниже
      if (entPlanY <= smbPlanY) {
        smbLblY = entPlanY + LABEL_H;
      } else {
        entLblY = smbPlanY + LABEL_H;
      }
    }
    const planLines = [
      entPlanY !== null ? `<line x1="${PAD.l}" y1="${entPlanY.toFixed(1)}" x2="${PAD.l+chartW}" y2="${entPlanY.toFixed(1)}" stroke="#7B61FF" stroke-width="1" stroke-dasharray="5,3" opacity="0.5"/>
        <text x="${PAD.l+chartW+5}" y="${(entLblY+2).toFixed(1)}" font-size="8" fill="#7B61FF" opacity="0.85" font-weight="600">ENT план</text>
        <text x="${PAD.l+chartW+5}" y="${(entLblY+13).toFixed(1)}" font-size="9" fill="#7B61FF" opacity="1" font-weight="700">${fmtShort(entMonthly)}</text>` : '',
      smbPlanY !== null ? `<line x1="${PAD.l}" y1="${smbPlanY.toFixed(1)}" x2="${PAD.l+chartW}" y2="${smbPlanY.toFixed(1)}" stroke="#FF9F0A" stroke-width="1" stroke-dasharray="5,3" opacity="0.5"/>
        <text x="${PAD.l+chartW+5}" y="${(smbLblY+2).toFixed(1)}" font-size="8" fill="#FF9F0A" opacity="0.85" font-weight="600">SMB план</text>
        <text x="${PAD.l+chartW+5}" y="${(smbLblY+13).toFixed(1)}" font-size="9" fill="#FF9F0A" opacity="1" font-weight="700">${fmtShort(smbMonthly)}</text>` : '',
    ].join('');

    return `
      <div class="cf-section" style="margin-top:16px">
        <div class="cf-section-head">
          <h2>🏢 Динамика по сегментам — ENT vs SMB/SME ${tip('Сравнение потерь ENT и SMB/SME по месяцам.\nENT = Enterprise (Segment = ENT в Airtable).\nSMB = все остальные сегменты (SS, SMB, SME-, SME, SME+).\nПунктир = месячный план по каждому сегменту.')}</h2>
        </div>
        <div style="display:flex;gap:16px;padding:6px 16px;font-size:0.75rem;flex-wrap:wrap">
          <span style="color:#7B61FF">▌ Enterprise</span>
          <span style="color:#FF9F0A">▌ SMB / SME</span>
          <span style="color:var(--muted)">Пунктир = месячный план</span>
        </div>
        <div style="padding:0 12px 12px;overflow-x:auto">
          <svg viewBox="0 0 ${W} ${H}" width="100%" style="min-width:${W}px">
            ${yLines}
            ${planLines}
            ${bars}
          </svg>
        </div>
      </div>`;
  }

  // ── Waterfall: сжигание бюджета потерь ───────────────────
  function buildWaterfallChart(d) {
    const plan      = d.targetTotal   || 0;
    const churn     = d.churnYtd      || 0;
    const ds        = d.downsellYtd   || 0;
    const risk      = d.prob3risk     || 0;
    const forecast  = d.forecastYear  || 0;
    if (!plan) return '';

    const remaining = Math.max(0, plan - forecast);
    const over      = Math.max(0, forecast - plan);

    const W = 680, H = 90;
    const PAD = { l: 120, r: 80, t: 12, b: 12 };
    const trackW = W - PAD.l - PAD.r;

    const pct = v => Math.min((v / plan) * trackW, trackW);
    const fmtPct = v => plan > 0 ? (v / plan * 100).toFixed(1) + '%' : '';

    // Строки waterfall
    const rows = [
      { label: 'Таргет потерь',   val: plan,    fill: '#5AC8FA',  pctX: 0,                   w: pct(plan),    dashed: false },
      { label: 'Факт Churn YTD',  val: churn,   fill: '#FF453A',  pctX: 0,                   w: pct(churn),   dashed: false },
      { label: 'Факт DownSell YTD', val: ds,    fill: '#FF9F0A',  pctX: pct(churn),          w: pct(ds),      dashed: false },
      { label: 'Риск (prob=3)',   val: risk,    fill: '#BF5AF2',  pctX: pct(churn + ds),     w: pct(risk),    dashed: true  },
      { label: 'Прогноз итого',   val: forecast,fill: forecast > plan ? '#FF453A' : '#34C759', pctX: 0, w: pct(forecast), dashed: false },
    ];

    const rowH   = (H - PAD.t - PAD.b) / rows.length;
    const barH   = Math.max(8, rowH - 6);
    const barOff = (rowH - barH) / 2;

    const svgRows = rows.map((r, i) => {
      const y   = PAD.t + i * rowH + barOff;
      const x   = PAD.l + r.pctX;
      const tip = `${r.label}: ${fmtR(r.val)} (${fmtPct(r.val)} от таргета)`;
      return `
        <g data-tip="${esc(tip)}">
          <text x="${PAD.l - 6}" y="${(y + barH/2 + 4).toFixed(1)}" text-anchor="end" font-size="9.5" fill="var(--muted)">${esc(r.label)}</text>
          <rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${Math.max(2, r.w).toFixed(1)}" height="${barH}"
            fill="${r.fill}" opacity="0.85" rx="2"
            ${r.dashed ? 'stroke="' + r.fill + '" stroke-dasharray="4,2" fill-opacity="0.35"' : ''}/>
          ${r.w > 30 ? `<text x="${(x + r.w + 4).toFixed(1)}" y="${(y + barH/2 + 4).toFixed(1)}" font-size="9" fill="${r.fill}" font-weight="600">${fmtShort(r.val)}</text>` : ''}
        </g>`;
    }).join('');

    // Вертикальная линия таргета
    const planLine = `<line x1="${PAD.l}" y1="${PAD.t}" x2="${PAD.l}" y2="${H - PAD.b}" stroke="var(--cf-border)" stroke-width="1"/>
      <line x1="${(PAD.l + pct(plan)).toFixed(1)}" y1="${PAD.t}" x2="${(PAD.l + pct(plan)).toFixed(1)}" y2="${H - PAD.b}" stroke="#5AC8FA" stroke-width="1" stroke-dasharray="3,2" opacity="0.5"/>`;

    const overNote = over > 0 ? `<span style="color:#FF453A;font-size:0.78rem;font-weight:700">⚠ Перебор таргета на ${fmtR(over)}</span>` : `<span style="color:#34C759;font-size:0.78rem">✓ Буфер ${fmtR(remaining)}</span>`;

    return `
      <div class="cf-section" style="margin-top:8px">
        <div class="cf-section-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <h2>🌊 Бюджет потерь ${tip('Как расходуется годовой таргет потерь.\nЧёрн + DownSell факт = потраченный бюджет.\nРиск prob=3 = прогноз дополнительных потерь.\nПрогноз итого vs Таргет = отклонение.')}</h2>
          ${overNote}
        </div>
        <div style="padding:0 12px 12px">
          <svg viewBox="0 0 ${W} ${H}" width="100%">
            ${planLine}
            ${svgRows}
          </svg>
        </div>
      </div>`;
  }

  // ── Export CSV helper ─────────────────────────────────────
  function exportCsv(rows, filename) {
    if (!rows?.length) return;
    const keys = Object.keys(rows[0]);
    const lines = [
      keys.join(';'),
      ...rows.map(r => keys.map(k => {
        const v = r[k] ?? '';
        const s = String(v).replace(/"/g, '""');
        return s.includes(';') || s.includes('"') || s.includes('\n') ? `"${s}"` : s;
      }).join(';'))
    ];
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  // ── Средний ряд: по продуктам, CSM, причинам ─────────────
  function buildMidRow(d) {
    return `
      <div class="cf-mid-row">
        ${buildProductSection(d)}
        ${buildCsmSection(d)}
      </div>
      <div class="cf-mid-row">
        ${buildReasonSection(d)}
        ${buildDsReasonSection(d)}
      </div>
      <div class="cf-mid-row">
        ${buildDsCsmSection(d)}
        ${buildVerticalSection(d)}
      </div>`;
  }

  function buildProductSection(d) {
    if (!d.byProduct?.length) return '<div class="cf-card"></div>';
    const maxV = Math.max(...d.byProduct.map(p => p.total), 1);
    const COLORS = {
      AnyQuery:'#7B61FF', AQ:'#7B61FF',
      AnyRecs:'#34C759',  Recs:'#34C759',
      AnyImages:'#30D5C8',
      AnyReviews:'#5AC8FA',
      AnyCollections:'#FF9F0A', AC:'#FF9F0A',
      APP:'#BF5AF2',
      Rees46:'#FF453A',
    };
    const rows = d.byProduct.map(p => {
      const w = (p.total / maxV * 100).toFixed(1);
      const col = COLORS[p.product] || 'var(--cf-accent)';
      return `<tr>
        <td><span class="prod-tag" style="background:${col}22;border-color:${col}44;color:${col}">${esc(p.product)}</span></td>
        <td class="num" style="color:#FF453A">${p.churn > 0 ? fmtR(p.churn) : '—'}</td>
        <td class="num" style="color:#FF9F0A">${p.downsell > 0 ? fmtR(p.downsell) : '—'}</td>
        <td class="num strong">${fmtR(p.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:${col}"></div></div></td>
      </tr>`;
    }).join('');
    return `
      <div class="cf-card">
        <div class="cf-card-head"><h3>По продуктам ${tip('Группировка потерь по продуктам.\nНормализация: AQ → AnyQuery, AC → AnyCollections и т.д.\nИсточник Churn: Airtable (отдельная вьюшка на каждый продукт).\nИсточник DownSell: Google Sheets, колонка «Продукт».')}</h3></div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr><th>Продукт</th><th class="th-num" style="color:#FF453A">Churn MRR</th><th class="th-num" style="color:#FF9F0A">DownSell MRR</th><th class="th-num">Итого</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>`;
  }

  function buildCsmSection(d) {
    if (!d.byCsm?.length) return `<div class="cf-card"><div class="cf-card-head"><h3>По CSM ${tip('Потери выручки по менеджерам CSM.\nИсточник: Google Sheets (Churn и DownSell вкладки), колонка «CSM менеджер».\nEmail нормализуются в имена по внутреннему справочнику.')}</h3></div><p class="empty-state">Нет данных (CSM указан только в DownSell)</p></div>`;
    const rows = d.byCsm.map(c => `<tr>
      <td class="strong">${esc(c.csm)}</td>
      <td class="num" style="color:#FF453A">${c.churn > 0 ? fmtR(c.churn) : '—'}</td>
      <td class="num" style="color:#FF9F0A">${c.downsell > 0 ? fmtR(c.downsell) : '—'}</td>
      <td class="num strong">${fmtR(c.total)}</td>
    </tr>`).join('');
    return `
      <div class="cf-card">
        <div class="cf-card-head"><h3>По CSM ${tip('Потери выручки по менеджерам CSM.\nИсточник: Google Sheets (Churn и DownSell вкладки), колонка «CSM менеджер».\nEmail нормализуются в имена по внутреннему справочнику.')}</h3></div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr><th>CSM</th><th class="th-num" style="color:#FF453A">Churn MRR</th><th class="th-num" style="color:#FF9F0A">DownSell MRR</th><th class="th-num">Итого</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>`;
  }

  function buildReasonSection(d) {
    const reasons = d.byChurnReason || d.byReason || [];
    if (!reasons.length) return `<div class="cf-card"><div class="cf-card-head"><h3>🔴 Причины Churn ${tip('Классификация причин ухода клиентов.\nИсточник: Airtable, поле «Классификация причины ухода AQ» / «Классификация причины ухода».\nТоп-10 причин по MRR.')}</h3></div><p class="empty-state">Нет данных</p></div>`;
    const maxV = Math.max(...reasons.map(r => r.total), 1);
    const rows = reasons.slice(0, 10).map(r => {
      const w = (r.total / maxV * 100).toFixed(1);
      return `<tr>
        <td style="font-size:0.78rem">${esc(r.reason)}</td>
        <td class="num strong" style="color:#FF453A">${fmtR(r.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:#FF453A"></div></div></td>
      </tr>`;
    }).join('');
    return `
      <div class="cf-card">
        <div class="cf-card-head"><h3>🔴 Причины Churn ${tip('Классификация причин ухода клиентов.\nИсточник: Airtable, поле «Классификация причины ухода AQ» / «Классификация причины ухода».\nТоп-10 причин по MRR.')}</h3></div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr><th>Причина</th><th class="th-num">Churn MRR</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>`;
  }

  function buildDsReasonSection(d) {
    const reasons = d.byDsReason || [];
    if (!reasons.length) {
      const dbg = d._dsDebug || {};
      const hdrs = (d._dsHeaders || []).join(', ') || '—';
      const debugHtml = `
        <details style="margin:8px 12px;font-size:0.72rem;color:var(--cf-muted,#888);text-align:left">
          <summary style="cursor:pointer;user-select:none">🔍 Отладка (нажмите чтобы раскрыть)</summary>
          <div style="margin-top:6px;line-height:1.6">
            <b>Строк в CSV:</b> ${dbg.totalLines ?? '?'}<br>
            <b>Не прошли фильтр типа (не "down"):</b> ${dbg.failedTypeFilter ?? '?'}<br>
            <b>Значения колонки типа:</b> ${(dbg.typeValues || []).join(' | ') || '—'}<br>
            <b>Не прошли фильтр "Постоянная":</b> ${dbg.failedKindFilter ?? '?'}<br>
            <b>Изменение = 0 или не найдено:</b> ${dbg.failedChangeFilter ?? '?'}<br>
            <b>Прошли все фильтры:</b> ${dbg.passed ?? '?'}<br>
            <b>Ошибка:</b> ${dbg.error ?? 'нет'}<br>
            <b>Заголовки CSV:</b><br><span style="word-break:break-all">${esc(hdrs)}</span>
          </div>
        </details>`;
      return `<div class="cf-card"><div class="cf-card-head"><h3>Причины DownSell</h3></div><p class="empty-state">Нет данных</p>${debugHtml}</div>`;
    }
    const maxV = Math.max(...reasons.map(r => r.total), 1);
    const rows = reasons.slice(0, 10).map(r => {
      const w = (r.total / maxV * 100).toFixed(1);
      return `<tr>
        <td style="font-size:0.78rem">${esc(r.reason)}</td>
        <td class="num strong" style="color:#FF9F0A">${fmtR(r.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:#FF9F0A"></div></div></td>
      </tr>`;
    }).join('');
    return `
      <div class="cf-card">
        <div class="cf-card-head"><h3>🟠 Причины DownSell ${tip('Классификация причин снижения MRR.\nИсточник: Google Sheets, вкладка UpSale/DownSell,\nколонка «Классификация причины скидки».\nФильтр: тип = Постоянная. Топ-10 по MRR.')}</h3></div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr><th>Причина</th><th class="th-num">DownSell MRR</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>`;
  }

  // ── DownSell по менеджерам ────────────────────────────────
  // Агрегируем из dsDetail (а не из byCsm) — чтобы работал фильтр по месяцу
  function buildDsCsmSection(d) {
    let rows = d.dsDetail || [];

    // Применить фильтр месяца (если выбран кликом на баре)
    if (state.monthFrom) rows = rows.filter(r => !r.month || r.month >= state.monthFrom);
    if (state.monthTo)   rows = rows.filter(r => !r.month || r.month <= state.monthTo);

    // Группировать по CSM
    const map = {};
    rows.forEach(r => {
      const csm = (r.csm && r.csm.trim()) ? r.csm.trim() : 'Не указан';
      map[csm] = (map[csm] || 0) + (r.mrr || 0);
    });

    const sorted = Object.entries(map)
      .map(([csm, total]) => ({ csm, total }))
      .filter(r => r.total > 0)
      .sort((a, b) => b.total - a.total);

    if (!sorted.length) return '';

    const maxV = Math.max(...sorted.map(r => r.total), 1);
    const totalAll = sorted.reduce((s, r) => s + r.total, 0);

    const periodLabel = state.monthFrom
      ? (state.monthFrom === state.monthTo ? state.monthFrom : `${state.monthFrom} – ${state.monthTo}`)
      : 'YTD';

    const tableRows = sorted.map(r => {
      const w   = (r.total / maxV * 100).toFixed(1);
      const pct = totalAll > 0 ? (r.total / totalAll * 100).toFixed(0) : 0;
      return `<tr>
        <td class="strong">${esc(r.csm)}</td>
        <td class="num" style="color:#FF9F0A;font-weight:600">${fmtR(r.total)}</td>
        <td class="muted" style="text-align:right;font-size:0.75rem">${pct}%</td>
        <td style="width:90px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:#FF9F0A"></div></div></td>
      </tr>`;
    }).join('');

    return `
      <div class="cf-card">
        <div class="cf-card-head">
          <h3>🟠 DownSell по менеджерам ${tip('Сумма DownSell по каждому CSM-менеджеру.\nИсточник: Google Sheets, вкладка UpSale/DownSell, колонка «CSM менеджер».\nКлик на столбце графика фильтрует эту таблицу по месяцу.')}</h3>
          <span style="font-size:0.72rem;color:var(--muted);margin-left:auto">${periodLabel}</span>
        </div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr>
            <th>Менеджер</th>
            <th class="th-num" style="color:#FF9F0A">DownSell MRR</th>
            <th class="th-num" style="color:var(--muted)">Доля</th>
            <th></th>
          </tr></thead>
          <tbody>${tableRows}</tbody>
        </table></div>
        <div style="padding:6px 12px 10px;font-size:0.75rem;color:var(--muted);border-top:1px solid var(--cf-border)">
          Итого: <strong style="color:#FF9F0A">${fmtR(totalAll)}</strong>
          &nbsp;·&nbsp; ${sorted.length} менеджер${sorted.length === 1 ? '' : sorted.length < 5 ? 'а' : 'ов'}
        </div>
      </div>`;
  }

  function buildVerticalSection(d) {
    if (!d.byVertical?.length) return `<div class="cf-card"><div class="cf-card-head"><h3>По вертикалям ${tip('Потери Churn по отраслевым вертикалям.\nИсточник: Airtable, поле «Вертикаль».\nТолько Churn (не DownSell). Топ-10 по MRR.')}</h3></div><p class="empty-state">Нет данных</p></div>`;
    const maxV = Math.max(...d.byVertical.map(v => v.total), 1);
    const rows = d.byVertical.slice(0, 10).map(v => {
      const w = (v.total / maxV * 100).toFixed(1);
      return `<tr>
        <td class="strong">${esc(v.vertical)}</td>
        <td class="num">${fmtR(v.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:#FF453A"></div></div></td>
      </tr>`;
    }).join('');
    return `
      <div class="cf-card">
        <div class="cf-card-head"><h3>По вертикалям (Churn) ${tip('Потери Churn по отраслевым вертикалям.\nИсточник: Airtable, поле «Вертикаль».\nТолько Churn (не DownSell). Топ-10 по MRR.')}</h3></div>
        <div class="table-wrap"><table class="cf-table">
          <thead><tr><th>Вертикаль</th><th>Churn MRR</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>`;
  }

  // ── Таблицы (Блок 11) ─────────────────────────────────────
  function buildTables(d) {
    const isChurn = state.tab === 'churn';
    const churnRows = (d.churnDetail || []).map(r => `<tr>
      <td class="muted">${esc(r.month)}</td>
      <td class="strong">${esc(r.account)}</td>
      <td><span class="prod-tag">${esc(r.product)}</span></td>
      <td class="num" style="color:#FF453A;font-weight:700">${fmtR(r.mrr)}</td>
      <td class="muted">${esc(r.replacement||'—')}</td>
      <td class="muted" style="font-size:0.75rem">${esc(r.reason||'—')}</td>
      <td class="muted">${esc(r.vertical||'—')}</td>
    </tr>`).join('');

    const dsRows = (d.dsDetail || []).map(r => `<tr>
      <td class="muted">${esc(r.month)}</td>
      <td class="strong">${esc(r.account)}</td>
      <td class="muted">${esc(r.csm||'—')}</td>
      <td><span class="prod-tag">${esc(r.product)}</span></td>
      <td class="num" style="color:#FF9F0A;font-weight:700">${fmtR(r.mrr)}</td>
      <td class="muted" style="font-size:0.75rem">${esc(r.reason||'—')}</td>
    </tr>`).join('');

    return `
      <div class="cf-section" style="margin-top:16px">
        <div class="cf-section-head">
          <h2>📋 Детальные таблицы</h2>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <div class="cf-tabs">
              <button class="cf-tab ${isChurn?'active':''}" data-tab="churn">
                🔴 Ушедшие клиенты (${d.churnDetail?.length || 0})
              </button>
              <button class="cf-tab ${!isChurn?'active':''}" data-tab="downsell">
                🟠 DownSell (${d.dsDetail?.length || 0})
              </button>
            </div>
            <input type="search" id="cf-client-search" class="cf-search-input" placeholder="Поиск по клиенту…" autocomplete="off">
            <button id="btn-export-churn" class="btn-export" style="${isChurn?'':'display:none'}" title="Скачать CSV">⬇ CSV</button>
            <button id="btn-export-ds"    class="btn-export" style="${!isChurn?'':'display:none'}" title="Скачать CSV">⬇ CSV</button>
          </div>
        </div>

        ${isChurn ? `
        <div class="table-wrap">
          <table class="cf-table" style="min-width:750px">
            <thead><tr>
              <th>Месяц</th><th>Клиент</th><th>Продукт</th>
              <th>MRR</th><th>Замена</th><th>Причина</th><th>Вертикаль</th>
            </tr></thead>
            <tbody>${churnRows || '<tr><td colspan="7" class="empty-state">Нет данных по Churn</td></tr>'}</tbody>
          </table>
        </div>` : `
        <div class="table-wrap">
          <table class="cf-table" style="min-width:650px">
            <thead><tr>
              <th>Месяц</th><th>Клиент</th><th>CSM</th>
              <th>Продукт</th><th>DownSell MRR</th><th>Причина</th>
            </tr></thead>
            <tbody>${dsRows || '<tr><td colspan="6" class="empty-state">Нет данных по DownSell</td></tr>'}</tbody>
          </table>
        </div>`}
      </div>`;
  }

  // ── Events ────────────────────────────────────────────────
  function attachEvents() {
    document.getElementById('btn-refresh')?.addEventListener('click', doRefresh);
    document.getElementById('btn-theme')?.addEventListener('click', () => {
      const root = document.getElementById('html-root');
      const next = root?.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root?.setAttribute('data-theme', next);
      localStorage.setItem('aq_theme', next);
      render();
    });
    document.querySelectorAll('.cf-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        state.tab = btn.dataset.tab;
        render();
      });
    });

    // Фильтр диапазона месяцев (F4)
    document.getElementById('cf-month-from')?.addEventListener('change', e => {
      state.monthFrom = e.target.value;
      // Если from > to — подтягиваем to
      if (state.monthTo && state.monthFrom > state.monthTo) state.monthTo = state.monthFrom;
      render();
    });
    document.getElementById('cf-month-to')?.addEventListener('change', e => {
      state.monthTo = e.target.value;
      if (state.monthFrom && state.monthTo < state.monthFrom) state.monthFrom = state.monthTo;
      render();
    });
    document.getElementById('cf-period-reset')?.addEventListener('click', () => {
      state.monthFrom = null;
      state.monthTo   = null;
      render();
    });

    // Клик по столбцу графика → фильтр периода на этот месяц
    document.querySelectorAll('.cf-bar-group').forEach(g => {
      g.addEventListener('click', () => {
        const month = g.dataset.month;
        if (!month) return;
        if (state.monthFrom === month && state.monthTo === month) {
          state.monthFrom = null;
          state.monthTo   = null;
        } else {
          state.monthFrom = month;
          state.monthTo   = month;
        }
        render();
      });
    });

    // Поиск по клиенту — фильтр строк без перерисовки
    document.getElementById('cf-client-search')?.addEventListener('input', e => {
      const q = e.target.value.trim().toLowerCase();
      document.querySelectorAll('.cf-table tbody tr').forEach(row => {
        const cell = row.querySelector('td:nth-child(2)');
        const match = !q || (cell?.textContent?.toLowerCase() ?? '').includes(q);
        row.style.display = match ? '' : 'none';
      });
    });

    // Export CSV кнопки
    document.getElementById('btn-export-churn')?.addEventListener('click', () => {
      exportCsv(state.data?.churnDetail, 'churn_detail.csv');
    });
    document.getElementById('btn-export-ds')?.addEventListener('click', () => {
      exportCsv(state.data?.dsDetail, 'downsell_detail.csv');
    });
  }

  // ── CSRF helper ───────────────────────────────────────────
  const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  // ── Progress bar (shared) ─────────────────────────────────
  let _progressTimer = null;
  function startProgress() {
    let bar = document.getElementById('aq-pbar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'aq-pbar';
      bar.innerHTML = '<div id="aq-pbar-fill"></div>';
      document.body.prepend(bar);
    }
    bar.style.display = ''; bar.style.opacity = '1';
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
      if (bar) { bar.style.opacity = '0'; setTimeout(() => { if (bar) { bar.style.display = 'none'; bar.style.opacity = '1'; } }, 350); }
    }, 250);
  }

  // ── Refresh ───────────────────────────────────────────────
  async function doRefresh() {
    if (state.loading) return;
    state.loading = true;
    startProgress();
    render();
    try {
      const res  = await fetch('churn_fact_api.php', {
        cache: 'no-store',
        headers: { 'X-CSRF-Token': csrfToken() },
      });
      const json = await res.json();
      if (json.ok && json.data) { state.data = json.data; state.loadedAt = Date.now(); }
      else showError(json.error || 'Ошибка получения данных');
    } catch (e) {
      showError(e.message);
    } finally {
      state.loading = false;
      endProgress();
      render();
    }
  }

  function showError(msg) {
    const app = document.getElementById('app');
    if (!app) return;
    const el = document.createElement('div');
    el.className = 'cf-error';
    el.innerHTML = `⚠ ${esc(msg)} <button onclick="this.parentNode.remove()" style="margin-left:12px;background:none;border:none;color:inherit;cursor:pointer">✕</button>`;
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

  // ── Init ──────────────────────────────────────────────────
  function init() {
    initTooltip();
    const el = document.getElementById('fact-bootstrap');
    if (el && el.textContent.trim()) {
      try {
        state.data = JSON.parse(el.textContent);
        if (state.data) state.loadedAt = Date.now();
        if (state.data) {
          const hasReasons = (state.data.byDsReason?.length ?? 0) > 0;
          const hasDs      = (state.data._rawDs ?? 0) > 0;
          if (!hasDs || !hasReasons) {
            console.info('[DownSell] Заголовки CSV:', state.data._dsHeaders);
            console.info('[DownSell] Фильтрация:', state.data._dsDebug);
          }
        }
      } catch (e) { showError('Ошибка разбора данных: ' + e.message); }
    }
    render();
    scheduleAuto();

    if (!state.data) {
      // Кэша нет — грузим асинхронно
      doRefresh();
    } else if (state.data._stale) {
      setTimeout(() => { if (!state.loading) doRefresh(); }, 2000);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

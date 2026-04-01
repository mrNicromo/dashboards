/* ============================================================
   ДЗ — Дашборд руководителя  |  manager.js  v2
   ============================================================ */

(function () {
  'use strict';

  // ─── Константы ────────────────────────────────────────────
  const GROUPS     = ['16-30', '31-60', '61-90', '91+'];
  const GRP_LABELS = { '16-30': '16–30 дн.', '31-60': '31–60 дн.', '61-90': '61–90 дн.', '91+': '91+ дн.' };
  const GRP_CSS    = { '16-30': 'grp-16-30', '31-60': 'grp-31-60', '61-90': 'grp-61-90', '91+': 'grp-91plus' };
  const BAR_CSS    = { '16-30': 'bar-16-30', '31-60': 'bar-31-60', '61-90': 'bar-61-90', '91+': 'bar-91plus' };
  const CHIP_CSS   = { '16-30': 'chip-16-30', '31-60': 'chip-31-60', '61-90': 'chip-61-90', '91+': 'chip-91plus' };
  const LS_KEY     = 'mgr_excl_v1';
  const AUTO_MS    = 5 * 60 * 1000; // 5 минут

  // ─── Форматтеры ───────────────────────────────────────────
  const fmtRub  = n  => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n)) + '\u00a0₽';
  const fmtPct  = n  => `${n}%`;
  const fmtDate = s  => {
    if (!s) return '—';
    const p = String(s).slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s;
  };
  const esc = s => String(s || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const fullMgr = name => {
    if (!name) return '—';
    // Если пришёл email — превращаем в имя, иначе показываем как есть
    if (name.includes('@')) {
      const local = name.split('@')[0].replace(/[._]/g, ' ');
      return local.split(' ').map(p => capitalize(p)).join(' ');
    }
    return name;
  };
  const capitalize = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
  const siteBadge = site => {
    if (!site) return '';
    // Убираем протокол для отображения, сохраняем для href
    const display = site.replace(/^https?:\/\//i, '').replace(/\/$/, '');
    const href = site.startsWith('http') ? site : 'https://' + site;
    return `<a class="site-badge" href="${esc(href)}" target="_blank" rel="noopener" title="${esc(site)}">${esc(display)}</a>`;
  };

  // ─── Состояние ────────────────────────────────────────────
  const state = {
    data:           null,
    churnData:      null,   // данные угрозы churn (churn-report.json)
    factData:       null,   // данные фактического churn (churn-fact-report.json)
    mainTab:        'dz',   // 'dz' | 'churn' | 'fact'
    groups:         new Set(GROUPS),
    excluded:       new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]')),
    sortCol:        'total',
    sortDir:        'desc',
    detailSortCol:  'amount',
    detailSortDir:  'desc',
    search:         '',
    detailFilters: { client: '', managers: new Set(), group: '', amtMin: '', amtMax: '', direction: '', status: '', company: '' },
    loading:        false,
    showHiddenPanel: false,
    detailPage:     0,
    modalClient:    null,
    detailGrouped:  false,
  };

  const saveExcluded = () => localStorage.setItem(LS_KEY, JSON.stringify([...state.excluded]));

  // ─── Комментарии к клиентам (localStorage) ────────────────
  const COMMENTS_KEY = 'aq_comments_v1';
  const loadComments = () => { try { return JSON.parse(localStorage.getItem(COMMENTS_KEY) || '{}'); } catch { return {}; } };
  const saveComment  = (client, text) => {
    const c = loadComments();
    if (text.trim()) c[client] = text.trim(); else delete c[client];
    localStorage.setItem(COMMENTS_KEY, JSON.stringify(c));
  };

  // Состояние открытого выпадающего списка менеджеров — не вызывает перерисовку
  let mgrDropOpen = false;

  // ─── Пересчёт данных с учётом активных фильтров ───────────
  function computeFiltered() {
    if (!state.data) return { top10: [], top10Total: 0, top10Pct: 0, totalDebt: 0, grpTotals: {}, uniqueClients: 0 };
    const rows = state.data.allRows;
    const clientMap = {};
    const grpTotals = Object.fromEntries(GROUPS.map(g => [g, 0]));
    let totalDebt = 0;

    for (const row of rows) {
      if (!state.groups.has(row.group))     continue;
      if (state.excluded.has(row.client))   continue;
      totalDebt += row.amount;
      grpTotals[row.group] += row.amount;
      if (!clientMap[row.client]) {
        clientMap[row.client] = { client: row.client, site: row.site || '', total: 0, groups: Object.fromEntries(GROUPS.map(g => [g, 0])), manager: row.manager };
      }
      if (!clientMap[row.client].site && row.site) clientMap[row.client].site = row.site;
      clientMap[row.client].total             += row.amount;
      clientMap[row.client].groups[row.group] += row.amount;
    }

    const allSorted = Object.values(clientMap).sort((a, b) => b.total - a.total);
    const top10     = allSorted.slice(0, 10);
    const top10Total = top10.reduce((s, c) => s + c.total, 0);
    const top10Pct   = totalDebt > 0 ? +(top10Total / totalDebt * 100).toFixed(1) : 0;

    return { top10, top10Total, top10Pct, totalDebt, grpTotals, uniqueClients: allSorted.length };
  }

  // ─── Главный рендер ───────────────────────────────────────
  function render() {
    const app = document.getElementById('app');
    if (!app) return;
    if (!state.data) {
      app.innerHTML = `<div class="mgr-wrap"><div class="mgr-loading">
        <div class="mgr-spinner"></div><div>Загружаем данные из Airtable…</div>
      </div></div>`;
      return;
    }
    const d = state.data;

    // ── Вкладка Угроза Churn ──────────────────────────────
    if (state.mainTab === 'churn') {
      app.innerHTML = `
        <div class="status-bar${state.loading ? ' loading' : ''}"></div>
        <div class="mgr-wrap">
          ${renderHeader(d)}
          ${renderTabNav()}
          ${renderChurnSummaryTab()}
        </div>`;
      attachTabEvents();
      return;
    }

    // ── Вкладка Факт Churn ────────────────────────────────
    if (state.mainTab === 'fact') {
      app.innerHTML = `
        <div class="status-bar${state.loading ? ' loading' : ''}"></div>
        <div class="mgr-wrap">
          ${renderHeader(d)}
          ${renderTabNav()}
          ${renderFactSummaryTab()}
        </div>`;
      attachTabEvents();
      return;
    }

    // ── Вкладка ДЗ (по умолчанию) ────────────────────────
    const computed = computeFiltered();
    app.innerHTML = `
      <div class="status-bar${state.loading ? ' loading' : ''}"></div>
      <div class="mgr-wrap">
        ${renderHeader(d)}
        ${renderTabNav()}
        ${renderHiddenPanel()}
        ${renderAlertBanner(computed, d)}
        ${renderKpiCards(computed, d)}
        ${renderFocusWeekSection(d, computed)}
        ${renderFocus91MrrSection(d)}
        ${renderTop10Section(computed)}
        ${renderChampionsSection(d, computed)}
        ${renderManagerTableSection(d)}
        ${renderGaugeSection(computed.totalDebt, d.mrr)}
        ${renderAgingTransitionSection(d)}
        ${renderWeeklyDzChart(d)}
        ${renderPaymentsSection(d)}
        ${renderWeeklyPayChart(d)}
        ${renderGroupChartSection(computed.grpTotals, computed.totalDebt)}
        ${renderDetailSection(d)}
      </div>
      ${state.modalClient ? renderClientModal(state.modalClient, d) : ''}`;

    attachEvents();
    updateCountdown();
  }

  // ─── Панель вкладок ───────────────────────────────────────
  function renderTabNav() {
    const tabs = [
      { id: 'dz',    label: '📋 ДЗ',           hint: 'Дебиторская задолженность' },
      { id: 'churn', label: '⚠️ Угроза Churn',  hint: 'Клиенты под риском оттока' },
      { id: 'fact',  label: '📉 Факт Churn',    hint: 'Фактические потери выручки' },
    ];
    const items = tabs.map(t => {
      const active = state.mainTab === t.id;
      return `<button class="main-tab-btn${active ? ' active' : ''}"
        data-tab="${t.id}" title="${t.hint}">${t.label}</button>`;
    }).join('');
    const weeklyLink = `<a class="main-tab-btn" href="weekly.php" title="Еженедельный отчёт по ДЗ (каждую среду)">📆 Еженедельный</a>`;
    return `<div class="main-tab-nav">${items}${weeklyLink}</div>`;
  }

  // ─── Привязка событий для вкладок ─────────────────────
  function attachTabEvents() {
    document.querySelectorAll('[data-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        const t = btn.dataset.tab;
        if (state.mainTab !== t) {
          state.mainTab = t;
          // Обновляем URL без перезагрузки
          const url = new URL(location.href);
          url.searchParams.set('tab', t);
          history.replaceState(null, '', url);
          render();
        }
      });
    });
    // Тема
    document.getElementById('btn-theme-toggle')?.addEventListener('click', toggleTheme);
    // Обновить
    document.getElementById('btn-refresh')?.addEventListener('click', doRefresh);
  }

  // ─── Сводная вкладка: Угроза Churn ────────────────────
  function renderChurnSummaryTab() {
    const d = state.churnData;
    if (!d) {
      return `<div class="mgr-section">
        <p style="color:var(--muted);text-align:center;padding:40px 0">
          Нет данных об угрозе оттока. <a href="churn_api.php?force=1">Обновить кэш</a>
        </p>
      </div>`;
    }

    const fmtR   = n => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n || 0)) + '\u00a0₽';
    const fmtPct = (a, b) => b > 0 ? (a / b * 100).toFixed(0) + '%' : '—';

    // Индикатор здоровья
    const prob3pct = d.totalRisk > 0 ? d.prob3mrr / d.totalRisk : 0;
    const healthCls  = prob3pct >= 0.6 ? 'health-red' : prob3pct >= 0.3 ? 'health-yellow' : 'health-green';
    const healthText = prob3pct >= 0.6 ? '🔴 Критично' : prob3pct >= 0.3 ? '🟡 Внимание' : '🟢 Под контролем';

    // KPI row — ИСПРАВЛЕНО: entProb3 = count, не MRR
    const kpiCards = [
      { label: 'Под риском',   value: fmtR(d.totalRisk),            sub: `${d.count || 0} клиентов`,                      cls: 'warn'   },
      { label: 'Prob=3 (крит)',value: fmtR(d.prob3mrr),             sub: `${d.prob3count || 0} клиентов · ${fmtPct(d.prob3mrr, d.totalRisk)} от риска`, cls: 'danger' },
      { label: 'ENT в риске',  value: String(d.entCount || 0),      sub: `из них Prob=3: ${d.entProb3 || 0} кл.`,         cls: 'info'   },
      { label: 'Прогноз 3м',   value: fmtR(d.forecast3),            sub: 'Prob=2+3 · потери за 3 мес.',                   cls: ''       },
      { label: 'Прогноз 6м',   value: fmtR(d.forecast6),            sub: 'Все уровни · за 6 мес.',                        cls: ''       },
    ].map(c => `
      <div class="cf-kpi-card${c.cls ? ' cf-kpi-' + c.cls : ''}">
        <div class="cf-kpi-label">${c.label}</div>
        <div class="cf-kpi-value">${c.value}</div>
        <div class="cf-kpi-sub">${c.sub}</div>
      </div>`).join('');

    // Стек-бар: Prob3 / Prob2 / Prob1
    const seg = d.bySegment || [];
    let p3 = 0, p2 = 0, p1 = 0;
    seg.forEach(s => { p3 += s.prob?.[3] || 0; p2 += s.prob?.[2] || 0; p1 += s.prob?.[1] || 0; });
    const total = p3 + p2 + p1 || 1;
    const b3 = (p3 / total * 100).toFixed(1), b2 = (p2 / total * 100).toFixed(1), b1 = (p1 / total * 100).toFixed(1);
    const riskBar = `
      <div style="margin:12px 0 4px">
        <div style="font-size:0.72rem;color:var(--muted);margin-bottom:5px;display:flex;justify-content:space-between">
          <span>Структура риска по вероятности</span>
          <span style="color:var(--text);font-weight:600">${fmtR(d.totalRisk)} всего</span>
        </div>
        <div style="height:8px;border-radius:4px;overflow:hidden;display:flex;gap:1px">
          <div style="width:${b3}%;background:#FF453A;border-radius:4px 0 0 4px" title="Prob=3: ${fmtR(p3)}"></div>
          <div style="width:${b2}%;background:#FF9F0A" title="Prob=2: ${fmtR(p2)}"></div>
          <div style="width:${b1}%;background:#30D158;border-radius:0 4px 4px 0" title="Prob=1: ${fmtR(p1)}"></div>
        </div>
        <div style="display:flex;gap:14px;margin-top:5px;font-size:0.72rem">
          <span><span style="color:#FF453A">■</span> Prob=3: ${fmtR(p3)}</span>
          <span><span style="color:#FF9F0A">■</span> Prob=2: ${fmtR(p2)}</span>
          <span><span style="color:#30D158">■</span> Prob=1: ${fmtR(p1)}</span>
        </div>
      </div>`;

    // По продуктам — ИСПРАВЛЕНО: r.mrr (не r.risk)
    const byProd = (d.byProduct || []).slice(0, 6);
    const maxMrr = Math.max(...byProd.map(r => r.mrr || 0), 1);
    const prodRows = byProd.map(r => {
      const w = ((r.mrr || 0) / maxMrr * 100).toFixed(1);
      return `<tr>
        <td style="font-size:0.8rem">${esc(r.product || '—')}</td>
        <td class="num" style="color:var(--warn)">${fmtR(r.mrr)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:var(--warn)"></div></div></td>
        <td class="num muted" style="font-size:0.75rem">${r.count || 0}</td>
      </tr>`;
    }).join('');

    // По CSM — ИСПРАВЛЕНО: r.mrr (не r.risk)
    const byCsm = (d.byCsm || []).slice(0, 8);
    const maxCsmMrr = Math.max(...byCsm.map(r => r.mrr || 0), 1);
    const csmRows = byCsm.map(r => {
      const w       = ((r.mrr || 0) / maxCsmMrr * 100).toFixed(1);
      const p3c     = r.prob3count || 0;
      const p3badge = p3c > 0 ? `<span style="color:#FF453A;font-size:0.7rem;font-weight:700"> · ${p3c} Prob3</span>` : '';
      return `<tr>
        <td style="font-size:0.8rem">${esc(r.csm || '—')}${p3badge}</td>
        <td class="num" style="color:var(--warn)">${fmtR(r.mrr)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:var(--accent)"></div></div></td>
        <td class="num muted" style="font-size:0.75rem">${fmtPct(r.mrr, d.totalRisk)}</td>
      </tr>`;
    }).join('');

    // Топ-3 клиента под риском
    const top3 = (d.clients || []).slice(0, 3);
    const top3html = top3.length ? top3.map((c, i) => {
      const probCls = c.probability === 3 ? 'color:#FF453A' : c.probability === 2 ? 'color:#FF9F0A' : 'color:#30D158';
      return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
        <span style="font-size:0.75rem;color:var(--muted);width:16px">${i+1}</span>
        <span style="flex:1;font-size:0.82rem;font-weight:600">${esc(c.account)}</span>
        <span style="font-size:0.72rem;${probCls};font-weight:700">Prob=${c.probability||'—'}</span>
        <span style="font-size:0.82rem;font-weight:700;color:var(--warn)">${fmtR(c.mrrAtRisk)}</span>
      </div>`;
    }).join('') : '<p class="muted" style="font-size:0.82rem">Нет данных</p>';

    const updatedAt = d.updatedAt || '—';
    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">⚠️ Угроза оттока клиентов
              <span style="margin-left:10px;font-size:0.78rem;font-weight:500;padding:3px 10px;border-radius:20px;
                background:${prob3pct>=0.6?'rgba(255,69,58,.15)':prob3pct>=0.3?'rgba(255,159,10,.15)':'rgba(48,209,88,.15)'}">
                ${healthText}
              </span>
            </h2>
            <p class="mgr-section-hint">Обновлено: ${esc(updatedAt)} · <a href="churn.php" style="color:var(--accent)">Открыть полный отчёт →</a></p>
          </div>
          <a href="churn.php" class="btn-secondary" style="align-self:center">Полный отчёт ↗</a>
        </div>
        ${riskBar}
        <div class="cf-kpi-row" style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 20px">
          ${kpiCards}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px">
          <div>
            <h3 style="font-size:0.82rem;color:var(--muted);margin:0 0 8px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">По продукту</h3>
            ${byProd.length ? `<table class="mgr-tbl" style="width:100%"><thead><tr><th>Продукт</th><th class="num">Риск MRR</th><th></th><th class="num">Кол-во</th></tr></thead><tbody>${prodRows}</tbody></table>` : '<p class="muted" style="font-size:0.82rem">Нет данных</p>'}
          </div>
          <div>
            <h3 style="font-size:0.82rem;color:var(--muted);margin:0 0 8px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">По CSM</h3>
            ${byCsm.length ? `<table class="mgr-tbl" style="width:100%"><thead><tr><th>CSM</th><th class="num">Риск MRR</th><th></th><th class="num">Доля</th></tr></thead><tbody>${csmRows}</tbody></table>` : '<p class="muted" style="font-size:0.82rem">Нет данных</p>'}
          </div>
          <div>
            <h3 style="font-size:0.82rem;color:var(--muted);margin:0 0 8px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Топ риски</h3>
            ${top3html}
            <a href="churn.php" style="display:inline-block;margin-top:10px;font-size:0.78rem;color:var(--accent);text-decoration:none">Все ${d.count || 0} клиентов →</a>
          </div>
        </div>
      </div>`;
  }

  // ─── Сводная вкладка: Факт Churn ──────────────────────
  function renderFactSummaryTab() {
    const d = state.factData;
    if (!d) {
      return `<div class="mgr-section">
        <p style="color:var(--muted);text-align:center;padding:40px 0">
          Нет данных о фактическом churn. <a href="churn_fact_api.php?force=1">Обновить кэш</a>
        </p>
      </div>`;
    }

    const fmtR = n => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n || 0)) + '\u00a0₽';
    const ytd     = d.churnYtd    || 0;
    const target  = d.targetTotal || 0;
    const pct     = target > 0 ? Math.min((ytd / target * 100), 200).toFixed(0) : 0;
    const pctNum  = target > 0 ? (ytd / target * 100) : 0;
    const barCls  = pctNum > 100 ? 'danger' : pctNum > 75 ? 'warn' : 'ok';

    const kpiCards = [
      { label: 'Churn YTD',    value: fmtR(ytd),           sub: `${new Date().getFullYear()} год, ₽`,   cls: 'danger' },
      { label: 'План потерь',  value: fmtR(target),         sub: 'целевой лимит',                        cls: '' },
      { label: 'ENT Churn',    value: fmtR(d.entYtd),       sub: `ENT план: ${fmtR(d.targetEnt)}`,      cls: 'info' },
      { label: 'SMB Churn',    value: fmtR(d.smbYtd),       sub: `SMB план: ${fmtR(d.targetSmb)}`,      cls: '' },
      { label: 'DownSell YTD', value: fmtR(d.downsellYtd),  sub: 'скидки',                               cls: '' },
    ].map(c => `
      <div class="cf-kpi-card${c.cls ? ' cf-kpi-' + c.cls : ''}">
        <div class="cf-kpi-label">${c.label}</div>
        <div class="cf-kpi-value">${c.value}</div>
        <div class="cf-kpi-sub">${c.sub}</div>
      </div>`).join('');

    // Прогресс к плану
    const progressBar = target > 0 ? `
      <div style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:var(--muted);margin-bottom:6px">
          <span>Исполнение плана по Churn</span>
          <span style="color:var(--${barCls === 'ok' ? 'ok' : barCls === 'warn' ? 'warn' : 'danger'})">${pct}% от плана</span>
        </div>
        <div style="height:10px;background:var(--border);border-radius:5px;overflow:hidden">
          <div style="height:100%;width:${Math.min(+pct, 100)}%;background:var(--${barCls === 'ok' ? 'ok' : barCls === 'warn' ? 'warn' : 'danger'});border-radius:5px;transition:width 0.4s"></div>
        </div>
      </div>` : '';

    // По продуктам
    const byProd = (d.byProduct || []).slice(0, 6);
    const maxProd = Math.max(...byProd.map(r => r.total || 0), 1);
    const prodRows = byProd.map(r => {
      const w = ((r.total || 0) / maxProd * 100).toFixed(1);
      return `<tr>
        <td style="font-size:0.8rem">${esc(r.product || '—')}</td>
        <td class="num" style="color:#FF453A">${fmtR(r.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:#FF453A"></div></div></td>
        <td class="num muted" style="font-size:0.75rem">${fmtR(r.churn)}</td>
      </tr>`;
    }).join('');

    // По CSM
    const byCsm = (d.byCsm || []).slice(0, 8);
    const maxCsm = Math.max(...byCsm.map(r => r.total || 0), 1);
    const csmRows = byCsm.map(r => {
      const w = ((r.total || 0) / maxCsm * 100).toFixed(1);
      return `<tr>
        <td style="font-size:0.8rem">${esc(r.csm || '—')}</td>
        <td class="num" style="color:#FF453A">${fmtR(r.total)}</td>
        <td style="width:80px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${w}%;background:var(--accent)"></div></div></td>
        <td class="num muted" style="font-size:0.75rem">${fmtR(r.downsell)}</td>
      </tr>`;
    }).join('');

    const updatedAt = d.updatedAt || '—';
    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">📉 Фактические потери выручки (Churn)</h2>
            <p class="mgr-section-hint">Обновлено: ${esc(updatedAt)} · <a href="churn_fact.php" style="color:var(--accent)">Открыть полный отчёт →</a></p>
          </div>
          <a href="churn_fact.php" class="btn-secondary" style="align-self:center">Полный отчёт ↗</a>
        </div>
        <div class="cf-kpi-row" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
          ${kpiCards}
        </div>
        ${progressBar}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
          <div>
            <h3 style="font-size:0.85rem;color:var(--muted);margin:0 0 10px">По продукту</h3>
            ${byProd.length ? `<table class="mgr-tbl" style="width:100%"><thead><tr><th>Продукт</th><th class="num">Всего</th><th></th><th class="num">Churn</th></tr></thead><tbody>${prodRows}</tbody></table>` : '<p class="muted" style="font-size:0.82rem">Нет данных</p>'}
          </div>
          <div>
            <h3 style="font-size:0.85rem;color:var(--muted);margin:0 0 10px">По CSM</h3>
            ${byCsm.length ? `<table class="mgr-tbl" style="width:100%"><thead><tr><th>CSM</th><th class="num">Всего</th><th></th><th class="num">DownSell</th></tr></thead><tbody>${csmRows}</tbody></table>` : '<p class="muted" style="font-size:0.82rem">Нет данных</p>'}
          </div>
        </div>
      </div>`;
  }

  // ─── Логотип AnyQuery ─────────────────────────────────────
  function renderLogo() {
    return `<div class="aq-logo" title="AnyQuery">
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="28" height="28" rx="7" fill="var(--accent)"/>
        <text x="14" y="19.5" text-anchor="middle" font-family="Segoe UI,system-ui,sans-serif"
              font-weight="800" font-size="13" fill="#fff" letter-spacing="-0.5">AQ</text>
      </svg>
      <span class="aq-logo-text">anyquery</span>
    </div>`;
  }

  // ─── Шапка ────────────────────────────────────────────────
  function renderHeader(d) {
    const isDark = (document.getElementById('html-root')?.getAttribute('data-theme') || 'dark') === 'dark';
    return `
      <div class="mgr-header">
        <div class="mgr-header-left">
          ${renderLogo()}
          <nav class="mgr-nav-tabs">
            <a class="mgr-nav-tab" href="index.php">🏠 Главная</a>
            <a class="mgr-nav-tab" href="churn.php">⚠ Угроза Churn</a>
            <a class="mgr-nav-tab" href="churn_fact.php">📉 Потери</a>
            <span class="mgr-nav-tab mgr-nav-tab-active">💰 ДЗ</span>
          </nav>
        </div>
        <div class="mgr-meta">
          <div class="mgr-meta-right">
            <span class="mgr-gen-date">Обновлено: <strong>${d.generatedAt}</strong></span>
            <span class="mgr-gen-date" id="next-refresh"></span>
            ${d.mrrMeta?.note ? `<span class="mgr-gen-date" style="font-size:0.72rem;color:var(--muted)" title="${esc(d.mrrMeta.note)}">MRR: ${d.mrrMeta.yearMonth}</span>` : ''}
          </div>
          <div class="mgr-meta-actions">
            <button class="btn-theme-toggle" id="btn-theme-toggle" title="${isDark ? 'Светлая тема' : 'Тёмная тема'}">
              ${isDark ? '☀️' : '🌙'}
            </button>
            <button class="btn-refresh${state.loading ? ' loading' : ''}" id="btn-refresh" ${state.loading ? 'disabled' : ''}>
              <span class="spin">⟳</span> Обновить данные
            </button>
            ${state.excluded.size > 0
              ? `<button class="btn-hidden-toggle${state.showHiddenPanel ? ' active' : ''}" id="btn-hidden-toggle">
                  👁 Скрытые <span class="hidden-count">${state.excluded.size}</span>
                </button>`
              : ''}
          </div>
        </div>
      </div>`;
  }

  // ─── Панель скрытых клиентов ──────────────────────────────
  function renderHiddenPanel() {
    if (!state.showHiddenPanel || state.excluded.size === 0) return '';
    const items = [...state.excluded].sort((a, b) => a.localeCompare(b, 'ru'));
    return `
      <div class="excl-panel">
        <div class="excl-panel-head">
          <strong>👁 Скрытые клиенты (${items.length})</strong>
          <button class="excl-restore-all" id="excl-restore-all">↩ Восстановить всех</button>
        </div>
        <div class="excl-panel-list">
          ${items.map(c => `
            <div class="excl-panel-item">
              <span class="excl-panel-name">${esc(c)}</span>
              <button class="excl-restore-one" data-restore="${esc(c)}" title="Восстановить">↩</button>
            </div>`).join('')}
        </div>
      </div>`;
  }

  // ─── KPI-карточки (краткая сводка) ────────────────────────
  function renderKpiCards(c, d) {
    const pct91  = c.totalDebt > 0 ? +((c.grpTotals['91+'] || 0) / c.totalDebt * 100).toFixed(0) : 0;
    const revPct = d.mrr > 0 ? +((c.totalDebt / d.mrr) * 100).toFixed(1) : 0;
    const revOk  = revPct <= 30;

    const cards = [
      {
        icon: '💸',
        label: 'Общая ДЗ',
        value: fmtRub(c.totalDebt),
        sub: `${c.uniqueClients} клиентов`,
        cls: '',
      },
      {
        icon: '🔴',
        label: 'Критично (91+ дн.)',
        value: fmtRub(c.grpTotals['91+'] || 0),
        sub: `${pct91}% от общей ДЗ`,
        cls: pct91 > 50 ? 'kpi-danger' : pct91 > 25 ? 'kpi-warn' : '',
      },
      {
        icon: revOk ? '✅' : '⚠️',
        label: '% ДЗ от выручки',
        value: fmtPct(revPct),
        sub: revOk ? 'В норме (≤ 30%)' : 'Превышен порог 30%',
        cls: revOk ? 'kpi-ok' : 'kpi-danger',
      },
      {
        icon: '💰',
        label: 'Собрано за неделю',
        value: fmtRub(d.payments.weekTotal),
        sub: `${d.payments.count} оплат · ${d.payments.fromTop10.length} из ТОП-10`,
        cls: d.payments.weekTotal > 0 ? 'kpi-ok' : '',
      },
    ];

    return `
      <div class="kpi-cards">
        ${cards.map(k => `
          <div class="kpi-card ${k.cls}">
            <div class="kpi-icon">${k.icon}</div>
            <div class="kpi-body">
              <div class="kpi-label">${k.label}</div>
              <div class="kpi-value">${k.value}</div>
              <div class="kpi-sub">${k.sub}</div>
            </div>
          </div>`).join('')}
      </div>`;
  }

  // ─── Фокус недели: критичные клиенты 91+ с MRR ───────────
  function renderFocusWeekSection(d, computed) {
    // Берем TOP-5 по сумме долга из API (бэкенд), fallback — локальный пересчет.
    const focused = (Array.isArray(d.top5Dz) && d.top5Dz.length > 0)
      ? d.top5Dz
      : (d.allClients || [])
          .sort((a, b) => (b.total || 0) - (a.total || 0))
          .slice(0, 5);

    if (!focused.length) return '';

    const totalCrit = focused.reduce((s, c) => s + (c.total || 0), 0);
    const totalMrr  = focused.reduce((s, c) => s + (c.mrr || 0), 0);

    // Максимальная просрочка в днях по клиенту из allRows (формат dueDate: d/m/yyyy)
    const maxDays = {};
    const today = Date.now();
    for (const r of (d.allRows || [])) {
      if (!r.dueDate) continue;
      const parts = r.dueDate.split('/');
      if (parts.length !== 3) continue;
      const due = new Date(+parts[2], +parts[1] - 1, +parts[0]);
      if (isNaN(due)) continue;
      const days = Math.floor((today - due.getTime()) / 86400000);
      if (days > 0) maxDays[r.client] = Math.max(maxDays[r.client] || 0, days);
    }

    const rows = focused.map((c, i) => {
      const debtTotal = c.total || 0;
      const days   = maxDays[c.client];
      const daysStr = days ? `${days} дн.` : '91+ дн.';
      const daysCls = days > 180 ? 'focus-crit' : days > 90 ? 'focus-warn' : '';
      const urgCls = days > 180 ? 'focus-crit' : days > 90 ? 'focus-warn' : '';
      return `
        <div class="focus-row ${urgCls}">
          <span class="focus-num">${i+1}</span>
          <span class="focus-name" title="${esc(c.client)}">${c.site ? esc(c.site.replace(/^https?:\/\//i,'').replace(/\/$/,'')) : esc(c.client)}</span>
          <span class="focus-mgr muted">${esc(c.manager || '—')}</span>
          <span class="focus-debt">${fmtRub(debtTotal)}</span>
          <span class="focus-days ${daysCls}" title="Максимальная просрочка по счетам клиента">⏱ ${daysStr}</span>
        </div>`;
    }).join('');

    return `
      <div class="mgr-section focus-week-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">🔥 Фокус недели — ТОП-5 по ДЗ
              <span class="mgr-help" title="Клиенты с наибольшей суммой долга (данные из API). Сортировка по общей сумме ДЗ. Подсветка строк: долгая просрочка по счетам (>90 / >180 дней).">?</span>
            </h2>
            <p class="mgr-section-hint">Требуют звонка или эскалации · Общий долг TOP-5: ${fmtRub(totalCrit)} · MRR под угрозой: ${fmtRub(totalMrr)}</p>
          </div>
        </div>
        <div class="focus-list">${rows}</div>
      </div>`;
  }

  // ─── Доп. фокус: 91+ с высоким MRR (старый блок) ──────────
  function renderFocus91MrrSection(d) {
    const focused = (d.allClients || [])
      .filter(c => (c.groups?.['91+'] || 0) > 0)
      .sort((a, b) => (b.groups?.['91+'] || 0) - (a.groups?.['91+'] || 0))
      .slice(0, 5);

    if (!focused.length) return '';

    const totalCrit91 = focused.reduce((s, c) => s + (c.groups?.['91+'] || 0), 0);
    const totalMrr    = focused.reduce((s, c) => s + (c.mrr || 0), 0);

    const rows = focused.map((c, i) => `
      <div class="focus-row">
        <span class="focus-num">${i + 1}</span>
        <span class="focus-name" title="${esc(c.client)}">${c.site ? esc(c.site.replace(/^https?:\/\//i,'').replace(/\/$/,'')) : esc(c.client)}</span>
        <span class="focus-mgr muted">${esc(c.manager || '—')}</span>
        <span class="focus-debt">${fmtRub(c.groups?.['91+'] || 0)}</span>
        <span class="focus-days" title="Долг в корзине 91+">91+ дн.</span>
      </div>
    `).join('');

    return `
      <div class="mgr-section focus-week-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">🔥 Фокус 91+ с высоким MRR</h2>
            <p class="mgr-section-hint">Критичный долг 91+: ${fmtRub(totalCrit91)} · MRR под риском: ${fmtRub(totalMrr)}</p>
          </div>
        </div>
        <div class="focus-list">${rows}</div>
      </div>`;
  }

  // ─── TOP-10 дебиторов ─────────────────────────────────────
  function renderTop10Section({ top10, top10Total, top10Pct, totalDebt }) {
    const exclCount = state.excluded.size;

    // Применяем сортировку для отображения
    const displayed = [...top10].sort((a, b) => {
      const dir = state.sortDir === 'asc' ? 1 : -1;
      if (state.sortCol === 'client') return dir * a.client.localeCompare(b.client, 'ru');
      return dir * (a.total - b.total);
    });

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">ТОП-10 дебиторов
              <span class="mgr-help" title="Топ клиентов по сумме просроченного долга. Можно скрыть клиента (он уйдёт в «Скрытые»), отфильтровать по группам просрочки.">?</span>
            </h2>
            <p class="mgr-section-hint">Клиенты с наибольшей суммой просроченного долга. Кликните по заголовку колонки для сортировки.</p>
          </div>
          <div class="filter-chips">
            <span style="font-size:0.75rem;color:var(--muted);margin-right:4px">Группы:</span>
            ${GROUPS.map(g => `
              <label class="chip ${CHIP_CSS[g]}${state.groups.has(g) ? '' : ' off'}" data-grp="${g}" title="Вкл/выкл группу ${GRP_LABELS[g]}">
                <input type="checkbox" ${state.groups.has(g) ? 'checked' : ''}>
                ${GRP_LABELS[g]}
              </label>`).join('')}
          </div>
        </div>

        <div class="top10-stat">
          <div class="top10-pct-big">${fmtPct(top10Pct)}</div>
          <div class="top10-pct-text">
            <div>ТОП-10 от общей ДЗ</div>
            <div class="sub-nums">
              ТОП-10: <strong>${fmtRub(top10Total)}</strong> &nbsp;/&nbsp; Всего: <strong>${fmtRub(totalDebt)}</strong>
              ${exclCount > 0 ? `&nbsp;&nbsp;<span class="excl-reset" id="excl-reset" title="Показать скрытых клиентов">↩ Сбросить скрытых (${exclCount})</span>` : ''}
            </div>
          </div>
        </div>

        <div class="mgr-table-wrap">
          <table class="mgr-table">
            <thead>
              <tr>
                <th class="idx">#</th>
                <th class="sortable${state.sortCol === 'client' ? ' sorted' : ''}" data-sort-col="client">
                  Клиент <span class="sort-arrow">${sortArrow('client')}</span>
                </th>
                <th class="sortable${state.sortCol === 'total' ? ' sorted' : ''}" data-sort-col="total" style="text-align:right">
                  Сумма долга <span class="sort-arrow">${sortArrow('total')}</span>
                </th>
                ${GROUPS.map(g => `<th style="text-align:right;font-size:0.72rem"><span class="grp-badge ${GRP_CSS[g]}">${GRP_LABELS[g]}</span></th>`).join('')}
                <th>Менеджер</th>
                <th title="Скрыть клиента из анализа">Скрыть</th>
              </tr>
            </thead>
            <tbody>
              ${displayed.length === 0
                ? `<tr><td colspan="9" class="empty-state">Нет данных по выбранным группам</td></tr>`
                : displayed.map((c, i) => renderTop10Row(c, i)).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  function renderTop10Row(c, i) {
    const isExcl = state.excluded.has(c.client);
    if (isExcl) return '';
    return `<tr>
      <td class="idx">${i + 1}</td>
      <td>
        <button class="client-name-btn" data-client-modal="${esc(c.client)}" title="${esc(c.client)}">
          ${c.site ? esc(c.site.replace(/^https?:\/\//i,'').replace(/\/$/,'')) : esc(c.client)}
        </button>
      </td>
      <td class="num amt-big">${fmtRub(c.total)}</td>
      ${GROUPS.map(g => `<td class="num ${c.groups[g] > 0 ? '' : 'amt-muted'}">${c.groups[g] > 0 ? fmtRub(c.groups[g]) : '—'}</td>`).join('')}
      <td class="muted">${esc(fullMgr(c.manager))}</td>
      <td>
        <button class="btn-excl" data-excl="${esc(c.client)}" title="Скрыть этого клиента из анализа">✕</button>
      </td>
    </tr>`;
  }

  // ─── Таблица менеджеров ────────────────────────────────────
  // ─── Чемпионы по ПДЗ ─────────────────────────────────────
  function renderChampionsSection(d, computed) {
    const mrr = d.mrr || 0;

    // managerMrr: предпочитаем данные с бэкенда (d.managerMrr),
    // fallback — считаем из allClients (MRR клиентов в долге)
    const managerMrr = d.managerMrr || (() => {
      const m = {};
      for (const c of (d.allClients || [])) {
        const mgr = c.manager || 'Не указан';
        m[mgr] = (m[mgr] || 0) + (c.mrr || 0);
      }
      return m;
    })();

    // Берём менеджеров, пересчитываем по активным группам, сортируем
    const mgrs = (d.byManager || [])
      .map(m => {
        const total = GROUPS.filter(g => state.groups.has(g))
          .reduce((s, g) => s + (m.groups[g] || 0), 0);
        return { ...m, filteredTotal: total };
      })
      .filter(m => m.filteredTotal > 0)
      .sort((a, b) => b.filteredTotal - a.filteredTotal);

    if (!mgrs.length) return '';

    const maxTotal   = mgrs[0].filteredTotal;
    const totalDebt  = mgrs.reduce((s, m) => s + m.filteredTotal, 0);

    const rows = mgrs.map((m, i) => {
      const barW    = maxTotal > 0 ? (m.filteredTotal / maxTotal * 100).toFixed(1) : 0;
      const pctMrr  = mrr > 0 ? (m.filteredTotal / mrr * 100).toFixed(1) : null;
      const pctAll  = totalDebt > 0 ? (m.filteredTotal / totalDebt * 100).toFixed(0) : 0;
      const ownMrr  = managerMrr[m.manager] || 0;
      const pctOwn  = ownMrr > 0 ? (m.filteredTotal / ownMrr * 100).toFixed(1) : null;
      const medal   = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}.`;
      const mrrCls  = pctMrr === null ? '' : +pctMrr > 15 ? 'champ-pct-danger' : +pctMrr > 8 ? 'champ-pct-warn' : 'champ-pct-ok';
      const ownCls  = pctOwn === null ? 'muted' : +pctOwn > 25 ? 'champ-pct-danger' : +pctOwn > 12 ? 'champ-pct-warn' : 'champ-pct-ok';

      return `
        <tr class="champ-row">
          <td class="champ-medal">${medal}</td>
          <td class="champ-name"><button class="client-name-btn" data-open-mgr-filter="${esc(m.manager)}">${esc(fullMgr(m.manager))}</button></td>
          <td class="champ-bar-cell">
            <div class="champ-bar-track">
              <div class="champ-bar-fill" style="width:${barW}%"></div>
            </div>
          </td>
          <td class="num champ-total">${fmtRub(m.filteredTotal)}</td>
          <td class="num champ-share muted">${pctAll}% от ПДЗ</td>
          <td class="num ${ownCls}" title="ДЗ / портфель MRR менеджера (${fmtRub(ownMrr)})">${pctOwn !== null ? pctOwn + '% портфеля' : '—'}</td>
          <td class="num ${mrrCls}">${pctMrr !== null ? pctMrr + '% MRR' : '—'}</td>
        </tr>`;
    });

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">🏆 Чемпионы по ПДЗ
              <span class="mgr-help" title="Рейтинг менеджеров по сумме дебиторской задолженности.&#10;% от общего ПДЗ — доля менеджера в общей сумме долгов.&#10;% портфеля — ДЗ / MRR его клиентов (здоровье портфеля). Норма ≤12%, критично >25%.&#10;% от MRR — ДЗ / общая выручка компании. Норма ≤8%.">?</span>
            </h2>
            <p class="mgr-section-hint">Кто держит наибольший объём просрочки · ${mgrs.length} менеджеров</p>
          </div>
        </div>
        <div class="mgr-table-wrap">
          <table class="mgr-table champ-table">
            <thead><tr>
              <th class="idx">#</th>
              <th>Менеджер</th>
              <th style="min-width:160px">Доля в портфеле</th>
              <th style="text-align:right">Сумма ДЗ</th>
              <th style="text-align:right">% от общего ПДЗ</th>
              <th style="text-align:right">% портфеля</th>
              <th style="text-align:right">% от MRR</th>
            </tr></thead>
            <tbody>${rows.join('')}</tbody>
          </table>
        </div>
      </div>`;
  }

  function renderManagerTableSection(d) {
    const mgrs = (d.byManager || []).filter(m => {
      // учитываем фильтр групп
      const grpTotal = GROUPS.filter(g => state.groups.has(g))
        .reduce((s, g) => s + (m.groups[g] || 0), 0);
      return grpTotal > 0;
    }).map(m => {
      // пересчитываем total только по активным группам
      const total = GROUPS.filter(g => state.groups.has(g))
        .reduce((s, g) => s + (m.groups[g] || 0), 0);
      return { ...m, filteredTotal: total };
    }).sort((a, b) => b.filteredTotal - a.filteredTotal);

    const maxTotal = mgrs.length ? mgrs[0].filteredTotal : 1;

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">👥 ДЗ по менеджерам
              <span class="mgr-help" title="Суммарная задолженность в разрезе аккаунт-менеджеров по выбранным группам просрочки. «Клиентов» — кол-во уникальных ЮЛ в портфеле менеджера.">?</span>
            </h2>
            <p class="mgr-section-hint">Активная просрочка по каждому менеджеру · ${mgrs.length} менеджеров</p>
          </div>
        </div>
        <div class="mgr-table-wrap">
          <table class="mgr-table">
            <thead><tr>
              <th class="idx">#</th>
              <th>Менеджер</th>
              <th style="min-width:120px">Доля</th>
              <th style="text-align:right">Итого</th>
              ${GROUPS.map(g => `<th style="text-align:right;font-size:0.72rem"><span class="grp-badge ${GRP_CSS[g]}">${GRP_LABELS[g]}</span></th>`).join('')}
              <th style="text-align:right">Клиентов</th>
            </tr></thead>
            <tbody>
              ${mgrs.length === 0
                ? `<tr><td colspan="9" class="empty-state">Нет данных</td></tr>`
                : mgrs.map((m, i) => {
                    const barW = maxTotal > 0 ? (m.filteredTotal / maxTotal * 100).toFixed(1) : 0;
                    return `<tr>
                      <td class="idx">${i + 1}</td>
                      <td><strong>${esc(m.manager)}</strong></td>
                      <td>
                        <div class="mgr-bar-track">
                          <div class="mgr-bar-fill" style="width:${barW}%"></div>
                        </div>
                      </td>
                      <td class="num amt-big">${fmtRub(m.filteredTotal)}</td>
                      ${GROUPS.map(g => `<td class="num ${m.groups[g] > 0 ? '' : 'amt-muted'}">${m.groups[g] > 0 ? fmtRub(m.groups[g]) : '—'}</td>`).join('')}
                      <td class="num muted">${m.clients}</td>
                    </tr>`;
                  }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  // ─── % ДЗ от выручки ──────────────────────────────────────
  function renderGaugeSection(totalDebt, mrr) {
    const pct   = mrr > 0 ? +(totalDebt / mrr * 100).toFixed(1) : 0;
    const isOk  = pct <= 30;
    const cls   = isOk ? 'ok' : 'bad';
    const barW  = Math.min(pct, 100);

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">% ДЗ от выручки (MRR)
              <span class="mgr-help" title="Отношение общей дебиторской задолженности к MRR (Monthly Recurring Revenue). Рассчитывается из таблицы Клиенты, поле MRR sum. Норма: ДЗ ≤ 30% MRR.">?</span>
            </h2>
            <p class="mgr-section-hint">Какую долю от ежемесячной выручки составляет текущая задолженность. Норма — до 30%.</p>
          </div>
          <span class="gauge-status-tag ${cls}">${isOk ? '✓ В норме' : '⚠ Превышен порог'}</span>
        </div>
        <div class="gauge-card">
          <div class="gauge-pct ${cls}">${fmtPct(pct)}</div>
          <div class="gauge-right">
            <div class="gauge-bar-wrap">
              <div class="gauge-fill ${cls}" style="width:${barW}%"></div>
              <div class="gauge-threshold">
                <span class="gauge-threshold-label">30%</span>
              </div>
            </div>
            <div class="gauge-meta">
              <span><span class="lbl">Общая ДЗ:</span> ${fmtRub(totalDebt)}</span>
              <span><span class="lbl">MRR (выручка):</span> ${fmtRub(mrr)}</span>
            </div>
          </div>
        </div>
      </div>`;
  }

  // ─── Оплаты за неделю ─────────────────────────────────────
  function renderPaymentsSection(d) {
    const p     = d.payments;
    const start = fmtDate(d.weekStart);
    const end   = fmtDate(d.weekEnd);

    const tableHtml = (rows, emptyMsg) => {
      if (!rows || rows.length === 0) return `<div class="empty-state">${emptyMsg}</div>`;
      return `<div class="mgr-table-wrap"><table class="mgr-table">
        <thead><tr><th class="idx">#</th><th>Клиент</th><th style="text-align:right">Сумма</th><th style="text-align:center">Дата</th></tr></thead>
        <tbody>${rows.map((r, i) => `
          <tr>
            <td class="idx">${i + 1}</td>
            <td><strong>${esc(r.client)}</strong></td>
            <td class="num" style="color:var(--ok);font-weight:700">${fmtRub(r.amount)}</td>
            <td class="num" style="text-align:center;color:var(--muted);font-size:0.82em">${fmtDate(r.date || '')}</td>
          </tr>`).join('')}
        </tbody>
      </table></div>`;
    };

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">💰 Оплаты за неделю
              <span class="mgr-help" title="Платежи из вида «♥️Оплачено CSM» за последние 7 дней (среда → среда). Показывает топ-5 крупнейших оплат и оплаты от клиентов из ТОП-10 дебиторов.">?</span>
            </h2>
            <p class="mgr-section-hint">Вид «♥️Оплачено CSM» · ${start} — ${end}</p>
          </div>
        </div>

        <div class="pay-summary">
          <div class="pay-stat">
            <span class="pay-stat-lbl">Итого собрано</span>
            <span class="pay-stat-val" style="color:var(--ok)">${fmtRub(p.weekTotal)}</span>
          </div>
          <div class="pay-stat">
            <span class="pay-stat-lbl">Клиентов оплатили</span>
            <span class="pay-stat-val">${p.count}</span>
          </div>
          <div class="pay-stat">
            <span class="pay-stat-lbl">Из ТОП-10 дебиторов</span>
            <span class="pay-stat-val ${p.fromTop10.length > 0 ? 'val-ok' : ''}">${p.fromTop10.length}</span>
          </div>
        </div>

        <div class="pay-two-col">
          <div>
            <div class="mgr-section-sub">ТОП-5 крупнейших оплат</div>
            ${tableHtml(p.top5, 'Нет оплат за этот период')}
          </div>
          <div>
            <div class="mgr-section-sub">Оплатили из ТОП-10 дебиторов</div>
            ${tableHtml(p.fromTop10, 'Никто из ТОП-10 не оплатил за этот период')}
          </div>
        </div>
      </div>`;
  }

  // ─── Еженедельный чарт ДЗ (сравнение) ────────────────────
  function renderWeeklyDzChart(d) {
    const pts = Array.isArray(d.weeklyHistory) ? d.weeklyHistory : [];
    if (pts.length < 2) return '';

    const maxVal = Math.max(...pts.map(p => p.totalDebt || 0), 1);

    const bars = pts.map((p, i) => {
      const totalW   = ((p.totalDebt   || 0) / maxVal * 100).toFixed(1);
      const overdueW = ((p.overdueDebt || 0) / maxVal * 100).toFixed(1);
      const isLast   = i === pts.length - 1;
      // Дата: показываем только число и месяц
      const d2 = String(p.weekEnd || '').slice(5); // "03-19"
      const [m, day] = d2.split('-');
      const label = day && m ? `${day}.${m}` : d2;
      return `
        <div class="wkly-col${isLast ? ' wkly-col-current' : ''}">
          <div class="wkly-bars">
            <div class="wkly-bar wkly-bar-total"  style="height:${totalW}%"
                 title="Общая ДЗ: ${fmtRub(p.totalDebt)}"></div>
            <div class="wkly-bar wkly-bar-overdue" style="height:${overdueW}%"
                 title="Просрочка 61+: ${fmtRub(p.overdueDebt)}"></div>
          </div>
          <div class="wkly-label">${esc(label)}</div>
        </div>`;
    });

    const last = pts[pts.length - 1];
    const prev = pts[pts.length - 2];
    const delta = (last.totalDebt || 0) - (prev.totalDebt || 0);
    const deltaCls = delta > 0 ? 'delta-up' : delta < 0 ? 'delta-down' : '';
    const deltaSign = delta > 0 ? '+' : '';

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">📈 ДЗ в еженедельном разрезе
              <span class="mgr-help" title="Динамика общей ДЗ (синий) и просрочки 61+ дней (красный) по неделям. Точка отсчёта — среда Europe/Moscow. Хранится до 16 точек.">?</span>
            </h2>
            <p class="mgr-section-hint">Сравнение задолженности неделя-к-неделе · ${pts.length} точек</p>
          </div>
          <div class="wkly-delta ${deltaCls}">
            <span class="wkly-delta-lbl">Изменение нед/нед</span>
            <span class="wkly-delta-val">${deltaSign}${fmtRub(delta)}</span>
          </div>
        </div>
        <div class="wkly-legend">
          <span class="wkly-legend-item wkly-legend-total">Общая ДЗ</span>
          <span class="wkly-legend-item wkly-legend-overdue">Просрочка 61+</span>
        </div>
        <div class="wkly-chart">
          ${bars.join('')}
        </div>
      </div>`;
  }

  // ─── Еженедельный чарт оплат ──────────────────────────────
  function renderWeeklyPayChart(d) {
    const wp = d.weeklyPayments;
    if (!wp || !Array.isArray(wp.bars) || wp.bars.length < 2) return '';

    const bars = wp.bars;
    const maxVal = Math.max(...bars.map(b => b.total || 0), 1);

    const cols = bars.map((b, i) => {
      const h = ((b.total || 0) / maxVal * 100).toFixed(1);
      const isLast = i === bars.length - 1;
      const d2 = String(b.weekEnd || '').slice(5);
      const [m, day] = d2.split('-');
      const label = day && m ? `${day}.${m}` : d2;
      return `
        <div class="wkly-col${isLast ? ' wkly-col-current' : ''}">
          <div class="wkly-bars">
            <div class="wkly-bar wkly-bar-pay" style="height:${h}%"
                 title="Оплаты: ${fmtRub(b.total)}"></div>
          </div>
          <div class="wkly-label">${esc(label)}</div>
        </div>`;
    });

    const lastBar = bars[bars.length - 1];
    const prevBar = bars[bars.length - 2];
    const delta = (lastBar.total || 0) - (prevBar.total || 0);
    const deltaCls = delta > 0 ? 'delta-down' : delta < 0 ? 'delta-up' : ''; // оплаты: рост = хорошо
    const deltaSign = delta > 0 ? '+' : '';

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">💳 Оплаты по неделям
              <span class="mgr-help" title="Суммы оплаченных счетов из вида «♥️Оплачено CSM» по неделям (среда → среда). Последний столбец — текущая неделя.">?</span>
            </h2>
            <p class="mgr-section-hint">Вид «♥️Оплачено CSM» · ${bars.length} недель</p>
          </div>
          <div class="wkly-delta ${deltaCls}">
            <span class="wkly-delta-lbl">Изменение нед/нед</span>
            <span class="wkly-delta-val">${deltaSign}${fmtRub(delta)}</span>
          </div>
        </div>
        <div class="wkly-legend">
          <span class="wkly-legend-item wkly-legend-pay">Оплачено за неделю</span>
          <span style="font-size:0.78rem;color:var(--muted)">Текущая неделя: <strong style="color:var(--ok)">${fmtRub(wp.currentWeekTotal)}</strong></span>
        </div>
        <div class="wkly-chart">
          ${cols.join('')}
        </div>
      </div>`;
  }

  // ─── Алерт-баннер ─────────────────────────────────────────
  function renderAlertBanner(computed, d) {
    const alerts = [];
    const pct91  = computed.totalDebt > 0 ? (computed.grpTotals['91+'] || 0) / computed.totalDebt * 100 : 0;
    const dtrPct = d.mrr > 0 ? computed.totalDebt / d.mrr * 100 : 0;
    if (pct91 > 40) alerts.push({ cls: 'alert-danger', icon: '🚨', text: `Критично: 91+ составляет <strong>${pct91.toFixed(0)}%</strong> портфеля (порог 40%). Требуется немедленная работа.` });
    else if (pct91 > 25) alerts.push({ cls: 'alert-warn', icon: '⚠️', text: `Внимание: 91+ достиг <strong>${pct91.toFixed(0)}%</strong> портфеля (норма до 25%).` });
    if (dtrPct > 50) alerts.push({ cls: 'alert-danger', icon: '🚨', text: `ДЗ/MRR превышает <strong>${dtrPct.toFixed(0)}%</strong> выручки (критический порог 50%).` });
    else if (dtrPct > 30) alerts.push({ cls: 'alert-warn', icon: '⚠️', text: `ДЗ/MRR = <strong>${dtrPct.toFixed(0)}%</strong> — превышен рекомендуемый порог 30%.` });
    if (!alerts.length) return '';
    return `<div class="alert-wrap">${alerts.map(a => `
      <div class="alert-banner ${a.cls}">
        <span class="alert-icon">${a.icon}</span>
        <span class="alert-text">${a.text}</span>
        <button class="alert-close" onclick="this.closest('.alert-banner').remove()">✕</button>
      </div>`).join('')}</div>`;
  }

  // ─── Aging transition (постарение долга) ──────────────────
  function renderAgingTransitionSection(d) {
    const at = d.agingTransition;
    if (!at) return '';
    const hasPrev = Object.values(at).some(v => v.previous > 0);
    if (!hasPrev) return ''; // первый запуск — нет с чем сравнивать

    const rows = GROUPS.map(g => {
      const v    = at[g] || { current: 0, previous: 0, delta: 0 };
      const up   = v.delta > 0.01;
      const down = v.delta < -0.01;
      const cls  = up ? 'aging-up' : down ? 'aging-down' : 'aging-same';
      const sign = up ? '+' : '';
      const arrow = up ? '↑' : down ? '↓' : '→';
      return `
        <div class="aging-row">
          <div class="aging-grp"><span class="grp-badge ${GRP_CSS[g]}">${GRP_LABELS[g]}</span></div>
          <div class="aging-curr">${fmtRub(v.current)}</div>
          <div class="aging-delta ${cls}">
            <span class="aging-arrow">${arrow}</span>
            ${sign}${fmtRub(v.delta)}
          </div>
          <div class="aging-prev muted" style="font-size:0.75rem">нед. назад: ${fmtRub(v.previous)}</div>
        </div>`;
    });

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">🔄 Динамика по группам просрочки (нед/нед)
              <span class="mgr-help" title="Изменение суммы долга в каждой группе просрочки по сравнению с прошлой неделей. ↑ красный = долг вырос, ↓ зелёный = снизился.">?</span>
            </h2>
            <p class="mgr-section-hint">Постарение портфеля — рост 61+ и 91+ означает ухудшение ситуации.</p>
          </div>
        </div>
        <div class="aging-grid">${rows.join('')}</div>
      </div>`;
  }

  // ─── Карточка клиента (модалка) ───────────────────────────
  function renderClientModal(clientName, d) {
    const comments  = loadComments();
    const comment   = comments[clientName] || '';
    const allRows   = d.allRows || [];
    const trends    = d.clientTrends || {};

    // Все строки этого клиента
    const clientRows = allRows.filter(r => r.client === clientName);
    // MRR берём из первой строки клиента — сервер проставил его в каждую строку
    const clientMrr = clientRows[0]?.mrr || 0;
    const totalDebt  = clientRows.reduce((s, r) => s + r.amount, 0);
    const mrrRatio   = clientMrr > 0 ? (totalDebt / clientMrr * 100).toFixed(0) : null;
    const ratioCls   = mrrRatio === null ? '' : +mrrRatio > 100 ? 'kpi-danger' : +mrrRatio > 50 ? 'kpi-warn' : 'kpi-ok';

    // Долг по группам
    const grpAmts = {};
    for (const r of clientRows) grpAmts[r.group] = (grpAmts[r.group] || 0) + r.amount;

    const grpBars = GROUPS.map(g => {
      const v = grpAmts[g] || 0;
      const w = totalDebt > 0 ? (v / totalDebt * 100).toFixed(1) : 0;
      return v > 0 ? `
        <div class="modal-grp-row">
          <span class="grp-badge ${GRP_CSS[g]}" style="min-width:80px">${GRP_LABELS[g]}</span>
          <div class="modal-grp-track"><div class="chart-bar ${BAR_CSS[g]}" style="width:${w}%"></div></div>
          <span class="num" style="min-width:120px;text-align:right">${fmtRub(v)}</span>
        </div>` : '';
    }).join('');

    // Оплаты этого клиента из d.payments.top5
    const payments = [...(d.payments?.top5 || []), ...(d.payments?.fromTop10 || [])]
      .filter((p, i, arr) => p.client === clientName && arr.findIndex(x => x.date === p.date && x.amount === p.amount) === i)
      .sort((a, b) => (b.date||'').localeCompare(a.date||''));

    const payHtml = payments.length ? `
      <div class="modal-sub-title">💰 Оплаты этой недели</div>
      <table class="mgr-table" style="margin-bottom:12px">
        <thead><tr><th>Дата</th><th style="text-align:right">Сумма</th></tr></thead>
        <tbody>${payments.map(p => `
          <tr>
            <td class="muted">${fmtDate(p.date)}</td>
            <td class="num" style="color:var(--ok);font-weight:700">${fmtRub(p.amount)}</td>
          </tr>`).join('')}
        </tbody>
      </table>` : '';

    // Таблица всех счетов
    const invoiceRows = clientRows.map(r => {
      const days = daysOverdue(r.dueDate);
      const dc   = days === null ? '' : days >= 91 ? 'days-critical' : days >= 61 ? 'days-warn' : days >= 31 ? 'days-medium' : '';
      return `<tr>
        <td class="muted">${esc(r.invoice)}</td>
        <td class="num">${fmtRub(r.amount)}</td>
        <td><span class="grp-badge ${GRP_CSS[r.group]}">${GRP_LABELS[r.group]}</span></td>
        <td class="muted">${fmtDate(r.dueDate)}</td>
        <td class="${dc}" style="font-weight:600;text-align:right">${days !== null ? days + ' дн.' : '—'}</td>
        <td class="muted" style="font-size:0.72rem">${esc(r.status)}</td>
      </tr>`;
    }).join('');

    const trend = trends[clientName];
    const trendBadge = TREND_HTML[trend] ? `<span style="margin-left:8px">${TREND_HTML[trend]}</span>` : '';
    const site = clientRows[0]?.site || '';

    return `
      <div class="modal-overlay" id="modal-overlay">
        <div class="modal-card" id="modal-card">
          <div class="modal-header">
            <div>
              <div class="modal-client-name">${esc(clientName)}${trendBadge}</div>
              ${site ? siteBadge(site) : ''}
            </div>
            <button class="modal-close" id="modal-close">✕</button>
          </div>

          <div class="modal-kpis">
            <div class="modal-kpi">
              <div class="modal-kpi-lbl">Общая ДЗ</div>
              <div class="modal-kpi-val">${fmtRub(totalDebt)}</div>
            </div>
            ${clientMrr > 0 ? `
            <div class="modal-kpi">
              <div class="modal-kpi-lbl">MRR клиента</div>
              <div class="modal-kpi-val">${fmtRub(clientMrr)}</div>
            </div>
            <div class="modal-kpi ${ratioCls}">
              <div class="modal-kpi-lbl">ДЗ / MRR</div>
              <div class="modal-kpi-val">${mrrRatio}%</div>
            </div>` : ''}
            <div class="modal-kpi">
              <div class="modal-kpi-lbl">Счётов</div>
              <div class="modal-kpi-val">${clientRows.length}</div>
            </div>
          </div>

          <div class="modal-sub-title">📊 Долг по группам</div>
          <div class="modal-grp-bars" style="margin-bottom:16px">${grpBars}</div>

          ${payHtml}

          <div class="modal-sub-title">🗂 Все счета</div>
          <div class="mgr-table-wrap" style="max-height:240px;overflow-y:auto;margin-bottom:16px">
            <table class="mgr-table">
              <thead><tr><th>Счёт</th><th style="text-align:right">Сумма</th><th>Группа</th><th>Срок</th><th style="text-align:right">Дней</th><th>Статус</th></tr></thead>
              <tbody>${invoiceRows || '<tr><td colspan="6" class="empty-state">Нет счетов</td></tr>'}</tbody>
            </table>
          </div>

          <div class="modal-sub-title">💬 Комментарий</div>
          <textarea class="modal-comment-ta" id="modal-comment-ta"
            placeholder="Добавить заметку по клиенту…" rows="3">${esc(comment)}</textarea>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
            <button class="btn-secondary" id="modal-comment-save">💾 Сохранить</button>
          </div>
        </div>
      </div>`;
  }

  // ─── Чарт структуры ДЗ по группам ────────────────────────
  function renderGroupChartSection(grpTotals, totalDebt) {
    const maxVal = Math.max(...Object.values(grpTotals), 1);

    const rows = GROUPS.map(g => {
      const val = grpTotals[g] || 0;
      const w   = (val / maxVal * 100).toFixed(1);
      const pct = totalDebt > 0 ? (val / totalDebt * 100).toFixed(1) : '0.0';
      return `
        <div class="chart-row">
          <div class="chart-label">
            <span class="grp-badge ${GRP_CSS[g]}">${GRP_LABELS[g]}</span>
          </div>
          <div class="chart-track" title="${fmtRub(val)} · ${pct}% от общей ДЗ">
            <div class="chart-bar ${BAR_CSS[g]}" style="width:${w}%"></div>
          </div>
          <div class="chart-val">
            ${fmtRub(val)}
            <span class="chart-pct">${pct}%</span>
          </div>
        </div>`;
    });

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">📊 Структура ДЗ по группам просрочки
              <span class="mgr-help" title="Распределение активной просрочки по группам: 16-30 дней — жёлтая зона, 91+ дней — критично. Чем больше доля 91+ — тем хуже здоровье портфеля.">?</span>
            </h2>
            <p class="mgr-section-hint">Чем длиннее просрочка — тем ниже вероятность возврата. Следите за ростом 91+ дней.</p>
          </div>
          <span style="font-size:0.82rem;color:var(--muted)">Всего: <strong style="color:var(--text)">${fmtRub(totalDebt)}</strong></span>
        </div>
        <div class="group-chart">
          ${rows.join('')}
        </div>
      </div>`;
  }

  // ─── Кастомный dropdown: выбор нескольких менеджеров ─────
  function renderMgrDropdown(allManagers, selected) {
    const count = selected.size;
    let label;
    if (count === 0)             label = 'Все менеджеры';
    else if (count === 1)        label = esc([...selected][0] || '—');
    else                         label = `${count} менеджера`;

    const badge = count > 0 ? `<span class="mgr-dd-badge">${count}</span>` : '';

    const items = allManagers.map(m => `
      <label class="mgr-dd-item">
        <input type="checkbox" class="mgr-dd-cb" data-mgr="${esc(m)}"
               ${selected.has(m) ? 'checked' : ''}>
        <span class="mgr-dd-name">${esc(fullMgr(m))}</span>
      </label>`).join('');

    const clearBtn = count > 0
      ? `<button class="mgr-dd-clear" id="mgr-dd-clear">✕ Сбросить</button>` : '';

    return `
      <div class="mgr-dd-wrap" id="mgr-dd-wrap">
        <button class="mgr-dd-btn${count > 0 ? ' active' : ''}" id="mgr-dd-btn" type="button">
          👤 ${label} ${badge} <span class="mgr-dd-arrow">${mgrDropOpen ? '▲' : '▼'}</span>
        </button>
        ${mgrDropOpen ? `
          <div class="mgr-dd-panel" id="mgr-dd-panel">
            <div class="mgr-dd-head">
              <span class="mgr-dd-title">Менеджеры</span>
              ${clearBtn}
            </div>
            <div class="mgr-dd-list">${items || '<div class="mgr-dd-empty">Нет данных</div>'}</div>
          </div>` : ''}
      </div>`;
  }

  // ─── Вспомогалки для новых столбцов ──────────────────────
  function buildClientMaps(allRows) {
    const totals = {}, counts = {};
    for (const r of allRows) {
      totals[r.client] = (totals[r.client] || 0) + r.amount;
      counts[r.client] = (counts[r.client] || 0) + 1;
    }
    return { totals, counts };
  }

  function daysOverdue(dueDateStr) {
    if (!dueDateStr) return null;
    const due = new Date(dueDateStr);
    if (isNaN(due)) return null;
    const today = new Date(); today.setHours(0,0,0,0);
    return Math.max(0, Math.floor((today - due) / 86400000));
  }

  const TREND_HTML = {
    up:   '<span class="trend-up"   title="Долг вырос с прошлой недели">↑</span>',
    down: '<span class="trend-down" title="Долг снизился с прошлой недели">↓</span>',
    new:  '<span class="trend-new"  title="Новый в списке">✦</span>',
    same: '<span class="trend-same" title="Без изменений">→</span>',
  };

  // ─── Детальная таблица ────────────────────────────────────
  function renderDetailSection(d) {
    const allRows = d.allRows || [];
    const f = state.detailFilters;
    const clientTrends = d.clientTrends || {};
    const { totals: clientTotals, counts: clientCounts } = buildClientMaps(allRows);

    // Build unique value lists for selects
    const uniq = key => [...new Set(allRows.map(r => r[key]).filter(x => x && x.trim()))].sort((a,b) => a.localeCompare(b, 'ru'));
    const clients   = uniq('client');
    const managers  = uniq('manager');
    const directions= uniq('direction');
    const companies = uniq('company');
    const statuses  = uniq('status');

    const amtMin = f.amtMin !== '' ? parseFloat(f.amtMin.replace(',','.')) : null;
    const amtMax = f.amtMax !== '' ? parseFloat(f.amtMax.replace(',','.')) : null;

    // Apply all filters
    let rows = allRows.filter(r => {
      if (state.excluded.has(r.client)) return false;
      if (!state.groups.has(r.group)) return false;
      if (state.search) {
        const q = state.search.toLowerCase();
        if (!r.client.toLowerCase().includes(q) && !r.invoice.toLowerCase().includes(q) && !(r.direction||'').toLowerCase().includes(q) && !(r.manager||'').toLowerCase().includes(q)) return false;
      }
      if (f.client    && r.client    !== f.client)                       return false;
      if (f.managers.size > 0 && !f.managers.has(r.manager || ''))      return false;
      if (f.group     && r.group     !== f.group)                        return false;
      if (f.direction && r.direction !== f.direction)                    return false;
      if (f.status    && r.status    !== f.status)                       return false;
      if (f.company   && r.company   !== f.company)                      return false;
      if (amtMin !== null && r.amount < amtMin) return false;
      if (amtMax !== null && r.amount > amtMax) return false;
      return true;
    });

    // Sort
    rows.sort((a, b) => {
      const dir = state.detailSortDir === 'asc' ? 1 : -1;
      const col = state.detailSortCol;
      const va = a[col], vb = b[col];
      if (typeof va === 'string') return dir * (va||'').localeCompare(vb||'', 'ru');
      return dir * ((va||0) - (vb||0));
    });

    const PAGE_SIZE = 50;
    const totalPages = Math.ceil(rows.length / PAGE_SIZE) || 1;
    if (state.detailPage >= totalPages) state.detailPage = totalPages - 1;
    const pageStart = state.detailPage * PAGE_SIZE;
    const pageRows  = rows.slice(pageStart, pageStart + PAGE_SIZE);

    const totalAmt  = rows.reduce((s, r) => s + r.amount, 0);
    const hasFilter = f.client || f.managers.size > 0 || f.group || f.direction || f.status || f.company || f.amtMin || f.amtMax || state.search;

    const pagerHtml = totalPages > 1 ? `
      <div class="pager">
        <button class="pager-btn" id="pager-prev" ${state.detailPage === 0 ? 'disabled' : ''}>← Пред.</button>
        <span class="pager-info">Стр. ${state.detailPage + 1} из ${totalPages} · ${pageStart + 1}–${Math.min(pageStart + PAGE_SIZE, rows.length)} из ${rows.length}</span>
        <button class="pager-btn" id="pager-next" ${state.detailPage >= totalPages - 1 ? 'disabled' : ''}>След. →</button>
      </div>` : '';

    const opt = (arr, val, placeholder) =>
      `<option value="">${esc(placeholder)}</option>` +
      arr.map(v => `<option value="${esc(v)}"${v===val?' selected':''}>${esc(v)}</option>`).join('');
    const grpOpt = `<option value="">Все группы</option>` +
      GROUPS.map(g => `<option value="${esc(g)}"${g===f.group?' selected':''}>${GRP_LABELS[g]}</option>`).join('');

    const dHead = (col, label, align) => {
      const isSorted = state.detailSortCol === col;
      return `<th class="sortable${isSorted?' sorted':''}" data-dsort="${col}"${align?` style="text-align:${align}"`:''}>
        ${label} <span class="sort-arrow">${dSortArrow(col)}</span>
      </th>`;
    };

    return `
      <div class="mgr-section">
        <div class="mgr-section-head">
          <div>
            <h2 class="mgr-section-title">🗂 Детальная таблица ДЗ
              <span class="mgr-help" title="Все строки из вида «🔸Debt 15,30,60,90 Демидова». Фильтруйте по клиенту, менеджеру, группе просрочки, сумме. Скачайте CSV для Excel.">?</span>
            </h2>
            <p class="mgr-section-hint">Все счета · ${rows.length} строк${rows.length < allRows.length ? ` из ${allRows.length}` : ''} · <strong style="color:var(--warn)">${fmtRub(totalAmt)}</strong></p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            ${hasFilter ? `<button class="excl-reset" id="detail-reset-filters">↺ Сбросить фильтры</button>` : ''}
            <button class="btn-secondary${state.detailGrouped ? ' btn-active' : ''}" id="detail-group-btn" title="Объединить строки одного клиента" style="font-size:0.75rem;padding:4px 10px">
              ${state.detailGrouped ? '⊞ По клиентам' : '⊟ По счетам'}
            </button>
            <button class="btn-secondary" id="detail-csv-btn" style="font-size:0.75rem;padding:4px 10px">⬇ CSV</button>
            <span class="mgr-row-count">${rows.length} строк</span>
          </div>
        </div>

        <div class="detail-filter-bar">
          <div class="detail-filter-row1">
            <input class="mgr-search" id="detail-search" type="text"
              placeholder="🔍 Поиск по клиенту, счёту, менеджеру, направлению…" value="${esc(state.search)}">
          </div>
          <div class="detail-filter-row2">
            <select id="df-client" class="df-select" title="Клиент">
              ${opt(clients, f.client, 'Все клиенты')}
            </select>
            ${renderMgrDropdown(managers, f.managers)}
            <select id="df-group" class="df-select" title="Группа просрочки">
              ${grpOpt}
            </select>
            <select id="df-direction" class="df-select" title="Направление">
              ${opt(directions, f.direction, 'Все направления')}
            </select>
            <select id="df-status" class="df-select" title="Статус оплаты">
              ${opt(statuses, f.status, 'Все статусы')}
            </select>
            <select id="df-company" class="df-select" title="Наша компания">
              ${opt(companies, f.company, 'Все компании')}
            </select>
            <div class="df-amount-range">
              <input class="df-input" id="df-amt-min" type="number" placeholder="Сумма от" value="${esc(f.amtMin)}" min="0">
              <span class="df-range-sep">—</span>
              <input class="df-input" id="df-amt-max" type="number" placeholder="до" value="${esc(f.amtMax)}" min="0">
            </div>
          </div>
        </div>

        <div class="mgr-table-wrap" id="detail-table-wrap">
          ${state.detailGrouped ? (() => {
            // ── Сгруппированный режим: одна строка = один клиент ──
            const grouped = {};
            for (const r of rows) {
              if (!grouped[r.client]) {
                grouped[r.client] = {
                  client: r.client, site: r.site || '',
                  total: 0, invoices: 0,
                  groups: {}, managers: new Set(),
                  trend: clientTrends[r.client],
                  mrr: r.mrr || 0,  // MRR уже прописан сервером в каждой строке
                };
              }
              const g = grouped[r.client];
              g.total   += r.amount;
              g.invoices++;
              g.groups[r.group] = (g.groups[r.group] || 0) + r.amount;
              if (r.manager) g.managers.add(fullMgr(r.manager));
            }
            const groupedRows = Object.values(grouped)
              .sort((a, b) => b.total - a.total);

            const PAGE_SIZE_G = 50;
            const totalPagesG = Math.ceil(groupedRows.length / PAGE_SIZE_G) || 1;
            if (state.detailPage >= totalPagesG) state.detailPage = totalPagesG - 1;
            const pageRowsG = groupedRows.slice(state.detailPage * PAGE_SIZE_G, (state.detailPage + 1) * PAGE_SIZE_G);

            const pagerG = totalPagesG > 1 ? `
              <div class="pager">
                <button class="pager-btn" id="pager-prev" ${state.detailPage === 0 ? 'disabled' : ''}>← Пред.</button>
                <span class="pager-info">Стр. ${state.detailPage + 1} из ${totalPagesG} · ${groupedRows.length} клиентов</span>
                <button class="pager-btn" id="pager-next" ${state.detailPage >= totalPagesG - 1 ? 'disabled' : ''}>След. →</button>
              </div>` : '';

            const bodyRows = pageRowsG.map((g, i) => {
              const clientMrr   = g.mrr || 0;
              const mrrRatio    = clientMrr > 0 ? (g.total / clientMrr * 100).toFixed(0) + '%' : '—';
              const mrrRatioCls = clientMrr > 0 ? (+mrrRatio.replace('%','') > 100 ? 'days-critical' : +mrrRatio.replace('%','') > 50 ? 'days-warn' : '') : '';
              const mgrList     = [...g.managers].join(', ') || '—';
              const grpBadges   = GROUPS
                .filter(grp => g.groups[grp] > 0)
                .map(grp => `<span class="grp-badge ${GRP_CSS[grp]}" style="font-size:0.7rem">${GRP_LABELS[grp]}: ${fmtRub(g.groups[grp])}</span>`)
                .join(' ');
              return `<tr>
                <td class="idx">${state.detailPage * PAGE_SIZE_G + i + 1}</td>
                <td><button class="client-name-btn" data-open-modal="${esc(g.client)}">${esc(g.client)}</button>${siteBadge(g.site)}</td>
                <td class="num amt-big">${fmtRub(g.total)}</td>
                <td class="num muted" style="text-align:right;font-size:0.8rem">${g.invoices}</td>
                <td>${grpBadges}</td>
                <td class="num ${mrrRatioCls}" style="text-align:right;font-size:0.8rem">${mrrRatio}</td>
                <td style="text-align:center">${TREND_HTML[g.trend] || '—'}</td>
                <td class="muted" style="font-size:0.8rem">${esc(mgrList)}</td>
                <td><button class="excl-btn" data-excl="${esc(g.client)}" title="Скрыть клиента">✕</button></td>
              </tr>`;
            }).join('');

            return `<table class="mgr-table">
              <thead><tr>
                <th class="idx">#</th>
                <th>Клиент</th>
                <th style="text-align:right">Общий долг</th>
                <th style="text-align:right" title="Количество счетов">Счётов</th>
                <th>Группы просрочки</th>
                <th style="text-align:right" title="Долг / MRR клиента">ДЗ/MRR</th>
                <th style="text-align:center">Тренд</th>
                <th>Менеджер</th>
                <th style="width:36px"></th>
              </tr></thead>
              <tbody>${groupedRows.length === 0
                ? `<tr><td colspan="9" class="empty-state">Нет данных</td></tr>`
                : bodyRows}
              </tbody>
            </table>${pagerG}`;
          })() : `
          <table class="mgr-table">
            <thead><tr>
              ${dHead('client',    'Клиент')}
              ${dHead('amount',    'Сумма долга', 'right')}
              <th style="text-align:right" title="Точное число дней просрочки на сегодня">Дней ↓</th>
              <th style="text-align:right" title="Доля счёта в общей ДЗ клиента">% ДЗ</th>
              <th style="text-align:right" title="Сколько счётов у клиента в таблице">Счётов</th>
              <th style="text-align:right" title="Отношение долга клиента к его MRR (из CS таблицы)">ДЗ/MRR</th>
              <th style="text-align:center" title="Изменение долга клиента с прошлой недели">Тренд</th>
              <th>Группа</th>
              ${dHead('dueDate',   'Срок оплаты')}
              <th>Направление</th>
              ${dHead('manager',   'Менеджер')}
              <th>Компания</th>
              <th>Номер счёта</th>
              <th>Статус</th>
              <th>Комментарий</th>
              <th style="width:36px"></th>
            </tr></thead>
            <tbody>
              ${rows.length === 0
                ? `<tr><td colspan="16" class="empty-state" style="text-align:center;padding:32px;color:var(--muted)">
                    Ничего не найдено${hasFilter ? ` — <button class="excl-reset" id="detail-reset-filters-2">сбросить фильтры</button>` : ''}
                  </td></tr>`
                : pageRows.map(r => {
                    const days  = daysOverdue(r.dueDate);
                    const pct   = clientTotals[r.client] > 0 ? (r.amount / clientTotals[r.client] * 100).toFixed(0) : 0;
                    const cnt   = clientCounts[r.client] || 1;
                    const clientMrr = r.mrr || 0;
                    const mrrRatio  = clientMrr > 0 ? (clientTotals[r.client] / clientMrr * 100).toFixed(0) + '%' : '—';
                    const mrrRatioCls = clientMrr > 0 ? (+mrrRatio.replace('%','') > 100 ? 'days-critical' : +mrrRatio.replace('%','') > 50 ? 'days-warn' : '') : '';
                    const trend = clientTrends[r.client];
                    const daysCls = days === null ? '' : days >= 91 ? 'days-critical' : days >= 61 ? 'days-warn' : days >= 31 ? 'days-medium' : 'days-ok';
                    return `<tr data-row-client="${esc(r.client)}">
                    <td><button class="client-name-btn" data-open-modal="${esc(r.client)}">${esc(r.client)}</button>${siteBadge(r.site||'')}</td>
                    <td class="num amt-big">${fmtRub(r.amount)}</td>
                    <td class="num ${daysCls}" style="text-align:right;font-weight:600">${days !== null ? days : '—'}</td>
                    <td class="num" style="text-align:right;color:var(--muted);font-size:0.8rem">${pct}%</td>
                    <td class="num" style="text-align:right;color:var(--muted);font-size:0.8rem">${cnt}</td>
                    <td class="num ${mrrRatioCls}" style="text-align:right;font-size:0.8rem">${mrrRatio}</td>
                    <td style="text-align:center">${TREND_HTML[trend] || '—'}</td>
                    <td><span class="grp-badge ${GRP_CSS[r.group]}">${GRP_LABELS[r.group]}</span></td>
                    <td class="muted">${fmtDate(r.dueDate)}</td>
                    <td class="muted">${esc(r.direction)}</td>
                    <td class="muted">${esc(fullMgr(r.manager))}</td>
                    <td class="muted" style="font-size:0.75rem">${esc(r.company)}</td>
                    <td class="muted">${esc(r.invoice)}</td>
                    <td class="muted" style="font-size:0.75rem">${esc(r.status)}</td>
                    <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.comment)}">${esc(r.comment)}</td>
                    <td><button class="excl-btn" data-excl="${esc(r.client)}" title="Скрыть клиента">✕</button></td>
                  </tr>`;
                  }).join('')}
            </tbody>
          </table>
          ${pagerHtml}`}
        </div>
      </div>`;
  }

  function exportDetailCsv(d) {
    const f = state.detailFilters;
    const amtMin = f.amtMin !== '' ? parseFloat(f.amtMin.replace(',','.')) : null;
    const amtMax = f.amtMax !== '' ? parseFloat(f.amtMax.replace(',','.')) : null;
    const rows = (d.allRows||[]).filter(r => {
      if (state.excluded.has(r.client)) return false;
      if (!state.groups.has(r.group)) return false;
      if (state.search) {
        const q = state.search.toLowerCase();
        if (!r.client.toLowerCase().includes(q) && !r.invoice.toLowerCase().includes(q) && !(r.direction||'').toLowerCase().includes(q)) return false;
      }
      if (f.client    && r.client    !== f.client)    return false;
      if (f.manager   && r.manager   !== f.manager)   return false;
      if (f.group     && r.group     !== f.group)     return false;
      if (f.direction && r.direction !== f.direction) return false;
      if (f.status    && r.status    !== f.status)    return false;
      if (f.company   && r.company   !== f.company)   return false;
      if (amtMin !== null && r.amount < amtMin) return false;
      if (amtMax !== null && r.amount > amtMax) return false;
      return true;
    });
    const { totals: csvTotals, counts: csvCounts } = buildClientMaps(d.allRows || []);
    const trends = d.clientTrends || {};
    const trendLabel = { up: '↑ Рост', down: '↓ Снижение', same: '→ Без изм.', new: '✦ Новый' };
    const headers = ['Клиент','Сумма','Дней просрочки','% от ДЗ клиента','Счётов у клиента','Тренд','Группа','Срок оплаты','Направление','Менеджер','Компания','Номер счёта','Статус','Комментарий'];
    const csvEsc = s => { s = String(s==null?'':s); return /[",\n\r;]/.test(s) ? '"'+s.replace(/"/g,'""')+'"' : s; };
    const lines = [headers.join(';')].concat(rows.map(r => {
      const days = daysOverdue(r.dueDate);
      const pct  = csvTotals[r.client] > 0 ? (r.amount / csvTotals[r.client] * 100).toFixed(0) + '%' : '';
      return [
        r.client, r.amount, days ?? '', pct, csvCounts[r.client] || 1,
        trendLabel[trends[r.client]] || '',
        GRP_LABELS[r.group]||r.group, r.dueDate, r.direction,
        fullMgr(r.manager), r.company, r.invoice, r.status, r.comment
      ].map(csvEsc).join(';');
    }));
    const bom = '\uFEFF';
    const blob = new Blob([bom + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'dz_detail_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
  }

  // ─── Вспомогалки ──────────────────────────────────────────
  const sortArrow  = col => state.sortCol === col        ? (state.sortDir === 'asc'       ? '↑' : '↓') : '↕';
  const dSortArrow = col => state.detailSortCol === col  ? (state.detailSortDir === 'asc' ? '↑' : '↓') : '↕';

  // ─── Переключение темы ────────────────────────────────────
  function toggleTheme() {
    const html = document.getElementById('html-root');
    if (!html) return;
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('aq_theme', next);
    render(); // перерисовываем иконку кнопки
  }

  // ─── Привязка событий ─────────────────────────────────────
  function attachEvents() {
    // Вкладки
    document.querySelectorAll('[data-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        const t = btn.dataset.tab;
        if (state.mainTab !== t) {
          state.mainTab = t;
          const url = new URL(location.href);
          url.searchParams.set('tab', t);
          history.replaceState(null, '', url);
          render();
        }
      });
    });

    // Тема
    document.getElementById('btn-theme-toggle')?.addEventListener('click', toggleTheme);

    // Обновить вручную
    document.getElementById('btn-refresh')?.addEventListener('click', doRefresh);

    // Фильтр-чипы групп
    document.querySelectorAll('[data-grp]').forEach(chip => {
      chip.addEventListener('click', () => {
        const g = chip.dataset.grp;
        if (state.groups.has(g)) { if (state.groups.size > 1) state.groups.delete(g); }
        else state.groups.add(g);
        render();
      });
    });

    // Сортировка TOP-10
    document.querySelectorAll('[data-sort-col]').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.dataset.sortCol;
        if (state.sortCol === col) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        else { state.sortCol = col; state.sortDir = col === 'total' ? 'desc' : 'asc'; }
        render();
      });
    });

    // Скрыть клиента
    document.querySelectorAll('[data-excl]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.excluded.add(btn.dataset.excl);
        saveExcluded();
        render();
      });
    });

    // Сбросить скрытых
    document.getElementById('excl-reset')?.addEventListener('click', () => {
      state.excluded.clear();
      saveExcluded();
      render();
    });

    // Сортировка детальной таблицы
    document.querySelectorAll('[data-dsort]').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.dataset.dsort;
        if (state.detailSortCol === col) state.detailSortDir = state.detailSortDir === 'asc' ? 'desc' : 'asc';
        else { state.detailSortCol = col; state.detailSortDir = col === 'amount' ? 'desc' : 'asc'; }
        render();
      });
    });

    // Поиск
    const si = document.getElementById('detail-search');
    if (si) {
      si.addEventListener('input', e => {
        state.search = e.target.value;
        state.detailPage = 0;
        render();
        const el = document.getElementById('detail-search');
        if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
      });
    }

    // Фильтры детальной таблицы
    // ── Dropdown менеджеров ───────────────────────────────────
    const ddBtn = document.getElementById('mgr-dd-btn');
    if (ddBtn) {
      ddBtn.addEventListener('click', e => {
        e.stopPropagation();
        mgrDropOpen = !mgrDropOpen;
        render();
        // Восстанавливаем фокус на кнопке после перерисовки
        requestAnimationFrame(() => document.getElementById('mgr-dd-btn')?.focus());
      });
    }

    // Чекбоксы менеджеров
    document.querySelectorAll('.mgr-dd-cb').forEach(cb => {
      cb.addEventListener('change', e => {
        const mgr = cb.dataset.mgr;
        if (cb.checked) state.detailFilters.managers.add(mgr);
        else            state.detailFilters.managers.delete(mgr);
        // Не закрываем dropdown — пользователь может выбрать ещё
        render();
        // Сохраняем позицию скролла в панели
        requestAnimationFrame(() => {
          const panel = document.getElementById('mgr-dd-panel');
          if (panel) panel.scrollTop = 0;
        });
      });
    });

    // Кнопка «Сбросить» внутри dropdown
    document.getElementById('mgr-dd-clear')?.addEventListener('click', e => {
      e.stopPropagation();
      state.detailFilters.managers.clear();
      render();
    });

    // Клик вне dropdown — закрыть
    document.addEventListener('click', function closeDrop(e) {
      if (!document.getElementById('mgr-dd-wrap')?.contains(e.target)) {
        if (mgrDropOpen) { mgrDropOpen = false; render(); }
        document.removeEventListener('click', closeDrop);
      }
    });

    const bindDf = (id, key) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', e => { state.detailFilters[key] = e.target.value; state.detailPage = 0; render(); });
    };
    const bindDfInput = (id, key) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', e => { state.detailFilters[key] = e.target.value; state.detailPage = 0; render(); });
    };
    bindDf('df-client',    'client');
    bindDf('df-group',     'group');
    bindDf('df-direction', 'direction');
    bindDf('df-status',    'status');
    bindDf('df-company',   'company');
    bindDfInput('df-amt-min', 'amtMin');
    bindDfInput('df-amt-max', 'amtMax');

    const resetFilters = () => {
      state.detailFilters = { client:'', managers: new Set(), group:'', amtMin:'', amtMax:'', direction:'', status:'', company:'' };
      state.search = '';
      mgrDropOpen = false;
      render();
    };
    document.getElementById('detail-reset-filters')?.addEventListener('click', resetFilters);
    document.getElementById('detail-reset-filters-2')?.addEventListener('click', resetFilters);

    // Переключение режима группировки
    document.getElementById('detail-group-btn')?.addEventListener('click', () => {
      state.detailGrouped = !state.detailGrouped;
      state.detailPage = 0;
      render();
    });

    // CSV экспорт
    document.getElementById('detail-csv-btn')?.addEventListener('click', () => {
      if (state.data) exportDetailCsv(state.data);
    });

    // Панель скрытых
    document.getElementById('btn-hidden-toggle')?.addEventListener('click', () => {
      state.showHiddenPanel = !state.showHiddenPanel;
      render();
    });
    document.getElementById('excl-restore-all')?.addEventListener('click', () => {
      state.excluded.clear();
      saveExcluded();
      state.showHiddenPanel = false;
      render();
    });
    document.querySelectorAll('[data-restore]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.excluded.delete(btn.dataset.restore);
        saveExcluded();
        if (state.excluded.size === 0) state.showHiddenPanel = false;
        render();
      });
    });

    // ── Пагинация ─────────────────────────────────────────────
    document.getElementById('pager-prev')?.addEventListener('click', () => {
      if (state.detailPage > 0) { state.detailPage--; render(); }
    });
    document.getElementById('pager-next')?.addEventListener('click', () => {
      state.detailPage++;
      render();
    });

    // ── Клик на менеджера в "Чемпионы" → фильтр по менеджеру ──
    document.querySelectorAll('[data-open-mgr-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        const mgr = btn.dataset.openMgrFilter;
        state.detailFilters.managers.clear();
        state.detailFilters.managers.add(mgr);
        state.detailPage = 0;
        render();
        // Прокрутка к детальной таблице
        requestAnimationFrame(() => {
          document.getElementById('detail-table-wrap')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    });

    // ── Открытие модалки ──────────────────────────────────────
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.modalClient = btn.dataset.openModal;
        render();
      });
    });

    // Клик по имени в ТОП-10 тоже открывает модалку
    document.querySelectorAll('[data-client-modal]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.modalClient = btn.dataset.clientModal;
        render();
      });
    });

    // ── Закрытие модалки ──────────────────────────────────────
    document.getElementById('modal-close')?.addEventListener('click', () => {
      state.modalClient = null; render();
    });
    document.getElementById('modal-overlay')?.addEventListener('click', e => {
      if (e.target.id === 'modal-overlay') { state.modalClient = null; render(); }
    });
    document.addEventListener('keydown', function escModal(e) {
      if (e.key === 'Escape' && state.modalClient) { state.modalClient = null; render(); document.removeEventListener('keydown', escModal); }
    });

    // ── Сохранение комментария ────────────────────────────────
    document.getElementById('modal-comment-save')?.addEventListener('click', () => {
      const ta = document.getElementById('modal-comment-ta');
      if (ta && state.modalClient) {
        saveComment(state.modalClient, ta.value);
        // brief flash feedback
        ta.style.borderColor = 'var(--ok)';
        setTimeout(() => { if (ta) ta.style.borderColor = ''; }, 1000);
      }
    });
  }

  // ─── Обновление данных ────────────────────────────────────
  async function doRefresh() {
    if (state.loading) return;
    state.loading = true;
    render();
    try {
      const res  = await fetch('manager_api.php', { cache: 'no-store' });
      const json = await res.json();
      if (json.ok && json.data) state.data = json.data;
      else showError(json.error || 'Ошибка получения данных');
    } catch (e) {
      showError(e.message);
    } finally {
      state.loading = false;
      render();
    }
  }

  function showError(msg) {
    const app = document.getElementById('app');
    if (!app) return;
    const el = document.createElement('div');
    el.className = 'mgr-error';
    el.innerHTML = `⚠ Ошибка: ${esc(msg)} <button onclick="this.parentNode.remove()" style="margin-left:12px;background:none;border:none;color:inherit;cursor:pointer;font-size:1rem">✕</button>`;
    app.prepend(el);
    setTimeout(() => el.remove(), 8000);
  }

  // ─── Авто-обновление каждые 5 минут ──────────────────────
  let autoTimer     = null;
  let nextRefreshAt = 0;
  let countdownInt  = null;

  function scheduleAuto() {
    clearTimeout(autoTimer);
    nextRefreshAt = Date.now() + AUTO_MS;
    autoTimer = setTimeout(async () => {
      if (!document.hidden) await doRefresh();
      scheduleAuto();
    }, AUTO_MS);
    clearInterval(countdownInt);
    countdownInt = setInterval(updateCountdown, 15_000);
  }

  function updateCountdown() {
    const el = document.getElementById('next-refresh');
    if (!el) return;
    const sec = Math.max(0, Math.round((nextRefreshAt - Date.now()) / 1000));
    const m = Math.floor(sec / 60), s = sec % 60;
    el.textContent = `· авто через ${m}:${String(s).padStart(2, '0')}`;
  }

  // ─── Инициализация ────────────────────────────────────────
  function init() {
    const urlParams = new URLSearchParams(location.search);

    // URL ?mgr=ИмяМенеджера — прямая ссылка на фильтр менеджера
    const urlMgr = urlParams.get('mgr');
    if (urlMgr) state.detailFilters.managers.add(urlMgr);

    // URL ?tab=dz|churn|fact — начальная вкладка
    const urlTab = urlParams.get('tab');
    if (urlTab && ['dz', 'churn', 'fact'].includes(urlTab)) state.mainTab = urlTab;

    // ДЗ данные
    const el = document.getElementById('manager-bootstrap');
    if (el) {
      try { state.data = JSON.parse(el.textContent); }
      catch (e) { showError('Ошибка разбора данных ДЗ: ' + e.message); }
    }

    // Данные угрозы Churn
    const ce = document.getElementById('churn-bootstrap');
    if (ce) {
      try { state.churnData = JSON.parse(ce.textContent); }
      catch (e) { /* молчим — раздел просто покажет заглушку */ }
    }

    // Данные фактического Churn
    const fe = document.getElementById('fact-bootstrap');
    if (fe) {
      try { state.factData = JSON.parse(fe.textContent); }
      catch (e) { /* молчим */ }
    }

    render();
    scheduleAuto();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

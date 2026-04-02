/* ============================================================
   Еженедельный отчёт по ДЗ  |  weekly.js  v2
   Данные приходят из ManagerReport::fetchReport() через
   <script id="weekly-bootstrap"> в weekly.php.
   ============================================================ */

(function () {
  'use strict';

  // ── Хелперы ─────────────────────────────────────────────
  const fmtRub = n =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n)) + '\u00a0₽';
  const fmtPct  = n  => `${n}%`;
  const fmtDate = s  => {
    if (!s) return '—';
    const p = String(s).slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s;
  };
  const esc = s =>
    String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  // ── Prev TOP-10 для дельты (E6a) ────────────────────────
  const PREV_KEY = 'wk_top10_v2';
  const loadPrevTop10 = () => {
    try { return JSON.parse(localStorage.getItem(PREV_KEY) || '{}') || {}; } catch { return {}; }
  };
  const savePrevTop10 = top10 => {
    try {
      const s = {};
      (top10 || []).forEach(c => { s[c.client] = c.total; });
      localStorage.setItem(PREV_KEY, JSON.stringify(s));
    } catch {}
  };

  // ── Загрузка данных ──────────────────────────────────────
  const bootstrapEl = document.getElementById('weekly-bootstrap');
  if (!bootstrapEl) return;
  let d;
  try { d = JSON.parse(bootstrapEl.textContent); }
  catch (e) { document.getElementById('app').innerHTML = '<p style="padding:20px;color:red">Ошибка чтения данных отчёта.</p>'; return; }

  // ── Главный рендер ───────────────────────────────────────
  function render() {
    const app = document.getElementById('app');
    if (!app) return;
    app.innerHTML =
      renderTopbar() +
      `<div class="wk-wrap">
        ${renderHeader()}
        ${renderBlock1()}
        ${renderBlock2()}
        ${renderBlock3()}
        ${renderBlock4()}
      </div>`;
    // Сохраняем текущий TOP-10 для следующего сравнения
    savePrevTop10(d.top10);
    attachEvents();
  }

  // ── Копирование сводки (C1) ──────────────────────────────
  function buildTextSummary() {
    const lines = [];
    lines.push(`Еженедельный отчёт по ДЗ — ${fmtDate(d.weekStart)}–${fmtDate(d.weekEnd)}`);
    lines.push(`Данные: ${d.generatedAt}`);
    lines.push('');
    lines.push(`Вся ДЗ: ${fmtRub(d.totalDebt || 0)}`);
    lines.push(`ТОП-10: ${fmtRub(d.top10Total || 0)} (${d.top10Percent || 0}% от итого)`);
    lines.push(`MRR: ${fmtRub(d.mrr || 0)} | ДЗ/MRR: ${d.debtToRevPct || 0}%`);
    lines.push('');
    lines.push('ТОП-10 дебиторов:');
    (d.top10 || []).forEach((c, i) => {
      lines.push(`  ${i + 1}. ${c.client} — ${fmtRub(c.total)}`);
    });
    lines.push('');
    lines.push(`Оплачено за неделю: ${fmtRub(d.payments?.weekTotal || 0)} (${d.payments?.count || 0} клиентов)`);
    lines.push('');
    lines.push('Группы просрочки:');
    const gt = d.groupTotals || {};
    ['16-30','31-60','61-90','91+'].forEach(g => {
      if (gt[g]) lines.push(`  ${g} дн: ${fmtRub(gt[g])}`);
    });
    return lines.join('\n');
  }

  async function doCopy() {
    const btn = document.getElementById('copy-btn');
    try {
      await navigator.clipboard.writeText(buildTextSummary());
      if (btn) { btn.textContent = '✓ Скопировано'; setTimeout(() => { btn.textContent = '📋 Сводка'; }, 2000); }
    } catch {
      if (btn) { btn.textContent = '✗ Ошибка'; setTimeout(() => { btn.textContent = '📋 Сводка'; }, 2000); }
    }
  }

  // ── Топбар ───────────────────────────────────────────────
  function renderTopbar() {
    return `
      <div class="topbar">
        <div class="topbar-nav">
          <a href="manager.php" class="wk-back-link">← Дашборд</a>
          <span class="wk-topbar-title">Еженедельный отчёт по ДЗ</span>
        </div>
        <div class="topbar-nav wk-topbar-tabs">
          <a href="churn.php" class="wk-tab-link">Угроза Churn</a>
          <a href="churn_fact.php" class="wk-tab-link">Потери</a>
          <a href="manager.php" class="wk-tab-link">ДЗ</a>
          <a href="ai_insights.php" class="wk-tab-link">AI</a>
          <span class="wk-tab-link wk-tab-active">Еженедельный</span>
        </div>
        <div class="topbar-nav">
          <button class="wk-copy-btn" id="copy-btn" title="Скопировать сводку отчёта в буфер обмена" aria-label="Скопировать еженедельную сводку в буфер обмена">📋 Сводка</button>
          <button class="wk-refresh-btn" id="refresh-btn" title="Обновить данные" aria-label="Обновить данные из Airtable"><span aria-hidden="true">↻</span> Обновить</button>
          <button class="theme-btn" id="theme-btn" title="Переключить тему" aria-label="Переключить цветовую тему"><span aria-hidden="true">☀</span></button>
        </div>
      </div>`;
  }

  // ── Обновление данных ────────────────────────────────────
  async function doRefresh() {
    const btn = document.getElementById('refresh-btn');
    if (btn) { btn.disabled = true; btn.textContent = '↻ Загрузка…'; }
    try {
      const resp = await fetch(location.href, { cache: 'no-store' });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const html = await resp.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const newBs = doc.getElementById('weekly-bootstrap');
      if (!newBs) throw new Error('bootstrap not found');
      d = JSON.parse(newBs.textContent);
      render();
    } catch (e) {
      if (btn) { btn.disabled = false; btn.textContent = '↻ Обновить'; }
      alert('Не удалось обновить данные: ' + e.message);
    }
  }

  // ── Шапка страницы ───────────────────────────────────────
  function renderHeader() {
    return `
      <div class="wk-header">
        <h2 class="wk-title">Еженедельный отчёт по дебиторской задолженности</h2>
        <div class="wk-period">Период: ${fmtDate(d.weekStart)} — ${fmtDate(d.weekEnd)}</div>
        <div class="wk-generated">Данные получены: ${esc(d.generatedAt)}</div>
      </div>`;
  }

  // ═══════════════════════════════════════════════════════════
  // БЛОК 1 — ТОП-10 дебиторов
  // D1: % ТОП-10 от всей ДЗ уже реализован через top10Percent
  // E6a: дельта-колонка ±X₽ сравнение с прошлой неделей
  // ═══════════════════════════════════════════════════════════
  function renderBlock1() {
    const top10   = d.top10 || [];
    const total   = d.totalDebt   || 0;
    const t10sum  = d.top10Total  || 0;
    const t10pct  = d.top10Percent || 0;
    const prev    = loadPrevTop10();
    const hasPrev = Object.keys(prev).length > 0;

    const rows = top10.map((c, i) => {
      const share = total > 0 ? (c.total / total * 100).toFixed(1) : '0.0';
      let deltaHtml = '';
      if (hasPrev) {
        const prevAmt = prev[c.client] ?? null;
        if (prevAmt === null) {
          deltaHtml = `<span class="wk-delta-new" title="Новый в TOP-10">new</span>`;
        } else {
          const delta = c.total - prevAmt;
          if (Math.abs(delta) < 1) {
            deltaHtml = `<span class="wk-delta-same">—</span>`;
          } else {
            const sign = delta > 0 ? '+' : '';
            const cls  = delta > 0 ? 'wk-delta-up' : 'wk-delta-down';
            const arrow = delta > 0 ? '▲' : '▼';
            deltaHtml  = `<span class="${cls}" title="${sign}${fmtRub(delta)} к прошлой неделе">${arrow}${fmtRub(Math.abs(delta))}</span>`;
          }
        }
      }
      return `<tr>
        <td class="wk-rank">${i + 1}</td>
        <td>${esc(c.client)}</td>
        <td class="wk-amount">${fmtRub(c.total)}</td>
        <td class="wk-pct-cell">${share}%</td>
        ${hasPrev ? `<td class="wk-delta-cell">${deltaHtml}</td>` : ''}
      </tr>`;
    }).join('') || `<tr><td colspan="${hasPrev ? 5 : 4}" class="wk-empty">Нет данных по дебиторам</td></tr>`;

    return `
      <section class="wk-block">
        <h3 class="wk-block-title">📋 ТОП-10 дебиторов</h3>
        <div class="wk-kpi-row">
          <div class="wk-kpi-card">
            <div class="wk-kpi-label">Вся ДЗ сегодня</div>
            <div class="wk-kpi-value">${fmtRub(total)}</div>
          </div>
          <div class="wk-kpi-card wk-kpi-accent">
            <div class="wk-kpi-label">ТОП-10 суммарно</div>
            <div class="wk-kpi-value">${fmtRub(t10sum)}</div>
          </div>
          <div class="wk-kpi-card wk-kpi-accent">
            <div class="wk-kpi-label">% от всей ДЗ</div>
            <div class="wk-kpi-value">${fmtPct(t10pct)}</div>
          </div>
        </div>
        <div class="wk-table-wrap wk-fade-wrap">
          <table class="wk-table">
            <thead>
              <tr>
                <th>#</th><th>Клиент</th><th>Сумма ДЗ</th><th>% от итого</th>
                ${hasPrev ? '<th title="Изменение ±₽ к прошлой неделе">Дельта нед/нед</th>' : ''}
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${top10.length === 0 ? `<div class="wk-empty-state">Нет данных по ТОП-10 дебиторам за период</div>` : ''}
      </section>`;
  }

  // ═══════════════════════════════════════════════════════════
  // БЛОК 2 — % ДЗ от выручки
  // E6c: mini-sparkline под gauge — 4 последние недели
  // ═══════════════════════════════════════════════════════════
  function renderBlock2() {
    const pct    = d.debtToRevPct || 0;
    const mrr    = d.mrr          || 0;
    const total  = d.totalDebt    || 0;
    const isOk   = pct <= 30;
    const cls    = isOk ? 'wk-gauge-ok' : 'wk-gauge-warn';
    const status = isOk ? '✓ В норме (≤ 30%)' : '✗ Превышение порога (> 30%)';

    const barWidth = Math.min(pct, 100).toFixed(1);

    const mrrNote = d.mrrMeta?.yearMonth
      ? ` · выручка за ${esc(d.mrrMeta.yearMonth)}`
      : '';

    // Sparkline из weeklyHistory (E6c)
    const history = d.weeklyHistory || [];
    const sparkHtml = renderSparkline(history);

    return `
      <section class="wk-block">
        <h3 class="wk-block-title">📊 ДЗ от выручки</h3>
        <div class="wk-gauge-wrap">
          <div class="wk-gauge-number ${cls}">${pct}%</div>
          <div class="wk-gauge-label">ДЗ / MRR × 100%</div>
          <div class="wk-gauge-bar-bg" style="overflow:visible; overflow:hidden">
            <div class="wk-gauge-bar-fill ${cls}" style="width:${barWidth}%"></div>
            <div class="wk-gauge-threshold-line" title="Порог 30%"></div>
          </div>
          <div class="wk-gauge-status ${cls}">${status}</div>
          <div class="wk-gauge-detail">
            ДЗ:&nbsp;<strong>${fmtRub(total)}</strong>
            &nbsp;/&nbsp;
            MRR:&nbsp;<strong>${fmtRub(mrr)}</strong>
            <span class="wk-muted">${mrrNote}</span>
          </div>
        </div>
        ${sparkHtml}
      </section>`;
  }

  // ── Sparkline из weeklyHistory (последние 4 недели) ──────
  function renderSparkline(history) {
    if (!history || history.length < 2) return '';
    const last4 = history.slice(-4);
    const vals  = last4.map(h => h.totalDebt || 0);
    const max   = Math.max(...vals);
    const min   = Math.min(...vals);
    const range = max - min || 1;
    const W = 200, H = 44;
    const PAD = 6;
    const pts = vals.map((v, i) => {
      const x = PAD + i * ((W - PAD * 2) / (vals.length - 1));
      const y = PAD + (1 - (v - min) / range) * (H - PAD * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
    const lastX = parseFloat(pts.split(' ').pop().split(',')[0]);
    const lastY = parseFloat(pts.split(' ').pop().split(',')[1]);
    const trend = vals[vals.length - 1] <= vals[0] ? 'wk-spark-ok' : 'wk-spark-warn';
    const color = vals[vals.length - 1] <= vals[0] ? '#34c759' : '#ff453a';
    return `
      <div class="wk-sparkline-wrap" title="Динамика ДЗ: последние ${last4.length} недели">
        <div class="wk-spark-label">Динамика ДЗ — последние ${last4.length} нед.</div>
        <svg class="wk-sparkline ${trend}" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
          <polyline points="${pts}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
          <circle cx="${lastX}" cy="${lastY}" r="3" fill="${color}"/>
        </svg>
        <div class="wk-spark-dates">
          ${last4.map(h => `<span>${h.week ? String(h.week).slice(5) : '?'}</span>`).join('')}
        </div>
      </div>`;
  }

  // ═══════════════════════════════════════════════════════════
  // БЛОК 3 — Оплаты за прошлую неделю
  // ═══════════════════════════════════════════════════════════
  function renderBlock3() {
    const pay    = d.payments || {};
    const top5   = pay.top5      || [];
    const ft10   = pay.fromTop10 || [];
    const wTotal = pay.weekTotal || 0;
    const wCount = pay.count     || 0;

    const top5Rows = top5.map((p, i) => `<tr>
        <td class="wk-rank">${i + 1}</td>
        <td>${esc(p.client)}</td>
        <td class="wk-amount">${fmtRub(p.amount)}</td>
        <td class="wk-date">${fmtDate(p.date)}</td>
      </tr>`).join('') ||
      `<tr><td colspan="4" class="wk-empty">Оплат за период не найдено</td></tr>`;

    const ft10Rows = ft10.map(p => `<tr>
        <td>${esc(p.client)}</td>
        <td class="wk-amount">${fmtRub(p.amount)}</td>
        <td class="wk-date">${fmtDate(p.date)}</td>
      </tr>`).join('') ||
      `<tr><td colspan="3" class="wk-empty">Никто из ТОП-10 не платил на этой неделе</td></tr>`;

    return `
      <section class="wk-block">
        <h3 class="wk-block-title">
          💰 Оплаты за прошлую неделю
          <span class="wk-period-badge">${fmtDate(d.weekStart)} — ${fmtDate(d.weekEnd)}</span>
        </h3>
        <div class="wk-kpi-row">
          <div class="wk-kpi-card wk-kpi-ok">
            <div class="wk-kpi-label">Итого оплачено</div>
            <div class="wk-kpi-value">${fmtRub(wTotal)}</div>
          </div>
          <div class="wk-kpi-card">
            <div class="wk-kpi-label">Клиентов оплатило</div>
            <div class="wk-kpi-value">${wCount}</div>
          </div>
        </div>
        ${wTotal === 0 ? `<div class="wk-empty-state">За выбранный период оплат не зафиксировано</div>` : ''}
        <div class="wk-two-col">
          <div>
            <div class="wk-sub-title">ТОП-5 самых крупных оплат</div>
            <div class="wk-table-wrap wk-fade-wrap">
              <table class="wk-table">
                <thead><tr><th>#</th><th>Клиент</th><th>Сумма</th><th>Дата</th></tr></thead>
                <tbody>${top5Rows}</tbody>
              </table>
            </div>
          </div>
          <div>
            <div class="wk-sub-title">Оплаты из ТОП-10 дебиторов</div>
            <div class="wk-table-wrap wk-fade-wrap">
              <table class="wk-table">
                <thead><tr><th>Клиент</th><th>Сумма</th><th>Дата</th></tr></thead>
                <tbody>${ft10Rows}</tbody>
              </table>
            </div>
          </div>
        </div>
      </section>`;
  }

  // ═══════════════════════════════════════════════════════════
  // БЛОК 4 — Группы просрочки
  // E6b: стрелки тренда ▲▼ + «+X₽ vs прошлая неделя»
  // ═══════════════════════════════════════════════════════════
  function renderBlock4() {
    const GROUPS = ['16-30', '31-60', '61-90', '91+'];
    const LABELS = {
      '16-30': '16–30 дней',
      '31-60': '31–60 дней',
      '61-90': '61–90 дней',
      '91+':   '91+ дней',
    };
    const COLORS = {
      '16-30': '#f5a623',
      '31-60': '#ff9500',
      '61-90': '#ff6b35',
      '91+':   '#ff453a',
    };

    const gt  = d.groupTotals      || {};
    const at  = d.agingTransition  || {};
    const maxVal = Math.max(...GROUPS.map(g => gt[g] || 0), 1);

    const cards = GROUPS.map(g => {
      const val   = gt[g]  || 0;
      const trans = at[g]  || {};
      const delta = trans.delta || 0;
      const barPct = (val / maxVal * 100).toFixed(1);

      let deltaHtml;
      if (Math.abs(delta) < 1) {
        deltaHtml = `<span class="wk-delta-same">без изменений нед/нед</span>`;
      } else if (delta > 0) {
        deltaHtml = `<span class="wk-delta-up">▲ +${fmtRub(delta)} vs прошлая неделя</span>`;
      } else {
        deltaHtml = `<span class="wk-delta-down">▼ ${fmtRub(delta)} vs прошлая неделя</span>`;
      }

      return `
        <div class="wk-aging-card">
          <div class="wk-aging-label">${LABELS[g]}</div>
          <div class="wk-aging-amount">${fmtRub(val)}</div>
          <div class="wk-aging-bar-bg">
            <div class="wk-aging-bar-fill" style="width:${barPct}%; background:${COLORS[g]}"></div>
          </div>
          <div class="wk-aging-delta">${deltaHtml}</div>
        </div>`;
    }).join('');

    const totalDebt = Object.values(gt).reduce((a, b) => a + b, 0);

    return `
      <section class="wk-block">
        <h3 class="wk-block-title">📉 Группы просрочки</h3>
        ${totalDebt === 0 ? `<div class="wk-empty-state">Нет данных по группам просрочки</div>` : `<div class="wk-aging-grid">${cards}</div>`}
      </section>`;
  }

  // ── События ──────────────────────────────────────────────
  function attachEvents() {
    const tb = document.getElementById('theme-btn');
    if (tb) {
      tb.addEventListener('click', () => {
        const root    = document.getElementById('html-root');
        const current = root.getAttribute('data-theme') || 'dark';
        const next    = current === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        localStorage.setItem('aq_theme', next);
        tb.textContent = next === 'dark' ? '☀' : '🌙';
      });
      const theme = document.getElementById('html-root').getAttribute('data-theme') || 'dark';
      tb.textContent = theme === 'dark' ? '☀' : '🌙';
    }
    const rb = document.getElementById('refresh-btn');
    if (rb) rb.addEventListener('click', doRefresh);

    const cb = document.getElementById('copy-btn');
    if (cb) cb.addEventListener('click', doCopy);
  }

  // ── Запуск ───────────────────────────────────────────────
  render();

})();

(function () {
  'use strict';

  /* ── Data & State ─────────────────────────────────────────────────── */
  var D = null;          // current dataset
  var REFRESH_SEC = 300; // auto-refresh interval (5 min)
  var countdown = REFRESH_SEC;
  var tickTimer = null;
  var autoTimer = null;

  var ST = {
    search: '',
    mgr: '',
    status: '',
    company: '',
    direction: '',
    overdueOnly: false,
    amtMin: '',
    amtMax: '',
    sortCol: 'daysOverdue',
    sortDir: 'desc',
    page: 0,
    PAGE_SIZE: 100,
    loading: false,
    rateHint: '',
  };

  var DASH_CSS_VER = '29';
  var AQ_MARK =
    '<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M2 12V4l6-2 6 2v8l-6 2-6-2zm6-9.2L4.5 4.3v7.4L8 13.2l3.5-1.5V4.3L8 2.8z"/><circle cx="8" cy="8" r="1.6" fill="currentColor"/></svg>';

  /* ── Helpers ──────────────────────────────────────────────────────── */
  function fmt(n) {
    if (n == null || isNaN(n)) return '—';
    return Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
  }

  function fmtK(n) {
    if (n == null || isNaN(n)) return '—';
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + ' М₽';
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(0) + ' К₽';
    return Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
  }

  function pct(a, b) {
    if (!b) return 0;
    return Math.round(a / b * 100);
  }

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function el(id) { return document.getElementById(id); }

  function fmtDate(s) {
    if (!s) return '—';
    var d = s.slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return s;
    var p = d.split('-');
    return p[2] + '.' + p[1] + '.' + p[0];
  }

  function agingColor(key) {
    return {
      '0-15':  '#34c759',
      '16-30': '#f5a623',
      '31-60': '#ff7c00',
      '61-90': '#ff453a',
      '91+':   '#d00',
      // Обратная совместимость со старыми ключами кэша
      '0–30':  '#f5a623',
      '31–60': '#ff7c00',
      '61–90': '#ff453a',
      '90+':   '#d00',
    }[key] || '#8b97a8';
  }

  /* ── Situation logic ──────────────────────────────────────────────── */
  function situation(d) {
    var total = d.kpi.totalDebt || 0;
    var over  = d.kpi.overdueDebt || 0;
    var ag90  = (d.aging && (d.aging['91+'] || d.aging['90+'])) || 0;
    var pctOver = pct(over, total);
    var pct90   = pct(ag90, total);
    if (pctOver < 25 && pct90 < 15) {
      return { icon: '🟢', label: 'Ситуация в норме',     cls: 'sit-ok',   text: 'Просрочка ' + pctOver + '%, критичная (91+) ' + pct90 + '%' };
    }
    if (pctOver <= 50 && pct90 <= 30) {
      return { icon: '🟡', label: 'Требует внимания',     cls: 'sit-warn', text: 'Просрочка ' + pctOver + '%, критичная (91+) ' + pct90 + '%' };
    }
    return   { icon: '🔴', label: 'Критическая ситуация', cls: 'sit-crit', text: 'Просрочка ' + pctOver + '%, критичная (91+) ' + pct90 + '%' };
  }

  /* ── Build sections ────────────────────────────────────────────────── */
  function buildTopbar(d) {
    var gen = d.generatedAt || (d.meta && d.meta.generatedAt) || '';
    var genDisp = gen ? fmtDate(gen.slice(0, 10)) + ' ' + gen.slice(11, 16) : '';
    var cachedNote = d.cached ? ' <span class="topbar-cached" title="Данные из кэша, возраст ' + (d.cacheAge || 0) + 'с">(кэш)</span>' : '';
    var isLight = typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'light';
    var themeIcon = isLight ? '🌙' : '☀️';
    var themeTitle = isLight ? 'Тёмная тема' : 'Светлая тема';
    var rateBlock = '<div id="rate-banner" class="topbar-rate' + (ST.rateHint ? ' visible' : '') + '">' +
      (ST.rateHint ? '<strong>Лимит API.</strong> ' + esc(ST.rateHint) : '') +
      '</div>';
    return rateBlock +
    '<div class="topbar" id="topbar">' +
      '<div class="topbar-left">' +
        '<span class="topbar-title">📊 ДЗ Дашборд</span>' +
        (genDisp ? '<span class="topbar-gen" title="Время последнего обновления данных в Airtable">обновлено ' + esc(genDisp) + cachedNote + '</span>' : '') +
      '</div>' +
      '<div class="topbar-brand">' +
        '<a class="anyquery-logo" href="https://anyquery.io" target="_blank" rel="noopener noreferrer" title="Anyquery — SQL для SaaS">' +
          AQ_MARK + ' Anyquery' +
        '</a>' +
      '</div>' +
      '<div class="topbar-right">' +
        '<button type="button" class="btn-icon btn-theme" id="btn-theme" title="' + esc(themeTitle) + '">' + themeIcon + '</button>' +
        '<button type="button" class="btn-icon" id="btn-export-html" title="Скачать страницу как HTML (нужен доступ к тому же серверу для стилей)">⬇ HTML</button>' +
        '<a class="btn-link" href="manager.php" title="Дашборд руководителя по дебиторам">Руководитель</a>' +
        '<button class="btn-icon" id="btn-export-csv" title="Скачать таблицу счетов в CSV (Excel)">⬇ CSV</button>' +
        '<button class="btn-icon" id="btn-refresh" title="Обновить данные из Airtable прямо сейчас">↺ <span id="cd">' + countdown + '</span>с</button>' +
      '</div>' +
    '</div>';
  }

  function buildSituation(d) {
    var s = situation(d);
    return '<div class="sit-card ' + s.cls + '" title="Светофор состояния портфеля: зелёный — всё хорошо, жёлтый — внимание, красный — критично">' +
      '<span class="sit-icon">' + s.icon + '</span>' +
      '<div class="sit-body">' +
        '<strong>' + s.label + '</strong>' +
        '<span>' + s.text + '</span>' +
      '</div>' +
    '</div>';
  }

  function buildKpis(d) {
    var total  = d.kpi.totalDebt || 0;
    var over   = d.kpi.overdueDebt || 0;
    var invCnt = d.kpi.invoiceCount || 0;
    var clients= d.kpi.legalEntityCount || 0;
    var overPct= pct(over, total);
    var mrr = d.mrr || {};
    var mrrVal = Number(mrr.value) || 0;
    var dtm = d.debtToMrrPct;
    var cards = [
      {
        label: 'Общий долг',
        value: fmtK(total),
        sub: d.recordCount + ' записей',
        cls: '',
        tip: 'Сумма всех неоплаченных и частично оплаченных счетов в Airtable',
      },
      {
        label: 'Просроченный долг',
        value: fmtK(over),
        sub: overPct + '% от общего',
        cls: over > 0 ? 'kpi-warn' : '',
        tip: 'Сумма счетов, по которым прошёл срок оплаты',
      },
      {
        label: 'Открытых счетов',
        value: invCnt,
        sub: 'не оплачено / частично',
        cls: '',
        tip: 'Количество счетов со статусом «Не оплачен» или «Оплачен частично»',
      },
      {
        label: 'Юридических лиц',
        value: clients,
        sub: 'уникальных клиентов',
        cls: '',
        tip: 'Количество уникальных ЮЛ с задолженностью',
      },
    ];
    if (mrrVal > 0) {
      var subMrr = (mrr.yearMonth ? 'месяц ' + mrr.yearMonth : '');
      if (dtm != null) subMrr = (subMrr ? subMrr + ' · ' : '') + 'ДЗ ' + dtm + '% от MRR';
      cards.push({
        label: 'MRR (выручка)',
        value: fmtK(mrrVal),
        sub: subMrr || 'CS ALL',
        cls: dtm != null && dtm > 30 ? 'kpi-warn' : '',
        tip: (mrr.note || 'Сумма MRR sum в CS ALL. ') + 'Обновление значения для нового календарного месяца — при первом запросе API в этом месяце.',
      });
    }
    var gridCls = cards.length >= 5 ? 'kpi-grid kpi-grid-5' : 'kpi-grid';
    return '<div class="' + gridCls + '">' +
      cards.map(function (c) {
        return '<div class="kpi-card ' + c.cls + '" title="' + esc(c.tip) + '">' +
          '<div class="kpi-label">' + c.label + '</div>' +
          '<div class="kpi-value">' + c.value + '</div>' +
          '<div class="kpi-sub">' + c.sub + '</div>' +
        '</div>';
      }).join('') +
    '</div>';
  }

  /** Отдельный блок ДЗ: динамика долга по неделям + оплаты по неделям */
  function buildDzWeeklySection(d) {
    var mode = d.meta && d.meta.dataMode;
    if (mode !== 'debt') return '';

    var trend = d.weeklyDebtTrend || [];
    var wp = d.weeklyPayments || {};
    var payBars = wp.bars || [];

    var maxD = 1;
    trend.forEach(function (p) {
      maxD = Math.max(maxD, p.totalDebt || 0, p.overdueDebt || 0, 1);
    });

    var debtBody;
    if (!trend.length) {
      debtBody = '<p class="chart-empty">История по неделям появится после нескольких обновлений данных (точка на графике — среда, Europe/Moscow).</p>';
    } else {
      debtBody = '<div class="chart-area">' + trend.map(function (p) {
        var tot = p.totalDebt || 0;
        var ov = p.overdueDebt || 0;
        var ok = Math.max(0, tot - ov);
        var hOk = Math.round(ok / maxD * 100);
        var hOv = Math.round(ov / maxD * 100);
        var label = fmtDate(p.weekEnd);
        return '<div class="chart-col" title="' + esc(label) + ' · ДЗ ' + fmt(tot) + ', просрочка ' + fmt(ov) + '">' +
          '<div class="chart-bar-wrap">' +
            '<div class="chart-bar-ov" style="height:' + hOv + '%"></div>' +
            '<div class="chart-bar-ok" style="height:' + hOk + '%"></div>' +
          '</div>' +
          '<span class="chart-x">' + esc(label) + '</span>' +
        '</div>';
      }).join('') + '</div>' +
        '<div class="chart-legend">' +
          '<span><i style="background:var(--chart-over)"></i> Просрочка (низ столбца)</span>' +
          '<span><i style="background:var(--chart-total)"></i> Остальная ДЗ</span>' +
        '</div>';
    }

    var maxP = 1;
    payBars.forEach(function (b) {
      maxP = Math.max(maxP, b.total || 0, 1);
    });

    var payHead = '';
    if (wp.weekStart && wp.weekEnd) {
      payHead = '<div class="chart-pay-head">Текущая неделя (' + esc(fmtDate(wp.weekStart)) + ' — ' + esc(fmtDate(wp.weekEnd)) + '): ' +
        '<strong>' + fmt(wp.currentWeekTotal || 0) + '</strong> по оплатам</div>';
    }

    var payBody;
    if (wp.error) {
      payBody = '<p class="chart-err">' + esc(wp.error) + '</p>';
    } else if (!payBars.length) {
      payBody = '<p class="chart-empty">Нет данных оплат за выбранный период (вид «оплачено» в Airtable).</p>';
    } else {
      payBody = payHead + '<div class="chart-area">' + payBars.map(function (b) {
        var h = Math.round((b.total || 0) / maxP * 100);
        var label = fmtDate(b.weekEnd);
        return '<div class="chart-col" title="' + esc(label) + ' · ' + fmt(b.total || 0) + '">' +
          '<div class="chart-bar-wrap" style="justify-content:flex-end;height:120px;background:var(--elevated)">' +
            '<div class="chart-bar-pay" style="height:' + Math.max(h, 2) + '%;max-width:100%"></div>' +
          '</div>' +
          '<span class="chart-x">' + esc(label) + '</span>' +
        '</div>';
      }).join('') + '</div>';
    }

    return '<div class="dz-weekly-block" id="dz-weekly-block">' +
      '<div class="card chart-card">' +
        '<div class="card-head">' +
          '<span>ДЗ по неделям (сравнение)</span>' +
          '<span class="help" title="Накопленная история: общий долг и просрочка по средам (МСК). Обновляется при каждой свежей выгрузке из API.">?</span>' +
        '</div>' +
        debtBody +
      '</div>' +
      '<div class="card chart-card">' +
        '<div class="card-head">' +
          '<span>Оплаты по неделям</span>' +
          '<span class="help" title="Суммы из вида «оплачено» по дате оплаты счёта, недели как у дашборда руководителя.">?</span>' +
        '</div>' +
        payBody +
      '</div>' +
    '</div>';
  }

  function buildAgingCard(d) {
    var ag = d.aging || {};
    var keys = ['0–30', '31–60', '61–90', '90+'];
    var labels = { '0–30': '0–30 дней', '31–60': '31–60 дней', '61–90': '61–90 дней', '90+': '90+ дней' };
    var total = keys.reduce(function (s, k) { return s + (ag[k] || 0); }, 0);
    var rows = keys.map(function (k) {
      var v = ag[k] || 0;
      var p = pct(v, total);
      return '<div class="ag-row">' +
        '<div class="ag-label" style="color:' + agingColor(k) + '">' + labels[k] + '</div>' +
        '<div class="ag-bar-wrap" title="' + fmt(v) + ' · ' + p + '%">' +
          '<div class="ag-bar" style="width:' + p + '%;background:' + agingColor(k) + '"></div>' +
        '</div>' +
        '<div class="ag-val">' + fmtK(v) + '</div>' +
        '<div class="ag-pct">' + p + '%</div>' +
      '</div>';
    }).join('');
    return '<div class="card">' +
      '<div class="card-head">' +
        '<span>Просрочка по срокам</span>' +
        '<span class="help" title="Распределение просроченного долга по группам: сколько дней прошло с даты оплаты счёта">?</span>' +
      '</div>' +
      '<div class="ag-list">' + rows + '</div>' +
    '</div>';
  }

  function buildManagersCard(d) {
    var mgrs = (d.byManager || []).slice(0, 8);
    if (!mgrs.length) return '';
    var max = mgrs[0].amount || 1;
    var rows = mgrs.map(function (m) {
      var p = Math.round(m.amount / max * 100);
      return '<div class="ag-row">' +
        '<div class="ag-label">' + esc(m.name) + '</div>' +
        '<div class="ag-bar-wrap" title="' + fmt(m.amount) + '">' +
          '<div class="ag-bar" style="width:' + p + '%;background:var(--accent)"></div>' +
        '</div>' +
        '<div class="ag-val">' + fmtK(m.amount) + '</div>' +
      '</div>';
    }).join('');
    return '<div class="card">' +
      '<div class="card-head">' +
        '<span>Долг по менеджерам</span>' +
        '<span class="help" title="Сумма задолженности клиентов, закреплённых за каждым аккаунт-менеджером">?</span>' +
      '</div>' +
      '<div class="ag-list">' + rows + '</div>' +
    '</div>';
  }

  function buildCompanyCard(d) {
    var list = (d.byCompany || []).slice(0, 6);
    if (!list.length) return '';
    var max = list[0].amount || 1;
    var rows = list.map(function (m) {
      var p = Math.round(m.amount / max * 100);
      return '<div class="ag-row">' +
        '<div class="ag-label">' + esc(m.name) + '</div>' +
        '<div class="ag-bar-wrap" title="' + fmt(m.amount) + '">' +
          '<div class="ag-bar" style="width:' + p + '%;background:var(--ok)"></div>' +
        '</div>' +
        '<div class="ag-val">' + fmtK(m.amount) + '</div>' +
      '</div>';
    }).join('');
    return '<div class="card">' +
      '<div class="card-head">' +
        '<span>По компаниям</span>' +
        '<span class="help" title="Распределение задолженности по юридическому лицу нашей компании">?</span>' +
      '</div>' +
      '<div class="ag-list">' + rows + '</div>' +
    '</div>';
  }

  function buildDirectionCard(d) {
    var list = (d.byDirection || []).slice(0, 6);
    if (!list.length) return '';
    var max = list[0].amount || 1;
    var colors = ['#3d8bfd','#af52de','#ff9f0a','#30d158','#ff453a','#64d2ff'];
    var rows = list.map(function (m, i) {
      var p = Math.round(m.amount / max * 100);
      return '<div class="ag-row">' +
        '<div class="ag-label">' + esc(m.name) + '</div>' +
        '<div class="ag-bar-wrap" title="' + fmt(m.amount) + '">' +
          '<div class="ag-bar" style="width:' + p + '%;background:' + (colors[i] || '#8b97a8') + '"></div>' +
        '</div>' +
        '<div class="ag-val">' + fmtK(m.amount) + '</div>' +
      '</div>';
    }).join('');
    return '<div class="card">' +
      '<div class="card-head">' +
        '<span>По направлениям</span>' +
        '<span class="help" title="Распределение задолженности по бизнес-направлениям">?</span>' +
      '</div>' +
      '<div class="ag-list">' + rows + '</div>' +
    '</div>';
  }

  function buildUrgent(rows) {
    var urgent = rows.filter(function (r) {
      return r.overdue && r.daysOverdue != null && r.daysOverdue >= 60;
    }).slice(0, 8);
    if (!urgent.length) return '';
    var items = urgent.map(function (r) {
      var mgrDisp = (r.managers || [])[0] || '—';
      var step = r.nextStep || '';
      return '<div class="urgent-item">' +
        '<div class="urgent-header">' +
          '<span class="urgent-name">' + esc(r.legal) + '</span>' +
          '<span class="urgent-amount">' + fmt(r.amount) + '</span>' +
          '<span class="badge badge-danger">' + r.daysOverdue + ' дней</span>' +
        '</div>' +
        '<div class="urgent-meta">' +
          '<span>Счёт: ' + esc(r.invoiceNo || '—') + '</span>' +
          '<span>Менеджер: ' + esc(mgrDisp) + '</span>' +
          (step ? '<span class="urgent-step" title="Следующий шаг">📌 ' + esc(step.slice(0, 60)) + (step.length > 60 ? '…' : '') + '</span>' : '') +
        '</div>' +
      '</div>';
    }).join('');
    return '<div class="card card-wide">' +
      '<div class="card-head">' +
        '<span>Требуют немедленного внимания <span class="badge-count">' + urgent.length + '</span></span>' +
        '<span class="help" title="Счета с просрочкой 60+ дней — критичная задолженность">?</span>' +
      '</div>' +
      '<div class="urgent-list">' + items + '</div>' +
    '</div>';
  }

  function buildTopDebtors(d) {
    var list = (d.topLegal || []).slice(0, 15);
    if (!list.length) return '';
    var total = d.kpi.totalDebt || 0;
    var rows = list.map(function (r, i) {
      var p = pct(r.amount, total);
      return '<tr>' +
        '<td class="td-num">' + (i + 1) + '</td>' +
        '<td class="td-name">' + esc(r.name) + '</td>' +
        '<td class="td-amt">' + fmt(r.amount) + '</td>' +
        '<td class="td-cnt">' + r.count + '</td>' +
        '<td class="td-pct">' +
          '<div class="mini-bar-wrap" title="' + p + '% от общего долга">' +
            '<div class="mini-bar" style="width:' + Math.min(p * 2, 100) + '%;"></div>' +
          '</div>' +
          '<span>' + p + '%</span>' +
        '</td>' +
      '</tr>';
    }).join('');
    return '<div class="card card-wide">' +
      '<div class="card-head">' +
        '<span>Топ должников (ЮЛ)</span>' +
        '<span class="help" title="15 юридических лиц с наибольшей суммой открытых счетов">?</span>' +
      '</div>' +
      '<div class="table-wrap">' +
        '<table class="data-table">' +
          '<thead><tr>' +
            '<th style="width:32px">#</th>' +
            '<th>Клиент (ЮЛ)</th>' +
            '<th class="td-amt">Сумма</th>' +
            '<th class="td-cnt" title="Количество открытых счетов">Счета</th>' +
            '<th style="min-width:120px">Доля</th>' +
          '</tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table>' +
      '</div>' +
    '</div>';
  }

  /* ── Filter helpers ──────────────────────────────────────────────── */
  function uniqueVals(rows, fn) {
    var seen = {}, out = [];
    rows.forEach(function (r) {
      var v = fn(r);
      if (v && v !== '—' && !seen[v]) { seen[v] = 1; out.push(v); }
    });
    out.sort();
    return out;
  }

  function filterRows(rows) {
    var q = ST.search.toLowerCase();
    var amtMin = ST.amtMin !== '' ? parseFloat(ST.amtMin) : null;
    var amtMax = ST.amtMax !== '' ? parseFloat(ST.amtMax) : null;
    return rows.filter(function (r) {
      if (ST.overdueOnly && !r.overdue) return false;
      if (ST.mgr && r.managers.indexOf(ST.mgr) < 0) return false;
      if (ST.status && r.status !== ST.status) return false;
      if (ST.company && r.ourCompany !== ST.company) return false;
      if (ST.direction && r.direction !== ST.direction) return false;
      if (amtMin !== null && r.amount < amtMin) return false;
      if (amtMax !== null && r.amount > amtMax) return false;
      if (q) {
        var hay = (r.legal + ' ' + r.invoiceNo + ' ' + (r.managers || []).join(' ')).toLowerCase();
        if (hay.indexOf(q) < 0) return false;
      }
      return true;
    });
  }

  function sortRows(rows) {
    var col = ST.sortCol, dir = ST.sortDir === 'asc' ? 1 : -1;
    return rows.slice().sort(function (a, b) {
      var av = a[col], bv = b[col];
      if (av == null) av = col === 'daysOverdue' ? -1 : '';
      if (bv == null) bv = col === 'daysOverdue' ? -1 : '';
      if (typeof av === 'number' && typeof bv === 'number') return dir * (av - bv);
      return dir * String(av).localeCompare(String(bv), 'ru');
    });
  }

  function buildFilters(rows) {
    var mgrs = uniqueVals(rows, function (r) { return (r.managers || [])[0] || ''; });
    var statuses = uniqueVals(rows, function (r) { return r.status; });
    var companies = uniqueVals(rows, function (r) { return r.ourCompany; });
    var directions = uniqueVals(rows, function (r) { return r.direction; });

    function sel(id, cur, opts, placeholder) {
      return '<select id="' + id + '" data-filter="' + id + '">' +
        '<option value="">' + placeholder + '</option>' +
        opts.map(function (o) {
          return '<option value="' + esc(o) + '"' + (cur === o ? ' selected' : '') + '>' + esc(o) + '</option>';
        }).join('') +
      '</select>';
    }

    return '<div class="filters" id="filters">' +
      '<input id="f-search" type="text" placeholder="Поиск по клиенту / счёту..." value="' + esc(ST.search) + '" title="Поиск по ЮЛ клиента или номеру счёта">' +
      '<div class="filter-row">' +
        sel('f-mgr',  ST.mgr,       mgrs,       'Менеджер') +
        sel('f-status', ST.status,  statuses,   'Статус') +
        sel('f-company', ST.company, companies, 'Компания') +
        sel('f-dir',  ST.direction, directions, 'Направление') +
        '<input id="f-amt-min" type="number" placeholder="Сумма от" value="' + esc(ST.amtMin) + '" style="width:110px" title="Минимальная сумма счёта">' +
        '<input id="f-amt-max" type="number" placeholder="до" value="' + esc(ST.amtMax) + '" style="width:100px" title="Максимальная сумма счёта">' +
        '<label class="chk-label" title="Показывать только просроченные счета">' +
          '<input type="checkbox" id="f-overdue"' + (ST.overdueOnly ? ' checked' : '') + '> Только просрочка' +
        '</label>' +
        '<button class="btn-reset" id="btn-reset-filters" title="Сбросить все фильтры">✕ Сбросить</button>' +
      '</div>' +
    '</div>';
  }

  function thSort(col, label, tip) {
    var arrow = ST.sortCol === col ? (ST.sortDir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th data-sort="' + col + '" title="' + esc(tip || '') + '" style="cursor:pointer">' + label + arrow + '</th>';
  }

  function buildTable(rows) {
    var filtered = filterRows(rows);
    var sorted   = sortRows(filtered);
    var total    = sorted.length;
    var pages    = Math.ceil(total / ST.PAGE_SIZE) || 1;
    var page     = Math.min(ST.page, pages - 1);
    var slice    = sorted.slice(page * ST.PAGE_SIZE, (page + 1) * ST.PAGE_SIZE);

    var tbody = slice.map(function (r) {
      var overCls  = r.overdue ? (r.daysOverdue >= 90 ? ' row-crit' : ' row-over') : '';
      var daysDisp = r.daysOverdue != null ? r.daysOverdue + ' д.' : '—';
      var mgrDisp  = (r.managers || []).join(', ') || '—';
      var stepDisp = r.nextStep ? esc(r.nextStep.slice(0, 40)) + (r.nextStep.length > 40 ? '…' : '') : '—';
      var stepDue  = r.stepDue ? fmtDate(r.stepDue) : '';
      return '<tr class="' + overCls + '">' +
        '<td class="td-name" title="' + esc(r.legal) + '">' + esc(r.legal) + '</td>' +
        '<td>' + esc(r.invoiceNo || '—') + '</td>' +
        '<td class="td-amt">' + fmt(r.amount) + '</td>' +
        '<td>' +
          '<span class="badge badge-' + statusBadgeCls(r.status) + '">' + esc(r.status) + '</span>' +
        '</td>' +
        '<td>' + fmtDate(r.dueDate) + '</td>' +
        '<td class="' + (r.overdue ? 'td-over' : '') + '">' + daysDisp + '</td>' +
        '<td>' + esc(r.ourCompany || '—') + '</td>' +
        '<td>' + esc(r.direction || '—') + '</td>' +
        '<td>' + esc(mgrDisp) + '</td>' +
        '<td class="td-step" title="' + esc(r.nextStep || '') + (stepDue ? ' · ' + stepDue : '') + '">' + stepDisp + (stepDue ? '<br><span class="step-due">' + stepDue + '</span>' : '') + '</td>' +
        '<td class="td-comment" title="' + esc(r.comment || '') + '">' + esc((r.comment || '').slice(0, 50)) + (r.comment && r.comment.length > 50 ? '…' : '') + '</td>' +
      '</tr>';
    }).join('');

    var pager = '';
    if (pages > 1) {
      var btns = '';
      for (var i = 0; i < pages; i++) {
        btns += '<button class="pager-btn' + (i === page ? ' active' : '') + '" data-page="' + i + '">' + (i + 1) + '</button>';
      }
      pager = '<div class="pager">' + btns + '</div>';
    }

    return '<div class="card card-wide" id="invoice-table">' +
      '<div class="card-head">' +
        '<span>Все счета <span class="badge-count">' + total + '</span></span>' +
        '<span class="help" title="Полная таблица счетов с фильтрами. Нажмите на заголовок колонки для сортировки">?</span>' +
      '</div>' +
      buildFilters(rows) +
      '<div class="table-wrap">' +
        '<table class="data-table" id="tbl-invoices">' +
          '<thead><tr>' +
            thSort('legal',       'Клиент (ЮЛ)',   'Юридическое лицо клиента') +
            thSort('invoiceNo',   'Счёт',           'Номер счёта') +
            thSort('amount',      'Сумма',          'Сумма счёта в рублях') +
            '<th>Статус</th>' +
            thSort('dueDate',     'Срок оплаты',    'Дата, до которой должен быть оплачен счёт') +
            thSort('daysOverdue', 'Просрочка',      'Количество дней просрочки') +
            '<th>Компания</th>' +
            '<th>Направление</th>' +
            '<th>Менеджер</th>' +
            '<th>Следующий шаг</th>' +
            '<th>Комментарий</th>' +
          '</tr></thead>' +
          '<tbody>' + (tbody || '<tr><td colspan="11" class="td-empty">Нет данных по фильтру</td></tr>') + '</tbody>' +
        '</table>' +
      '</div>' +
      pager +
      '<div class="table-footer">Показано ' + slice.length + ' из ' + total + ' счетов</div>' +
    '</div>';
  }

  function statusBadgeCls(s) {
    if (!s) return 'default';
    if (s === 'Не оплачен') return 'danger';
    if (s === 'Оплачен частично') return 'warn';
    if (s.toLowerCase().indexOf('оплач') >= 0) return 'ok';
    return 'default';
  }

  /* ── Main render ──────────────────────────────────────────────────── */
  function render() {
    if (!D) return;
    var rows = D.rows || [];
    var html =
      buildTopbar(D) +
      '<div class="main-content">' +
        buildSituation(D) +
        buildKpis(D) +
        buildDzWeeklySection(D) +
        '<div class="mid-row">' +
          buildAgingCard(D) +
          buildManagersCard(D) +
        '</div>' +
        '<div class="mid-row">' +
          buildCompanyCard(D) +
          buildDirectionCard(D) +
        '</div>' +
        buildUrgent(rows) +
        buildTopDebtors(D) +
        buildTable(rows) +
      '</div>';

    var app = el('app');
    if (app) app.innerHTML = html;
    bindEvents(rows);
  }

  function bindEvents(rows) {
    var btnTheme = el('btn-theme');
    if (btnTheme) {
      btnTheme.addEventListener('click', function () {
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        var next = isLight ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try {
          localStorage.setItem('aq_theme', next);
          localStorage.removeItem('dz-theme');
        } catch (e1) {}
        render();
      });
    }
    var btnHtml = el('btn-export-html');
    if (btnHtml) btnHtml.addEventListener('click', exportHtmlPage);

    // Refresh button
    var btnRefresh = el('btn-refresh');
    if (btnRefresh) btnRefresh.addEventListener('click', function () { doRefresh(); });

    // CSV export
    var btnCsv = el('btn-export-csv');
    if (btnCsv) btnCsv.addEventListener('click', function () { exportCsv(filterRows(rows)); });

    // Search input
    var search = el('f-search');
    if (search) {
      search.addEventListener('input', function () {
        ST.search = this.value;
        ST.page = 0;
        reRenderTable(rows);
      });
    }

    // Select filters
    ['f-mgr', 'f-status', 'f-company', 'f-dir'].forEach(function (id) {
      var sel = el(id);
      if (!sel) return;
      var map = { 'f-mgr': 'mgr', 'f-status': 'status', 'f-company': 'company', 'f-dir': 'direction' };
      sel.addEventListener('change', function () {
        ST[map[id]] = this.value;
        ST.page = 0;
        reRenderTable(rows);
      });
    });

    // Amount inputs
    ['f-amt-min', 'f-amt-max'].forEach(function (id) {
      var inp = el(id);
      if (!inp) return;
      inp.addEventListener('input', function () {
        if (id === 'f-amt-min') ST.amtMin = this.value;
        else ST.amtMax = this.value;
        ST.page = 0;
        reRenderTable(rows);
      });
    });

    // Overdue checkbox
    var chk = el('f-overdue');
    if (chk) {
      chk.addEventListener('change', function () {
        ST.overdueOnly = this.checked;
        ST.page = 0;
        reRenderTable(rows);
      });
    }

    // Reset filters
    var btnReset = el('btn-reset-filters');
    if (btnReset) {
      btnReset.addEventListener('click', function () {
        ST.search = ''; ST.mgr = ''; ST.status = ''; ST.company = '';
        ST.direction = ''; ST.overdueOnly = false; ST.amtMin = ''; ST.amtMax = '';
        ST.page = 0;
        reRenderTable(rows);
      });
    }

    // Column sort
    var thead = document.querySelector('#tbl-invoices thead');
    if (thead) {
      thead.addEventListener('click', function (e) {
        var th = e.target.closest('[data-sort]');
        if (!th) return;
        var col = th.dataset.sort;
        if (ST.sortCol === col) {
          ST.sortDir = ST.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          ST.sortCol = col;
          ST.sortDir = 'desc';
        }
        ST.page = 0;
        reRenderTable(rows);
      });
    }

    // Pager buttons
    var pager = document.querySelector('.pager');
    if (pager) {
      pager.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-page]');
        if (!btn) return;
        ST.page = parseInt(btn.dataset.page, 10);
        reRenderTable(rows);
      });
    }
  }

  function reRenderTable(rows) {
    var wrap = el('invoice-table');
    if (!wrap) return;
    var newHtml = buildTable(rows);
    var tmp = document.createElement('div');
    tmp.innerHTML = newHtml;
    wrap.replaceWith(tmp.firstElementChild);
    // Re-bind table-specific events
    var thead = document.querySelector('#tbl-invoices thead');
    if (thead) {
      thead.addEventListener('click', function (e) {
        var th = e.target.closest('[data-sort]');
        if (!th) return;
        var col = th.dataset.sort;
        if (ST.sortCol === col) {
          ST.sortDir = ST.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          ST.sortCol = col;
          ST.sortDir = 'desc';
        }
        ST.page = 0;
        reRenderTable(rows);
      });
    }
    var pager = document.querySelector('.pager');
    if (pager) {
      pager.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-page]');
        if (!btn) return;
        ST.page = parseInt(btn.dataset.page, 10);
        reRenderTable(rows);
      });
    }
    var btnReset = el('btn-reset-filters');
    if (btnReset) {
      btnReset.addEventListener('click', function () {
        ST.search = ''; ST.mgr = ''; ST.status = ''; ST.company = '';
        ST.direction = ''; ST.overdueOnly = false; ST.amtMin = ''; ST.amtMax = '';
        ST.page = 0;
        reRenderTable(rows);
      });
    }
    var search = el('f-search');
    if (search) {
      search.addEventListener('input', function () { ST.search = this.value; ST.page = 0; reRenderTable(rows); });
    }
    ['f-mgr', 'f-status', 'f-company', 'f-dir'].forEach(function (id) {
      var sel = el(id);
      if (!sel) return;
      var map = { 'f-mgr': 'mgr', 'f-status': 'status', 'f-company': 'company', 'f-dir': 'direction' };
      sel.addEventListener('change', function () { ST[map[id]] = this.value; ST.page = 0; reRenderTable(rows); });
    });
    ['f-amt-min', 'f-amt-max'].forEach(function (id) {
      var inp = el(id);
      if (!inp) return;
      inp.addEventListener('input', function () {
        if (id === 'f-amt-min') ST.amtMin = this.value;
        else ST.amtMax = this.value;
        ST.page = 0;
        reRenderTable(rows);
      });
    });
    var chk = el('f-overdue');
    if (chk) {
      chk.addEventListener('change', function () { ST.overdueOnly = this.checked; ST.page = 0; reRenderTable(rows); });
    }
    var btnCsv = el('btn-export-csv');
    if (btnCsv) {
      btnCsv.addEventListener('click', function () { exportCsv(filterRows(rows)); });
    }
  }

  /* ── Refresh & countdown ─────────────────────────────────────────── */
  function startCountdown() {
    if (tickTimer) clearInterval(tickTimer);
    countdown = REFRESH_SEC;
    tickTimer = setInterval(function () {
      countdown--;
      var cd = el('cd');
      if (cd) cd.textContent = countdown;
      if (countdown <= 0) {
        clearInterval(tickTimer);
        doRefresh();
      }
    }, 1000);
  }

  function doRefresh() {
    if (ST.loading) return;
    ST.loading = true;
    var btn = el('btn-refresh');
    if (btn) btn.classList.add('loading');
    var url = 'api.php?_=' + Date.now();
    // preserve tableId if present in URL
    try {
      var u = new URL(window.location.href);
      if (u.searchParams.has('tableId')) {
        url += '&tableId=' + encodeURIComponent(u.searchParams.get('tableId'));
      }
    } catch (e) {}
    fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        ST.loading = false;
        if (btn) btn.classList.remove('loading');
        if (json.ok && json.data) {
          ST.rateHint = '';
          D = json.data;
          render();
        } else {
          ST.rateHint = json.rateLimited ? (json.rateLimitHint || json.error || '') : '';
          if (D) render();
          else showError(json.error || 'Ошибка API');
        }
        startCountdown();
      })
      .catch(function () {
        ST.loading = false;
        if (btn) btn.classList.remove('loading');
        startCountdown();
      });
  }

  function exportHtmlPage() {
    var path = window.location.pathname.replace(/[^/]*$/, '');
    if (!path.endsWith('/')) path += '/';
    var base = window.location.origin + path;
    var theme = document.documentElement.getAttribute('data-theme') || 'dark';
    var app = el('app');
    var inner = app ? app.innerHTML : '';
    var doc = '<!DOCTYPE html>\n<html lang="ru" data-theme="' + esc(theme) + '">\n<head>\n' +
      '<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n' +
      '<title>ДЗ — экспорт</title>\n<base href="' + esc(base) + '">\n' +
      '<link rel="stylesheet" href="assets/dashboard.css?v=' + DASH_CSS_VER + '">\n</head>\n<body>\n' +
      '<div id="app">' + inner + '</div>\n' +
      '<script>(function(){var r=document.documentElement;var t=localStorage.getItem("aq_theme");if(t!=="light"&&t!=="dark"){t=localStorage.getItem("dz-theme")==="light"?"light":"dark";}r.setAttribute("data-theme",t||"dark");})();<\/script>\n' +
      '<script src="assets/dashboard.js?v=' + DASH_CSS_VER + '"><\/script>\n</body>\n</html>';
    var blob = new Blob([doc], { type: 'text/html;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'dz-dashboard-' + new Date().toISOString().slice(0, 10) + '.html';
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
  }

  /* ── CSV export ───────────────────────────────────────────────────── */
  function exportCsv(rows) {
    var cols = ['ЮЛ клиента', 'Номер счёта', 'Сумма', 'Статус', 'Срок оплаты', 'Просрочка (дней)', 'Компания', 'Направление', 'Менеджер', 'Комментарий'];
    var lines = [cols.join(';')];
    rows.forEach(function (r) {
      lines.push([
        r.legal,
        r.invoiceNo || '',
        r.amount,
        r.status,
        r.dueDate || '',
        r.daysOverdue != null ? r.daysOverdue : '',
        r.ourCompany || '',
        r.direction || '',
        (r.managers || []).join(', '),
        (r.comment || '').replace(/"/g, '""'),
      ].map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(';'));
    });
    var bom = '\uFEFF';
    var blob = new Blob([bom + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'dz_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
  }

  /* ── Boot ────────────────────────────────────────────────────────── */
  function showError(msg) {
    var app = el('app');
    var rate = ST.rateHint
      ? '<div class="topbar-rate visible" style="margin:0 auto 16px;max-width:560px;border-radius:8px;padding:12px 16px;"><strong>Лимит API.</strong> ' + esc(ST.rateHint) + '</div>'
      : '';
    if (app) {
      app.innerHTML = rate +
        '<div class="loading-screen"><div class="loading-text" style="color:var(--danger)">Ошибка загрузки</div><div class="loading-sub">' + esc(msg) + '</div><button class="btn-icon" style="margin-top:16px" onclick="location.reload()">↺ Повторить</button></div>';
    }
  }

  function boot() {
    // Try bootstrap script first (legacy support)
    var script = document.getElementById('dz-bootstrap');
    if (script) {
      try {
        D = JSON.parse(script.textContent || script.innerHTML);
        if (D) { render(); startCountdown(); return; }
      } catch (e) {}
    }
    // Load data async from api.php
    var url = 'api.php';
    try {
      var u = new URL(window.location.href);
      if (u.searchParams.has('tableId')) {
        url += '?tableId=' + encodeURIComponent(u.searchParams.get('tableId'));
      }
    } catch (e) {}
    fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json.ok && json.data) {
          ST.rateHint = '';
          D = json.data;
          render();
          startCountdown();
        } else {
          ST.rateHint = json.rateLimited ? (json.rateLimitHint || json.error || '') : '';
          showError(json.error || 'Неизвестная ошибка');
        }
      })
      .catch(function (e) {
        ST.rateHint = '';
        showError(String(e));
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$c = dashboard_config();

// ── Читаем кэши ────────────────────────────────────────────────────────────
function readCache(string $path): array {
    if (!is_readable($path)) return [];
    $d = json_decode(file_get_contents($path) ?: '', true);
    return is_array($d) ? $d : [];
}

$dz    = readCache(__DIR__ . '/cache/dz-data-default.json');
$churn = readCache(__DIR__ . '/cache/churn-report.json');
$fact  = readCache(__DIR__ . '/cache/churn-fact-report.json');

// DZ metrics
$dzTotal    = (float)($dz['totalDebt'] ?? 0);
$dzCritical = (float)($dz['grpTotals']['91+'] ?? 0);
$dzClients  = (int)($dz['clientCount'] ?? count($dz['allRows'] ?? []));
$dzUpdated  = $dz['generatedAt'] ?? '';

// Churn risk metrics
$churnRisk    = (float)($churn['totalRisk']  ?? 0);
$churnProb3   = (float)($churn['prob3mrr']   ?? 0);
$churnCount   = (int)  ($churn['count']      ?? 0);
$churnUpdated = $churn['updatedAt'] ?? '';

// Fact metrics
$factChurn   = (float)($fact['churnYtd']    ?? 0);
$factDs      = (float)($fact['downsellYtd'] ?? 0);
$factTotal   = (float)($fact['totalYtd']    ?? 0);
$factTarget  = (float)($fact['targetTotal'] ?? 8_200_000);
$factUpdated = $fact['updatedAt'] ?? '';
$factDevPct  = $factTarget > 0 ? ($factTotal / $factTarget * 100) : 0;

// MRR
$mrr = (float)($dz['mrr'] ?? 0);

function fmtM(float $v): string {
    if ($v >= 1_000_000) return number_format($v/1_000_000, 1, '.', '').'М';
    if ($v >= 1_000)     return number_format($v/1_000, 0, '.', '').'К';
    return (string)(int)$v;
}
function fmtR(float $v): string {
    return number_format((int)$v, 0, '.', ' ').' ₽';
}
?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>AnyQuery — Дашборды</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=14">
  <script>
    (function(){
      var t = localStorage.getItem('aq_theme') || 'dark';
      document.getElementById('html-root').setAttribute('data-theme', t);
    })();
  </script>
  <style>
    /* ── Hub ────────────────────────────── */
    :root,[data-theme="dark"]{
      --hub-bg:#0d0d12;--hub-surface:#16161e;--hub-card:#1c1c26;
      --hub-border:rgba(255,255,255,.08);--hub-text:#e8e8f0;--hub-muted:#888;
      --hub-accent:#7B61FF;--hub-danger:#FF453A;--hub-warn:#FF9F0A;--hub-ok:#34C759;
    }
    [data-theme="light"]{
      --hub-bg:#f4f4f8;--hub-surface:#fff;--hub-card:#f9f9fc;
      --hub-border:rgba(0,0,0,.09);--hub-text:#1a1a2e;--hub-muted:#666;
      --hub-accent:#6B51EF;--hub-danger:#E3342F;--hub-warn:#E07B09;--hub-ok:#27AE60;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--hub-bg);color:var(--hub-text);font-family:system-ui,sans-serif;min-height:100vh}

    /* topbar */
    .hub-top{
      display:flex;align-items:center;justify-content:space-between;
      padding:0 24px;height:58px;
      background:var(--hub-surface);border-bottom:1px solid var(--hub-border);
      position:sticky;top:0;z-index:100;
    }
    .hub-logo{display:flex;align-items:center;gap:10px}
    .hub-logo-box{background:var(--hub-accent);color:#fff;font-size:.65rem;font-weight:900;border-radius:6px;padding:4px 7px}
    .hub-logo-name{font-size:.9rem;font-weight:700;color:var(--hub-muted)}
    .hub-logo-title{font-size:1rem;font-weight:800;color:var(--hub-text)}
    .hub-logo-sep{color:var(--hub-border);margin:0 4px}
    .btn-icon{background:none;border:1px solid var(--hub-border);border-radius:8px;
      color:var(--hub-text);cursor:pointer;font-size:1rem;padding:5px 10px;transition:border-color .15s}
    .btn-icon:hover{border-color:var(--hub-accent)}

    /* ── Nav-вкладки (единый стиль) ── */
    .hub-nav-tabs{display:flex;align-items:center;gap:2px}
    .hub-nav-tab{
      padding:5px 12px;border-radius:8px;font-size:.78rem;font-weight:500;
      color:var(--hub-muted);text-decoration:none;border:none;background:none;
      cursor:pointer;transition:color .15s,background .15s;white-space:nowrap;font-family:inherit
    }
    .hub-nav-tab:hover{color:var(--hub-text);background:rgba(123,97,255,.08)}
    .hub-nav-tab-active{color:var(--hub-accent);background:rgba(123,97,255,.12);font-weight:700;cursor:default}

    /* hero */
    .hub-hero{
      text-align:center;padding:52px 20px 36px;
    }
    .hub-hero h1{font-size:1.9rem;font-weight:900;color:var(--hub-text);margin-bottom:10px}
    .hub-hero p{color:var(--hub-muted);font-size:.95rem;max-width:500px;margin:0 auto}

    /* section grid */
    .hub-wrap{max-width:1200px;margin:0 auto;padding:0 20px 60px}
    .hub-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(270px,1fr));
      gap:18px;margin-top:8px;
    }

    /* section card */
    .hub-card{
      background:var(--hub-card);border:1px solid var(--hub-border);border-radius:16px;
      overflow:hidden;display:flex;flex-direction:column;
      transition:transform .15s,border-color .2s,box-shadow .2s;
    }
    .hub-card:hover{transform:translateY(-3px);border-color:var(--hub-accent);
      box-shadow:0 8px 32px rgba(123,97,255,.12)}
    .hub-card.soon{opacity:.55;pointer-events:none}
    .hub-card-head{padding:20px 22px 14px;border-bottom:1px solid var(--hub-border)}
    .hub-card-icon{font-size:1.6rem;margin-bottom:8px}
    .hub-card-title{font-size:1.05rem;font-weight:800;color:var(--hub-text);margin-bottom:4px}
    .hub-card-desc{font-size:.78rem;color:var(--hub-muted);line-height:1.5}
    .hub-card-body{padding:16px 22px;flex:1}
    .hub-stat{display:flex;justify-content:space-between;align-items:baseline;
      margin-bottom:8px;font-size:.82rem}
    .hub-stat-lbl{color:var(--hub-muted)}
    .hub-stat-val{font-weight:700;font-size:.95rem;color:var(--hub-text)}
    .hub-stat-val.danger{color:var(--hub-danger)}
    .hub-stat-val.warn{color:var(--hub-warn)}
    .hub-stat-val.ok{color:var(--hub-ok)}
    .hub-stat-val.accent{color:var(--hub-accent)}
    .hub-updated{font-size:.68rem;color:var(--hub-muted);margin-top:10px;padding-top:10px;
      border-top:1px solid var(--hub-border)}
    .hub-card-foot{padding:14px 22px}
    .hub-btn{
      display:block;width:100%;padding:10px;border-radius:10px;text-align:center;
      background:var(--hub-accent);color:#fff;font-size:.85rem;font-weight:700;
      text-decoration:none;transition:opacity .15s;border:none;cursor:pointer;
    }
    .hub-btn:hover{opacity:.85}
    .hub-btn.secondary{background:transparent;border:1px solid var(--hub-border);
      color:var(--hub-text);font-weight:500}
    .hub-btn.secondary:hover{border-color:var(--hub-accent);color:var(--hub-accent)}
    .hub-btn-group{display:flex;gap:8px}
    .hub-btn-group .hub-btn{flex:1}
    .badge-soon{display:inline-block;background:rgba(255,159,10,.15);border:1px solid rgba(255,159,10,.3);
      color:var(--hub-warn);border-radius:5px;padding:2px 8px;font-size:.68rem;font-weight:700;margin-left:8px}

    /* divider label */
    .hub-section-label{font-size:.72rem;font-weight:700;text-transform:uppercase;
      letter-spacing:.07em;color:var(--hub-muted);padding:28px 0 10px}

    /* summary table */
    .hub-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--hub-border)}
    .hub-table{width:100%;border-collapse:collapse;font-size:.82rem}
    .hub-table thead th{
      padding:9px 14px;text-align:left;font-size:.67rem;font-weight:700;
      text-transform:uppercase;letter-spacing:.05em;color:var(--hub-muted);
      background:var(--hub-card);border-bottom:1px solid var(--hub-border);white-space:nowrap;
    }
    .hub-table tbody tr{border-bottom:1px solid var(--hub-border);transition:background .1s}
    .hub-table tbody tr:last-child{border-bottom:none}
    .hub-table tbody tr:hover{background:rgba(123,97,255,.05)}
    .hub-table td{padding:10px 14px;vertical-align:middle;color:var(--hub-text)}
    .hub-table td.num{text-align:right;font-variant-numeric:tabular-nums}
    .hub-table td.muted{color:var(--hub-muted)}
    .tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.68rem;font-weight:600}
    .tag-churn{background:rgba(255,69,58,.12);color:var(--hub-danger);border:1px solid rgba(255,69,58,.2)}
    .tag-dz{background:rgba(255,159,10,.12);color:var(--hub-warn);border:1px solid rgba(255,159,10,.2)}
    .tag-risk{background:rgba(123,97,255,.12);color:var(--hub-accent);border:1px solid rgba(123,97,255,.2)}

    /* progress bar */
    .prog-wrap{background:rgba(255,255,255,.06);border-radius:4px;height:6px;overflow:hidden;margin-top:6px}
    .prog-fill{height:100%;border-radius:4px;transition:width .4s}

    @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
    .hub-grid>*{animation:fadeUp .3s ease both}
    .hub-grid>*:nth-child(2){animation-delay:.06s}
    .hub-grid>*:nth-child(3){animation-delay:.12s}
    .hub-grid>*:nth-child(4){animation-delay:.18s}
  </style>
</head>
<body>

<div class="hub-top">
  <div class="hub-logo">
    <span class="hub-logo-box">AQ</span>
    <span class="hub-logo-name">anyquery</span>
    <span class="hub-logo-sep">|</span>
    <span class="hub-logo-title">Дашборды</span>
  </div>
  <nav class="hub-nav-tabs">
    <span class="hub-nav-tab hub-nav-tab-active">🏠 Главная</span>
    <a class="hub-nav-tab" href="churn.php">⚠ Угроза Churn</a>
    <a class="hub-nav-tab" href="churn_fact.php">📉 Потери</a>
    <a class="hub-nav-tab" href="manager.php">💰 ДЗ</a>
  </nav>
  <button class="btn-icon" id="btn-theme" title="Сменить тему">🌙</button>
</div>

<div class="hub-hero">
  <h1>Командный центр</h1>
  <p>Выберите раздел для анализа — дебиторка, угрозы чёрна или фактические потери</p>
</div>

<div class="hub-wrap">

  <!-- ── Разделы ─────────────────────────────────────── -->
  <div class="hub-section-label">📂 Разделы</div>
  <div class="hub-grid">

    <!-- ДЗ -->
    <div class="hub-card">
      <div class="hub-card-head">
        <div class="hub-card-icon">💸</div>
        <div class="hub-card-title">Дебиторская задолженность</div>
        <div class="hub-card-desc">Просроченный долг клиентов, ТОП-10 дебиторов, динамика по неделям, выплаты</div>
      </div>
      <div class="hub-card-body">
        <?php if ($dzTotal > 0): ?>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Общая ДЗ</span>
          <span class="hub-stat-val danger"><?= fmtR($dzTotal) ?></span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Критично (91+ дн.)</span>
          <span class="hub-stat-val warn"><?= fmtR($dzCritical) ?></span>
        </div>
        <?php if ($mrr > 0): ?>
        <div class="hub-stat">
          <span class="hub-stat-lbl">% от MRR</span>
          <span class="hub-stat-val <?= ($dzTotal/$mrr*100)>30?'danger':'ok' ?>"><?= number_format($dzTotal/$mrr*100, 1) ?>%</span>
        </div>
        <?php endif; ?>
        <?php if ($dzUpdated): ?><div class="hub-updated">Обновлено: <?= htmlspecialchars($dzUpdated) ?></div><?php endif; ?>
        <?php else: ?>
        <div style="color:var(--hub-muted);font-size:.82rem;padding:4px 0">Данные загрузятся при первом открытии</div>
        <?php endif; ?>
      </div>
      <div class="hub-card-foot">
        <a class="hub-btn" href="manager.php">Открыть ДЗ →</a>
      </div>
    </div>

    <!-- Угроза Churn -->
    <div class="hub-card">
      <div class="hub-card-head">
        <div class="hub-card-icon">⚠️</div>
        <div class="hub-card-title">Угроза Churn</div>
        <div class="hub-card-desc">Клиенты в зоне риска, MRR под угрозой, вероятность по сегментам и продуктам</div>
      </div>
      <div class="hub-card-body">
        <?php if ($churnRisk > 0): ?>
        <div class="hub-stat">
          <span class="hub-stat-lbl">MRR под угрозой</span>
          <span class="hub-stat-val danger"><?= fmtR($churnRisk) ?></span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Prob=3 (критично)</span>
          <span class="hub-stat-val danger"><?= fmtR($churnProb3) ?></span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Клиентов в зоне риска</span>
          <span class="hub-stat-val accent"><?= $churnCount ?></span>
        </div>
        <?php if ($churnUpdated): ?><div class="hub-updated">Обновлено: <?= htmlspecialchars($churnUpdated) ?></div><?php endif; ?>
        <?php else: ?>
        <div style="color:var(--hub-muted);font-size:.82rem;padding:4px 0">Данные загрузятся при первом открытии</div>
        <?php endif; ?>
      </div>
      <div class="hub-card-foot">
        <a class="hub-btn" href="churn.php">Открыть Угрозы →</a>
      </div>
    </div>

    <!-- Потери выручки -->
    <div class="hub-card">
      <div class="hub-card-head">
        <div class="hub-card-icon">📉</div>
        <div class="hub-card-title">Потери выручки</div>
        <div class="hub-card-desc">Фактический Churn + DownSell за год, динамика по месяцам, сравнение с таргетом</div>
      </div>
      <div class="hub-card-body">
        <?php if ($factTotal > 0): ?>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Потери YTD (факт)</span>
          <span class="hub-stat-val danger"><?= fmtR($factTotal) ?></span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Таргет на год</span>
          <span class="hub-stat-val muted"><?= fmtR($factTarget) ?></span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Выполнение таргета</span>
          <span class="hub-stat-val <?= $factDevPct > 100 ? 'danger' : ($factDevPct > 75 ? 'warn' : 'ok') ?>"><?= number_format($factDevPct, 1) ?>%</span>
        </div>
        <?php
        $barW = min(100, $factDevPct);
        $barColor = $factDevPct > 100 ? 'var(--hub-danger)' : ($factDevPct > 75 ? 'var(--hub-warn)' : 'var(--hub-accent)');
        ?>
        <div class="prog-wrap"><div class="prog-fill" style="width:<?= $barW ?>%;background:<?= $barColor ?>"></div></div>
        <?php if ($factUpdated): ?><div class="hub-updated">Обновлено: <?= htmlspecialchars($factUpdated) ?></div><?php endif; ?>
        <?php else: ?>
        <div style="color:var(--hub-muted);font-size:.82rem;padding:4px 0">Данные загрузятся при первом открытии</div>
        <?php endif; ?>
      </div>
      <div class="hub-card-foot">
        <div class="hub-btn-group">
          <a class="hub-btn" href="churn_fact.php">Открыть →</a>
          <button class="hub-btn secondary" id="btn-refresh-fact" title="Сбросить кэш и обновить данные">↻</button>
        </div>
      </div>
    </div>

    <!-- Расчёт KPI — скоро -->
    <div class="hub-card soon">
      <div class="hub-card-head">
        <div class="hub-card-icon">🎯</div>
        <div class="hub-card-title">Расчёт KPI <span class="badge-soon">Скоро</span></div>
        <div class="hub-card-desc">Расчёт целевых показателей, план/факт по командам и продуктам</div>
      </div>
      <div class="hub-card-body">
        <div style="color:var(--hub-muted);font-size:.82rem">Раздел в разработке</div>
      </div>
      <div class="hub-card-foot">
        <button class="hub-btn secondary" disabled>Скоро</button>
      </div>
    </div>

  </div><!-- /hub-grid -->

  <!-- ── Авто-запуск ──────────────────────────────── -->
  <div class="hub-section-label">⚙️ Настройки</div>
  <div class="hub-grid" style="grid-template-columns:repeat(auto-fit,minmax(270px,340px))">
    <div class="hub-card" id="autostart-card">
      <div class="hub-card-head">
        <div class="hub-card-icon">🚀</div>
        <div class="hub-card-title">Авто-запуск при старте ПК</div>
        <div class="hub-card-desc">Сервер будет запускаться автоматически при входе в систему — не нужно каждый раз открывать LAUNCH</div>
      </div>
      <div class="hub-card-body">
        <div class="hub-stat">
          <span class="hub-stat-lbl">Статус</span>
          <span class="hub-stat-val" id="as-status-val" style="color:var(--hub-muted)">…</span>
        </div>
        <div class="hub-stat">
          <span class="hub-stat-lbl">Система</span>
          <span class="hub-stat-val" id="as-os-val" style="color:var(--hub-muted)">…</span>
        </div>
        <div id="as-msg" style="font-size:.75rem;color:var(--hub-muted);margin-top:6px;min-height:18px"></div>
      </div>
      <div class="hub-card-foot">
        <button class="hub-btn" id="as-btn" disabled>…</button>
      </div>
    </div>
  </div>

  <!-- ── Сводная таблица ────────────────────────────── -->
  <?php
  // Merge top items from churn + dz for the summary table
  $tableRows = [];

  // Top churn clients (up to 10)
  $churnDetail = array_slice($fact['churnDetail'] ?? [], 0, 8);
  foreach ($churnDetail as $r) {
      $tableRows[] = [
          'type'    => 'Churn',
          'account' => $r['account'] ?? '',
          'product' => $r['product'] ?? '',
          'mrr'     => (float)($r['mrr'] ?? 0),
          'month'   => $r['month'] ?? '',
          'note'    => $r['reason'] ?: ($r['vertical'] ?? ''),
      ];
  }

  // Top DZ clients
  $dzRows = [];
  foreach (($dz['allRows'] ?? []) as $r) {
      $acc = $r['client'] ?? $r['account'] ?? '';
      if (!isset($dzRows[$acc])) $dzRows[$acc] = ['type'=>'ДЗ','account'=>$acc,'product'=>'—','mrr'=>0,'month'=>'','note'=>''];
      $dzRows[$acc]['mrr'] += (float)($r['amount'] ?? 0);
  }
  usort($dzRows, fn($a,$b) => $b['mrr'] <=> $a['mrr']);
  $tableRows = array_merge($tableRows, array_slice($dzRows, 0, 6));

  // Top churn risk
  foreach (array_slice($churn['clients'] ?? [], 0, 5) as $r) {
      $tableRows[] = [
          'type'    => 'Риск',
          'account' => $r['account'] ?? '',
          'product' => implode(', ', $r['products'] ?? []),
          'mrr'     => (float)($r['mrrAtRisk'] ?? 0),
          'month'   => 'Prob='.(($r['probability'] ?? '?')),
          'note'    => ($r['segment'] ?? '').' '.$r['csm'] ?? '',
      ];
  }

  usort($tableRows, fn($a,$b) => $b['mrr'] <=> $a['mrr']);
  $tableRows = array_slice($tableRows, 0, 20);
  ?>

  <?php if (!empty($tableRows)): ?>
  <div class="hub-section-label">📋 Сводная таблица — ключевые клиенты</div>
  <div class="hub-table-wrap">
    <table class="hub-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Клиент</th>
          <th>Раздел</th>
          <th>Продукт</th>
          <th style="text-align:right">Сумма</th>
          <th>Период / Вероятность</th>
          <th>Примечание</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tableRows as $i => $r): ?>
        <?php
        $tag = match($r['type']) {
            'Churn' => '<span class="tag tag-churn">Churn</span>',
            'ДЗ'    => '<span class="tag tag-dz">ДЗ</span>',
            'Риск'  => '<span class="tag tag-risk">Риск</span>',
            default => '<span class="tag">'.$r['type'].'</span>',
        };
        ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td><strong><?= htmlspecialchars($r['account']) ?></strong></td>
          <td><?= $tag ?></td>
          <td class="muted"><?= htmlspecialchars($r['product']) ?></td>
          <td class="num" style="font-weight:700;color:<?= $r['type']==='Churn'?'var(--hub-danger)':($r['type']==='ДЗ'?'var(--hub-warn)':'var(--hub-accent)') ?>">
            <?= fmtR($r['mrr']) ?>
          </td>
          <td class="muted"><?= htmlspecialchars($r['month']) ?></td>
          <td class="muted" style="font-size:.78rem"><?= htmlspecialchars(substr(trim($r['note']), 0, 40)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /hub-wrap -->

<script>
  // ── Авто-запуск ──────────────────────────────────────────
  (function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const btn     = document.getElementById('as-btn');
    const statusEl = document.getElementById('as-status-val');
    const osEl     = document.getElementById('as-os-val');
    const msgEl    = document.getElementById('as-msg');

    function setMsg(text, color) {
      if (!msgEl) return;
      msgEl.textContent = text;
      msgEl.style.color = color || 'var(--hub-muted)';
    }

    function applyStatus(installed, os) {
      if (!btn || !statusEl || !osEl) return;
      statusEl.textContent = installed ? 'Включён' : 'Выключен';
      statusEl.style.color = installed ? 'var(--hub-ok)' : 'var(--hub-muted)';
      osEl.textContent = os || '—';
      btn.disabled = false;
      btn.textContent = installed ? 'Отключить авто-запуск' : 'Включить авто-запуск';
      btn.className = installed ? 'hub-btn secondary' : 'hub-btn';
    }

    async function fetchStatus() {
      try {
        const r = await fetch('autostart_api.php?action=status');
        const d = await r.json();
        if (d.ok) applyStatus(d.installed, d.os);
        else setMsg(d.error || 'Ошибка', 'var(--hub-danger)');
      } catch(e) { setMsg('Нет ответа от сервера', 'var(--hub-muted)'); }
    }

    btn?.addEventListener('click', async () => {
      if (btn.disabled) return;
      const installed = statusEl?.textContent === 'Включён';
      btn.disabled = true;
      btn.textContent = '…';
      setMsg('', '');
      try {
        const r = await fetch('autostart_api.php?action=' + (installed ? 'remove' : 'install'), {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf },
        });
        const d = await r.json();
        if (d.ok) { applyStatus(!installed, osEl?.textContent); setMsg(d.message || '', 'var(--hub-ok)'); }
        else       { btn.disabled = false; applyStatus(installed, osEl?.textContent); setMsg(d.error || 'Ошибка', 'var(--hub-danger)'); }
      } catch(e) { btn.disabled = false; applyStatus(installed, osEl?.textContent); setMsg('Ошибка запроса: ' + e.message, 'var(--hub-danger)'); }
    });

    fetchStatus();
  })();

  // ── Кнопка обновления "Потери выручки" ──────────────────
  (function() {
    const btn = document.getElementById('btn-refresh-fact');
    if (!btn) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    btn.addEventListener('click', async function() {
      btn.disabled = true;
      btn.textContent = '…';
      try {
        const r = await fetch('churn_fact_api.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf },
        });
        const d = await r.json();
        btn.textContent = d.ok ? '✓' : '✕';
        setTimeout(() => { btn.textContent = '↻'; btn.disabled = false; }, 2000);
        if (d.ok) window.location.reload();
      } catch(e) {
        btn.textContent = '✕';
        setTimeout(() => { btn.textContent = '↻'; btn.disabled = false; }, 2000);
      }
    });
  })();

  // Theme toggle
  document.getElementById('btn-theme')?.addEventListener('click', function() {
    var root = document.getElementById('html-root');
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('aq_theme', next);
    this.textContent = next === 'dark' ? '🌙' : '☀️';
  });
  // Set correct icon on load
  (function(){
    var t = document.getElementById('html-root').getAttribute('data-theme');
    var btn = document.getElementById('btn-theme');
    if (btn) btn.textContent = t === 'dark' ? '🌙' : '☀️';
  })();
</script>
<script src="assets/shared-nav.js?v=1" defer></script>
</body>
</html>

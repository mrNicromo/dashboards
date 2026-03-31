<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function curlGet(string $url): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'code'=>0,'body'=>'','err'=>'cURL off'];
    foreach ([$url, str_replace('%2F','/',$url)] as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if (is_string($body) && $code===200 && strlen($body)>50)
            return ['ok'=>true,'code'=>$code,'body'=>$body,'err'=>'','len'=>strlen($body)];
    }
    return ['ok'=>false,'code'=>$code??0,'body'=>'','err'=>$err??'','len'=>0];
}

function parseCsvLine(string $line): array {
    $r=[]; $f=''; $q=false; $len=strlen($line);
    for($i=0;$i<$len;$i++){
        $ch=$line[$i];
        if($q){if($ch==='"'&&$i+1<$len&&$line[$i+1]==='"'){$f.='"';$i++;}
               elseif($ch==='"'){$q=false;}else{$f.=$ch;}}
        else{if($ch==='"'){$q=true;}elseif($ch===','){$r[]=$f;$f='';}else{$f.=$ch;}}
    }
    $r[]=$f; return $r;
}

function parseCsv(string $body): array {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $body))));
    if (!$lines) return ['cols'=>[],'cols_norm'=>[],'rows'=>[],'total'=>0];
    $rawHeader = parseCsvLine(array_shift($lines));
    $colsNorm = [];
    foreach ($rawHeader as $h) $colsNorm[] = mb_strtolower(trim($h, " \t\r\n\xEF\xBB\xBF\""));
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cells = parseCsvLine($line);
        $row = [];
        foreach ($colsNorm as $i => $col) $row[$col] = trim($cells[$i] ?? '', " \"\t\r\n");
        $rows[] = $row;
    }
    return ['raw_cols'=>$rawHeader, 'cols'=>$colsNorm, 'rows'=>$rows, 'total'=>count($rows)];
}

function topVals(array $rows, string $col, int $max=15): array {
    $out=[];
    foreach($rows as $r){ $v=$r[$col]??''; $out[$v]=($out[$v]??0)+1; }
    arsort($out);
    return array_slice($out,0,$max,true);
}

function amtParse(string $raw): float {
    $s = preg_replace('/[^\d.,\-]/','', trim($raw)) ?? '';
    if($s==='') return 0;
    $lc=strrpos($s,','); $ld=strrpos($s,'.');
    if($lc!==false&&$ld!==false){ if($ld>$lc) $s=str_replace(',','',$s); else{$s=str_replace('.','',$s);$s=str_replace(',','.',$s);} }
    elseif($lc!==false){ $after=substr($s,$lc+1); $s=strlen($after)<=2?str_replace(',','.',$s):str_replace(',','',$s); }
    return round((float)$s,2);
}

$SHEETS_ID = '1Tkax6awhWmNXfXpzORPIqHy5qgAhLzfifSHc-YLQhhY';
$dsRes  = curlGet("https://docs.google.com/spreadsheets/d/{$SHEETS_ID}/gviz/tq?tqx=out:csv&sheet=UpSale%2FDownSell");
$chnRes = curlGet("https://docs.google.com/spreadsheets/d/{$SHEETS_ID}/gviz/tq?tqx=out:csv&sheet=Churn");

$ds  = $dsRes['ok']  ? parseCsv($dsRes['body'])  : null;
$chn = $chnRes['ok'] ? parseCsv($chnRes['body']) : null;
?><!DOCTYPE html>
<html lang="ru" id="html-root" data-theme="dark">
<head>
  <meta charset="utf-8">
  <title>DownSell Debug</title>
  <style>
    :root { --bg:#0d0d12;--card:#1a1a24;--border:rgba(255,255,255,.08);--text:#e8e8f0;--muted:#6e6e80;--ok:#34C759;--warn:#FF9F0A;--danger:#FF453A;--accent:#7B61FF; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,sans-serif; padding:24px; }
    h1 { color:var(--accent); font-size:1.3rem; margin:0 0 20px; }
    h2 { font-size:1rem; margin:24px 0 10px; color:var(--text); border-bottom:1px solid var(--border); padding-bottom:6px; }
    h3 { font-size:.85rem; color:var(--muted); margin:16px 0 6px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:16px; }
    .ok   { color:var(--ok);     font-weight:700; }
    .warn { color:var(--warn);   font-weight:700; }
    .bad  { color:var(--danger); font-weight:700; }
    .muted{ color:var(--muted); }
    table { border-collapse:collapse; width:100%; font-size:.78rem; }
    th,td { border:1px solid var(--border); padding:5px 10px; text-align:left; }
    thead th { background:var(--card); color:var(--muted); font-size:.65rem; text-transform:uppercase; }
    tr:hover td { background:rgba(255,255,255,.02); }
    code { background:rgba(255,255,255,.07); border-radius:4px; padding:1px 6px; font-size:.82em; }
    .step { display:flex; align-items:center; gap:12px; padding:8px 14px; border-radius:8px; background:rgba(255,255,255,.03); margin:4px 0; }
    .step-num { font-size:1.2rem; font-weight:800; min-width:50px; }
    .step-label { flex:1; }
    .cols-list { display:flex; flex-wrap:wrap; gap:6px; }
    .col-badge { background:rgba(123,97,255,.12); border:1px solid rgba(123,97,255,.2); color:var(--accent); border-radius:5px; padding:3px 8px; font-size:.72rem; }
    .col-badge.match { background:rgba(52,199,89,.12); border-color:rgba(52,199,89,.3); color:var(--ok); }
    .col-badge.maybe { background:rgba(255,159,10,.12); border-color:rgba(255,159,10,.3); color:var(--warn); }
    pre { background:rgba(255,255,255,.04); border-radius:6px; padding:12px; font-size:.75rem; overflow-x:auto; white-space:pre-wrap; word-break:break-word; }
    .tip { font-size:.72rem; color:var(--muted); margin-top:4px; }
  </style>
</head>
<body>
<h1>🔍 DownSell Debug — Google Sheets</h1>

<!-- ── Статус подключения ── -->
<div class="card">
  <h2>1. Подключение к Google Sheets</h2>
  <div class="step">
    <span class="step-num <?= $dsRes['ok']?'ok':'bad' ?>"><?= $dsRes['ok']?'✓':'✗' ?></span>
    <span class="step-label">
      <strong>UpSale/DownSell</strong> (sheet=UpSale%2FDownSell) —
      HTTP <?= h((string)$dsRes['code']) ?>,
      <?= $dsRes['ok'] ? '<span class="ok">'.number_format($dsRes['len']).' байт</span>' : '<span class="bad">'.h($dsRes['err']).'</span>' ?>
      <?= $ds ? ', <span class="ok">'.number_format($ds['total']).' строк</span>' : '' ?>
    </span>
  </div>
  <div class="step">
    <span class="step-num <?= $chnRes['ok']?'ok':'bad' ?>"><?= $chnRes['ok']?'✓':'✗' ?></span>
    <span class="step-label">
      <strong>Churn</strong> (sheet=Churn) —
      HTTP <?= h((string)$chnRes['code']) ?>,
      <?= $chnRes['ok'] ? '<span class="ok">'.number_format($chnRes['len']).' байт</span>' : '<span class="bad">'.h($chnRes['err']).'</span>' ?>
      <?= $chn ? ', <span class="ok">'.number_format($chn['total']).' строк</span>' : '' ?>
    </span>
  </div>
</div>

<?php if ($ds): ?>
<!-- ── Колонки DownSell ── -->
<div class="card">
  <h2>2. Колонки листа UpSale/DownSell (<?= count($ds['cols']) ?> шт.)</h2>
  <p class="tip">Зелёный = скорее всего нужная колонка. Оранжевый = возможный вариант.</p>

  <?php
  $typeKeywords  = ['upsale', 'downsell', 'тип сделки', 'тип изменения'];
  $kindKeywords  = ['тип', 'type'];
  $mrrKeywords   = ['mrr', 'изменение', 'сумма', 'delta'];
  $monthKeywords = ['месяц', 'month', 'дата', 'date'];
  $prodKeywords  = ['продукт', 'product'];
  $csmKeywords   = ['csm', 'менеджер'];
  $clientKeywords= ['клиент', 'account', 'client'];

  function colClass(string $col, array $kw): string {
      foreach($kw as $k) if(mb_stripos($col,$k)!==false) return 'match';
      return '';
  }
  ?>
  <div class="cols-list">
    <?php foreach($ds['cols'] as $col): ?>
    <span class="col-badge <?= colClass($col, array_merge($typeKeywords,$kindKeywords,$mrrKeywords,$monthKeywords,$prodKeywords,$csmKeywords,$clientKeywords)) ?>">
      <?= h($col) ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Значения ключевых колонок ── -->
<div class="card">
  <h2>3. Значения в ключевых колонках</h2>

  <?php
  // Find columns by keyword
  function findCols(array $cols, array $kw): array {
      $out=[];
      foreach($cols as $col)
          foreach($kw as $k)
              if(mb_stripos($col,$k)!==false && !in_array($col,$out)) $out[]=$col;
      return $out;
  }

  $typeCols   = findCols($ds['cols'], $typeKeywords);
  $kindCols   = findCols($ds['cols'], $kindKeywords);
  $mrrCols    = findCols($ds['cols'], $mrrKeywords);
  $monthCols  = findCols($ds['cols'], $monthKeywords);
  $prodCols   = findCols($ds['cols'], $prodKeywords);
  $csmCols    = findCols($ds['cols'], $csmKeywords);
  $clientCols = findCols($ds['cols'], $clientKeywords);

  function showColVals(array $rows, string $col, string $label): void {
      $vals = topVals($rows, $col, 20);
      if(!$vals) return;
      echo "<h3>«{$label}» <code>".h($col)."</code></h3><table><thead><tr><th>Значение</th><th>Строк</th></tr></thead><tbody>";
      foreach($vals as $v=>$cnt) {
          $cls = ($v==='') ? 'muted' : '';
          echo "<tr><td class=\"{$cls}\">".h($v===''?'(пусто)':$v)."</td><td>{$cnt}</td></tr>";
      }
      echo "</tbody></table>";
  }

  foreach($typeCols  as $c) showColVals($ds['rows'], $c, 'Тип сделки (UpSale/DownSell)');
  foreach($kindCols  as $c) showColVals($ds['rows'], $c, 'Тип (Постоянная/Временная)');
  foreach($prodCols  as $c) showColVals($ds['rows'], $c, 'Продукт');
  foreach($mrrCols   as $c) showColVals($ds['rows'], $c, 'MRR / Сумма изменения');
  foreach($monthCols as $c) showColVals($ds['rows'], $c, 'Месяц / Дата');
  ?>
</div>

<!-- ── Пошаговая фильтрация ── -->
<div class="card">
  <h2>4. Пошаговая фильтрация DownSell строк</h2>

  <?php
  $total = count($ds['rows']);

  // Шаг 1: найти колонку с "down"
  $typeColFound = $typeCols[0] ?? null;
  echo "<div class='step'><span class='step-num'>Всего</span><span class='step-label'>Строк в листе: <strong>{$total}</strong></span></div>";

  if(!$typeColFound) {
      echo "<p class='bad'>⚠ Колонка с типом сделки не найдена! Ожидались: ".h(implode(', ',$typeKeywords))."</p>";
  } else {
      // Step 1: contains 'down'
      $s1 = array_filter($ds['rows'], fn($r) => mb_stripos($r[$typeColFound]??'','down')!==false);
      $s1 = array_values($s1);
      echo "<div class='step'><span class='step-num ".($s1?'ok':'bad')."'>".count($s1)."</span>
        <span class='step-label'>После фильтра: <code>{$typeColFound}</code> содержит «down»</span></div>";

      if($s1) {
          // Step 2: kind filter
          $kindColFound = null;
          foreach($kindCols as $kc) if($kc !== $typeColFound) { $kindColFound=$kc; break; }

          if($kindColFound) {
              $s2 = array_filter($s1, fn($r) => in_array(mb_strtolower(trim($r[$kindColFound]??'')),['постоянная','']));
              $s2 = array_values($s2);
              echo "<div class='step'><span class='step-num ".($s2?'ok':'warn')."'>".count($s2)."</span>
                <span class='step-label'>После фильтра: <code>{$kindColFound}</code> = «Постоянная» или пусто</span></div>";

              $s2_perm = array_values(array_filter($s1, fn($r)=>mb_strtolower(trim($r[$kindColFound]??''))==='постоянная'));
              $s2_empty= array_values(array_filter($s1, fn($r)=>trim($r[$kindColFound]??'')===''));
              echo "<div class='tip' style='padding:4px 14px'>из них: Постоянная=<strong>".count($s2_perm)."</strong>, пусто=<strong>".count($s2_empty)."</strong></div>";
          } else {
              $s2 = $s1;
              echo "<p class='warn'>⚠ Колонка «тип» не найдена — пропускаем этот фильтр</p>";
          }

          // Step 3: MRR > 0
          $mrrColFound = null;
          foreach($mrrCols as $mc) if(mb_stripos($mc,'изменени')!==false||mb_stripos($mc,'mrr')!==false) { $mrrColFound=$mc; break; }
          if(!$mrrColFound && $mrrCols) $mrrColFound = $mrrCols[0];

          if($mrrColFound) {
              $s3 = array_values(array_filter($s2, fn($r) => amtParse($r[$mrrColFound]??'') > 0));
              echo "<div class='step'><span class='step-num ".($s3?'ok':'bad')."'>".count($s3)."</span>
                <span class='step-label'>После фильтра: <code>{$mrrColFound}</code> > 0 ₽</span></div>";

              if(!$s3 && $s2) {
                  // Try MRR old - new
                  $mrrOldCol = null; $mrrNewCol = null;
                  foreach($mrrCols as $mc) {
                      if(mb_stripos($mc,'стар')!==false||mb_stripos($mc,'old')!==false) $mrrOldCol=$mc;
                      if(mb_stripos($mc,'нов')!==false||mb_stripos($mc,'new')!==false)  $mrrNewCol=$mc;
                  }
                  if($mrrOldCol && $mrrNewCol) {
                      $s3b = array_values(array_filter($s2, function($r) use($mrrOldCol,$mrrNewCol){
                          $old=amtParse($r[$mrrOldCol]??''); $new=amtParse($r[$mrrNewCol]??'');
                          return $old>$new;
                      }));
                      echo "<div class='step'><span class='step-num ".($s3b?'ok':'bad')."'>".count($s3b)."</span>
                        <span class='step-label'>Альтернатива: <code>{$mrrOldCol}</code> &gt; <code>{$mrrNewCol}</code></span></div>";
                      $s3 = $s3b;
                  } else {
                      // Show sample MRR values
                      $samples = array_slice($s2,0,5);
                      echo "<p class='warn'>⚠ Не найден столбец с изменением MRR. Значения в {$mrrColFound}: ";
                      foreach($samples as $sr) echo h($sr[$mrrColFound]??'(пусто)').' | ';
                      echo "</p>";
                  }
              }
          } else {
              $s3 = $s2;
              echo "<p class='bad'>⚠ Колонка с суммой MRR не найдена! Нужна колонка «Изменение» или «MRR Старый»+«MRR Новый»</p>";
          }

          echo "<hr style='border-color:var(--border);margin:12px 0'>";
          echo "<div class='step'><span class='step-num ".($s3?'ok':'bad')."'>".count($s3)."</span>
            <span class='step-label'><strong>ИТОГО пройдут в отчёт</strong></span></div>";
      }
  }
  ?>
</div>

<!-- ── Первые 5 строк сырых данных ── -->
<div class="card">
  <h2>5. Первые 5 строк (сырые данные)</h2>
  <?php
  $sample = array_slice($ds['rows'], 0, 5);
  if($sample):
  ?>
  <div style="overflow-x:auto">
  <table>
    <thead><tr>
      <?php foreach($ds['cols'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
      <?php foreach($sample as $row): ?>
      <tr><?php foreach($ds['cols'] as $c): ?><td><?= h($row[$c]??'') ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: echo "<p class='bad'>Нет строк данных</p>"; endif; ?>
</div>

<!-- ── Рекомендации для кода ── -->
<div class="card">
  <h2>6. Что нужно поправить в ChurnFactReport.php</h2>
  <?php
  echo "<p>Текущий код ищет колонки: <code>upsale или downsell</code>, <code>тип</code>, <code>изменение</code></p>";
  if($typeCols) echo "<p class='ok'>✓ Найдены колонки типа сделки: ".h(implode(', ', $typeCols))."</p>";
  else          echo "<p class='bad'>✗ Колонка типа сделки не найдена!</p>";
  if($kindCols) echo "<p class='ok'>✓ Найдены колонки тип: ".h(implode(', ', $kindCols))."</p>";
  if($mrrCols)  echo "<p class='ok'>✓ Найдены MRR-колонки: ".h(implode(', ', $mrrCols))."</p>";
  else          echo "<p class='bad'>✗ Колонки MRR не найдены!</p>";
  if($prodCols) echo "<p class='ok'>✓ Продукт: ".h(implode(', ', $prodCols))."</p>";
  if($csmCols)  echo "<p class='ok'>✓ CSM: ".h(implode(', ', $csmCols))."</p>";
  if($clientCols) echo "<p class='ok'>✓ Клиент: ".h(implode(', ', $clientCols))."</p>";
  ?>
  <p style="margin-top:12px">Скопируй эти названия и сравни с <code>fetchDownsellRows()</code></p>
</div>

<?php endif; ?>

<?php if (!$ds): ?>
<div class="card">
  <p class="bad">⚠ UpSale/DownSell лист недоступен. HTTP <?= h((string)$dsRes['code']) ?> — <?= h($dsRes['err']) ?></p>
</div>
<?php endif; ?>

</body>
</html>

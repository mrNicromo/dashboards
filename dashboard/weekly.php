<?php
declare(strict_types=1);
set_time_limit(0);   // Airtable-запросы могут занимать >30 с при большом объёме данных

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/DzWeeklyHistory.php';
require_once __DIR__ . '/lib/DzWeekPayments.php';
require_once __DIR__ . '/lib/DzMrrCache.php';
require_once __DIR__ . '/lib/ManagerReport.php';

$c            = dashboard_config();
$bootstrapJson = '';
$errorMsg      = '';
$hasPat        = $c['airtable_pat'] !== '';

if ($hasPat) {
    try {
        $report        = ManagerReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
        $bootstrapJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($bootstrapJson === false) {
            $errorMsg = 'Ошибка кодирования JSON: ' . json_last_error_msg();
            $hasPat   = false;
        }
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        $hasPat   = false;
    }
}

?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <title>Еженедельный отчёт по ДЗ</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/weekly.css?v=3">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
<?php if (!$hasPat): ?>
  <div class="setup">
    <h1>Еженедельный отчёт по ДЗ</h1>
    <p>Нужен токен Airtable. Проверьте <code>config.php</code> или переменную окружения <code>AIRTABLE_PAT</code>.</p>
    <?php if ($errorMsg !== ''): ?>
      <p class="setup-err"><strong>Ошибка:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p><a href="manager.php">← Вернуться к дашборду</a></p>
  </div>
<?php else: ?>
  <script type="application/json" id="weekly-bootstrap"><?= $bootstrapJson ?></script>
  <div id="app">
    <div class="sk-page" id="app-skeleton">
      <div class="sk-topbar"></div>
      <div class="sk-wrap">
        <div class="sk-block sk-tall"></div>
        <div class="sk-block"></div>
        <div class="sk-block"></div>
        <div class="sk-block sk-short"></div>
      </div>
    </div>
  </div>
  <script src="assets/utils.js?v=1" defer></script>
  <script src="assets/weekly.js?v=3" defer></script>
  <script src="assets/shared-nav.js?v=2" defer></script>
<?php endif; ?>
</body>
</html>

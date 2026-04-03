<?php
declare(strict_types=1);

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

// Churn-данные из кэша (не делаем живых запросов — только кэш)
$churnJson = 'null';
$factJson  = 'null';
$churnCache = __DIR__ . '/cache/churn-report.json';
$factCache  = __DIR__ . '/cache/churn-fact-report.json';
if (is_readable($churnCache)) {
    $raw = file_get_contents($churnCache) ?: 'null';
    $churnJson = $raw !== '' ? $raw : 'null';
}
if (is_readable($factCache)) {
    $raw = file_get_contents($factCache) ?: 'null';
    $factJson = $raw !== '' ? $raw : 'null';
}

?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <title>Анализ дебиторской задолженности</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/manager.css?v=7">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
<?php if (!$hasPat): ?>
  <div class="setup">
    <h1>Дашборд руководителя</h1>
    <p>Нужен токен Airtable. Проверьте <code>config.php</code> или переменную окружения <code>AIRTABLE_PAT</code>.</p>
    <?php if ($errorMsg !== ''): ?>
      <p class="setup-err"><strong>Ошибка при загрузке:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p><a href="manager_api.php">Проверить API (JSON)</a></p>
  </div>
<?php else: ?>
  <script type="application/json" id="manager-bootstrap"><?= $bootstrapJson ?></script>
  <script type="application/json" id="churn-bootstrap"><?= $churnJson ?></script>
  <script type="application/json" id="fact-bootstrap"><?= $factJson ?></script>
  <div id="app"></div>
  <script src="assets/manager.js?v=8" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
<?php endif; ?>
</body>
</html>

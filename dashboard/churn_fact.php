<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/ChurnReport.php';
require_once __DIR__ . '/lib/ChurnFactReport.php';

$c             = dashboard_config();
$hasPat        = $c['airtable_pat'] !== '';
$bootstrapJson = '';

// Используем только кэш — страница загружается мгновенно.
// Если кэша нет, JS сам сделает async-запрос к churn_fact_api.php.
if ($hasPat) {
    $cached = ChurnFactReport::getCached();
    if ($cached !== null) {
        $bootstrapJson = json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}
?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title>Потери выручки — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/churn_fact.css?v=11">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
<?php if (!$hasPat): ?>
  <div class="setup">
    <h1>Потери выручки</h1>
    <p>Нужен токен Airtable. Проверьте <code>config.php</code>.</p>
  </div>
<?php else: ?>
  <script type="application/json" id="fact-bootstrap"><?= $bootstrapJson ?></script>
  <div id="app">
    <div class="sk-page" id="app-skeleton">
      <div class="sk-topbar"></div>
      <div class="sk-wrap">
        <div class="sk-kpi-row">
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
          <div class="sk-block sk-kpi"></div>
        </div>
        <div class="sk-block sk-tall"></div>
        <div class="sk-block"></div>
      </div>
    </div>
  </div>
  <script src="assets/utils.js?v=1" defer></script>
  <script src="assets/churn_fact.js?v=16" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
<?php endif; ?>
</body>
</html>

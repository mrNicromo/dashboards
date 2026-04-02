<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/ChurnReport.php';

$c             = dashboard_config();
$bootstrapJson = '';
$errorMsg      = '';
$hasPat        = $c['airtable_pat'] !== '';

// Используем только кэш — страница загружается мгновенно.
// Если кэша нет или он устарел, JS сам сделает async-запрос к churn_api.php.
if ($hasPat) {
    $cached = ChurnReport::getCached();
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
  <title>Угроза Churn — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/churn.css?v=10">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
<?php if (!$hasPat): /* PAT не настроен */ ?>
  <div class="setup">
    <h1>Угроза Churn</h1>
    <p>Нужен токен Airtable. Проверьте <code>config.php</code> или переменную окружения <code>AIRTABLE_PAT</code>.</p>
    <?php if ($errorMsg !== ''): ?>
      <p class="setup-err"><strong>Ошибка:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <script type="application/json" id="churn-bootstrap"><?= $bootstrapJson ?></script>
  <div id="app">
    <div class="sk-page" id="app-skeleton">
      <div class="sk-topbar"></div>
      <div class="sk-wrap">
        <div class="sk-block sk-tall"></div>
        <div class="sk-block"></div>
        <div class="sk-block sk-tall"></div>
      </div>
    </div>
  </div>
  <script src="assets/utils.js?v=1" defer></script>
  <script src="assets/churn.js?v=6" defer></script>
  <script src="assets/shared-nav.js?v=2" defer></script>
<?php endif; ?>
</body>
</html>

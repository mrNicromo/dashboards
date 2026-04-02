<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

$c = dashboard_config();
$baseId = (string) ($c['airtable_base_id'] ?? '');
$charts = AiInsightsContext::chartPayload(AiInsightsContext::cacheDir(), $baseId);
$geminiConfigured = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? ''))) !== '';
$groqConfigured = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? ''))) !== '';
$keyConfigured = $geminiConfigured || $groqConfigured;
$historyChart = AiInsightsHistory::chartSeries(56);
$hist = AiInsightsHistory::load();
$historyCount = isset($hist['items']) && is_array($hist['items']) ? count($hist['items']) : 0;
$bootstrapJson = json_encode(
    [
        'charts' => $charts,
        'historyChart' => $historyChart,
        'historyCount' => $historyCount,
        'hasAiKey' => $keyConfigured,
        'csrf' => csrf_token(),
    ],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <title>AI-аналитика — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/ai_insights.css?v=2">
  <script src="assets/aq-theme-boot.js?v=1"></script>
</head>
<body>
  <div class="ai-topbar">
    <div class="ai-topbar-left">
      <div class="ai-logo"><span class="ai-logo-box">AQ</span><span class="ai-logo-text">anyquery</span></div>
      <nav class="ai-nav-tabs">
        <a class="ai-nav-tab" href="index.php">🏠 Главная</a>
        <a class="ai-nav-tab" href="churn.php">⚠ Churn</a>
        <a class="ai-nav-tab" href="churn_fact.php">📉 Потери</a>
        <a class="ai-nav-tab" href="manager.php">💰 ДЗ</a>
        <a class="ai-nav-tab" href="weekly.php">📅 Неделя</a>
        <span class="ai-nav-tab ai-nav-tab-active">🤖 AI</span>
      </nav>
    </div>
    <div class="ai-topbar-right">
      <button type="button" class="btn-icon btn-theme-ai" id="btn-theme" title="Светлая тема" aria-label="Переключить тему">☀️</button>
    </div>
  </div>

  <div class="ai-wrap">
    <header class="ai-hero">
      <h1>AI-аналитика</h1>
      <p class="ai-hero-sub">Графики — по кэшу тех же данных, что и дашборды (Airtable). История снимков хранится локально в <code>cache/ai-insights-history.json</code> (не в git): после нескольких точек модель видит тренд и может делать осторожный прогноз.</p>
    </header>

    <?php if (!$keyConfigured): ?>
    <div class="ai-banner ai-banner-warn">
      <strong>Ключ AI не настроен.</strong> Нужен хотя бы один: <code>DASHBOARD_GEMINI_API_KEY</code> / <code>gemini_api_key</code> (основной) и/или <code>DASHBOARD_GROQ_API_KEY</code> / <code>groq_api_key</code> (резерв при лимите Gemini). Ключи не храните в git.
    </div>
    <?php endif; ?>

    <section class="ai-card ai-card-wide" id="ai-card-history-wrap">
      <h2>История снимков (тренд)</h2>
      <p class="ai-card-hint">Каждая точка — сохранённые метрики. Сохраняется автоматически после «Сгенерировать анализ»; можно добавить точку без AI — «Записать снимок метрик». Всего в истории: <strong id="ai-history-count"><?= (int) $historyCount ?></strong>.</p>
      <p class="ai-card-hint ai-history-empty" id="ai-history-empty" <?= $historyCount > 0 ? 'hidden' : '' ?>>Пока нет сохранённых снимков — нажмите «Записать снимок метрик» или сгенерируйте анализ.</p>
      <div class="ai-canvas-wrap ai-canvas-tall" id="ai-history-canvas-wrap" <?= $historyCount > 0 ? '' : 'hidden' ?>><canvas id="chart-history" aria-label="Динамика метрик по сохранённым снимкам"></canvas></div>
    </section>

    <div class="ai-chart-grid">
      <section class="ai-card">
        <h2>ДЗ: просрочка по корзинам</h2>
        <p class="ai-card-hint">Суммы из кэша дебиторки (таблица счетов Airtable)</p>
        <div class="ai-canvas-wrap"><canvas id="chart-aging" aria-label="График просрочки по корзинам"></canvas></div>
      </section>
      <section class="ai-card">
        <h2>Churn: MRR по сегментам</h2>
        <p class="ai-card-hint">Рискованный MRR из отчёта «Угроза Churn»</p>
        <div class="ai-canvas-wrap"><canvas id="chart-segment" aria-label="График MRR по сегментам"></canvas></div>
      </section>
      <section class="ai-card">
        <h2>ДЗ: топ менеджеров по сумме</h2>
        <p class="ai-card-hint">Агрегат по полю менеджера в счетах</p>
        <div class="ai-canvas-wrap"><canvas id="chart-managers" aria-label="График по менеджерам"></canvas></div>
      </section>
      <section class="ai-card ai-card-wide" id="ai-card-monthly-wrap" hidden>
        <h2>Потери: churn + downsell по месяцам</h2>
        <p class="ai-card-hint">Если есть кэш «Потери выручки»</p>
        <div class="ai-canvas-wrap ai-canvas-tall"><canvas id="chart-monthly" aria-label="График потерь по месяцам"></canvas></div>
      </section>
    </div>

    <section class="ai-card ai-insight-card">
      <div class="ai-insight-head">
        <h2>Выводы и решения</h2>
        <div class="ai-insight-actions">
          <button type="button" class="btn-icon ai-btn-secondary" id="btn-snapshot" title="Сохранить текущие метрики в историю без вызова AI">Записать снимок метрик</button>
          <button type="button" class="btn-icon ai-btn-primary" id="btn-generate" <?= $keyConfigured ? '' : 'disabled' ?>>Сгенерировать анализ</button>
        </div>
      </div>
      <p class="ai-card-hint" id="ai-status">«Сгенерировать анализ» — перед вызовом AI подтягиваются свежие данные из Airtable (ДЗ, Churn) и пересчитываются отчёты; затем JSON + история снимков → Gemini или Groq. «Записать снимок» — только метрики из кэша (для графика тренда).</p>
      <div class="ai-markdown" id="ai-output" hidden></div>
    </section>
  </div>

  <script type="application/json" id="ai-bootstrap"><?= $bootstrapJson ?></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js" crossorigin="anonymous"></script>
  <script src="assets/ai_insights.js?v=4" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
</body>
</html>

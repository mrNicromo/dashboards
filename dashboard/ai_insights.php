<?php
declare(strict_types=1);
set_time_limit(120);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

$c = dashboard_config();
$baseId = (string) ($c['airtable_base_id'] ?? '');
$dir = AiInsightsContext::cacheDir();
$chartLoadError = '';

$charts = AiInsightsContext::chartPayload($dir, $baseId);
$patOk = trim((string) ($c['airtable_pat'] ?? '')) !== '';
$chartsNeedAsyncRefresh = $patOk && (
    AiInsightsContext::chartPayloadLooksEmpty($charts)
    || AiInsightsContext::chartPayloadDzDepleted($charts)
);
$geminiConfigured = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? ''))) !== '';
$groqConfigured = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? ''))) !== '';
$anthropicConfigured = trim((string) (dashboard_env('DASHBOARD_ANTHROPIC_API_KEY') ?: ($c['anthropic_api_key'] ?? ''))) !== '';
$keyConfigured = $geminiConfigured || $groqConfigured || $anthropicConfigured;
// Порядок: Groq → Gemini → Claude (Groq как основной, Gemini как резерв)
$allProviders = [
    ['id' => 'groq',     'name' => 'Groq',     'ok' => $groqConfigured],
    ['id' => 'gemini',   'name' => 'Gemini',   'ok' => $geminiConfigured],
    ['id' => 'claude',   'name' => 'Claude',   'ok' => $anthropicConfigured],
];
$configuredProviders = array_filter([
    $groqConfigured ? 'Groq' : null,
    $geminiConfigured ? 'Gemini' : null,
    $anthropicConfigured ? 'Claude' : null,
]);
$historyChart = AiInsightsHistory::chartSeries(56);
$hist = AiInsightsHistory::load();
$historyCount = isset($hist['items']) && is_array($hist['items']) ? count($hist['items']) : 0;

// Последний сохранённый AI-анализ из истории (для авто-показа на странице)
$lastAnalysisText = '';
$lastAnalysisTs = 0;
if (!empty($hist['items']) && is_array($hist['items'])) {
    foreach (array_reverse($hist['items']) as $hItem) {
        if (!empty($hItem['analysis'])) {
            $lastAnalysisText = (string) $hItem['analysis'];
            $lastAnalysisTs = (int) ($hItem['t'] ?? 0);
            break;
        }
    }
}
$chartHints = AiInsightsContext::chartHintsFromCharts($charts);
// Мета-список снимков для UI сравнения (timestamp + краткие метрики, без текста анализа)
// Auto-snapshot: check if last snapshot is older than configured interval
$autoSnapshotHours = max(1, (int) ($c['ai_auto_snapshot_hours'] ?? 24));
$lastItems = $hist['items'] ?? [];
$lastSnapshotTs = 0;
if (!empty($lastItems)) {
    $lastItem = end($lastItems);
    $lastSnapshotTs = (int) ($lastItem['t'] ?? 0);
}
$autoSnapshotNeeded = $keyConfigured && $patOk && (time() - $lastSnapshotTs) > ($autoSnapshotHours * 3600);

$historyMeta = array_reverse(array_map(static function (array $item): array {
    $m = $item['metrics'] ?? [];
    return [
        't' => $item['t'] ?? '',
        'hasAnalysis' => isset($item['analysis']) && $item['analysis'] !== null && $item['analysis'] !== '',
        'm' => [
            'dzTotal'   => $m['dzTotal'] ?? null,
            'dzOverdue' => $m['dzOverdue'] ?? null,
            'churnRisk' => $m['churnRisk'] ?? null,
            'factTotalYtd' => $m['factTotalYtd'] ?? null,
        ],
    ];
}, $hist['items'] ?? []));

$bootstrapJson = json_encode(
    [
        'charts' => $charts,
        'historyChart' => $historyChart,
        'historyCount' => $historyCount,
        'historyMeta' => $historyMeta,
        'hasAiKey' => $keyConfigured,
        'chartsNeedAsyncRefresh' => $chartsNeedAsyncRefresh,
        'chartHints' => $chartHints,
        'providers' => array_values($configuredProviders),
        'allProviders' => $allProviders,
        'autoSnapshotNeeded' => $autoSnapshotNeeded,
        'autoSnapshotHours' => $autoSnapshotHours,
        'lastAnalysis' => $lastAnalysisText !== '' ? ['text' => $lastAnalysisText, 't' => $lastAnalysisTs] : null,
        'csrf' => csrf_token(),
        'baseId' => htmlspecialchars($baseId, ENT_QUOTES, 'UTF-8'),
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
  <link rel="stylesheet" href="assets/ai_insights.css?v=6">
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
        <a class="ai-nav-tab" href="settings.php">⚙️ Настройки</a>
      </nav>
    </div>
    <div class="ai-topbar-right">
      <button type="button" class="btn-icon btn-theme-ai" id="btn-theme" title="Светлая тема" aria-label="Переключить тему">☀️</button>
    </div>
  </div>

  <div class="ai-wrap">
    <header class="ai-hero">
      <h1>AI-аналитика</h1>
      <p class="ai-hero-sub">Графики — снимок из Airtable (дебиторка, Churn) и отчётов; потери по месяцам — из Sheets → кэш. <strong>Сгенерировать анализ</strong> идёт в два HTTP-шага (синхронизация по API, затем модель) — реже обрывается по таймауту прокси. История снимков — <code>cache/ai-insights-history.json</code>. Пустые графики при открытии подгружаются в фоне.</p>
    </header>

    <p class="ai-sync-line" id="ai-charts-sync-status" <?= $chartsNeedAsyncRefresh ? '' : 'hidden' ?>><?= $chartsNeedAsyncRefresh ? 'Синхронизация с Airtable для графиков…' : '' ?></p>

    <?php if ($chartLoadError !== ''): ?>
    <div class="ai-banner ai-banner-warn">
      <strong>Не удалось подгрузить данные для графиков из Airtable.</strong> <?= htmlspecialchars($chartLoadError, ENT_QUOTES, 'UTF-8') ?> Откройте сначала <a href="manager.php">ДЗ</a> или <a href="index.php">Главную</a>, либо проверьте PAT и права токена.
    </div>
    <?php endif; ?>

    <!-- Статусы AI-провайдеров (Groq → Gemini → Claude) -->
    <div class="ai-provider-status-row">
      <?php foreach ($allProviders as $prov): ?>
      <div class="ai-provider-status <?= $prov['ok'] ? 'ai-provider-ok' : 'ai-provider-off' ?>">
        <span class="ai-provider-dot"></span>
        <span class="ai-provider-name"><?= htmlspecialchars($prov['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="ai-provider-state"><?= $prov['ok'] ? 'подключено' : 'ключ не задан' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$keyConfigured): ?>
    <div class="ai-banner ai-banner-warn">
      <strong>Ключ AI не настроен.</strong> Нужен хотя бы один:
      <code>DASHBOARD_GROQ_API_KEY</code> (Groq · основной),
      <code>DASHBOARD_GEMINI_API_KEY</code> (Gemini · резерв),
      <code>DASHBOARD_ANTHROPIC_API_KEY</code> (Claude · резерв).
      Задайте в <code>config.php</code> или переменных окружения. Ключи не храните в git.
    </div>
    <?php endif; ?>

    <!-- Блок ошибок подключения (заполняется JS при ошибках Airtable/LLM) -->
    <div id="ai-error-status-wrap" hidden></div>

    <section class="ai-card ai-card-wide" id="ai-card-history-wrap">
      <h2>История снимков (тренд)</h2>
      <p class="ai-card-hint">Каждая точка — сохранённые метрики. Сохраняется автоматически после «Сгенерировать анализ»; можно добавить точку без AI — «Записать снимок метрик». Всего в истории: <strong id="ai-history-count"><?= (int) $historyCount ?></strong>.</p>
      <p class="ai-card-hint ai-history-empty" id="ai-history-empty" <?= $historyCount > 0 ? 'hidden' : '' ?>>Пока нет сохранённых снимков — нажмите «Записать снимок метрик» или сгенерируйте анализ.</p>
      <div class="ai-canvas-wrap ai-canvas-tall" id="ai-history-canvas-wrap" <?= $historyCount > 0 ? '' : 'hidden' ?>><canvas id="chart-history" aria-label="Динамика метрик по сохранённым снимкам"></canvas></div>
    </section>

    <div class="ai-chart-grid">
      <section class="ai-card">
        <h2>ДЗ: просрочка по корзинам</h2>
        <p class="ai-card-hint">Снимок дебиторки из Airtable (счета)</p>
        <div class="ai-canvas-wrap"><canvas id="chart-aging" aria-label="График просрочки по корзинам"></canvas></div>
        <p class="ai-chart-foot" id="ai-hint-aging" <?= $chartHints['aging'] !== '' ? '' : 'hidden' ?>><?= htmlspecialchars($chartHints['aging'], ENT_QUOTES, 'UTF-8') ?></p>
      </section>
      <section class="ai-card">
        <h2>Churn: MRR по сегментам</h2>
        <p class="ai-card-hint">Риск MRR по отчёту «Угроза Churn» (Airtable)</p>
        <div class="ai-canvas-wrap"><canvas id="chart-segment" aria-label="График MRR по сегментам"></canvas></div>
      </section>
      <section class="ai-card">
        <h2>ДЗ: топ менеджеров по сумме</h2>
        <p class="ai-card-hint">Агрегат по менеджеру в счетах (Airtable)</p>
        <div class="ai-canvas-wrap"><canvas id="chart-managers" aria-label="График по менеджерам"></canvas></div>
        <p class="ai-chart-foot" id="ai-hint-managers" <?= $chartHints['managers'] !== '' ? '' : 'hidden' ?>><?= htmlspecialchars($chartHints['managers'], ENT_QUOTES, 'UTF-8') ?></p>
      </section>
      <section class="ai-card ai-card-wide" id="ai-card-monthly-wrap" hidden>
        <h2>Потери: churn + downsell по месяцам</h2>
        <p class="ai-card-hint">По данным отчёта потерь (Sheets → кэш на сервере)</p>
        <div class="ai-canvas-wrap ai-canvas-tall"><canvas id="chart-monthly" aria-label="График потерь по месяцам"></canvas></div>
      </section>
      <section class="ai-card" id="ai-card-product-wrap" hidden>
        <h2>Потери по продуктам (YTD)</h2>
        <p class="ai-card-hint">Churn + Downsell в разбивке по продукту за текущий год</p>
        <div class="ai-canvas-wrap"><canvas id="chart-product" aria-label="Потери по продуктам"></canvas></div>
      </section>
      <section class="ai-card" id="ai-card-seg-monthly-wrap" hidden>
        <h2>Потери: ENT vs SMB по месяцам</h2>
        <p class="ai-card-hint">Сравнение сегментов Enterprise и SMB</p>
        <div class="ai-canvas-wrap"><canvas id="chart-seg-monthly" aria-label="ENT vs SMB потери"></canvas></div>
      </section>
    </div>

    <section class="ai-card ai-insight-card">
      <div class="ai-insight-head">
        <h2>Выводы и решения</h2>
        <div class="ai-insight-actions">
          <button type="button" class="btn-icon ai-btn-secondary" id="btn-snapshot" title="Сохранить текущие метрики в историю без вызова AI">Записать снимок метрик</button>
          <button type="button" class="btn-icon ai-btn-secondary" id="btn-generate-stream" <?= $keyConfigured ? '' : 'disabled' ?> title="Потоковая генерация — текст появляется по мере ответа модели">⚡ Стриминг</button>
          <button type="button" class="btn-icon ai-btn-primary" id="btn-generate" <?= $keyConfigured ? '' : 'disabled' ?>>Сгенерировать анализ</button>
          <button type="button" class="btn-icon ai-btn-force" id="btn-analyze-all" <?= $keyConfigured ? '' : 'disabled' ?> title="Принудительная синхронизация всех данных из Airtable без кэша + краткий анализ всех дашбордов">🔄 Все дашборды</button>
        </div>
      </div>
      <p class="ai-card-hint" id="ai-status">«Сгенерировать анализ» — синхронизация Airtable → запрос к модели. «⚡ Стриминг» — то же, но текст появляется сразу по мере ответа. «Записать снимок» — только метрики без AI.</p>

      <div class="ai-custom-question-wrap" id="ai-custom-question-wrap">
        <button type="button" class="ai-custom-question-toggle" id="btn-custom-question-toggle" aria-expanded="false">＋ Добавить свой вопрос к данным</button>
        <div class="ai-custom-question-body" id="ai-custom-question-body" hidden>
          <textarea
            id="ai-custom-question"
            class="ai-custom-question-textarea"
            placeholder="Например: Какие клиенты из корзины 90+ ещё не закрыли долг? Или: Сравни риск чёрна этого месяца с прошлым."
            rows="3"
            maxlength="1000"
          ></textarea>
          <p class="ai-card-hint">Вопрос добавляется к промпту — модель ответит на него, используя данные снимка.</p>
        </div>
      </div>
      <p class="ai-restore-row" id="ai-restore-wrap" hidden>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-restore">Показать последний сохранённый анализ</button>
      </p>
      <div class="ai-output-toolbar" id="ai-output-toolbar" hidden>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-copy">Копировать Markdown</button>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-dl">Скачать .md</button>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-expand" hidden>Развернуть полностью</button>
      </div>
      <div class="ai-number-warn" id="ai-number-warn" hidden></div>
      <div class="ai-output-wrap ai-output-wrap-collapsed" id="ai-output-wrap">
        <div class="ai-markdown ai-markdown-empty" id="ai-output">
          <p class="ai-output-placeholder">Нажмите «Сгенерировать анализ», чтобы получить текстовые выводы модели по данным дашборда.</p>
        </div>
      </div>
    </section>
    <section class="ai-card ai-compare-card" id="ai-compare-section" <?= $historyCount < 2 ? 'hidden' : '' ?>>
      <h2>Сравнение двух снимков</h2>
      <p class="ai-card-hint">Выберите два снимка из истории и сравните метрики — увидите дельту по ДЗ, Churn, потерям.</p>
      <div class="ai-compare-controls">
        <div class="ai-compare-select-wrap">
          <label class="ai-compare-label">Снимок A (новее)</label>
          <select id="ai-compare-a" class="ai-compare-select"></select>
        </div>
        <div class="ai-compare-select-wrap">
          <label class="ai-compare-label">Снимок B (старше)</label>
          <select id="ai-compare-b" class="ai-compare-select"></select>
        </div>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-compare">Сравнить</button>
      </div>
      <div id="ai-compare-result" hidden></div>
    </section>

    <section class="ai-card ai-cron-card">
      <h2>Автоматический анализ (cron)</h2>
      <p class="ai-card-hint">Запускайте анализ по расписанию без браузера — через <code>ai_insights_cron_api.php</code> с <code>DASHBOARD_API_SECRET</code>.</p>
      <pre class="ai-cron-example"># Каждый день в 9:00 UTC
0 9 * * * curl -s -X POST https://your-domain/ai_insights_cron_api.php \
     -H "Authorization: Bearer &lt;DASHBOARD_API_SECRET&gt;" \
     -H "Content-Type: application/json" \
     -d '{}' &gt;&gt; /var/log/aq-ai-cron.log

# Только снимок метрик (без вызова LLM):
0 * * * * curl -s -X POST https://your-domain/ai_insights_cron_api.php \
     -H "Authorization: Bearer &lt;DASHBOARD_API_SECRET&gt;" \
     -H "Content-Type: application/json" \
     -d '{"metricsOnly":true}'

# Пороги алертов (env на сервере):
# DASHBOARD_AI_ALERT_OVERDUE_PCT=40   — алерт если просрочка &gt; 40% ДЗ
# DASHBOARD_AI_ALERT_AGING90_PCT=20   — алерт если корзина 90+ &gt; 20% ДЗ
# DASHBOARD_AI_ALERT_CHURN_MRR=500000 — алерт если Churn-риск MRR &gt; 500 000 ₽</pre>
    </section>
  </div>

  <script type="application/json" id="ai-bootstrap"><?= $bootstrapJson ?></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js" crossorigin="anonymous"></script>
  <script src="assets/ai_insights.js?v=9" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
</body>
</html>

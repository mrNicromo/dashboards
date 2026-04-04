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
// Порядок попыток: Gemini → Groq → Claude
$allProviders = [
    ['id' => 'gemini',   'name' => 'Gemini',   'ok' => $geminiConfigured],
    ['id' => 'groq',     'name' => 'Groq',     'ok' => $groqConfigured],
    ['id' => 'claude',   'name' => 'Claude',   'ok' => $anthropicConfigured],
];
$configuredProviders = array_filter([
    $geminiConfigured ? 'Gemini' : null,
    $groqConfigured ? 'Groq' : null,
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
        'syncedAt' => $chartsNeedAsyncRefresh ? null : time(),
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
  <link rel="stylesheet" href="assets/ai_insights.css?v=12">
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
      <div class="ai-topbar-provider-badge" id="ai-topbar-badge">
        <?php if ($geminiConfigured || $groqConfigured || $anthropicConfigured): ?>
          <span class="ai-topbar-badge-dot ai-topbar-badge-dot-ok"></span>
          <?= $geminiConfigured ? 'Gemini' : ($groqConfigured ? 'Groq' : 'Claude') ?>
        <?php else: ?>
          <span class="ai-topbar-badge-dot ai-topbar-badge-dot-off"></span>AI не настроен
        <?php endif; ?>
      </div>
      <button type="button" class="btn-icon btn-theme-ai" id="btn-theme" title="Светлая тема" aria-label="Переключить тему">☀️</button>
    </div>
  </div>

  <div class="ai-wrap">
    <header class="ai-hero">
      <h1>AI-аналитика</h1>
      <p class="ai-hero-sub">AI анализирует данные вашей команды: дебиторку, отток и фактические потери. Нажмите <strong>«Анализировать»</strong> — результат появится через 15–30 секунд. Каждый анализ сохраняется в истории снимков для сравнения динамики.</p>
    </header>

    <p class="ai-sync-line" id="ai-charts-sync-status" <?= $chartsNeedAsyncRefresh ? '' : 'hidden' ?>><?= $chartsNeedAsyncRefresh ? 'Синхронизация с Airtable для графиков…' : '' ?></p>

    <?php if ($chartLoadError !== ''): ?>
    <div class="ai-banner ai-banner-warn">
      <strong>Не удалось подгрузить данные для графиков из Airtable.</strong> <?= htmlspecialchars($chartLoadError, ENT_QUOTES, 'UTF-8') ?> Откройте сначала <a href="manager.php">ДЗ</a> или <a href="index.php">Главную</a>, либо проверьте PAT и права токена.
    </div>
    <?php endif; ?>

    <!-- Статусы AI-провайдеров (свёрнуто — для отладки) -->
    <details class="ai-provider-details">
      <summary>Провайдеры AI</summary>
      <div class="ai-provider-status-row">
        <?php foreach ([
            ['name' => 'Gemini', 'ok' => $geminiConfigured],
            ['name' => 'Groq',   'ok' => $groqConfigured],
            ['name' => 'Claude', 'ok' => $anthropicConfigured],
        ] as $prov): ?>
        <div class="ai-provider-status <?= $prov['ok'] ? 'ai-provider-ok' : 'ai-provider-off' ?>">
          <span class="ai-provider-dot"></span>
          <span class="ai-provider-name"><?= htmlspecialchars($prov['name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="ai-provider-state"><?= $prov['ok'] ? 'подключено' : 'ключ не задан' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php if (!$keyConfigured): ?>
    <div class="ai-banner ai-banner-warn">
      <strong>Ключ AI не настроен.</strong> Нужен хотя бы один:
      <code>DASHBOARD_GEMINI_API_KEY</code> (Gemini · основной),
      <code>DASHBOARD_GROQ_API_KEY</code> (Groq · резерв),
      <code>DASHBOARD_ANTHROPIC_API_KEY</code> (Claude · резерв).
      Задайте в <code>config.php</code> или переменных окружения. Ключи не храните в git.
    </div>
    <?php endif; ?>

    <!-- Блок ошибок подключения (заполняется JS при ошибках Airtable/LLM) -->
    <div id="ai-error-status-wrap" hidden></div>

    <section class="ai-card ai-card-wide ai-snapshot-card">
      <div class="ai-snapshot-head">
        <div>
          <h2>Снимок текущей ситуации</h2>
          <p class="ai-card-hint">Ключевые показатели по текущему набору данных — что выглядит самым тяжёлым прямо сейчас.</p>
        </div>
        <div class="ai-snapshot-actions">
          <span class="ai-data-timestamp" id="ai-data-timestamp" hidden></span>
          <button type="button" class="btn-icon ai-btn-secondary ai-btn-sync" id="btn-sync-data" <?= $patOk ? '' : 'disabled' ?> data-tip="Загружает свежие данные из Airtable без запуска AI. Используйте перед анализом, если данные изменились.">↻ Синхронизировать</button>
        </div>
      </div>
      <div class="ai-kpi-strip" id="ai-kpi-strip">
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="ai-kpi-card ai-kpi-skeleton">
          <div class="ai-skel-line" style="width:60%;height:.65rem"></div>
          <div class="ai-skel-line" style="width:80%;height:1.2rem;margin-top:6px"></div>
          <div class="ai-skel-line" style="width:90%;height:.6rem;margin-top:6px"></div>
        </div>
        <?php endfor; ?>
      </div>
    </section>

    <section class="ai-card ai-card-wide" id="ai-card-history-wrap">
      <div class="ai-section-head">
        <div>
          <h2>История снимков <span class="ai-history-badge" id="ai-history-count-badge" <?= $historyCount > 0 ? '' : 'hidden' ?>><?= (int) $historyCount ?></span></h2>
          <p class="ai-card-hint ai-history-empty" id="ai-history-empty" <?= $historyCount > 0 ? 'hidden' : '' ?>>Пока нет снимков — запустите анализ или нажмите «Записать снимок метрик».</p>
        </div>
      </div>
      <div class="ai-canvas-wrap ai-canvas-tall" id="ai-history-canvas-wrap" <?= $historyCount > 0 ? '' : 'hidden' ?>><canvas id="chart-history" aria-label="Динамика метрик по сохранённым снимкам"></canvas></div>
    </section>

    <div class="ai-charts-header">
      <span class="ai-charts-label">Графики</span>
      <button type="button" class="ai-charts-toggle" id="btn-charts-toggle" aria-expanded="false" data-tip="Показывает KPI-графики: просрочка ДЗ, churn по сегментам, топ-менеджеры. Загружается один раз.">Загрузить графики ▾</button>
    </div>

    <div class="ai-chart-grid ai-chart-grid-hidden" id="ai-chart-grid">
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
          <button type="button" class="btn-icon ai-btn-secondary" id="btn-snapshot" data-tip="Фиксирует текущие метрики в историю без AI. Полезно для сравнения «до/после».">Записать снимок метрик</button>
          <button type="button" class="btn-icon ai-btn-primary" id="btn-generate-stream" <?= $keyConfigured ? '' : 'disabled' ?> data-tip="Запустить анализ. Ответ появляется постепенно. Горячая клавиша: Ctrl+Enter">⚡ Анализировать</button>
          <button type="button" class="btn-icon ai-btn-secondary" id="btn-generate" <?= $keyConfigured ? '' : 'disabled' ?> data-tip="Ожидает полного ответа от модели и показывает всё сразу — медленнее, но стабильнее.">Без стриминга</button>
          <button type="button" class="btn-icon ai-btn-force" id="btn-analyze-all" <?= $keyConfigured ? '' : 'disabled' ?> data-tip="Принудительная синхронизация всех данных из Airtable + краткий анализ по всем дашбордам. Тратит больше токенов.">🔄 Все дашборды</button>
        </div>
      </div>
      <!-- Progress bar -->
      <div class="ai-progress-wrap" id="ai-progress-wrap" hidden>
        <div class="ai-progress-steps">
          <div class="ai-progress-step" id="ai-step-sync" data-tip="Синхронизация ДЗ, churn и потерь из Airtable">📡 Данные</div>
          <div class="ai-progress-sep">›</div>
          <div class="ai-progress-step" id="ai-step-model" data-tip="Запрос к AI-модели (Gemini/Groq/Claude)">🧠 Анализ</div>
          <div class="ai-progress-sep">›</div>
          <div class="ai-progress-step" id="ai-step-done" data-tip="Результат получен и отрисован">✓ Готово</div>
        </div>
      </div>
      <p class="ai-card-hint" id="ai-status">«⚡ Анализировать» — синхронизация Airtable → модель, текст идёт потоком. «Без стриминга» — то же, но ожидает полного ответа. «Записать снимок» — только метрики без AI.</p>
      <div class="ai-result-meta" id="ai-result-meta" hidden></div>

      <div class="ai-custom-question-wrap" id="ai-custom-question-wrap">
        <div class="ai-preset-row" id="ai-preset-row">
          <button type="button" class="ai-preset-chip" data-ai-preset="Собери конкретный план действий на 7 дней: выдели самые срочные риски, расставь приоритеты и дай короткие исполнимые шаги.">План на 7 дней</button>
          <button type="button" class="ai-preset-chip" data-ai-preset="Сделай разбор по менеджерам: где у кого самая большая проблема, какие суммы или клиенты её формируют и что каждому делать дальше.">По менеджерам</button>
          <button type="button" class="ai-preset-chip" data-ai-preset="Сфокусируйся на самых критичных долгах 91+ и выше: какие клиенты или зоны требуют немедленного вмешательства и какие действия нужны в первую очередь.">Критичные долги 91+</button>
          <button type="button" class="ai-preset-chip" data-ai-preset="Сравни, где сейчас основной риск для выручки: дебиторка, churn или фактические потери. Дай короткое объяснение и приоритет действий.">Где главный риск</button>
        </div>
        <textarea
          id="ai-custom-question"
          class="ai-custom-question-textarea"
          placeholder="Свой вопрос к данным (необязательно) — Ctrl+Enter для запуска"
          rows="2"
          maxlength="1000"
        ></textarea>
      </div>
      <p class="ai-restore-row" id="ai-restore-wrap" hidden>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-restore" data-tip="Показать последний анализ, сохранённый на сервере">Показать последний сохранённый анализ</button>
      </p>
      <div class="ai-output-toolbar" id="ai-output-toolbar" hidden>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-copy" data-tip="Скопировать результат в буфер обмена в формате Markdown">Копировать Markdown</button>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-dl" data-tip="Скачать анализ как файл .md — удобно для архива или отправки в мессенджер">Скачать .md</button>
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-ai-expand" hidden data-tip="Показать полный текст без ограничения высоты">Развернуть полностью</button>
      </div>
      <div class="ai-number-warn" id="ai-number-warn" hidden></div>
      <!-- Action items card — extracted from Приоритеты section -->
      <div class="ai-actions-card" id="ai-actions-card" hidden>
        <div class="ai-actions-head">
          <span class="ai-actions-title" data-tip="Задачи из раздела «Приоритеты» в ответе AI. Отмечайте галочками — состояние сохраняется в браузере.">✅ Что делать</span>
          <button type="button" class="ai-actions-clear" id="btn-actions-clear" data-tip="Снять все галочки и сбросить прогресс">Сбросить отметки</button>
        </div>
        <ul class="ai-actions-list" id="ai-actions-list"></ul>
      </div>
      <!-- Plan/timeline section — built from action items -->
      <div class="ai-plan-section" id="ai-plan-section" hidden>
        <div class="ai-plan-head">
          <span class="ai-plan-title">📅 Недельный план</span>
          <span class="ai-plan-hint">Порядок выполнения задач по неделям</span>
        </div>
        <div class="ai-plan-chart-wrap">
          <canvas id="ai-plan-chart" height="160"></canvas>
        </div>
      </div>
      <div class="ai-outline" id="ai-outline" hidden></div>
      <div class="ai-output-wrap ai-output-wrap-collapsed" id="ai-output-wrap">
        <div class="ai-markdown ai-markdown-empty" id="ai-output">
          <div class="ai-empty-state">
            <div class="ai-empty-icon">🤖</div>
            <p class="ai-empty-title">Нажмите <strong>⚡ Анализировать</strong></p>
            <p class="ai-empty-sub">AI проанализирует ДЗ, отток и потери — результат через 15–30 сек</p>
          </div>
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
        <button type="button" class="btn-icon ai-btn-secondary" id="btn-compare" data-tip="Показывает дельту метрик между снимком A и B: ДЗ, churn, потери">Сравнить</button>
      </div>
      <div id="ai-compare-result" hidden></div>
    </section>

  </div>

  <!-- FAB — появляется при скролле вниз -->
  <button type="button" class="ai-fab" id="btn-fab" data-tip="Запустить анализ (Ctrl+Enter)" data-tip-pos="below" <?= $keyConfigured ? '' : 'disabled' ?>>⚡</button>

  <script type="application/json" id="ai-bootstrap"><?= $bootstrapJson ?></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js" crossorigin="anonymous"></script>
  <script src="assets/ai_insights.js?v=15" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
</body>
</html>

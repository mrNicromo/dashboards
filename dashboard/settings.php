<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$c = dashboard_config();
$csrf = csrf_token();

// Current values to pre-fill the form
$vals = [
    'airtable_pat'       => $c['airtable_pat'] ?? '',
    'airtable_base_id'   => $c['airtable_base_id'] ?? '',
    'gemini_api_key'     => $c['gemini_api_key'] ?? '',
    'groq_api_key'       => $c['groq_api_key'] ?? '',
    'anthropic_api_key'  => $c['anthropic_api_key'] ?? '',
    'api_secret'         => $c['api_secret'] ?? '',
    'auth_username'      => $c['auth_username'] ?? '',
    'auth_password'      => $c['auth_password'] ?? '',
    'sheets_churn_csv'   => $c['sheets_churn_csv'] ?? '',
    'sheets_ds_csv'      => $c['sheets_ds_csv'] ?? '',
    'ai_auto_snapshot_hours' => $c['ai_auto_snapshot_hours'] ?? '24',
    'ai_alert_overdue_pct'   => dashboard_env('DASHBOARD_AI_ALERT_OVERDUE_PCT') ?: '',
    'ai_alert_aging91_pct'   => dashboard_env('DASHBOARD_AI_ALERT_AGING90_PCT') ?: '',
    'ai_alert_churn_mrr'     => dashboard_env('DASHBOARD_AI_ALERT_CHURN_MRR') ?: '',
];

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="csrf-token" content="<?= esc($csrf) ?>">
  <title>Настройки — AnyQuery</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <link rel="stylesheet" href="assets/settings.css?v=1">
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
        <a class="ai-nav-tab" href="ai_insights.php">🤖 AI</a>
        <span class="ai-nav-tab ai-nav-tab-active">⚙️ Настройки</span>
      </nav>
    </div>
    <div class="ai-topbar-right">
      <button type="button" class="btn-icon btn-theme-ai" id="btn-theme" title="Светлая тема" aria-label="Переключить тему">☀️</button>
    </div>
  </div>

  <div class="st-wrap">
    <header class="st-header">
      <h1>Настройки</h1>
      <p class="st-header-sub">Параметры сохраняются в <code>.env.local</code> в корне проекта. Файл не попадает в git. Переменные окружения на хостинге имеют приоритет над <code>.env.local</code>.</p>
    </header>

    <div id="st-alert" class="st-alert" hidden></div>

    <form id="st-form" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

      <!-- ── Airtable ──────────────────────────────────────── -->
      <section class="st-section">
        <h2 class="st-section-title">Airtable</h2>
        <div class="st-grid">
          <div class="st-field">
            <label class="st-label" for="airtable_pat">Personal Access Token (PAT)</label>
            <input class="st-input st-input-secret" type="password" id="airtable_pat" name="airtable_pat"
              value="<?= esc($vals['airtable_pat']) ?>" placeholder="patXXX…" autocomplete="off">
            <p class="st-hint">Создаётся в airtable.com → Account → Developer Hub. Нужны права read на базу.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="airtable_base_id">Base ID</label>
            <input class="st-input" type="text" id="airtable_base_id" name="airtable_base_id"
              value="<?= esc($vals['airtable_base_id']) ?>" placeholder="appXXX…">
          </div>
        </div>
      </section>

      <!-- ── AI-провайдеры ─────────────────────────────────── -->
      <section class="st-section">
        <h2 class="st-section-title">AI-провайдеры</h2>
        <p class="st-section-hint">Порядок попыток: Gemini → Groq → Claude. Достаточно одного ключа.</p>
        <div class="st-grid">
          <div class="st-field">
            <label class="st-label" for="gemini_api_key">
              <span class="st-badge st-badge-gemini">Gemini</span> API-ключ
            </label>
            <input class="st-input st-input-secret" type="password" id="gemini_api_key" name="gemini_api_key"
              value="<?= esc($vals['gemini_api_key']) ?>" placeholder="AIzaSy…" autocomplete="off">
            <p class="st-hint">Бесплатный уровень: <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a>.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="groq_api_key">
              <span class="st-badge st-badge-groq">Groq</span> API-ключ
            </label>
            <input class="st-input st-input-secret" type="password" id="groq_api_key" name="groq_api_key"
              value="<?= esc($vals['groq_api_key']) ?>" placeholder="gsk_…" autocomplete="off">
            <p class="st-hint">Бесплатный: <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com</a>.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="anthropic_api_key">
              <span class="st-badge st-badge-claude">Claude</span> API-ключ
              <span class="st-badge-note">(резерв — добавить позже)</span>
            </label>
            <input class="st-input st-input-secret" type="password" id="anthropic_api_key" name="anthropic_api_key"
              value="<?= esc($vals['anthropic_api_key']) ?>" placeholder="sk-ant-…" autocomplete="off" disabled>
            <p class="st-hint">Поле зарезервировано. Раскомментируйте <code>disabled</code> когда будете добавлять ключ.</p>
          </div>
        </div>
      </section>

      <!-- ── Google Sheets ─────────────────────────────────── -->
      <section class="st-section">
        <h2 class="st-section-title">Google Sheets (данные потерь)</h2>
        <p class="st-section-hint">CSV-ссылки на листы Churn и DownSell. Если пусто — используются встроенные URL из кода.</p>
        <div class="st-grid st-grid-wide">
          <div class="st-field">
            <label class="st-label" for="sheets_churn_csv">Churn — CSV URL</label>
            <input class="st-input" type="url" id="sheets_churn_csv" name="sheets_churn_csv"
              value="<?= esc($vals['sheets_churn_csv']) ?>"
              placeholder="https://docs.google.com/spreadsheets/d/…/gviz/tq?tqx=out:csv&sheet=Churn">
          </div>
          <div class="st-field">
            <label class="st-label" for="sheets_ds_csv">DownSell — CSV URL</label>
            <input class="st-input" type="url" id="sheets_ds_csv" name="sheets_ds_csv"
              value="<?= esc($vals['sheets_ds_csv']) ?>"
              placeholder="https://docs.google.com/spreadsheets/d/…/gviz/tq?tqx=out:csv&sheet=UpSale%2FDownSell">
          </div>
        </div>
      </section>

      <!-- ── Автоматизация ─────────────────────────────────── -->
      <section class="st-section">
        <h2 class="st-section-title">Автоматизация</h2>
        <div class="st-grid">
          <div class="st-field">
            <label class="st-label" for="ai_auto_snapshot_hours">Авто-снимок метрик каждые N часов</label>
            <input class="st-input st-input-short" type="number" id="ai_auto_snapshot_hours" name="ai_auto_snapshot_hours"
              value="<?= esc($vals['ai_auto_snapshot_hours']) ?>" min="1" max="168" placeholder="24">
            <p class="st-hint">При открытии страницы AI-аналитики — если прошло больше N часов, снимок метрик сохранится автоматически.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="api_secret">API Secret (cron / Bearer-токен)</label>
            <input class="st-input st-input-secret" type="password" id="api_secret" name="api_secret"
              value="<?= esc($vals['api_secret']) ?>" placeholder="Длинный случайный токен…" autocomplete="off">
            <p class="st-hint">Используется для запуска cron без браузерной сессии: <code>Authorization: Bearer &lt;secret&gt;</code>.</p>
          </div>
        </div>
        <div class="st-grid">
          <div class="st-field">
            <label class="st-label" for="ai_alert_overdue_pct">Порог алерта: просрочка % от ДЗ</label>
            <input class="st-input st-input-short" type="number" id="ai_alert_overdue_pct" name="ai_alert_overdue_pct"
              value="<?= esc($vals['ai_alert_overdue_pct']) ?>" min="0" max="100" placeholder="40">
            <p class="st-hint">Env: <code>DASHBOARD_AI_ALERT_OVERDUE_PCT</code>. Пусто = алерт выключен.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="ai_alert_aging91_pct">Порог алерта: корзина 91+ % от ДЗ</label>
            <input class="st-input st-input-short" type="number" id="ai_alert_aging91_pct" name="ai_alert_aging91_pct"
              value="<?= esc($vals['ai_alert_aging91_pct']) ?>" min="0" max="100" placeholder="20">
            <p class="st-hint">Env: <code>DASHBOARD_AI_ALERT_AGING90_PCT</code>.</p>
          </div>
          <div class="st-field">
            <label class="st-label" for="ai_alert_churn_mrr">Порог алерта: Churn-риск MRR (₽)</label>
            <input class="st-input st-input-short" type="number" id="ai_alert_churn_mrr" name="ai_alert_churn_mrr"
              value="<?= esc($vals['ai_alert_churn_mrr']) ?>" min="0" placeholder="500000">
            <p class="st-hint">Env: <code>DASHBOARD_AI_ALERT_CHURN_MRR</code>.</p>
          </div>
        </div>
      </section>

      <!-- ── Авторизация ───────────────────────────────────── -->
      <section class="st-section">
        <h2 class="st-section-title">Авторизация</h2>
        <div class="st-grid">
          <div class="st-field">
            <label class="st-label" for="auth_username">Логин</label>
            <input class="st-input" type="text" id="auth_username" name="auth_username"
              value="<?= esc($vals['auth_username']) ?>" placeholder="admin" autocomplete="username">
          </div>
          <div class="st-field">
            <label class="st-label" for="auth_password">Пароль (plain-text)</label>
            <input class="st-input st-input-secret" type="password" id="auth_password" name="auth_password"
              value="<?= esc($vals['auth_password']) ?>" placeholder="••••••••" autocomplete="new-password">
            <p class="st-hint">Или задайте хеш через env <code>DASHBOARD_AUTH_PASSWORD_HASH</code> (рекомендуется).</p>
          </div>
        </div>
      </section>

      <div class="st-footer">
        <button type="submit" class="st-btn-save" id="st-btn-save">Сохранить настройки</button>
        <span class="st-save-status" id="st-save-status"></span>
      </div>
    </form>
  </div>

  <script src="assets/aq-theme-boot.js?v=1"></script>
  <script src="assets/settings.js?v=1" defer></script>
  <script src="assets/shared-nav.js?v=3" defer></script>
</body>
</html>

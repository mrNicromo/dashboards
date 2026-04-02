<?php
declare(strict_types=1);

/**
 * Cron-эндпоинт для автоматического AI-анализа без браузера.
 *
 * Аутентификация: Bearer-токен или X-Api-Key (DASHBOARD_API_SECRET).
 *
 * Пример cron (каждый день в 9:00):
 *   curl -s -X POST https://your-domain/ai_insights_cron_api.php \
 *        -H "Authorization: Bearer <api_secret>" \
 *        -H "Content-Type: application/json" \
 *        -d '{}' >> /var/log/aq-cron.log
 *
 * Переменные-пороги (env или config):
 *   DASHBOARD_AI_ALERT_OVERDUE_PCT  — % просрочки от общего ДЗ (по умолчанию: 0 = выкл.)
 *   DASHBOARD_AI_ALERT_AGING90_PCT  — % корзины 90+ от общего ДЗ (по умолчанию: 0 = выкл.)
 *   DASHBOARD_AI_ALERT_CHURN_MRR    — абс. сумма риска Churn MRR в рублях (по умолчанию: 0 = выкл.)
 */

set_time_limit(0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsSupport.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Аутентификация: только api_secret (cron без сессии) ───────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!dashboard_request_has_valid_api_secret()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Требуется DASHBOARD_API_SECRET (Bearer или X-Api-Key).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bodyIn = json_decode(file_get_contents('php://input') ?: '{}', true);
/** Пропустить вызов LLM — только refresh + снимок метрик */
$metricsOnly = is_array($bodyIn) && !empty($bodyIn['metricsOnly']);
$customQuestion = is_array($bodyIn) ? trim((string) ($bodyIn['customQuestion'] ?? '')) : '';

// ── Ключи ─────────────────────────────────────────────────────
$c = dashboard_config();
$geminiKey = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')));
$groqKey = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? '')));
$anthropicKey = trim((string) (dashboard_env('DASHBOARD_ANTHROPIC_API_KEY') ?: ($c['anthropic_api_key'] ?? '')));
$hasLlm = $geminiKey !== '' || $groqKey !== '' || $anthropicKey !== '';

if (!$metricsOnly && !$hasLlm) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Нет ключей LLM. Передайте metricsOnly:true для сохранения снимка без AI.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (trim((string) ($c['airtable_pat'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Задайте AIRTABLE_PAT.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Rate limit ─────────────────────────────────────────────────
$rl = AiInsightsSupport::checkRateLimit($dir, 'refresh', AiInsightsSupport::maxRefreshPerHour());
if ($rl !== null) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $rl], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$metricsOnly) {
    $rlLlm = AiInsightsSupport::checkRateLimit($dir, 'llm', AiInsightsSupport::maxLlmPerHour());
    if ($rlLlm !== null) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => $rlLlm], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Refresh кэшей ─────────────────────────────────────────────
$t0 = microtime(true);
try {
    AiInsightsContext::refreshCachesFromAirtable($c);
} catch (Throwable $e) {
    AiInsightsSupport::logLine('cron_refresh_fail', ['err' => $e->getMessage()]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => AiInsightsSupport::mapFetchError($e->getMessage())], JSON_UNESCAPED_UNICODE);
    exit;
}
$refreshMs = (int) round((microtime(true) - $t0) * 1000);

$baseId = (string) ($c['airtable_base_id'] ?? '');

// ── Проверка порогов алертов ──────────────────────────────────
$alerts = ai_cron_check_thresholds($dir, $baseId);

// ── metricsOnly — только снимок без AI ────────────────────────
if ($metricsOnly) {
    $metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
    AiInsightsHistory::appendMetricsOnly($metrics);
    $hist = AiInsightsHistory::load();
    $histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;
    AiInsightsSupport::logLine('cron_metrics_only', ['refreshMs' => $refreshMs, 'alerts' => count($alerts)]);
    echo json_encode([
        'ok' => true,
        'metricsOnly' => true,
        'refreshMs' => $refreshMs,
        'historyCount' => $histCount,
        'alerts' => $alerts,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Промпт ────────────────────────────────────────────────────
$ctxJson = AiInsightsContext::promptContext($dir, $baseId);

$alertBlock = '';
if ($alerts !== []) {
    $alertLines = array_map(static fn (string $a) => '- ' . $a, $alerts);
    $alertBlock = "\n\n---\n### АВТОМАТИЧЕСКИЕ АЛЕРТЫ (превышены пороги):\n" . implode("\n", $alertLines) . "\n\nОбрати особое внимание на эти метрики в анализе.";
}

$customBlock = $customQuestion !== ''
    ? "\n\n---\n### Дополнительный вопрос (приоритет):\n" . $customQuestion
    : '';

$system = <<<'PROMPT'
Ты — финансовый и операционный аналитик B2B SaaS.

Главное правило: анализируй **только то, что реально присутствует в JSON** ниже. Не дополняй снимок выдуманными метриками, не ссылайся на «типичные» показатели, не описывай разделы дашборда (ДЗ, churn, потери, недели), если для них в JSON нет непустых полей или они сведены к нулю/пустым массивам без содержательных чисел. Пустые или отсутствующие блоки **пропускай** или один раз напиши: «В снимке нет данных по …» — без заполнения фантазией.

Разрешённые источники выводов: поля JSON и (если есть текст ниже) блок «История сохранённых снимков».

Если в JSON есть несколько непустых разделов — согласуй выводы **только между теми, что заполнены**.

Структура (Markdown):
- «## Сводка по данным снимка»
- «## Общая картина»
- «## Зоны внимания»
- «## Приоритеты»
- «## Прогноз и риски»

Пиши по-русски. Формат: Markdown, без таблиц в pipe-синтаксисе.
PROMPT;

$historyBlock = AiInsightsHistory::buildTrendPromptSection(32);
$liveNote = "\n\n(Данные получены запросами к Airtable прямо перед этим запросом.)";
$user = "Ниже — единственный источник фактов для ответа.\n\nТекущий снимок (JSON):\n```json\n" . $ctxJson . "\n```\n\n---\n### История сохранённых снимков\n" . $historyBlock . $liveNote . $alertBlock . $customBlock;

// ── Вызов LLM ─────────────────────────────────────────────────
$tLlm = microtime(true);
$provider = null;
$llmModelId = '';
$gen = null;
$lastErr = '';

if ($geminiKey !== '') {
    $gen = ai_cron_gemini($geminiKey, $system, $user);
    if ($gen['ok']) {
        $provider = 'gemini';
        $llmModelId = (string) ($gen['modelId'] ?? 'gemini-2.0-flash');
    } else {
        $lastErr = (string) ($gen['error'] ?? '');
        $httpCode = (int) ($gen['httpCode'] ?? 0);
        if ($groqKey !== '' && ($httpCode === 429 || $httpCode === 401 || $httpCode === 403)) {
            $gen = ai_cron_groq($groqKey, $system, $user);
            if ($gen['ok']) {
                $provider = 'groq';
                $llmModelId = (string) ($gen['modelId'] ?? 'llama-3.3-70b-versatile');
            } else {
                $lastErr = (string) ($gen['error'] ?? $lastErr);
            }
        }
    }
}
if ($provider === null && $groqKey !== '' && $geminiKey === '') {
    $gen = ai_cron_groq($groqKey, $system, $user);
    if ($gen['ok']) {
        $provider = 'groq';
        $llmModelId = (string) ($gen['modelId'] ?? 'llama-3.3-70b-versatile');
    } else {
        $lastErr = (string) ($gen['error'] ?? '');
    }
}
if ($provider === null && $anthropicKey !== '') {
    $gen = ai_cron_claude($anthropicKey, $system, $user);
    if ($gen['ok']) {
        $provider = 'claude';
        $llmModelId = (string) ($gen['modelId'] ?? 'claude-sonnet-4-6');
    } else {
        $lastErr = (string) ($gen['error'] ?? $lastErr);
    }
}
$llmMs = (int) round((microtime(true) - $tLlm) * 1000);

if ($provider === null) {
    AiInsightsSupport::logLine('cron_llm_fail', ['err' => $lastErr]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $lastErr ?: 'Не удалось получить ответ от AI.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = (string) ($gen['text'] ?? '');
$metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
AiInsightsHistory::appendWithAnalysis($metrics, $text);
$hist = AiInsightsHistory::load();
$histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

AiInsightsSupport::logLine('cron_ok', [
    'refreshMs' => $refreshMs,
    'llmMs' => $llmMs,
    'provider' => $provider,
    'alerts' => count($alerts),
]);

echo json_encode([
    'ok' => true,
    'provider' => $provider,
    'llmModel' => $llmModelId,
    'refreshMs' => $refreshMs,
    'llmMs' => $llmMs,
    'historyCount' => $histCount,
    'alerts' => $alerts,
    'textPreview' => mb_substr($text, 0, 400) . (mb_strlen($text) > 400 ? '…' : ''),
], JSON_UNESCAPED_UNICODE);

// ── Вспомогательные функции ───────────────────────────────────

/**
 * @return list<string> Список сработавших алертов.
 */
function ai_cron_check_thresholds(string $dir, string $baseId): array
{
    $alerts = [];
    $metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);

    $overduePctThreshold = (float) dashboard_env('DASHBOARD_AI_ALERT_OVERDUE_PCT');
    if ($overduePctThreshold > 0) {
        $total = (float) ($metrics['dzTotal'] ?? 0);
        $overdue = (float) ($metrics['dzOverdue'] ?? 0);
        if ($total > 0) {
            $pct = $overdue / $total * 100;
            if ($pct >= $overduePctThreshold) {
                $alerts[] = sprintf(
                    'Просрочка ДЗ %.1f%% ≥ порога %.0f%% (сумма: %s ₽)',
                    $pct,
                    $overduePctThreshold,
                    number_format($overdue, 0, '.', ' ')
                );
            }
        }
    }

    $aging90PctThreshold = (float) dashboard_env('DASHBOARD_AI_ALERT_AGING90_PCT');
    if ($aging90PctThreshold > 0) {
        $total = (float) ($metrics['dzTotal'] ?? 0);
        $aging90 = (float) ($metrics['aging90p'] ?? 0);
        if ($total > 0) {
            $pct = $aging90 / $total * 100;
            if ($pct >= $aging90PctThreshold) {
                $alerts[] = sprintf(
                    'Корзина 90+ составляет %.1f%% ДЗ ≥ порога %.0f%% (сумма: %s ₽)',
                    $pct,
                    $aging90PctThreshold,
                    number_format($aging90, 0, '.', ' ')
                );
            }
        }
    }

    $churnMrrThreshold = (float) dashboard_env('DASHBOARD_AI_ALERT_CHURN_MRR');
    if ($churnMrrThreshold > 0) {
        $churnRisk = (float) ($metrics['churnRisk'] ?? 0);
        if ($churnRisk >= $churnMrrThreshold) {
            $alerts[] = sprintf(
                'Churn-риск MRR %s ₽ ≥ порога %s ₽',
                number_format($churnRisk, 0, '.', ' '),
                number_format($churnMrrThreshold, 0, '.', ' ')
            );
        }
    }

    return $alerts;
}

function ai_cron_gemini(string $apiKey, string $system, string $user): array
{
    $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($apiKey);
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['temperature' => 0.35, 'maxOutputTokens' => 8192],
    ];
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'curl: нет ответа', 'httpCode' => $code];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Некорректный JSON (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if (!empty($j['error']['message'])) {
        return ['ok' => false, 'error' => 'Gemini: ' . (string) $j['error']['message'], 'httpCode' => $code];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'Gemini HTTP ' . $code, 'httpCode' => $code];
    }
    $out = '';
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) {
        $out .= (string) ($p['text'] ?? '');
    }
    if ($out === '') {
        return ['ok' => false, 'error' => 'Gemini: пустой ответ'];
    }
    return ['ok' => true, 'text' => $out, 'modelId' => $model];
}

function ai_cron_groq(string $apiKey, string $system, string $user): array
{
    $model = 'llama-3.3-70b-versatile';
    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => 8192,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'curl: нет ответа'];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Groq: некорректный JSON (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'Groq: ' . ($j['error']['message'] ?? 'HTTP ' . $code), 'httpCode' => $code];
    }
    $out = (string) ($j['choices'][0]['message']['content'] ?? '');
    if ($out === '') {
        return ['ok' => false, 'error' => 'Groq: пустой ответ'];
    }
    return ['ok' => true, 'text' => $out, 'modelId' => $model];
}

function ai_cron_claude(string $apiKey, string $system, string $user): array
{
    $model = 'claude-sonnet-4-6';
    $payload = [
        'model' => $model,
        'max_tokens' => 8192,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'curl: нет ответа'];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Claude: некорректный JSON (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'Claude: ' . ($j['error']['message'] ?? 'HTTP ' . $code), 'httpCode' => $code];
    }
    $out = '';
    foreach ($j['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $out .= (string) ($block['text'] ?? '');
        }
    }
    if ($out === '') {
        return ['ok' => false, 'error' => 'Claude: пустой ответ'];
    }
    return ['ok' => true, 'text' => $out, 'modelId' => $model];
}

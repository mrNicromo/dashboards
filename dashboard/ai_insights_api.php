<?php
declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$rawIn = file_get_contents('php://input') ?: '{}';
$bodyIn = json_decode($rawIn, true);
$forceRefresh = is_array($bodyIn) && !empty($bodyIn['forceRefresh']);

$c = dashboard_config();
$geminiKey = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')));
$groqKey = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? '')));

if ($geminiKey === '' && $groqKey === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Нужен хотя бы один ключ: DASHBOARD_GEMINI_API_KEY (Gemini) и/или DASHBOARD_GROQ_API_KEY (резерв Groq). См. config.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (trim((string) ($c['airtable_pat'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Задайте AIRTABLE_PAT — перед анализом подтягиваются свежие данные из Airtable.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dzFile = $dir . '/dz-data-default.json';
$refreshTtlSec = 180;
$skipAirtableRefresh = false;
if (!$forceRefresh && is_file($dzFile)) {
    $ageSec = time() - (int) filemtime($dzFile);
    if ($ageSec >= 0 && $ageSec < $refreshTtlSec) {
        $skipAirtableRefresh = true;
    }
}

if (!$skipAirtableRefresh) {
    try {
        AiInsightsContext::refreshCachesFromAirtable($c);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'Не удалось загрузить данные из Airtable / отчётов: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$baseId = (string) ($c['airtable_base_id'] ?? '');
$ctxJson = AiInsightsContext::promptContext($dir, $baseId);

$system = <<<'PROMPT'
Ты — финансовый и операционный аналитик B2B SaaS. JSON — единый снимок по всем основным экранам дашборда; при полной синхронизации он соответствует актуальным данным из Airtable и связанных отчётов. dz — дебиторка и корзины aging, менеджеры, статусы, топ юрлица и строки счетов; churn — риск MRR по сегментам/вертикалям/продуктам/CSM, клиенты, прогнозы; factLosses — фактические потери YTD (churn + downsell) с разбивкой по месяцам, продуктам, CSM, причинам, примерам строк; crossDashboard — недельная динамика ДЗ, снимок MRR, доп. корзины aging, топ клиентов по недельному изменению долга. Опирайся на все непустые разделы и согласуй выводы между ними (ДЗ ↔ churn ↔ потери ↔ тренды).

Если ниже есть блок «История сохранённых снимков», используй его для оценки тренда (рост/падение ключевых сумм). В конце ответа добавь раздел «## Прогноз и риски» (несколько абзацев) с осторожным сценарием на 1–3 месяца (явно укажи допущения и неопределённость; не выдавай точные числа без данных).

Объём ответа: развёрнутый аналитический текст (не короткая сводка). Минимум ~900 слов, если данных в JSON достаточно; если данных мало — всё равно дай максимум полезных выводов из того, что есть и укажи явные пробелы.

Структура ответа (Markdown, заголовки ## и ###):
1. «## Сводка KPI» — ключевые числа из dz.kpi, churn (totalRisk, сегменты), factLosses (YTD, по месяцам если есть), crossDashboard если непусто. Перечисли цифры явно.
2. «## Общая картина» — 4–6 предложений.
3. «## Зоны внимания» — 6–12 пунктов (по важности): для каждого — заголовок, цифры из JSON, почему критично, 3–5 действий (роль, срок).
4. «## Связки и противоречия» — где ДЗ, churn и потери дополняют или расходятся.
5. «## Приоритеты на 2 недели» — нумерованный список из 5–7 шагов.
6. «## Прогноз и риски» — как выше.

Пиши по-русски. Не выдумывай цифры: опирайся только на поля JSON и историю снимков. Если блока нет — напиши явно.
Формат: Markdown, без таблиц в pipe-синтаксисе.
PROMPT;

$historyBlock = AiInsightsHistory::buildTrendPromptSection(32);
$cacheNote = '';
if ($skipAirtableRefresh && is_file($dzFile)) {
    $cacheNote = "\n\n(Служебно: полная синхронизация с Airtable пропущена — использован недавний серверный кэш, возраст снимка ДЗ: ~" . (string) max(0, time() - (int) filemtime($dzFile)) . " с.)";
}
$user = "Текущий снимок (JSON):\n```json\n" . $ctxJson . "\n```\n\n---\n### История сохранённых снимков (для тренда и прогноза)\n" . $historyBlock . $cacheNote;

$gen = null;
$provider = null;
$lastErr = '';

if ($geminiKey !== '') {
    $gen = ai_insights_gemini_generate($geminiKey, $system, $user);
    if ($gen['ok']) {
        $provider = 'gemini';
    } else {
        $lastErr = (string) ($gen['error'] ?? '');
        $httpCode = (int) ($gen['httpCode'] ?? 0);
        if ($groqKey !== '' && ai_insights_should_fallback_to_groq($lastErr, $httpCode)) {
            $gen = ai_insights_groq_generate($groqKey, $system, $user);
            if ($gen['ok']) {
                $provider = 'groq';
            } else {
                $lastErr = (string) ($gen['error'] ?? $lastErr);
            }
        }
    }
}

if ($provider === null && $groqKey !== '' && ($geminiKey === '' || !($gen['ok'] ?? false))) {
    if ($geminiKey === '') {
        $gen = ai_insights_groq_generate($groqKey, $system, $user);
        if ($gen['ok']) {
            $provider = 'groq';
        } else {
            $lastErr = (string) ($gen['error'] ?? '');
        }
    }
}

if ($provider === null || !($gen['ok'] ?? false)) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $lastErr !== '' ? $lastErr : ($gen['error'] ?? 'Не удалось получить ответ от AI.'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = $gen['text'];

$metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
AiInsightsHistory::appendWithAnalysis($metrics, $text);
$hist = AiInsightsHistory::load();
$histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

$chartsOut = AiInsightsContext::chartPayload($dir, $baseId);
echo json_encode([
    'ok' => true,
    'text' => $text,
    'provider' => $provider,
    'dataRefreshedFromAirtable' => !$skipAirtableRefresh,
    'usedRecentCache' => $skipAirtableRefresh,
    'historyCount' => $histCount,
    'historyChart' => AiInsightsHistory::chartSeries(56),
    'charts' => $chartsOut,
    'chartHints' => AiInsightsContext::chartHintsFromCharts($chartsOut),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * Резерв при лимите/квоте Gemini, а также при неверном ключе / отказе доступа (тогда пробуем Groq).
 */
function ai_insights_should_fallback_to_groq(string $errorMessage, int $httpCode): bool
{
    if (in_array($httpCode, [401, 403, 429], true)) {
        return true;
    }
    $e = mb_strtolower($errorMessage);
    $needles = [
        'resource_exhausted', 'quota', 'rate limit', 'too many requests',
        'billing', 'limit exceeded', 'exceeded your', 'tokens', 'capacity',
        '429', '503', 'overloaded', 'try again later',
        'api key not valid', 'invalid api key', 'api_key_invalid', 'invalid key',
        'permission denied', 'request had invalid authentication',
    ];
    foreach ($needles as $n) {
        if (str_contains($e, $n)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{ok:bool, text?: string, error?: string, httpCode?: int}
 */
function ai_insights_gemini_generate(string $apiKey, string $systemInstruction, string $userText): array
{
    $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $systemInstruction]],
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $userText]]],
        ],
        'generationConfig' => [
            'temperature' => 0.35,
            'maxOutputTokens' => 8192,
        ],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed', 'httpCode' => 0];
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
        return ['ok' => false, 'error' => 'Сеть: запрос к Google AI не выполнен', 'httpCode' => $code];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Некорректный ответ API (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if (!empty($j['error']['message'])) {
        return ['ok' => false, 'error' => 'Google AI: ' . (string) $j['error']['message'], 'httpCode' => $code];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'httpCode' => $code];
    }
    $parts = $j['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        $fr = $j['candidates'][0]['finishReason'] ?? '';
        return ['ok' => false, 'error' => 'Пустой ответ модели' . ($fr !== '' ? ' (' . $fr . ')' : ''), 'httpCode' => $code];
    }
    $out = '';
    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $out .= (string) $p['text'];
        }
    }
    if ($out === '') {
        return ['ok' => false, 'error' => 'Модель вернула пустой текст', 'httpCode' => $code];
    }
    return ['ok' => true, 'text' => $out, 'httpCode' => $code];
}

/**
 * Groq — OpenAI-совместимый API (ключ gsk_…).
 *
 * @return array{ok:bool, text?: string, error?: string, httpCode?: int}
 */
function ai_insights_groq_generate(string $apiKey, string $systemInstruction, string $userText): array
{
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $model = 'llama-3.3-70b-versatile';

    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => 8192,
        'messages' => [
            ['role' => 'system', 'content' => $systemInstruction],
            ['role' => 'user', 'content' => $userText],
        ],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed', 'httpCode' => 0];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
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
        return ['ok' => false, 'error' => 'Сеть: запрос к Groq не выполнен', 'httpCode' => $code];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Некорректный ответ Groq (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if ($code >= 400) {
        $msg = isset($j['error']['message']) ? (string) $j['error']['message'] : 'HTTP ' . $code;
        return ['ok' => false, 'error' => 'Groq: ' . $msg, 'httpCode' => $code];
    }
    $out = (string) ($j['choices'][0]['message']['content'] ?? '');
    if ($out === '') {
        return ['ok' => false, 'error' => 'Groq: пустой ответ', 'httpCode' => $code];
    }
    return ['ok' => true, 'text' => $out, 'httpCode' => $code];
}

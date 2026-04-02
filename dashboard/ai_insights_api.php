<?php
declare(strict_types=1);

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

$c = dashboard_config();
$key = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')));
if ($key === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Не задан ключ Google AI. Укажите переменную окружения DASHBOARD_GEMINI_API_KEY или поле gemini_api_key в config.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseId = (string) ($c['airtable_base_id'] ?? '');
$ctxJson = AiInsightsContext::promptContext($dir, $baseId);

$system = <<<'PROMPT'
Ты — финансовый и операционный аналитик B2B SaaS. Тебе передаётся JSON со снимком метрик дашборда AnyQuery: дебиторская задолженность (счета из Airtable), риски churn по клиентам, агрегаты по сегментам/вертикалям/CSM, при наличии — фактические потери выручки (churn + downsell) YTD.

Если ниже есть блок «История сохранённых снимков», используй его для оценки тренда (рост/падение ключевых сумм). В конце ответа добавь короткий раздел «## Прогноз и риски» с осторожным сценарием на 1–3 месяца (явно укажи допущения и неопределённость; не выдавай точные числа без данных).

Задача:
1. Кратко (2–4 предложения) опиши общую картину.
2. Выдели 4–8 проблемных зон с наибольшим бизнес-эффектом (связывай с цифрами из данных).
3. Для каждой зоны: почему это важно, 2–4 конкретных действия (владелец/роль, срок «на этой неделе» / «в месяц»).
4. В конце основного раздела — 3 приоритета на ближайшие 2 недели.

Пиши по-русски, деловым но понятным языком. Не выдумывай цифры: опирайся только на поля JSON и историю снимков. Если какого-то блока нет — честно скажи, что данных в снимке нет.
Формат ответа: Markdown с заголовками ## и ###, списками, без таблиц в pipe-синтаксисе (таблицы не нужны).
PROMPT;

$historyBlock = AiInsightsHistory::buildTrendPromptSection(32);
$user = "Текущий снимок (JSON):\n```json\n" . $ctxJson . "\n```\n\n---\n### История сохранённых снимков (для тренда и прогноза)\n" . $historyBlock;

$gen = ai_insights_gemini_generate($key, $system, $user);
if (!$gen['ok']) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $gen['error'] ?? 'Не удалось получить ответ от Google AI. Проверьте ключ и квоту API.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$text = $gen['text'];

$metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
AiInsightsHistory::appendWithAnalysis($metrics, $text);
$hist = AiInsightsHistory::load();
$histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

echo json_encode([
    'ok' => true,
    'text' => $text,
    'historyCount' => $histCount,
    'historyChart' => AiInsightsHistory::chartSeries(56),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * @return array{ok:bool, text?: string, error?: string}
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
        return ['ok' => false, 'error' => 'Сеть: запрос к Google AI не выполнен'];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Некорректный ответ API (HTTP ' . $code . ')'];
    }
    if (!empty($j['error']['message'])) {
        return ['ok' => false, 'error' => 'Google AI: ' . (string) $j['error']['message']];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $code];
    }
    $parts = $j['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        $fr = $j['candidates'][0]['finishReason'] ?? '';
        return ['ok' => false, 'error' => 'Пустой ответ модели' . ($fr !== '' ? ' (' . $fr . ')' : '')];
    }
    $out = '';
    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $out .= (string) $p['text'];
        }
    }
    if ($out === '') {
        return ['ok' => false, 'error' => 'Модель вернула пустой текст'];
    }
    return ['ok' => true, 'text' => $out];
}

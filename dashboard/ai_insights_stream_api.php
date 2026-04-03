<?php
declare(strict_types=1);

/**
 * SSE-стриминг ответа от LLM (Gemini / Groq / Claude).
 * Клиент делает fetch() с POST и читает ReadableStream.
 *
 * Формат событий:
 *   event: status   data: {"msg":"..."}
 *   event: text     data: {"t":"chunk"}
 *   event: done     data: {"provider":"...","llmModel":"...","llmMs":123,"historyCount":5,"numberWarnings":[]}
 *   event: error    data: {"error":"..."}
 */

set_time_limit(0);
ignore_user_abort(false);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsSupport.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

// ── SSE-хелпер ────────────────────────────────────────────────
function sse_send(string $event, mixed $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function sse_error(string $message): never
{
    sse_send('error', ['error' => $message]);
    exit;
}

// ── Заголовки SSE ─────────────────────────────────────────────
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// ── Метод ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sse_error('Только POST');
}

csrf_check();

$bodyIn = json_decode(file_get_contents('php://input') ?: '{}', true);
$customQuestion = is_array($bodyIn) ? trim((string) ($bodyIn['customQuestion'] ?? '')) : '';
$skipRefresh = is_array($bodyIn) && !empty($bodyIn['skipRefresh']);

// ── Ключи ─────────────────────────────────────────────────────
$c = dashboard_config();
$geminiKey = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')));
$groqKey = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? '')));
$anthropicKey = trim((string) (dashboard_env('DASHBOARD_ANTHROPIC_API_KEY') ?: ($c['anthropic_api_key'] ?? '')));

if ($geminiKey === '' && $groqKey === '' && $anthropicKey === '') {
    sse_error('Нужен хотя бы один ключ: DASHBOARD_GEMINI_API_KEY, DASHBOARD_GROQ_API_KEY или DASHBOARD_ANTHROPIC_API_KEY.');
}

if (trim((string) ($c['airtable_pat'] ?? '')) === '') {
    sse_error('Задайте AIRTABLE_PAT — нужны данные из Airtable.');
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    sse_error('Каталог cache недоступен.');
}

// ── Rate limit ─────────────────────────────────────────────────
$rlLlm = AiInsightsSupport::checkRateLimit($dir, 'llm', AiInsightsSupport::maxLlmPerHour());
if ($rlLlm !== null) {
    sse_error($rlLlm);
}

// ── Refresh кэшей (если не пропускаем) ────────────────────────
if (!$skipRefresh) {
    $rl = AiInsightsSupport::checkRateLimit($dir, 'refresh', AiInsightsSupport::maxRefreshPerHour());
    if ($rl !== null) {
        sse_error($rl);
    }
    sse_send('status', ['msg' => '1/2 Синхронизация с Airtable…']);
    try {
        AiInsightsContext::refreshCachesFromAirtable($c);
    } catch (Throwable $e) {
        sse_send('error', ['error' => AiInsightsSupport::mapFetchError($e->getMessage()), 'rawError' => $e->getMessage()]);
        exit;
    }
    sse_send('status', ['msg' => '2/2 Запрос к модели…']);
} else {
    sse_send('status', ['msg' => 'Запрос к модели…']);
}

// ── Промпт ────────────────────────────────────────────────────
$baseId = (string) ($c['airtable_base_id'] ?? '');
// Полный контекст для Gemini/Claude (большой лимит), урезанный для Groq (free-tier: ~6k TPM, hard limit на тело запроса)
$ctxJson     = AiInsightsContext::promptContext($dir, $baseId);          // ~280 KB
$ctxJsonGroq = AiInsightsContext::promptContext($dir, $baseId, 18000);   // ~18 KB ≈ 4.5k tokens

$system = <<<'PROMPT'
Ты — аналитик дебиторки и оттока B2B SaaS. Работаешь строго по JSON-снимку.

Правила:
- Используй ТОЛЬКО данные из JSON. Не выдумывай числа, не дополняй «типичными» метриками.
- Пустые/нулевые блоки пропускай молча (не пиши «нет данных по X» для каждого).
- Все суммы, проценты, имена клиентов — только из источника.
- При наличии истории снимков — строй тренд; при её отсутствии — не упоминай.

Структура ответа (Markdown; включай раздел только если есть факты):
## Ключевые показатели — KPI, суммы, доли из JSON
## Зоны риска — конкретные клиенты, сроки, суммы просрочки
## Приоритеты — 3–5 чётких действий с обоснованием из данных
## Тренд — только при наличии истории

Язык: русский. Формат: Markdown без pipe-таблиц.
PROMPT;

$historyBlock = AiInsightsHistory::buildTrendPromptSection(12);
$customBlock  = $customQuestion !== '' ? "\n\nВопрос: {$customQuestion}" : '';
$user     = ($customBlock !== '' ? "Приоритетный вопрос: {$customQuestion}\n\n" : '')
          . "JSON-снимок:\n```json\n{$ctxJson}\n```"
          . ($historyBlock !== '' ? "\n\nИстория (тренд):\n{$historyBlock}" : '')
          . $customBlock;
$userGroq = ($customBlock !== '' ? "Приоритетный вопрос: {$customQuestion}\n\n" : '')
          . "JSON-снимок:\n```json\n{$ctxJsonGroq}\n```"
          . "\n\nИстория (тренд):\n" . AiInsightsHistory::buildTrendPromptSection(5)
          . $customBlock;

// ── Стриминг от провайдера ────────────────────────────────────
$tLlm0 = microtime(true);
$provider = null;
$llmModelId = '';
$fullText = '';
$lastErr = '';

/** @param callable(string):void $onChunk */
function ai_stream_gemini(string $apiKey, string $system, string $user, callable $onChunk): array
{
    $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model
        . ':streamGenerateContent?key=' . rawurlencode($apiKey) . '&alt=sse';
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['temperature' => 0.35, 'maxOutputTokens' => 8192],
    ];
    $buffer = '';
    $fullText = '';
    $httpCode = 0;
    $curlErr = '';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buffer, &$fullText, $onChunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    continue;
                }
                $j = json_decode($data, true);
                if (!is_array($j)) {
                    continue;
                }
                $t = (string) ($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
                if ($t !== '') {
                    $fullText .= $t;
                    $onChunk($t);
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'Gemini curl: ' . $curlErr];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => 'Gemini HTTP ' . $httpCode, 'httpCode' => $httpCode];
    }
    if ($fullText === '') {
        return ['ok' => false, 'error' => 'Gemini: пустой ответ'];
    }
    return ['ok' => true, 'text' => $fullText, 'modelId' => $model];
}

function ai_stream_groq(string $apiKey, string $system, string $user, callable $onChunk): array
{
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $model = 'llama-3.3-70b-versatile';
    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => 2048,  // free-tier: 12k TPM; держим output мал чтобы влезть в лимит
        'stream' => true,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ];
    $buffer = '';
    $fullText = '';
    $httpCode = 0;
    $curlErr = '';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buffer, &$fullText, $onChunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    continue;
                }
                $j = json_decode($data, true);
                if (!is_array($j)) {
                    continue;
                }
                $t = (string) ($j['choices'][0]['delta']['content'] ?? '');
                if ($t !== '') {
                    $fullText .= $t;
                    $onChunk($t);
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'Groq curl: ' . $curlErr];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => 'Groq HTTP ' . $httpCode, 'httpCode' => $httpCode];
    }
    if ($fullText === '') {
        return ['ok' => false, 'error' => 'Groq: пустой ответ'];
    }
    return ['ok' => true, 'text' => $fullText, 'modelId' => $model];
}

function ai_stream_claude(string $apiKey, string $system, string $user, callable $onChunk): array
{
    $url = 'https://api.anthropic.com/v1/messages';
    $model = 'claude-sonnet-4-6';
    $payload = [
        'model' => $model,
        'max_tokens' => 8192,
        'stream' => true,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ];
    $buffer = '';
    $fullText = '';
    $currentEvent = '';
    $httpCode = 0;
    $curlErr = '';
    $ch = curl_init($url);
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
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buffer, &$fullText, &$currentEvent, $onChunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (str_starts_with($line, 'event: ')) {
                    $currentEvent = trim(substr($line, 7));
                } elseif (str_starts_with($line, 'data: ') && $currentEvent === 'content_block_delta') {
                    $j = json_decode(substr($line, 6), true);
                    if (!is_array($j)) {
                        continue;
                    }
                    $t = (string) ($j['delta']['text'] ?? '');
                    if ($t !== '') {
                        $fullText .= $t;
                        $onChunk($t);
                    }
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'Claude curl: ' . $curlErr];
    }
    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => 'Claude HTTP ' . $httpCode, 'httpCode' => $httpCode];
    }
    if ($fullText === '') {
        return ['ok' => false, 'error' => 'Claude: пустой ответ'];
    }
    return ['ok' => true, 'text' => $fullText, 'modelId' => $model];
}

// ── Выбор провайдера и стриминг ──────────────────────────────
$onChunk = static function (string $t) use (&$fullText): void {
    $fullText .= $t;
    sse_send('text', ['t' => $t]);
};

$gen = null;
if ($geminiKey !== '') {
    $gen = ai_stream_gemini($geminiKey, $system, $user, $onChunk);
    if ($gen['ok']) {
        $provider = 'gemini';
        $llmModelId = $gen['modelId'] ?? 'gemini-2.0-flash';
    } else {
        $lastErr = (string) ($gen['error'] ?? '');
        // Groq — фолбэк при любой ошибке Gemini (лимит, авторизация, недоступность)
        if ($groqKey !== '') {
            $gen = ai_stream_groq($groqKey, $system, $userGroq, $onChunk);
            if ($gen['ok']) {
                $provider = 'groq';
                $llmModelId = $gen['modelId'] ?? 'llama-3.3-70b-versatile';
            } else {
                $lastErr = (string) ($gen['error'] ?? $lastErr);
            }
        }
    }
}

if ($provider === null && $groqKey !== '' && $geminiKey === '') {
    $gen = ai_stream_groq($groqKey, $system, $userGroq, $onChunk);
    if ($gen['ok']) {
        $provider = 'groq';
        $llmModelId = $gen['modelId'] ?? 'llama-3.3-70b-versatile';
    } else {
        $lastErr = (string) ($gen['error'] ?? '');
    }
}

if ($provider === null && $anthropicKey !== '') {
    $gen = ai_stream_claude($anthropicKey, $system, $user, $onChunk);
    if ($gen['ok']) {
        $provider = 'claude';
        $llmModelId = $gen['modelId'] ?? 'claude-sonnet-4-6';
    } else {
        $lastErr = (string) ($gen['error'] ?? $lastErr);
    }
}

$llmMs = (int) round((microtime(true) - $tLlm0) * 1000);

if ($provider === null) {
    sse_error($lastErr !== '' ? $lastErr : 'Не удалось получить ответ от AI.');
}

// ── Сохраняем в историю ──────────────────────────────────────
$metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
AiInsightsHistory::appendWithAnalysis($metrics, $fullText);
$hist = AiInsightsHistory::load();
$histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

$numberWarnings = AiInsightsSupport::verifyNumbersAgainstJson($fullText, $ctxJson);

AiInsightsSupport::logLine('stream_ok', [
    'llmMs' => $llmMs,
    'provider' => $provider,
    'ctxBytes' => strlen($ctxJson),
    'skipRefresh' => $skipRefresh,
]);

// ── Финальное событие ─────────────────────────────────────────
sse_send('done', [
    'provider' => $provider,
    'llmModel' => $llmModelId,
    'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
    'llmMs' => $llmMs,
    'historyCount' => $histCount,
    'historyChart' => AiInsightsHistory::chartSeries(56),
    'charts' => AiInsightsContext::chartPayload($dir, $baseId),
    'chartHints' => AiInsightsContext::chartHintsFromCharts(AiInsightsContext::chartPayload($dir, $baseId)),
    'numberWarnings' => $numberWarnings,
    'customQuestion' => $customQuestion,
]);

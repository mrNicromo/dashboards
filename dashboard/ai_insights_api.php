<?php
declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsSupport.php';
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

$bodyIn = json_decode(file_get_contents('php://input') ?: '{}', true);
$skipRefresh = is_array($bodyIn) && !empty($bodyIn['skipRefresh']);
$customQuestion = is_array($bodyIn) ? trim((string) ($bodyIn['customQuestion'] ?? '')) : '';

$c = dashboard_config();
$geminiKey = trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')));
$groqKey = trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? '')));
$anthropicKey = trim((string) (dashboard_env('DASHBOARD_ANTHROPIC_API_KEY') ?: ($c['anthropic_api_key'] ?? '')));

if ($geminiKey === '' && $groqKey === '' && $anthropicKey === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Нужен хотя бы один ключ: DASHBOARD_GEMINI_API_KEY (Gemini), DASHBOARD_GROQ_API_KEY (Groq) или DASHBOARD_ANTHROPIC_API_KEY (Claude). См. config.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (trim((string) ($c['airtable_pat'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Задайте AIRTABLE_PAT — для анализа нужны данные из Airtable.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lock = AiInsightsSupport::tryAcquireLock($dir);
if ($lock === null) {
    http_response_code(423);
    echo json_encode(['ok' => false, 'error' => 'Уже выполняется другая операция (синхронизация или анализ). Подождите.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($lock === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Не удалось создать блокировку.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$refreshMs = 0;
$didRefresh = false;

try {
    if (!$skipRefresh) {
        $rl = AiInsightsSupport::checkRateLimit($dir, 'refresh', AiInsightsSupport::maxRefreshPerHour());
        if ($rl !== null) {
            AiInsightsSupport::releaseLock();
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => $rl, 'promptVersion' => AiInsightsSupport::PROMPT_VERSION], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $pipe = AiInsightsSupport::executeRefreshPipeline($c);
            $refreshMs = $pipe['refreshMs'];
            $didRefresh = true;
        } catch (Throwable $e) {
            AiInsightsSupport::releaseLock();
            AiInsightsSupport::logLine('refresh_fail', ['err' => $e->getMessage(), 'where' => 'ai_insights_api']);
            http_response_code(502);
            $baseId = trim((string) ($c['airtable_base_id'] ?? ''));
            $errMeta = AiInsightsSupport::classifyFetchError($e->getMessage(), $baseId);
            echo json_encode([
                'ok' => false,
                'error' => $errMeta['message'],
                'errorMeta' => $errMeta,
                'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $rlLlm = AiInsightsSupport::checkRateLimit($dir, 'llm', AiInsightsSupport::maxLlmPerHour());
    if ($rlLlm !== null) {
        AiInsightsSupport::releaseLock();
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => $rlLlm, 'promptVersion' => AiInsightsSupport::PROMPT_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $baseId = (string) ($c['airtable_base_id'] ?? '');
    $ctxJson = AiInsightsContext::promptContext($dir, $baseId);
    $ctxBytes = strlen($ctxJson);

    // Groq free tier: 12 000 TPM; системный промпт ~1850 токенов → контекст ≤16 000 байт (~4000 т) + history + 2048 вывода ≈ 11 000 т
    $groqCtxJson = AiInsightsContext::promptContext($dir, $baseId, 16000);
    $groqHistoryBlock = AiInsightsHistory::buildTrendPromptSection(8);

    $system = <<<'PROMPT'
Ты — сильный операционный аналитик и руководитель revenue operations в B2B SaaS. Твоя задача — не просто описать цифры, а превратить снимок данных в понятный и применимый план действий для команды.

Главное правило: используй **только факты из JSON** и, если он непустой, из блока «История сохранённых снимков». Не выдумывай метрики, клиентов, менеджеров, причины, тренды или прогнозы. Если какого-то блока данных нет, он пустой или в нём нет содержательных чисел — пропусти его или один раз прямо скажи, что данных по нему нет.

Не пиши абстрактные советы вроде «усилить контроль», «проработать клиентов», «обратить внимание» без конкретики. Каждый важный вывод должен быть привязан к числам, менеджерам, сегментам, клиентам или корзинам просрочки, если они есть в JSON.

Если в JSON есть несколько заполненных блоков (например, dz, churn, factLosses, crossDashboard) — согласуй выводы только между этими блоками. Если содержательно заполнен только один блок — строй ответ только на нём.

Если есть дополнительный вопрос от пользователя — начни ответ с раздела `## Ответ на дополнительный вопрос` и ответь на него прямо, опираясь только на JSON.

Главная цель ответа:
- быстро показать, что происходит сейчас;
- назвать главные проблемы с цифрами;
- дать конкретный пошаговый план;
- при наличии данных по менеджерам обязательно выделить блок по менеджерам.

Требования к действиям:
- действия должны быть конкретными и исполнимыми;
- по возможности указывай приоритет: высокий, средний или низкий;
- по возможности указывай владельца действия: менеджер, CSM, финансы, руководитель;
- каждое действие должно быть связано с проблемой из данных;
- если уместно, называй клиентов, суммы, сегменты, корзины просрочки и менеджеров;
- не используй pipe-таблицы.

Если есть данные по менеджерам (`dz.byManagerFull`, `charts.dzByManager`, `churn.byCsm` или похожие блоки), обязательно включи раздел `## По менеджерам`. Внутри сделай подзаголовки `### <Имя менеджера>` только для тех менеджеров, по которым реально есть факты. Для каждого менеджера покажи:
- масштаб проблемы;
- какие клиенты или суммы формируют риск;
- 1–3 конкретных шага, что делать дальше.

Структура ответа: Markdown с заголовками `##` и `###`. Включай только те разделы, для которых есть факты.

Обязательный каркас:
- `## Краткий вывод`
- `## Что происходит сейчас`
- `## Главные проблемы`
- `## План действий на 7 дней`
- `## По менеджерам` — только если в данных есть содержательная разбивка по менеджерам
- `## Что проверить в первую очередь`
- `## Ограничения данных`

Наполнение разделов:
- В `## Краткий вывод` дай 2–4 коротких тезиса с ключевыми цифрами.
- В `## Что происходит сейчас` опиши картину строго по данным снимка.
- В `## Главные проблемы` перечисли риски в порядке важности и рядом приводи цифры.
- В `## План действий на 7 дней` дай конкретные шаги списком. Формат пункта: что сделать, зачем, с кем/для кого, на каком факте это основано.
- В `## По менеджерам` не повторяй общий текст; дай адресные действия по каждому менеджеру.
- В `## Что проверить в первую очередь` перечисли вопросы, которые стоит быстро уточнить в данных или у команды, если без них нельзя уверенно принять решение.
- В `## Ограничения данных` коротко укажи, чего не хватает в снимке и как это ограничивает выводы.

Пиши по-русски. Стиль — деловой, плотный, конкретный, без воды. Лучше короткий, но точный план, чем длинный общий текст.
PROMPT;

    $historyBlock = AiInsightsHistory::buildTrendPromptSection(32);
    $liveNote = "\n\n(Служебно: этот JSON собран **сразу перед этим запросом** — сервер вызвал Airtable API и связанные отчёты (Sheets для потерь и т.д.), затем сформировал снимок. Это не «произвольное чтение старого кэша без запросов».)";
    $customBlock = $customQuestion !== ''
        ? "\n\n---\n### Дополнительный вопрос (приоритет — ответь на него явно в начале):\n" . $customQuestion
        : '';
    $user = "Ниже — единственный источник фактов для ответа. Игнорируй общие знания о бизнесе, если они не подтверждены этим JSON.\n\nТекущий снимок (JSON):\n```json\n" . $ctxJson . "\n```\n\n---\n### История сохранённых снимков (учитывай только если здесь есть строки/числа; иначе не строй тренд)\n" . $historyBlock . $liveNote . $customBlock;
    $groqUser = "Ниже — единственный источник фактов для ответа. Игнорируй общие знания о бизнесе, если они не подтверждены этим JSON.\n\nТекущий снимок (JSON):\n```json\n" . $groqCtxJson . "\n```\n\n---\n### История сохранённых снимков\n" . $groqHistoryBlock . $liveNote . $customBlock;

    $gen = null;
    $provider = null;
    $lastErr = '';
    $llmModelId = '';

    $tLlm0 = microtime(true);

    if ($geminiKey !== '') {
        $gen = ai_insights_gemini_generate($geminiKey, $system, $user);
        if ($gen['ok']) {
            $provider = 'gemini';
            $llmModelId = (string) ($gen['modelId'] ?? 'gemini-2.0-flash');
        } else {
            $lastErr = (string) ($gen['error'] ?? '');
            $httpCode = (int) ($gen['httpCode'] ?? 0);
            if ($groqKey !== '' && ai_insights_should_fallback_to_groq($lastErr, $httpCode)) {
                $gen = ai_insights_groq_generate($groqKey, $system, $groqUser);
                if ($gen['ok']) {
                    $provider = 'groq';
                    $llmModelId = (string) ($gen['modelId'] ?? 'llama-3.3-70b-versatile');
                } else {
                    $lastErr = (string) ($gen['error'] ?? $lastErr);
                }
            }
        }
    }

    if ($provider === null && $groqKey !== '' && ($geminiKey === '' || !($gen['ok'] ?? false))) {
        if ($geminiKey === '') {
            $gen = ai_insights_groq_generate($groqKey, $system, $groqUser);
            if ($gen['ok']) {
                $provider = 'groq';
                $llmModelId = (string) ($gen['modelId'] ?? 'llama-3.3-70b-versatile');
            } else {
                $lastErr = (string) ($gen['error'] ?? '');
            }
        }
    }

    // Claude — третий резерв
    if ($provider === null && $anthropicKey !== '') {
        $gen = ai_insights_claude_generate($anthropicKey, $system, $user);
        if ($gen['ok']) {
            $provider = 'claude';
            $llmModelId = (string) ($gen['modelId'] ?? 'claude-sonnet-4-6');
        } else {
            $lastErr = (string) ($gen['error'] ?? $lastErr);
        }
    }

    $llmMs = (int) round((microtime(true) - $tLlm0) * 1000);

    if ($provider === null || !($gen['ok'] ?? false)) {
        AiInsightsSupport::releaseLock();
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => $lastErr !== '' ? $lastErr : ($gen['error'] ?? 'Не удалось получить ответ от AI.'),
            'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $text = $gen['text'];
    $numberWarnings = AiInsightsSupport::verifyNumbersAgainstJson($text, $ctxJson);

    $metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
    AiInsightsHistory::appendWithAnalysis($metrics, $text);
    $hist = AiInsightsHistory::load();
    $histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

    $chartsOut = AiInsightsContext::chartPayload($dir, $baseId);

    AiInsightsSupport::logLine('llm_ok', [
        'refreshMs' => $refreshMs,
        'llmMs' => $llmMs,
        'provider' => $provider,
        'ctxBytes' => $ctxBytes,
        'skipRefresh' => $skipRefresh,
    ]);

    $response = [
        'ok' => true,
        'text' => $text,
        'provider' => $provider,
        'llmModel' => $llmModelId,
        'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
        'refreshMs' => $refreshMs,
        'llmMs' => $llmMs,
        'refreshSkipped' => $skipRefresh,
        'dataRefreshedFromAirtable' => $didRefresh || $skipRefresh,
        'usedRecentCache' => false,
        'customQuestion' => $customQuestion,
        'numberWarnings' => $numberWarnings,
        'historyCount' => $histCount,
        'historyChart' => AiInsightsHistory::chartSeries(56),
        'charts' => $chartsOut,
        'chartHints' => AiInsightsContext::chartHintsFromCharts($chartsOut),
    ];

    if (AiInsightsSupport::debugAiEnabled()) {
        $response['debug'] = [
            'ctxBytes' => $ctxBytes,
            'numberWarningsCount' => count($numberWarnings),
        ];
    }

    AiInsightsSupport::releaseLock();

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    AiInsightsSupport::releaseLock();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Внутренняя ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

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
 * @return array{ok:bool, text?: string, error?: string, httpCode?: int, modelId?: string}
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

    return ['ok' => true, 'text' => $out, 'httpCode' => $code, 'modelId' => 'gemini-2.0-flash'];
}

/**
 * Groq — OpenAI-совместимый API (ключ gsk_…).
 *
 * @return array{ok:bool, text?: string, error?: string, httpCode?: int, modelId?: string}
 */
function ai_insights_groq_generate(string $apiKey, string $systemInstruction, string $userText, int $maxTokens = 2048): array
{
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $model = 'llama-3.3-70b-versatile';

    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => $maxTokens,
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

    return ['ok' => true, 'text' => $out, 'httpCode' => $code, 'modelId' => $model];
}

/**
 * Claude (Anthropic) — через Messages API (sk-ant-…).
 *
 * @return array{ok:bool, text?: string, error?: string, httpCode?: int, modelId?: string}
 */
function ai_insights_claude_generate(string $apiKey, string $systemInstruction, string $userText): array
{
    $url = 'https://api.anthropic.com/v1/messages';
    $model = 'claude-sonnet-4-6';

    $payload = [
        'model' => $model,
        'max_tokens' => 8192,
        'system' => $systemInstruction,
        'messages' => [
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
        return ['ok' => false, 'error' => 'Сеть: запрос к Anthropic не выполнен', 'httpCode' => $code];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Некорректный ответ Anthropic (HTTP ' . $code . ')', 'httpCode' => $code];
    }
    if ($code >= 400) {
        $msg = isset($j['error']['message']) ? (string) $j['error']['message'] : 'HTTP ' . $code;
        return ['ok' => false, 'error' => 'Claude: ' . $msg, 'httpCode' => $code];
    }
    $out = '';
    foreach ($j['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $out .= (string) ($block['text'] ?? '');
        }
    }
    if ($out === '') {
        return ['ok' => false, 'error' => 'Claude: пустой ответ', 'httpCode' => $code];
    }

    return ['ok' => true, 'text' => $out, 'httpCode' => $code, 'modelId' => $model];
}

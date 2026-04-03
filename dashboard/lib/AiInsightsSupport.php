<?php
declare(strict_types=1);

require_once __DIR__ . '/AiInsightsContext.php';

/**
 * Блокировки, лимиты, проверка чисел и сообщения об ошибках для AI-аналитики.
 */
final class AiInsightsSupport
{
    public const PROMPT_VERSION = 2;

    /** @var resource|null */
    private static $lockFp = null;

    public static function lockPath(string $cacheDir): string
    {
        return $cacheDir . '/.ai-insights-operation.lock';
    }

    /**
     * Неблокирующая эксклюзивная блокировка (одна тяжёлая операция на процесс кэша).
     *
     * @return resource|false|null null = занято
     */
    public static function tryAcquireLock(string $cacheDir)
    {
        $path = self::lockPath($cacheDir);
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return null;
        }
        self::$lockFp = $fp;

        return $fp;
    }

    public static function releaseLock(): void
    {
        if (self::$lockFp !== null && is_resource(self::$lockFp)) {
            flock(self::$lockFp, LOCK_UN);
            fclose(self::$lockFp);
        }
        self::$lockFp = null;
    }

    /**
     * @return string|null Сообщение об ошибке или null если можно
     */
    public static function checkRateLimit(string $cacheDir, string $bucket, int $maxPerHour): ?string
    {
        if ($maxPerHour <= 0) {
            return null;
        }
        $sub = $cacheDir . '/ratelimit';
        if (!is_dir($sub) && !@mkdir($sub, 0775, true) && !is_dir($sub)) {
            return null;
        }
        $id = self::rateLimitClientId();
        $file = $sub . '/' . hash('sha256', $bucket . "\0" . $id) . '.json';
        $now = time();
        $data = ['t' => []];
        if (is_readable($file)) {
            $raw = file_get_contents($file);
            $j = json_decode((string) $raw, true);
            if (is_array($j) && isset($j['t']) && is_array($j['t'])) {
                $data['t'] = $j['t'];
            }
        }
        $cut = $now - 3600;
        $data['t'] = array_values(array_filter(array_map('intval', $data['t']), static fn (int $x): bool => $x > $cut));
        if (count($data['t']) >= $maxPerHour) {
            return 'Слишком много запросов. Подождите до часа или снизьте частоту генераций.';
        }
        $data['t'][] = $now;
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

        return null;
    }

    public static function rateLimitClientId(): string
    {
        $sid = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return hash('sha256', $sid . "\0" . $ip);
    }

    public static function maxRefreshPerHour(): int
    {
        $v = (int) (getenv('DASHBOARD_AI_RL_REFRESH') ?: $_ENV['DASHBOARD_AI_RL_REFRESH'] ?? 0);

        return $v > 0 ? $v : 48;
    }

    public static function maxLlmPerHour(): int
    {
        $v = (int) (getenv('DASHBOARD_AI_RL_LLM') ?: $_ENV['DASHBOARD_AI_RL_LLM'] ?? 0);

        return $v > 0 ? $v : 15;
    }

    public static function mapFetchError(string $rawMessage): string
    {
        return self::classifyFetchError($rawMessage)['message'];
    }

    /**
     * Структурированная классификация ошибки Airtable / LLM.
     *
     * @return array{type:string, message:string, detail:string, action:string, link:string|null}
     */
    public static function classifyFetchError(string $rawMessage, string $baseId = '', string $tableId = ''): array
    {
        $l = mb_strtolower($rawMessage);
        $airtableLink = ($baseId !== '' && $tableId !== '')
            ? 'https://airtable.com/' . $baseId . '/' . $tableId
            : null;

        // 429 Rate Limit (Airtable)
        if (str_contains($l, '429') || str_contains($l, 'rate limit') || str_contains($l, 'too many requests')) {
            return [
                'type' => 'rate_limit',
                'message' => 'Превышен лимит запросов Airtable (429).',
                'detail' => 'Airtable ограничивает 5 запросов/сек на базу. Подождите ~60 секунд и повторите.',
                'action' => 'Подождите ~60 сек и нажмите «Сгенерировать анализ» снова.',
                'link' => $airtableLink,
            ];
        }

        // 401 Unauthorized / invalid PAT
        if (str_contains($l, '401') || str_contains($l, 'unauthorized') || str_contains($l, 'invalid permissions')) {
            return [
                'type' => 'no_auth',
                'message' => 'AIRTABLE_PAT недействителен (401).',
                'detail' => 'Токен устарел, отозван или введён с ошибкой.',
                'action' => 'Обновите AIRTABLE_PAT в config.php. Токен создаётся в настройках Airtable → Developer Hub → Personal Access Tokens.',
                'link' => null,
            ];
        }

        // 403 Forbidden / no access to base/table
        if (str_contains($l, '403') || str_contains($l, 'forbidden')) {
            return [
                'type' => 'no_access',
                'message' => 'Нет доступа к таблице (403).',
                'detail' => 'PAT не имеет прав на чтение этой базы или таблицы.',
                'action' => 'Откройте Airtable → Personal Access Tokens → добавьте базу к токену. Нужны права data.records:read и schema.bases:read.',
                'link' => $airtableLink,
            ];
        }

        // 422 Unprocessable — часто означает неверный filterByFormula или view с ограничениями
        if (str_contains($l, '422') || str_contains($l, 'invalid filter') || str_contains($l, 'filterbyformula')) {
            return [
                'type' => 'filtered_table',
                'message' => 'Ошибка фильтра или вида Airtable (422).',
                'detail' => 'Вид содержит несовместимый фильтр или формула filterByFormula некорректна.',
                'action' => 'Откройте вид в Airtable и снимите все фильтры, или укажите другой airtable_dz_view_id в config.php.',
                'link' => $airtableLink,
            ];
        }

        // 404 Not Found — таблица/вид не существует
        if (str_contains($l, 'not found') || str_contains($l, '404') || str_contains($l, 'unknown table') || str_contains($l, 'view')) {
            $isView = str_contains($l, 'view');
            if ($isView) {
                return [
                    'type' => 'view_not_found',
                    'message' => 'Вид Airtable не найден.',
                    'detail' => 'ID вида (viw…) в config.php не существует или был удалён. Данные будут загружены без фильтра вида.',
                    'action' => 'Очистите airtable_dz_view_id / airtable_cs_view_id в config.php или укажите актуальный ID вида.',
                    'link' => $airtableLink,
                ];
            }

            return [
                'type' => 'table_not_found',
                'message' => 'Таблица или база Airtable не найдены.',
                'detail' => 'Таблица с указанным ID (tbl…) или база (app…) не существует. Возможно, таблица была удалена или Base ID изменился.',
                'action' => 'Проверьте AIRTABLE_BASE_ID и airtable_dz_table_id в config.php. Актуальный ID таблицы можно взять из URL Airtable.',
                'link' => $airtableLink,
            ];
        }

        // LLM token/quota errors
        if (str_contains($l, 'quota') || str_contains($l, 'tokens') || str_contains($l, 'resource_exhausted')) {
            return [
                'type' => 'llm_quota',
                'message' => 'Исчерпана квота токенов модели.',
                'detail' => $rawMessage,
                'action' => 'Подождите до обновления квоты (обычно ежедневно) или переключитесь на другой провайдер в config.php.',
                'link' => null,
            ];
        }

        // LLM invalid key
        if (str_contains($l, 'api key') || str_contains($l, 'invalid key') || str_contains($l, 'incorrect api')) {
            return [
                'type' => 'llm_bad_key',
                'message' => 'Ключ AI-провайдера недействителен.',
                'detail' => $rawMessage,
                'action' => 'Проверьте DASHBOARD_GEMINI_API_KEY / DASHBOARD_GROQ_API_KEY / DASHBOARD_ANTHROPIC_API_KEY в config.php или .env.',
                'link' => null,
            ];
        }

        // LLM overload / service unavailable
        if (str_contains($l, 'overloaded') || str_contains($l, 'service unavailable') || str_contains($l, '503') || str_contains($l, '529')) {
            return [
                'type' => 'llm_unavailable',
                'message' => 'AI-провайдер временно недоступен.',
                'detail' => $rawMessage,
                'action' => 'Подождите несколько минут и повторите. Или переключитесь на другой провайдер.',
                'link' => null,
            ];
        }

        return [
            'type' => 'unknown',
            'message' => 'Не удалось загрузить данные из Airtable.',
            'detail' => $rawMessage,
            'action' => 'Проверьте AIRTABLE_PAT, BASE_ID и ID таблиц в config.php.',
            'link' => $airtableLink,
        ];
    }

    /**
     * Полный refresh кэшей и payload графиков (замер времени).
     *
     * @return array{refreshMs: int, charts: array, chartHints: array}
     */
    public static function executeRefreshPipeline(array $c): array
    {
        $t0 = microtime(true);
        AiInsightsContext::refreshCachesFromAirtable($c);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $dir = AiInsightsContext::cacheDir();
        $baseId = (string) ($c['airtable_base_id'] ?? '');
        $charts = AiInsightsContext::chartPayload($dir, $baseId);

        return [
            'refreshMs' => $ms,
            'charts' => $charts,
            'chartHints' => AiInsightsContext::chartHintsFromCharts($charts),
        ];
    }

    /**
     * Числа в ответе модели, которых нет в JSON-контексте (эвристика, возможны ложные срабатывания).
     *
     * @return list<string>
     */
    public static function verifyNumbersAgainstJson(string $answerText, string $ctxJson): array
    {
        $warnings = [];
        $jsonNorm = preg_replace('/\D+/', '', $ctxJson) ?? '';
        if ($jsonNorm === '') {
            return [];
        }
        // Убираем блоки кода из ответа для поиска «левых» сумм
        $text = preg_replace('/```[\s\S]*?```/', ' ', $answerText) ?? $answerText;
        if (!preg_match_all('/\d[\d\s\x{00a0},.]*\d/u', $text, $matches)) {
            return [];
        }
        $seen = [];
        foreach ($matches[0] as $raw) {
            $digits = preg_replace('/\D/', '', $raw);
            if ($digits === '' || strlen($digits) < 4) {
                continue;
            }
            $n = (int) $digits;
            if ($n >= 2000 && $n <= 2035 && strlen($digits) === 4) {
                continue;
            }
            if (isset($seen[$digits])) {
                continue;
            }
            $seen[$digits] = true;
            if (!str_contains($jsonNorm, $digits)) {
                $warnings[] = trim($raw) . ' (нет в исходном JSON)';
                if (count($warnings) >= 14) {
                    break;
                }
            }
        }

        return $warnings;
    }

    public static function debugAiEnabled(): bool
    {
        $v = getenv('DASHBOARD_DEBUG_AI') ?: ($_ENV['DASHBOARD_DEBUG_AI'] ?? '');

        return $v === '1' || $v === 'true';
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function logLine(string $event, array $extra = []): void
    {
        $base = array_merge(['event' => $event, 'ts' => gmdate('c')], $extra);
        $line = 'ai_insights ' . json_encode($base, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log($line);
    }
}

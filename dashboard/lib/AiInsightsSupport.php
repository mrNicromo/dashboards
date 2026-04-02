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
        $m = $rawMessage;
        $l = mb_strtolower($m);
        if (str_contains($l, '429') || str_contains($l, 'rate limit') || str_contains($l, 'too many requests')) {
            return 'Лимит запросов к Airtable (429). Повторите позже или проверьте план Airtable.';
        }
        if (str_contains($l, '401') || str_contains($l, 'unauthorized') || str_contains($l, 'invalid permissions')) {
            return 'Доступ к Airtable отклонён (401). Проверьте AIRTABLE_PAT и права токена.';
        }
        if (str_contains($l, '403') || str_contains($l, 'forbidden')) {
            return 'Доступ запрещён (403). Проверьте права PAT и доступ к базе.';
        }
        if (str_contains($l, 'not found') || str_contains($l, '404') || str_contains($l, 'unknown table')) {
            return 'Таблица или база не найдены. Проверьте AIRTABLE_BASE_ID и ID таблиц в config.';
        }

        return 'Не удалось загрузить данные из Airtable / отчётов: ' . $m;
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

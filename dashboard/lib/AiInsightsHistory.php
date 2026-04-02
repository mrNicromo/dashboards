<?php
declare(strict_types=1);

/**
 * Локальная история снимков метрик и ответов AI (файл в cache/, не в git).
 * Нужна для трендов и осторожных прогнозов в следующих запусках.
 */
final class AiInsightsHistory
{
    private const FILE = 'ai-insights-history.json';
    private const MAX_ITEMS = 120;
    private const MAX_ANALYSIS_LEN = 16000;
    private const VERSION = 1;

    public static function path(): string
    {
        return __DIR__ . '/../cache/' . self::FILE;
    }

    /**
     * Полный снимок после генерации анализа (метрики + текст).
     *
     * @param array<string, mixed> $metrics
     */
    public static function appendWithAnalysis(array $metrics, string $analysisText): void
    {
        $analysis = $analysisText !== ''
            ? mb_substr($analysisText, 0, self::MAX_ANALYSIS_LEN)
            : null;
        self::appendInternal($metrics, $analysis);
    }

    /**
     * Только метрики (без расхода токенов AI) — для регулярных точек на графике.
     *
     * @param array<string, mixed> $metrics
     */
    public static function appendMetricsOnly(array $metrics): void
    {
        self::appendInternal($metrics, null);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private static function appendInternal(array $metrics, ?string $analysis): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }
        $raw = stream_get_contents($fp);
        $data = ['v' => self::VERSION, 'items' => []];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                $data = $decoded;
            }
        }
        $data['v'] = self::VERSION;
        $items = $data['items'] ?? [];
        $items[] = [
            't' => gmdate('Y-m-d\TH:i:s\Z'),
            'metrics' => $metrics,
            'analysis' => $analysis,
        ];
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, -self::MAX_ITEMS);
        }
        $data['items'] = $items;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** @return array{v: int, items: list<array<string, mixed>>} */
    public static function load(): array
    {
        $path = self::path();
        if (!is_readable($path)) {
            return ['v' => self::VERSION, 'items' => []];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['v' => self::VERSION, 'items' => []];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) {
            return ['v' => self::VERSION, 'items' => []];
        }
        return ['v' => (int) ($d['v'] ?? 1), 'items' => $d['items']];
    }

    /** Текстовый блок для промпта Gemini (последние N точек). */
    public static function buildTrendPromptSection(int $maxPoints = 28): string
    {
        $data = self::load();
        $items = array_slice($data['items'] ?? [], -$maxPoints);
        if ($items === []) {
            return '(Исторических снимков ещё нет. После нескольких запусков «Сгенерировать анализ» или «Записать снимок метрик» здесь появится тренд.)';
        }
        $lines = [];
        $lines[] = 'Ниже сохранённые снимки (UTC). Сравни динамику, отметь ускорение/замедление рисков. Прогноз только осторожный, с оговорками.';
        $lines[] = 'дата | ДЗ_всего_₽ | ДЗ_просрочка_₽ | %ДЗ/MRR | 90+_₽ | Churn_риск_MRR | Prob3_MRR | клиентов_в_риске | потери_YTD_₽';
        foreach ($items as $it) {
            $m = $it['metrics'] ?? [];
            $lines[] = sprintf(
                '%s | %s | %s | %s | %s | %s | %s | %s | %s',
                $it['t'] ?? '',
                self::fmtRub($m['dzTotal'] ?? null),
                self::fmtRub($m['dzOverdue'] ?? null),
                isset($m['debtToMrrPct']) ? (string) $m['debtToMrrPct'] : '—',
                self::fmtRub($m['aging90p'] ?? null),
                self::fmtRub($m['churnRisk'] ?? null),
                self::fmtRub($m['churnProb3'] ?? null),
                isset($m['churnClients']) ? (string) (int) $m['churnClients'] : '—',
                self::fmtRub($m['factTotalYtd'] ?? null)
            );
        }
        return implode("\n", $lines);
    }

    private static function fmtRub($v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $n = (float) $v;
        if (abs($n) >= 1_000_000) {
            return round($n / 1_000_000, 2) . 'M';
        }
        if (abs($n) >= 1000) {
            return round($n / 1000) . 'K';
        }
        return (string) round($n);
    }

    /** Данные для Chart.js: динамика ключевых метрик по снимкам. */
    public static function chartSeries(int $limit = 56): array
    {
        $data = self::load();
        $items = array_slice($data['items'] ?? [], -$limit);
        $labels = [];
        $dzTotal = [];
        $churnRisk = [];
        $churnProb3 = [];
        $factYtd = [];
        foreach ($items as $it) {
            $m = $it['metrics'] ?? [];
            $t = (string) ($it['t'] ?? '');
            $labels[] = strlen($t) >= 16 ? substr($t, 5, 11) : $t;
            $dzTotal[] = round((float) ($m['dzTotal'] ?? 0));
            $churnRisk[] = round((float) ($m['churnRisk'] ?? 0));
            $churnProb3[] = round((float) ($m['churnProb3'] ?? 0));
            $fy = $m['factTotalYtd'] ?? null;
            $factYtd[] = $fy !== null && $fy !== '' ? round((float) $fy) : null;
        }
        return [
            'labels' => $labels,
            'dzTotal' => $dzTotal,
            'churnRisk' => $churnRisk,
            'churnProb3' => $churnProb3,
            'factTotalYtd' => $factYtd,
            'count' => count($items),
        ];
    }
}

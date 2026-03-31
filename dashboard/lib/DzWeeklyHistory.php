<?php
declare(strict_types=1);

/**
 * Точки «общий долг / просрочка» по неделям (граница — среда, Europe/Moscow, как у дашборда руководителя).
 * Дополняется при каждой свежей выгрузке из Airtable (см. api.php).
 */
final class DzWeeklyHistory
{
    private const MAX_POINTS = 16;

    /**
     * @return array{0: string, 1: string} [prevWed Y-m-d, thisWed Y-m-d]
     */
    public static function moscowWeekRange(?DateTimeImmutable $at = null): array
    {
        $now = $at ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
        $dow = (int) $now->format('N');
        $daysBack = ($dow < 3) ? ($dow + 4) : ($dow - 3);
        $thisWed = $now->modify("-{$daysBack} days");
        $prevWed = $thisWed->modify('-7 days');

        return [$prevWed->format('Y-m-d'), $thisWed->format('Y-m-d')];
    }

    /**
     * Добавить/обновить точку за текущую неделю и вернуть последние точки для графика.
     *
     * @return list<array{weekEnd: string, weekStart: string, totalDebt: float, overdueDebt: float}>
     */
    public static function recordAndGet(string $slug, float $totalDebt, float $overdueDebt): array
    {
        [$prevWed, $thisWed] = self::moscowWeekRange();
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug !== '' ? $slug : 'default') ?: 'default';
        $path = __DIR__ . '/../cache/dz-weekly-' . $safe . '.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $points = [];
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j) && isset($j['points']) && is_array($j['points'])) {
                foreach ($j['points'] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $we = (string) ($p['weekEnd'] ?? '');
                    if ($we === '') {
                        continue;
                    }
                    $points[] = [
                        'weekEnd' => $we,
                        'weekStart' => (string) ($p['weekStart'] ?? ''),
                        'totalDebt' => (float) ($p['totalDebt'] ?? 0),
                        'overdueDebt' => (float) ($p['overdueDebt'] ?? 0),
                    ];
                }
            }
        }

        $byEnd = [];
        foreach ($points as $p) {
            $byEnd[$p['weekEnd']] = $p;
        }
        $byEnd[$thisWed] = [
            'weekEnd' => $thisWed,
            'weekStart' => $prevWed,
            'totalDebt' => round($totalDebt, 2),
            'overdueDebt' => round($overdueDebt, 2),
        ];

        $merged = array_values($byEnd);
        usort($merged, static fn (array $a, array $b): int => strcmp($a['weekEnd'], $b['weekEnd']));
        if (count($merged) > self::MAX_POINTS) {
            $merged = array_slice($merged, -self::MAX_POINTS);
        }

        file_put_contents(
            $path,
            json_encode(['points' => $merged], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $merged;
    }
}

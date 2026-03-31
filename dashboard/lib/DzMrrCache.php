<?php
declare(strict_types=1);

/**
 * MRR (сумма поля MRR sum в CS ALL) — одно значение на календарный месяц.
 * При смене месяца подставляется свежая сумма из последней выгрузки клиентов.
 */
final class DzMrrCache
{
    /**
     * @return array{value: float, yearMonth: string, updatedAt: string, note: string}
     */
    public static function resolve(string $slug, float $freshMrrFromCsFetch): array
    {
        $tz = new DateTimeZone('Europe/Moscow');
        $now = new DateTimeImmutable('now', $tz);
        $ym = $now->format('Y-m');

        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug !== '' ? $slug : 'default') ?: 'default';
        $path = __DIR__ . '/../cache/mrr-' . $safe . '.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $cachedYm = '';
        $cachedVal = 0.0;
        $cachedUpdated = '';
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $cachedYm = (string) ($j['yearMonth'] ?? '');
                $cachedVal = (float) ($j['value'] ?? 0);
                $cachedUpdated = (string) ($j['updatedAt'] ?? '');
            }
        }

        if ($cachedYm === $ym) {
            return [
                'value' => round($cachedVal, 2),
                'yearMonth' => $cachedYm,
                'updatedAt' => $cachedUpdated,
                'note' => 'Значение MRR за ' . $ym . ' (фиксируется на весь месяц, обновление — при первом запросе в новом месяце).',
            ];
        }

        $out = [
            'value' => round($freshMrrFromCsFetch, 2),
            'yearMonth' => $ym,
            'updatedAt' => $now->format('c'),
            'note' => 'MRR обновлён для нового месяца (' . $ym . ').',
        ];
        file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $out;
    }
}

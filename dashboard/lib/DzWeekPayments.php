<?php
declare(strict_types=1);

/**
 * Оплаты по неделям (вид «оплачено» + поле «Дата оплаты счета»).
 */
final class DzWeekPayments
{
    private const PAID_VIEW_DEFAULT = 'viwNp3aOtWxmQuKp5';

    /** @param mixed $raw */
    private static function parseAmount($raw): float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = (string) $raw;
        if ($s === '') {
            return 0.0;
        }
        $s = preg_replace('/[^\d.,\-]/', '', str_replace(["\xc2\xa0", ' '], '', $s)) ?? '';
        $commas = substr_count($s, ',');
        $dots = substr_count($s, '.');
        if ($commas > 0 && $dots > 0) {
            if (strrpos($s, '.') > strrpos($s, ',')) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            }
        } elseif ($commas === 1 && $dots === 0) {
            $parts = explode(',', $s);
            $s = strlen($parts[1] ?? '') <= 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($commas > 1) {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    private static function payDateStr(array $fields): string
    {
        $payDate = (string) ($fields['Дата оплаты счета'] ?? '');
        if (strlen($payDate) > 10) {
            $payDate = substr($payDate, 0, 10);
        }

        return $payDate;
    }

    /**
     * @return array{
     *   bars: list<array{weekEnd: string, weekStart: string, total: float}>,
     *   currentWeekTotal: float,
     *   weekStart: string,
     *   weekEnd: string,
     *   error: ?string
     * }
     */
    public static function weeklyPaidSeries(
        string $token,
        string $baseId,
        string $debtTableId,
        string $paidViewId,
        int $weeks = 12
    ): array {
        $v = trim($paidViewId);
        if ($v === '' || preg_match('/^viw[a-zA-Z0-9]{3,}$/', $v) !== 1) {
            $v = self::PAID_VIEW_DEFAULT;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
        $from = $now->modify('-' . (($weeks + 2) * 7) . ' days')->format('Y-m-d');
        $formula = "AND({Дата оплаты счета}, IS_AFTER({Дата оплаты счета}, '{$from}'))";

        try {
            $raw = Airtable::fetchAllPages($baseId, $debtTableId, [
                'view' => $v,
                'filterByFormula' => $formula,
                'pageSize' => '100',
            ], $token);
        } catch (Throwable $e) {
            return [
                'bars' => [],
                'currentWeekTotal' => 0.0,
                'weekStart' => '',
                'weekEnd' => '',
                'error' => $e->getMessage(),
            ];
        }

        $tz = new DateTimeZone('Europe/Moscow');
        [$curPrev, $curThis] = DzWeeklyHistory::moscowWeekRange($now);
        $thisWedDt = DateTimeImmutable::createFromFormat('Y-m-d', $curThis, $tz);
        if ($thisWedDt === false) {
            $thisWedDt = $now;
        }

        $templates = [];
        for ($i = 0; $i < $weeks; $i++) {
            $end = $thisWedDt->modify('-' . (7 * $i) . ' days');
            $start = $end->modify('-7 days');
            $templates[$end->format('Y-m-d')] = [
                'weekEnd' => $end->format('Y-m-d'),
                'weekStart' => $start->format('Y-m-d'),
                'total' => 0.0,
            ];
        }

        $currentWeekTotal = 0.0;

        foreach ($raw as $rec) {
            $f = $rec['fields'] ?? [];
            if (!is_array($f)) {
                continue;
            }
            $d = self::payDateStr($f);
            if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                continue;
            }
            $amt = self::parseAmount($f['Сумма счета'] ?? $f['Фактическая задолженность'] ?? 0);
            foreach ($templates as &$t) {
                if ($d >= $t['weekStart'] && $d <= $t['weekEnd']) {
                    $t['total'] += $amt;
                    break;
                }
            }
            unset($t);
            if ($d >= $curPrev && $d <= $curThis) {
                $currentWeekTotal += $amt;
            }
        }

        $bars = array_values($templates);
        usort($bars, static fn (array $a, array $b): int => strcmp($a['weekEnd'], $b['weekEnd']));
        foreach ($bars as &$b) {
            $b['total'] = round($b['total'], 2);
        }
        unset($b);

        return [
            'bars' => $bars,
            'currentWeekTotal' => round($currentWeekTotal, 2),
            'weekStart' => $curPrev,
            'weekEnd' => $curThis,
            'error' => null,
        ];
    }
}

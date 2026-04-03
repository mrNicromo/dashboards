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

    /**
     * Дата оплаты в Y-m-d для фильтров и сравнений.
     * Без cellFormat API отдаёт ISO; с cellFormat=string+ru — «25.03.2026», иначе всё уходит в ноль.
     */
    public static function normalizePaymentDateYmd(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return '';
    }

    private static function payDateStr(array $fields): string
    {
        return self::normalizePaymentDateYmd($fields['Дата оплаты счета'] ?? '');
    }

    /**
     * @return array{
     *   bars: list<array<string, mixed>>,
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
        int $weeks = 12,
        bool $withDetails = false
    ): array {
        $v = trim($paidViewId);
        if ($v === '' || preg_match('/^viw[a-zA-Z0-9]{3,}$/', $v) !== 1) {
            $v = self::PAID_VIEW_DEFAULT;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
        $from = $now->modify('-' . (($weeks + 2) * 7) . ' days')->format('Y-m-d');
        $formula = "AND({Дата оплаты счета}, IS_AFTER({Дата оплаты счета}, '{$from}'))";

        // Без cellFormat: даты приходят как ISO (YYYY-MM-DD), иначе при ru-locale строки ломают разбор.
        $query = [
            'view'              => $v,
            'filterByFormula'   => $formula,
            'pageSize'          => '100',
        ];

        try {
            $raw = Airtable::fetchAllPages($baseId, $debtTableId, $query, $token);
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
            $end   = $thisWedDt->modify('-' . (7 * $i) . ' days');
            $start = $end->modify('-7 days');
            $we    = $end->format('Y-m-d');
            $ws    = $start->format('Y-m-d');
            $row   = [
                'weekEnd'   => $we,
                'weekStart' => $ws,
                'total'     => 0.0,
            ];
            if ($withDetails) {
                $row['days']    = [];
                $row['entries'] = [];
                $startI = DateTimeImmutable::createFromFormat('Y-m-d', $ws, $tz);
                $endI   = DateTimeImmutable::createFromFormat('Y-m-d', $we, $tz);
                if ($startI !== false && $endI !== false) {
                    for ($d = $startI; $d <= $endI; $d = $d->modify('+1 day')) {
                        $row['days'][] = ['date' => $d->format('Y-m-d'), 'total' => 0.0];
                    }
                }
            }
            $templates[$we] = $row;
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
                    if ($withDetails && isset($t['days']) && is_array($t['days'])) {
                        foreach ($t['days'] as &$dayRow) {
                            if (($dayRow['date'] ?? '') === $d) {
                                $dayRow['total'] += $amt;
                                break;
                            }
                        }
                        unset($dayRow);
                        $t['entries'][] = ['date' => $d, 'amount' => $amt, 'fields' => $f];
                    }
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
            if ($withDetails && isset($b['days']) && is_array($b['days'])) {
                foreach ($b['days'] as &$drow) {
                    $drow['total'] = round((float) ($drow['total'] ?? 0), 2);
                }
                unset($drow);
            }
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

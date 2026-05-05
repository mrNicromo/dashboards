<?php
declare(strict_types=1);

/**
 * ChurnFactGSheet — читает данные о потерях из Google Sheets
 * Источник: лист "Потери Q1 2026" таблицы клиента
 *
 * Колонки (0-based index):
 *   A(0)  = Status      ("CHURN" | "Downsell")
 *   B(1)  = Месяц       (1–12)
 *   C(2)  = Год         (2026)
 *   H(7)  = Продукт
 *   Q(16) = Контрагент  (клиент)
 *   S(18) = Segment#2
 *   AC(28)= Сумма потери (формат «р.2 000», «-р.750»)
 *   AF(31)= Менеджер    (CSM)
 *   AL(37)= Классификация причины
 *   AM(38)= Замена
 */
final class ChurnFactGSheet
{
    public const CACHE        = __DIR__ . '/../cache/churn-fact-gsheet.json';
    public const CACHE_BACKUP = __DIR__ . '/../cache/churn-fact-gsheet.backup.json';
    private const TTL         = 900; // 15 min

    private const SHEETS_URL = 'https://docs.google.com/spreadsheets/d/1YRkBvjT1Gkq4cwsW0fWDr6rVhsZIRxP6rZVk7E8qLds/gviz/tq?tqx=out:csv&sheet=%D0%9F%D0%BE%D1%82%D0%B5%D1%80%D0%B8%20Q1%202026';

    // Column indices (0-based)
    private const COL_STATUS  = 0;
    private const COL_MONTH   = 1;
    private const COL_YEAR    = 2;
    private const COL_PRODUCT = 7;
    private const COL_ACCOUNT = 16;
    private const COL_SEG2    = 18;
    private const COL_AMOUNT  = 28;
    private const COL_CSM     = 31;
    private const COL_CLASS   = 37;
    private const COL_REPLACE = 38;

    // ── Квартальные таргеты (из ТЗ) ─────────────────────────────────
    public const QUARTER_TARGETS = [
        'Q1' => ['total' => 1_449_999, 'smb' => 1_449_999, 'ent' =>         0],
        'Q2' => ['total' => 2_929_999, 'smb' => 1_449_999, 'ent' => 1_480_000],
        'Q3' => ['total' => 2_349_999, 'smb' => 1_449_999, 'ent' =>   900_000],
        'Q4' => ['total' => 1_449_999, 'smb' => 1_449_999, 'ent' =>         0],
    ];

    // ── Квартальная выручка (источник для блока «% Churn от выручки») ──
    // 0 = данных пока нет, фронт фолбэкнется на (MRR × 3).
    // Заполнить точные суммы, как только клиент пришлёт цифры по Q1–Q4.
    public const QUARTER_REVENUE = [
        'Q1' => 0,
        'Q2' => 0,
        'Q3' => 0,
        'Q4' => 0,
    ];

    // ── Помесячные таргеты (из ТЗ) ──────────────────────────────────
    public const MONTH_TARGETS = [
        '2026-01' =>   483_333,
        '2026-02' =>   483_333,
        '2026-03' =>   483_333,
        '2026-04' =>   483_333,
        '2026-05' =>   483_333,
        '2026-06' => 1_963_333, // уход Золотого яблока + таргет команды Кристины
        '2026-07' =>   483_333,
        '2026-08' =>   483_333,
        '2026-09' => 1_384_000, // уход Самоката + таргет команды Кристины
        '2026-10' =>   483_333,
        '2026-11' =>   483_333,
        '2026-12' =>   483_333,
    ];

    // Годовые таргеты — сумма по месяцам
    public const TARGET_TOTAL = 8_180_663;
    public const TARGET_SMB   = 5_799_996; // 1 449 999 × 4 квартала
    public const TARGET_ENT   = 2_380_000; // Q2: 1 480 000 + Q3: 900 000

    // ── Нормализация продуктов ───────────────────────────────────────
    private const PRODUCT_LABELS = [
        'aq'             => 'AnyQuery',
        'anyquery'       => 'AnyQuery',
        'ac'             => 'AnyCollections',
        'anycollections' => 'AnyCollections',
        'recs'           => 'AnyRecs',
        'anyrecs'        => 'AnyRecs',
        'anyimages'      => 'AnyImages',
        'ai'             => 'AnyImages',
        'anyreviews'     => 'AnyReviews',
        'ar'             => 'AnyReviews',
        'rees46'         => 'Rees46',
        'app'            => 'APP',
    ];

    // ── Публичный интерфейс ──────────────────────────────────────────

    public static function getCached(): ?array
    {
        if (!is_readable(self::CACHE)) return null;
        $data = json_decode((string)file_get_contents(self::CACHE), true);
        if (!is_array($data)) return null;
        $age = time() - ($data['_ts'] ?? 0);
        if ($age >= self::TTL * 2) return null;
        if ($age >= self::TTL) $data['_stale'] = true;
        return $data;
    }

    public static function fetchReport(
        float $prob3risk    = 0.0,
        float $prob3riskEnt = 0.0,
        float $prob3riskSmb = 0.0
    ): array {
        // Свежий кэш — пересчитать только прогнозные поля
        if (is_readable(self::CACHE)) {
            $cached = json_decode((string)file_get_contents(self::CACHE), true);
            if (is_array($cached) && (time() - ($cached['_ts'] ?? 0)) < self::TTL) {
                $cached['prob3risk']    = $prob3risk;
                $cached['prob3riskEnt'] = $prob3riskEnt;
                $cached['prob3riskSmb'] = $prob3riskSmb;
                $cached['forecastYear'] = round(($cached['totalYtd'] ?? 0) + $prob3risk, 2);
                $cached['forecastEnt']  = round(($cached['entYtd']   ?? 0) + $prob3riskEnt, 2);
                $cached['forecastSmb']  = round(($cached['smbYtd']   ?? 0) + $prob3riskSmb, 2);
                $cached['devEntPct']    = self::TARGET_ENT > 0
                    ? round(($cached['forecastEnt'] / self::TARGET_ENT - 1) * 100, 1) : 0.0;
                $cached['devSmbPct']    = self::TARGET_SMB > 0
                    ? round(($cached['forecastSmb'] / self::TARGET_SMB - 1) * 100, 1) : 0.0;
                return $cached;
            }
        }

        try {
            $rows   = self::parseRows(self::fetchCsv());
            $report = self::build($rows, $prob3risk, $prob3riskEnt, $prob3riskSmb);
            $report['_ts'] = time();

            if (!is_dir(dirname(self::CACHE))) mkdir(dirname(self::CACHE), 0775, true);
            $json = json_encode($report, JSON_UNESCAPED_UNICODE);
            file_put_contents(self::CACHE, $json);
            file_put_contents(self::CACHE_BACKUP, $json);
            return $report;
        } catch (Throwable $e) {
            if (is_readable(self::CACHE_BACKUP)) {
                $backup = json_decode((string)file_get_contents(self::CACHE_BACKUP), true);
                if (is_array($backup)) {
                    $backup['_stale']     = true;
                    $backup['_backup']    = true;
                    $backup['prob3risk']    = $prob3risk;
                    $backup['forecastYear'] = round(($backup['totalYtd'] ?? 0) + $prob3risk, 2);
                    $backup['forecastEnt']  = round(($backup['entYtd']   ?? 0) + $prob3riskEnt, 2);
                    $backup['forecastSmb']  = round(($backup['smbYtd']   ?? 0) + $prob3riskSmb, 2);
                    return $backup;
                }
            }
            throw $e;
        }
    }

    // ── Парсинг CSV ──────────────────────────────────────────────────

    private static function fetchCsv(): string
    {
        $urls = [self::SHEETS_URL, str_replace('%20', '+', self::SHEETS_URL)];
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 6,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Dashboard/2.0)',
                CURLOPT_HTTPHEADER     => ['Accept: text/csv, text/plain, */*'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $code === 200 && strlen($body) > 100) return $body;
        }
        throw new \RuntimeException('Google Sheets CSV недоступен (HTTP ' . $code . ')');
    }

    /** Разбирает одну строку CSV с поддержкой кавычек и запятых внутри */
    private static function csvRow(string $line): array
    {
        $result = [];
        $field  = '';
        $inQ    = false;
        $len    = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($inQ) {
                if ($c === '"') {
                    if ($i + 1 < $len && $line[$i + 1] === '"') { $field .= '"'; $i++; }
                    else $inQ = false;
                } else {
                    $field .= $c;
                }
            } else {
                if ($c === '"') { $inQ = true; }
                elseif ($c === ',') { $result[] = $field; $field = ''; }
                else $field .= $c;
            }
        }
        $result[] = $field;
        return $result;
    }

    /** Парсит сумму потери из формата «р.2 000», «-р.750», «₽1 500» */
    private static function parseAmount(string $raw): float
    {
        // Убираем всё кроме цифр и минуса
        $digits = (string)preg_replace('/[^0-9\-]/u', '', $raw);
        if ($digits === '' || $digits === '-') return 0.0;
        return abs((float)$digits);
    }

    private static function normProduct(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        return self::PRODUCT_LABELS[$key] ?? ($raw !== '' ? $raw : 'Не указан');
    }

    private static function normSegment2(string $raw): string
    {
        $r = mb_strtolower(trim($raw));
        if ($r === 'ent' || $r === 'enterprise')                          return 'ENT';
        if (str_contains($r, 'sme+') || $r === 'sme_plus')               return 'SME+';
        if (str_contains($r, 'sme-') || str_contains($r, 'sme -') || $r === 'sme_minus') return 'SME-';
        if ($r === 'sme')                                                  return 'SME';
        if ($r === 'ss' || $r === 'self-service' || $r === 'self service') return 'SS';
        if ($r === 'smb')                                                  return 'SMB';
        return $raw;
    }

    private static function segmentGroup(string $seg2): string
    {
        return mb_strtolower($seg2) === 'ent' ? 'ENT' : 'SMB';
    }

    private static function quarterOf(string $monthKey): string
    {
        $m = (int)substr($monthKey, 5, 2);
        if ($m <= 3) return 'Q1';
        if ($m <= 6) return 'Q2';
        if ($m <= 9) return 'Q3';
        return 'Q4';
    }

    private static function parseRows(string $csv): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $csv))
        ));
        if (count($lines) < 2) return [];

        // Пропускаем строку заголовков
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = self::csvRow($line);

            $status = mb_strtolower(trim($cols[self::COL_STATUS] ?? ''));
            // Только Churn и Downsell строки
            if ($status !== 'churn' && !str_contains($status, 'downsell')) continue;

            $type     = str_contains($status, 'downsell') ? 'downsell' : 'churn';
            $monthNum = trim($cols[self::COL_MONTH]   ?? '');
            $yearNum  = trim($cols[self::COL_YEAR]    ?? '');
            $amount   = self::parseAmount($cols[self::COL_AMOUNT] ?? '');

            if ($amount <= 0) continue; // пропускаем нулевые суммы

            // Формируем ключ месяца: "2026-01"
            $y = (int)($yearNum ?: date('Y'));
            $m = (int)$monthNum;
            $monthKey = ($m >= 1 && $m <= 12 && $y >= 2020 && $y <= 2035)
                ? sprintf('%04d-%02d', $y, $m)
                : '';

            $seg2     = self::normSegment2(trim($cols[self::COL_SEG2]    ?? ''));
            $product  = self::normProduct(trim($cols[self::COL_PRODUCT]  ?? ''));
            $account  = trim($cols[self::COL_ACCOUNT] ?? '');
            $csm      = trim($cols[self::COL_CSM]     ?? '');
            $class    = trim($cols[self::COL_CLASS]   ?? '');
            $replace  = trim($cols[self::COL_REPLACE] ?? '');

            // Нормализация: пустые причины → «Не указана»
            if ($class === '' || $class === '-' || $class === '—') $class = 'Не указана';

            $rows[] = [
                'type'    => $type,
                'month'   => $monthKey,
                'amount'  => $amount,
                'seg2'    => $seg2,
                'segGrp'  => self::segmentGroup($seg2),
                'product' => $product,
                'account' => $account,
                'csm'     => $csm,
                'class'   => $class,
                'replace' => $replace,
            ];
        }
        return $rows;
    }

    // ── Построение отчёта ────────────────────────────────────────────

    private static function build(array $rows, float $prob3risk, float $prob3riskEnt, float $prob3riskSmb): array
    {
        $yearNow  = 2026; // данные только Q1 2026
        $ytdMonth = date('Y-m');

        $churnYtd    = 0.0;
        $downsellYtd = 0.0;
        $entYtd      = 0.0;
        $smbYtd      = 0.0;

        // Инициализируем серии по месяцам (2026-01 … 2026-12)
        $byMonth = [];
        for ($mo = 1; $mo <= 12; $mo++) {
            $key = sprintf('%04d-%02d', $yearNow, $mo);
            $byMonth[$key] = ['month' => $key, 'churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0];
        }

        // Инициализируем кварталы
        $byQuarter = [
            'Q1' => ['churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0, 'smb' => 0.0, 'ent' => 0.0],
            'Q2' => ['churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0, 'smb' => 0.0, 'ent' => 0.0],
            'Q3' => ['churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0, 'smb' => 0.0, 'ent' => 0.0],
            'Q4' => ['churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0, 'smb' => 0.0, 'ent' => 0.0],
        ];

        $byProduct          = [];
        $byCsm              = [];
        $byChurnClass       = []; // Классификация Churn
        $byDsClass          = []; // Классификация DownSell
        $byChurnReplacement = []; // Замена Churn
        $byDsReplacement    = []; // Замена DownSell
        $byMonthSeg         = []; // {month => {ent, smb}}
        $churnDetail        = [];
        $dsDetail           = [];

        foreach ($rows as $r) {
            $month  = (string)($r['month'] ?? '');
            $amount = (float)($r['amount'] ?? 0);
            $type   = (string)($r['type']   ?? '');

            // Пропускаем строки другого года
            if ($month !== '' && substr($month, 0, 4) !== (string)$yearNow) continue;

            $effectiveMonth = $month !== '' ? $month : $ytdMonth;
            $isYtd          = $month === '' || $month <= $ytdMonth;

            // Агрегация по месяцам
            if (isset($byMonth[$effectiveMonth])) {
                if ($type === 'churn')    $byMonth[$effectiveMonth]['churn']    += $amount;
                if ($type === 'downsell') $byMonth[$effectiveMonth]['downsell'] += $amount;
                $byMonth[$effectiveMonth]['total'] += $amount;
            }

            // Сегменты по месяцам
            if (!isset($byMonthSeg[$effectiveMonth])) $byMonthSeg[$effectiveMonth] = ['ent' => 0.0, 'smb' => 0.0];
            if ($r['segGrp'] === 'ENT') $byMonthSeg[$effectiveMonth]['ent'] += $amount;
            else                        $byMonthSeg[$effectiveMonth]['smb'] += $amount;

            // Квартал
            if ($effectiveMonth !== '') {
                $q = self::quarterOf($effectiveMonth);
                if (isset($byQuarter[$q])) {
                    if ($type === 'churn')    $byQuarter[$q]['churn']    += $amount;
                    if ($type === 'downsell') $byQuarter[$q]['downsell'] += $amount;
                    $byQuarter[$q]['total'] += $amount;
                    if ($r['segGrp'] === 'ENT') $byQuarter[$q]['ent'] += $amount;
                    else                        $byQuarter[$q]['smb'] += $amount;
                }
            }

            // YTD итоги
            if ($isYtd) {
                if ($type === 'churn')    $churnYtd    += $amount;
                if ($type === 'downsell') $downsellYtd += $amount;
                if ($r['segGrp'] === 'ENT') $entYtd += $amount;
                else                        $smbYtd += $amount;
            }

            // По продуктам
            $prod = $r['product'];
            if (!isset($byProduct[$prod])) $byProduct[$prod] = ['product' => $prod, 'churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0];
            if ($type === 'churn')    $byProduct[$prod]['churn']    += $amount;
            if ($type === 'downsell') $byProduct[$prod]['downsell'] += $amount;
            $byProduct[$prod]['total'] += $amount;

            // По CSM
            $csm = $r['csm'];
            if ($csm !== '') {
                if (!isset($byCsm[$csm])) $byCsm[$csm] = ['csm' => $csm, 'churn' => 0.0, 'downsell' => 0.0, 'total' => 0.0];
                if ($type === 'churn')    $byCsm[$csm]['churn']    += $amount;
                if ($type === 'downsell') $byCsm[$csm]['downsell'] += $amount;
                $byCsm[$csm]['total'] += $amount;
            }

            // Классификация (причина)
            $class = $r['class'];
            if ($type === 'churn') {
                if (!isset($byChurnClass[$class])) $byChurnClass[$class] = ['reason' => $class, 'total' => 0.0];
                $byChurnClass[$class]['total'] += $amount;
            } else {
                if (!isset($byDsClass[$class])) $byDsClass[$class] = ['reason' => $class, 'total' => 0.0];
                $byDsClass[$class]['total'] += $amount;
            }

            // Замена
            $replace = $r['replace'] !== '' ? $r['replace'] : 'Не указана';
            if ($type === 'churn') {
                if (!isset($byChurnReplacement[$replace])) $byChurnReplacement[$replace] = ['replacement' => $replace, 'total' => 0.0];
                $byChurnReplacement[$replace]['total'] += $amount;
            } else {
                if (!isset($byDsReplacement[$replace])) $byDsReplacement[$replace] = ['replacement' => $replace, 'total' => 0.0];
                $byDsReplacement[$replace]['total'] += $amount;
            }

            // Детальные строки
            if ($type === 'churn') {
                $churnDetail[] = [
                    'account'     => $r['account'],
                    'product'     => $r['product'],
                    'mrr'         => $amount,
                    'month'       => $effectiveMonth,
                    'seg2'        => $r['seg2'],
                    'csm'         => $r['csm'],
                    'class'       => $r['class'],
                    'replacement' => $r['replace'],
                ];
            } else {
                $dsDetail[] = [
                    'account' => $r['account'],
                    'csm'     => $r['csm'],
                    'product' => $r['product'],
                    'mrr'     => $amount,
                    'month'   => $effectiveMonth,
                    'class'   => $r['class'],
                    'seg2'    => $r['seg2'],
                    'replacement' => $r['replace'],
                ];
            }
        }

        $totalYtd    = round($churnYtd + $downsellYtd, 2);
        $forecastEnt = round($entYtd + $prob3riskEnt, 2);
        $forecastSmb = round($smbYtd + $prob3riskSmb, 2);

        // Сортировки
        usort($churnDetail,          static fn($a,$b) => $b['mrr']   <=> $a['mrr']);
        usort($dsDetail,             static fn($a,$b) => $b['mrr']   <=> $a['mrr']);
        uasort($byProduct,           static fn($a,$b) => $b['total'] <=> $a['total']);
        uasort($byCsm,               static fn($a,$b) => $b['total'] <=> $a['total']);
        uasort($byChurnClass,        static fn($a,$b) => $b['total'] <=> $a['total']);
        uasort($byDsClass,           static fn($a,$b) => $b['total'] <=> $a['total']);
        uasort($byChurnReplacement,  static fn($a,$b) => $b['total'] <=> $a['total']);
        uasort($byDsReplacement,     static fn($a,$b) => $b['total'] <=> $a['total']);

        // Округляем квартальные итоги
        foreach ($byQuarter as &$q) {
            foreach ($q as &$v) $v = round($v, 2);
        }
        unset($q, $v);

        return [
            'updatedAt'          => date('Y-m-d H:i'),
            'year'               => $yearNow,
            'churnYtd'           => round($churnYtd,    2),
            'downsellYtd'        => round($downsellYtd, 2),
            'totalYtd'           => $totalYtd,
            'entYtd'             => round($entYtd, 2),
            'smbYtd'             => round($smbYtd, 2),
            'prob3risk'          => $prob3risk,
            'prob3riskEnt'       => $prob3riskEnt,
            'prob3riskSmb'       => $prob3riskSmb,
            'forecastYear'       => round($totalYtd + $prob3risk, 2),
            'forecastEnt'        => $forecastEnt,
            'forecastSmb'        => $forecastSmb,
            'devEntPct'          => self::TARGET_ENT > 0
                ? round(($forecastEnt / self::TARGET_ENT - 1) * 100, 1) : 0.0,
            'devSmbPct'          => self::TARGET_SMB > 0
                ? round(($forecastSmb / self::TARGET_SMB - 1) * 100, 1) : 0.0,
            'targetTotal'        => self::TARGET_TOTAL,
            'targetSmb'          => self::TARGET_SMB,
            'targetEnt'          => self::TARGET_ENT,
            'monthTargets'       => self::MONTH_TARGETS,
            'quarterTargets'     => self::QUARTER_TARGETS,
            'quarterRevenue'     => self::QUARTER_REVENUE,
            'byMonth'            => array_values($byMonth),
            'byMonthSegment'     => $byMonthSeg,
            'byQuarter'          => $byQuarter,
            'byProduct'          => array_values($byProduct),
            'byCsm'              => array_values($byCsm),
            'byChurnReason'      => array_values($byChurnClass),
            'byDsReason'         => array_values($byDsClass),
            'byChurnReplacement' => array_values($byChurnReplacement),
            'byDsReplacement'    => array_values($byDsReplacement),
            'churnDetail'        => array_slice($churnDetail, 0, 500),
            'dsDetail'           => array_slice($dsDetail,    0, 500),
            '_rawChurn'          => count($churnDetail),
            '_rawDs'             => count($dsDetail),
            '_source'            => 'gsheet',
        ];
    }
}

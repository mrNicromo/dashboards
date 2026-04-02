<?php
declare(strict_types=1);

final class ChurnFactReport
{
    private const TABLE        = 'tblIKAi1gcFayRJTn';
    private const CACHE        = __DIR__ . '/../cache/churn-fact-report.json';
    private const CACHE_BACKUP = __DIR__ . '/../cache/churn-fact-report.backup.json';
    private const TTL          = 900; // 15 min

    // Targets (₽/year)
    public const TARGET_TOTAL = 8_200_000;
    public const TARGET_SMB   = 5_800_000; // SS+SMB+SME-+SME+SME+
    public const TARGET_ENT   = 2_400_000;

    // Planned churn hardcoded per ТЗ
    private const PLANNED_CHURN = [
        ['account'=>'Самокат', 'product'=>'AQ',  'mrr'=>975_000,   'month'=>'2026-03', 'segment'=>'ENT', 'reason'=>'Плановый', 'vertical'=>'', 'replacement'=>''],
        ['account'=>'ЗЯ',      'product'=>'AQ',  'mrr'=>1_474_438, 'month'=>'2026-05', 'segment'=>'ENT', 'reason'=>'Плановый', 'vertical'=>'', 'replacement'=>''],
    ];

    // Единые отображаемые имена продуктов (нормализация)
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

    // Маппинг email → имя CSM (нормализация, чтобы email и имя не дублировались)
    private const CSM_MAP = [
        'y.bandero@tbank.ru'        => 'Бандеро Я.',
        'a.a.koshkareva@tbank.ru'   => 'Кошкарева А.',
        'a.gayfullina@tbank.ru'     => 'Гайфуллина А.',
        'd.arkhangelskiy@tbank.ru'  => 'Архангельский Д.',
        'k.pengrin@tbank.ru'        => 'Пенгрин К.',
        'a.a.ganeeva@tbank.ru'      => 'Ганеева А.',
        'ni.shmelev@tbank.ru'       => 'Шмелев Н.',
        'niki.medvedev@tbank.ru'    => 'Медведев Н.',
        't.o.lukyanova@tbank.ru'    => 'Лукьянова Т.',
        'ta.a.andrianova@tbank.ru'  => 'Андрианова Т.',
        'n.i.zaporozhets@tbank.ru'  => 'Запорожец Н.',
        'e.ilyinskaya@tbank.ru'     => 'Ильинская Е.',
        's.shkapa@tbank.ru'         => 'Шкапа С.',
        'k.a.demidova@tbank.ru'     => 'Демидова К.',
        'a.v.kraynova@tbank.ru'     => 'Краянова А.',
        'e.kryakhova@tbank.ru'      => 'Краёхова Е.',
    ];

    // View ID → product label + MRR field in that view
    // Using view IDs (stable) instead of names — actual names have emoji prefixes:
    // 🍳 Recent Churn AQ, 🃏 Recent Churn AnyImages, 💰 Recent Churn AC, etc.
    private const CHURN_VIEWS = [
        'AnyQuery'       => ['view'=>'viw2n9PbsL1L0pyoZ', 'mrr'=>'AQ MRR'],
        'AnyCollections' => ['view'=>'viwBF2H34NOpGV6Xw',  'mrr'=>'MRR AC Churn'],
        'AnyRecs'        => ['view'=>'viwK44p3Snc2nvq8g',  'mrr'=>'MRR RECS Churn'],
        'AnyImages'      => ['view'=>'viwYrdTHr3aPRPtAp',  'mrr'=>'AnyImages MRR Churn'],
        'AnyReviews'     => ['view'=>'viwOLcUMubxibDk3O',  'mrr'=>'AnyReviews MRR'],
        'Rees46'         => ['view'=>'viw3we7FH7JBm700w',  'mrr'=>'Rees46 MRR Churn'],
    ];

    private const SHEETS_ID      = '1Tkax6awhWmNXfXpzORPIqHy5qgAhLzfifSHc-YLQhhY';
    private const SHEETS_DS_CSV  = 'https://docs.google.com/spreadsheets/d/1Tkax6awhWmNXfXpzORPIqHy5qgAhLzfifSHc-YLQhhY/gviz/tq?tqx=out:csv&sheet=UpSale%2FDownSell';
    private const SHEETS_CHN_CSV = 'https://docs.google.com/spreadsheets/d/1Tkax6awhWmNXfXpzORPIqHy5qgAhLzfifSHc-YLQhhY/gviz/tq?tqx=out:csv&sheet=Churn';

    /** Нормализация названия продукта: AQ → AnyQuery, AC → AnyCollections и т.д. */
    private static function normProduct(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        return self::PRODUCT_LABELS[$key] ?? $raw;
    }

    /** Нормализация CSM: email → русское имя по таблице */
    private static function normCsm(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        // Прямое совпадение по email (lowercase)
        foreach (self::CSM_MAP as $email => $name) {
            if ($key === mb_strtolower($email)) return $name;
        }
        return $raw; // оставляем как есть (напр. «Бандеро Я.» уже нормализован)
    }

    /** Нормализация сегмента (Google Sheets может писать Small/Medium/ENT) */
    private static function normSegment(string $raw): string
    {
        $r = mb_strtolower(trim($raw));
        if ($r === 'ent' || $r === 'enterprise') return 'ENT';
        return 'SMB'; // Small, Medium, SMB, SME — всё в одну группу
    }

    // Russian month names → number
    private const RU_MONTHS = [
        'январь'=>1,'января'=>1,'jan'=>1,
        'февраль'=>2,'февраля'=>2,'feb'=>2,
        'март'=>3,'марта'=>3,'mar'=>3,
        'апрель'=>4,'апреля'=>4,'apr'=>4,
        'май'=>5,'мая'=>5,'may'=>5,
        'июнь'=>6,'июня'=>6,'jun'=>6,
        'июль'=>7,'июля'=>7,'jul'=>7,
        'август'=>8,'августа'=>8,'aug'=>8,
        'сентябрь'=>9,'сентября'=>9,'sep'=>9,
        'октябрь'=>10,'октября'=>10,'oct'=>10,
        'ноябрь'=>11,'ноября'=>11,'nov'=>11,
        'декабрь'=>12,'декабря'=>12,'dec'=>12,
    ];

    // ------------------------------------------------------------------ //

    /**
     * Читает кэш без обращения к Airtable.
     * Возвращает данные если кэш < 30 мин, null иначе.
     */
    public static function getCached(): ?array
    {
        if (!is_readable(self::CACHE)) return null;
        $cached = json_decode(file_get_contents(self::CACHE) ?: '', true);
        if (!is_array($cached)) return null;
        $age = time() - ($cached['_ts'] ?? 0);
        if ($age >= self::TTL * 2) return null;
        if ($age >= self::TTL) $cached['_stale'] = true;
        return $cached;
    }

    public static function fetchReport(
        string $pat,
        string $baseId,
        float $prob3risk    = 0.0,
        float $prob3riskEnt = 0.0,
        float $prob3riskSmb = 0.0
    ): array {
        if (is_readable(self::CACHE)) {
            $cached = json_decode(file_get_contents(self::CACHE) ?: '', true);
            if (is_array($cached) && (time() - ($cached['_ts'] ?? 0)) < self::TTL) {
                // Пересчитываем прогнозные поля из актуального prob3risk (не кэшируются)
                $cached['prob3risk']    = $prob3risk;
                $cached['forecastYear'] = round($cached['totalYtd'] + $prob3risk, 2);
                $cached['forecastEnt']  = round($cached['entYtd'] + $prob3riskEnt, 2);
                $cached['forecastSmb']  = round($cached['smbYtd'] + $prob3riskSmb, 2);
                $cached['devEntPct']    = self::TARGET_ENT > 0
                    ? round(($cached['forecastEnt'] / self::TARGET_ENT - 1) * 100, 1) : 0.0;
                $cached['devSmbPct']    = self::TARGET_SMB > 0
                    ? round(($cached['forecastSmb'] / self::TARGET_SMB - 1) * 100, 1) : 0.0;
                return $cached;
            }
        }

        try {
            $churnRows    = self::fetchChurnRows($pat, $baseId);
            $downsellRows = self::fetchDownsellRows();
            $report       = self::build($churnRows, $downsellRows, $prob3risk, $prob3riskEnt, $prob3riskSmb);
            $report['_ts'] = time();

            if (!is_dir(dirname(self::CACHE))) mkdir(dirname(self::CACHE), 0775, true);
            $json = json_encode($report, JSON_UNESCAPED_UNICODE);
            file_put_contents(self::CACHE, $json);
            file_put_contents(self::CACHE_BACKUP, $json); // резервная копия на случай недоступности Airtable
            return $report;
        } catch (Throwable $e) {
            // Airtable/Google Sheets недоступны — отдаём резервный кэш если есть
            if (is_readable(self::CACHE_BACKUP)) {
                $backup = json_decode(file_get_contents(self::CACHE_BACKUP) ?: '', true);
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

    // ── Fetch Churn from Google Sheets CSV ───────────────────────────

    /** Заголовки последнего Churn CSV — для отладки */
    public static array $lastChnHeaders = [];

    private static function fetchChurnRows(string $pat, string $baseId): array
    {
        $rows = [];
        try {
            $raw = self::fetchCsv(self::SHEETS_CHN_CSV);
            if (!$raw) {
                self::$lastChnHeaders = ['error' => 'empty CSV response'];
                // Fallback: planned churn only
                foreach (self::PLANNED_CHURN as $p) {
                    $rows[] = array_merge($p, ['type' => 'churn', 'csm' => '', 'product' => self::normProduct($p['product'])]);
                }
                return $rows;
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
            if (count($lines) < 2) return $rows;

            $header = self::csvRow(array_shift($lines));
            $idx = [];
            foreach ($header as $i => $h) {
                $norm = mb_strtolower(trim($h, " \t\r\n\xEF\xBB\xBF\""));
                $norm = (string)preg_replace('/\s+/u', ' ', $norm);
                $idx[$norm] = $i;
            }
            self::$lastChnHeaders = array_keys($idx);

            $col = function(array $row, string ...$keys) use ($idx): string {
                foreach ($keys as $k) {
                    $kn = (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim($k)));
                    if (isset($idx[$kn], $row[$idx[$kn]])) {
                        return trim($row[$idx[$kn]], " \"\t\r\n");
                    }
                }
                return '';
            };

            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row     = self::csvRow($line);
                $account = $col($row, 'клиент', 'account', 'client');
                if ($account === '') continue;

                $mrrRaw = self::amt($col($row, 'mrr', 'сумма mrr', 'mrr сумма', 'mrr потеря', 'потеря mrr', 'сумма'));
                $mrr    = abs($mrrRaw);

                $monthVal = $col($row, 'месяц', 'month');
                $yearVal  = $col($row, 'год', 'year');
                $month    = self::buildMonthStr($monthVal, $yearVal);

                // Segment: from column, else derive from MRR
                $segRaw  = $col($row, 'сегмент', 'segment');
                $segment = $segRaw !== '' ? self::normSegment($segRaw) : ($mrr >= 100_000 ? 'ENT' : 'SMB');

                $rows[] = [
                    'type'        => 'churn',
                    'account'     => $account,
                    'product'     => self::normProduct($col($row, 'продукт', 'product')),
                    'mrr'         => $mrr,
                    'month'       => $month,
                    'reason'      => $col($row,
                        'классификация причины ухода',
                        'классификация причины',
                        'классификация',
                        'причина ухода',
                        'причина',
                        'reason',
                        'комментарий',
                        'comment'
                    ),
                    'vertical'    => $col($row, 'вертикаль', 'vertical'),
                    'replacement' => $col($row, 'замена', 'replacement'),
                    'csm'         => self::normCsm($col($row, 'csm менеджер', 'csm', 'менеджер', 'manager')),
                    'segment'     => $segment,
                ];
            }
        } catch (Throwable $e) {
            self::$lastChnHeaders = ['exception' => $e->getMessage()];
        }

        // Add hardcoded planned churn
        foreach (self::PLANNED_CHURN as $p) {
            $rows[] = array_merge($p, [
                'type'    => 'churn',
                'csm'     => '',
                'product' => self::normProduct($p['product']),
            ]);
        }
        return $rows;
    }

    // ── cURL helper for public CSV URLs ───────────────────────────────

    private static function fetchCsv(string $url): string
    {
        // Try with URL-encoded slash, then raw slash fallback
        $urls = [$url, str_replace('%2F', '/', $url)];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Dashboard/1.0)',
                CURLOPT_HTTPHEADER     => ['Accept: text/csv, text/plain, */*'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($body) && $code === 200 && strlen($body) > 50) {
                return $body;
            }
        }
        return '';
    }

    // ── Enrich churn rows with CSM from Google Sheets "Churn" tab ────

    private static function enrichWithSheetsCsm(array $churnRows): array
    {
        try {
            $raw = self::fetchCsv(self::SHEETS_CHN_CSV);
            if (!$raw) return $churnRows;

            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
            if (count($lines) < 2) return $churnRows;

            $header = self::csvRow(array_shift($lines));
            $idx = [];
            foreach ($header as $i => $h) {
                $idx[mb_strtolower(trim($h, " \t\r\n\xEF\xBB\xBF\""))] = $i;
            }

            $col = function(array $row, string ...$keys) use ($idx): string {
                foreach ($keys as $k) {
                    $kn = mb_strtolower(trim($k));
                    if (isset($idx[$kn], $row[$idx[$kn]])) return trim($row[$idx[$kn]], " \"\t\r\n");
                }
                return '';
            };

            // Build map: account → CSM (last wins)
            $csmMap = [];
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row     = self::csvRow($line);
                $account = $col($row, 'клиент', 'account', 'client');
                $csm     = $col($row, 'csm менеджер', 'csm', 'менеджер', 'manager');
                if ($account !== '' && $csm !== '') {
                    $csmMap[mb_strtolower($account)] = $csm;
                }
            }

            // Enrich churn rows:
            // Обогащение: Google Sheets → имя CSM, плюс нормализация email→имя по CSM_MAP
            foreach ($churnRows as &$r) {
                $cur = (string)($r['csm'] ?? '');
                $isEmail = str_contains($cur, '@');
                if ($isEmail || $cur === '') {
                    // Сначала пробуем найти в Google Sheets по аккаунту
                    $key = mb_strtolower((string)($r['account'] ?? ''));
                    if (isset($csmMap[$key])) {
                        $r['csm'] = self::normCsm($csmMap[$key]);
                    } else {
                        // Иначе нормализуем email напрямую
                        $r['csm'] = self::normCsm($cur);
                    }
                }
            }
            unset($r);
        } catch (Throwable $e) {
            // Sheets unavailable — return as-is
        }
        return $churnRows;
    }

    // ── Fetch DownSell from Google Sheets CSV ─────────────────────────

    /** Заголовки последнего DownSell CSV — для отладки пустых причин */
    public static array $lastDsHeaders = [];
    /** Счётчики фильтрации — для отладки */
    public static array $lastDsDebug   = [];
    /** Примеры значений полей даты / месяца (отладка сопоставления с Airtable) */
    public static array $lastDateSamples = [];
    /** Имена полей из вьюх Recent Churn (отладка Meta) */
    public static array $lastChurnFields = [];

    private static function fetchDownsellRows(): array
    {
        $rows = [];
        $dbg  = ['totalLines'=>0,'noTypeCol'=>0,'typeValues'=>[],'failedTypeFilter'=>0,'failedKindFilter'=>0,'failedChangeFilter'=>0,'passed'=>0];
        try {
            $raw = self::fetchCsv(self::SHEETS_DS_CSV);
            if (!$raw) {
                self::$lastDsDebug = ['error'=>'empty CSV response'];
                return [];
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
            $dbg['totalLines'] = count($lines) - 1; // minus header
            if (count($lines) < 2) return [];

            // Parse header row
            $header = self::csvRow(array_shift($lines));
            $idx = [];
            foreach ($header as $i => $h) {
                // Нормализуем: убираем BOM/кавычки, приводим к нижнему регистру,
                // схлопываем множественные пробелы в один
                $norm = mb_strtolower(trim($h, " \t\r\n\xEF\xBB\xBF\""));
                $norm = (string)preg_replace('/\s+/u', ' ', $norm);
                $idx[$norm] = $i;
            }
            // Сохраняем заголовки для отладки
            self::$lastDsHeaders = array_keys($idx);

            // Helper: get cell by column name (with aliases), returns trimmed string
            $col = function(array $row, string ...$keys) use ($idx): string {
                foreach ($keys as $k) {
                    $kn = (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim($k)));
                    if (isset($idx[$kn], $row[$idx[$kn]])) {
                        return trim($row[$idx[$kn]], " \"\t\r\n");
                    }
                }
                return '';
            };

            $typeValSamples = [];
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row = self::csvRow($line);

                // Filter: UpSale или DownSell column — value must contain "down"
                $type = $col($row,
                    'upsale или downsell', 'upsale/downsell',
                    'тип сделки', 'тип изменения',
                    'upsale', 'downsell', 'тип операции', 'операция'
                );
                // Collect unique type values for debug (max 10)
                if ($type !== '' && count($typeValSamples) < 10 && !in_array($type, $typeValSamples, true)) {
                    $typeValSamples[] = $type;
                }
                if ($type === '') { $dbg['noTypeCol']++; }

                // Если тип заполнен и явно НЕ "down" — пропускаем (например "upsale").
                // Если тип пуст — пропускаем только Upsale-строки (change > 0 отсеет нули).
                if ($type !== '' && mb_stripos($type, 'down') === false) {
                    $dbg['failedTypeFilter']++;
                    continue;
                }

                // Filter: Тип = "Постоянная" OR empty (Временная = skip)
                $kind = mb_strtolower(trim($col($row, 'тип', 'type')));
                if ($kind !== '' && $kind !== 'постоянная') {
                    $dbg['failedKindFilter']++;
                    continue;
                }

                // MRR change — "Изменение" может быть отрицательным (MRR new - MRR old),
                // поэтому берём abs(). Если колонки нет — считаем из MRR старый/новый.
                $changeRaw = self::amt($col($row, 'изменение', 'mrr изменение', 'delta mrr', 'дельта mrr'));
                $change    = abs($changeRaw);
                if ($change == 0.0) {
                    $mrrOld = self::amt($col($row, 'mrr старый', 'mrr old', 'старый mrr', 'mrr до', 'mrr_old'));
                    $mrrNew = self::amt($col($row, 'mrr новый', 'mrr new', 'новый mrr', 'mrr после', 'mrr_new'));
                    $change = abs($mrrOld - $mrrNew);
                }
                if ($change <= 0.0) {
                    $dbg['failedChangeFilter']++;
                    continue;
                }

                // Month parsing: column "Месяц" can be "Март", "3", month name
                $monthVal = $col($row, 'месяц', 'month');
                $yearVal  = $col($row, 'год', 'year');
                $monthStr = self::buildMonthStr($monthVal, $yearVal);

                $rows[] = [
                    'type'     => 'downsell',
                    'account'  => $col($row, 'клиент', 'account', 'client'),
                    'csm'      => self::normCsm($col($row, 'csm менеджер', 'csm', 'менеджер')),
                    'product'  => self::normProduct($col($row, 'продукт', 'product')),
                    'mrr'      => $change,
                    'month'    => $monthStr,
                    'reason'   => $col($row,
                        'классификация причины скидки',
                        'классификация причины ухода',
                        'классификация',
                        'причина скидки',
                        'причина ухода',
                        'причина',
                        'reason',
                        'комментарий',
                        'comment',
                        'причина downsell',
                        'downsell причина'
                    ),
                    'vertical' => '',
                    'segment'  => $col($row, 'сегмент', 'segment'),
                    'replacement' => '',
                ];
                $dbg['passed']++;
            }
            $dbg['typeValues'] = $typeValSamples;
        } catch (Throwable $e) {
            $dbg['exception'] = $e->getMessage();
            // Google Sheets unavailable — skip
        }
        self::$lastDsDebug = $dbg;
        return $rows;
    }

    // ── Build consolidated report ─────────────────────────────────────

    private static function build(array $churn, array $downsell, float $prob3risk, float $prob3riskEnt = 0.0, float $prob3riskSmb = 0.0): array
    {
        $allRows = array_merge($churn, $downsell);
        $yearNow = (int)date('Y');
        $ytdMonth = date('Y-m');

        $churnYtd    = 0.0;
        $downsellYtd = 0.0;
        $entYtd      = 0.0;
        $smbYtd      = 0.0;

        // Monthly series — full year
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = $yearNow . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $months[$key] = ['month'=>$key, 'churn'=>0.0, 'downsell'=>0.0, 'total'=>0.0];
        }

        $byProduct     = [];
        $byCsm         = [];
        $byChurnReason = [];
        $byDsReason    = [];
        $byVertical    = [];
        $byMonthSeg    = []; // {month => {ent, smb}}
        $churnDetail   = [];
        $dsDetail      = [];

        foreach ($allRows as $r) {
            $month = (string)($r['month'] ?? '');
            $mrr   = (float)($r['mrr'] ?? 0);
            if ($mrr <= 0) continue;

            // Only current year rows (skip if explicitly another year)
            if ($month !== '' && substr($month, 0, 4) !== (string)$yearNow) continue;

            // If date is missing — treat as current month (include in YTD)
            $effectiveMonth = $month !== '' ? $month : $ytdMonth;
            $isYtd = $month === '' || $month <= $ytdMonth;

            // Monthly aggregation
            // Use effective month for all aggregations
            $month = $effectiveMonth;

            if (isset($months[$month])) {
                if ($r['type'] === 'churn')    $months[$month]['churn']    += $mrr;
                if ($r['type'] === 'downsell') $months[$month]['downsell'] += $mrr;
                $months[$month]['total'] += $mrr;
            }

            // Segment group
            $seg      = (string)($r['segment'] ?? '');
            $segGroup = self::normSegment($seg);

            // Segment by month chart
            if (!isset($byMonthSeg[$month])) $byMonthSeg[$month] = ['ent'=>0.0,'smb'=>0.0];
            if ($segGroup === 'ENT') $byMonthSeg[$month]['ent'] += $mrr;
            else                     $byMonthSeg[$month]['smb'] += $mrr;

            if ($isYtd) {
                if ($r['type'] === 'churn')    $churnYtd    += $mrr;
                if ($r['type'] === 'downsell') $downsellYtd += $mrr;
                if ($segGroup === 'ENT') $entYtd += $mrr;
                else                     $smbYtd += $mrr;
            }

            // By product
            $prod = (string)($r['product'] ?? 'Не указан');
            if ($prod === '') $prod = 'Не указан';
            if (!isset($byProduct[$prod])) $byProduct[$prod] = ['product'=>$prod,'churn'=>0.0,'downsell'=>0.0,'total'=>0.0];
            if ($r['type']==='churn')    $byProduct[$prod]['churn']    += $mrr;
            if ($r['type']==='downsell') $byProduct[$prod]['downsell'] += $mrr;
            $byProduct[$prod]['total'] += $mrr;

            // By CSM (mainly downsell)
            $csm = (string)($r['csm'] ?? '');
            if ($csm !== '') {
                if (!isset($byCsm[$csm])) $byCsm[$csm] = ['csm'=>$csm,'churn'=>0.0,'downsell'=>0.0,'total'=>0.0];
                if ($r['type']==='churn')    $byCsm[$csm]['churn']    += $mrr;
                if ($r['type']==='downsell') $byCsm[$csm]['downsell'] += $mrr;
                $byCsm[$csm]['total'] += $mrr;
            }

            // By reason (split by type)
            $reason = (string)($r['reason'] ?? '');
            if ($reason === '' || $reason === '-' || $reason === '—' || $reason === '--') {
                $reason = 'Не указана';
            }
            if ($reason !== '') {
                if ($r['type'] === 'churn') {
                    if (!isset($byChurnReason[$reason])) $byChurnReason[$reason] = ['reason'=>$reason,'total'=>0.0];
                    $byChurnReason[$reason]['total'] += $mrr;
                } else {
                    if (!isset($byDsReason[$reason])) $byDsReason[$reason] = ['reason'=>$reason,'total'=>0.0];
                    $byDsReason[$reason]['total'] += $mrr;
                }
            }

            // By vertical (churn only)
            $vert = (string)($r['vertical'] ?? '');
            if ($vert !== '' && $r['type'] === 'churn') {
                if (!isset($byVertical[$vert])) $byVertical[$vert] = ['vertical'=>$vert,'total'=>0.0];
                $byVertical[$vert]['total'] += $mrr;
            }

            // Detail rows
            if ($r['type'] === 'churn') {
                $churnDetail[] = [
                    'account'     => (string)($r['account'] ?? ''),
                    'product'     => (string)($r['product'] ?? ''),
                    'mrr'         => $mrr,
                    'month'       => $month,
                    'reason'      => (string)($r['reason'] ?? ''),
                    'vertical'    => (string)($r['vertical'] ?? ''),
                    'replacement' => (string)($r['replacement'] ?? ''),
                ];
            } else {
                $dsDetail[] = [
                    'account' => (string)($r['account'] ?? ''),
                    'csm'     => (string)($r['csm'] ?? ''),
                    'product' => (string)($r['product'] ?? ''),
                    'mrr'     => $mrr,
                    'month'   => $month,
                    'reason'  => (string)($r['reason'] ?? ''),
                ];
            }
        }

        $totalYtd    = round($churnYtd + $downsellYtd, 2);
        $forecastEnt = round($entYtd + $prob3riskEnt, 2);
        $forecastSmb = round($smbYtd + $prob3riskSmb, 2);

        usort($churnDetail,    static function($a,$b){ return $b['mrr']   <=> $a['mrr'];   });
        usort($dsDetail,       static function($a,$b){ return $b['mrr']   <=> $a['mrr'];   });
        uasort($byProduct,     static function($a,$b){ return $b['total'] <=> $a['total']; });
        uasort($byCsm,         static function($a,$b){ return $b['total'] <=> $a['total']; });
        uasort($byChurnReason, static function($a,$b){ return $b['total'] <=> $a['total']; });
        uasort($byDsReason,    static function($a,$b){ return $b['total'] <=> $a['total']; });
        uasort($byVertical,    static function($a,$b){ return $b['total'] <=> $a['total']; });

        return [
            'updatedAt'    => date('Y-m-d H:i'),
            'year'         => $yearNow,
            'churnYtd'     => round($churnYtd,    2),
            'downsellYtd'  => round($downsellYtd, 2),
            'totalYtd'     => $totalYtd,
            'entYtd'       => round($entYtd,      2),
            'smbYtd'       => round($smbYtd,      2),
            'prob3risk'    => $prob3risk,
            'forecastYear' => round($totalYtd + $prob3risk, 2),
            'forecastEnt'  => $forecastEnt,
            'forecastSmb'  => $forecastSmb,
            'devEntPct'    => self::TARGET_ENT > 0 ? round(($forecastEnt / self::TARGET_ENT - 1) * 100, 1) : 0.0,
            'devSmbPct'    => self::TARGET_SMB > 0 ? round(($forecastSmb / self::TARGET_SMB - 1) * 100, 1) : 0.0,
            'targetTotal'  => self::TARGET_TOTAL,
            'targetSmb'    => self::TARGET_SMB,
            'targetEnt'    => self::TARGET_ENT,
            'byMonth'         => array_values($months),
            'byMonthSegment'  => $byMonthSeg,
            'byProduct'       => array_values($byProduct),
            'byCsm'           => array_values($byCsm),
            'byChurnReason'   => array_values($byChurnReason),
            'byDsReason'      => array_values($byDsReason),
            'byVertical'      => array_values($byVertical),
            'churnDetail'     => array_slice($churnDetail, 0, 300),
            'dsDetail'        => array_slice($dsDetail,    0, 300),
            '_rawChurn'       => count($churnDetail),
            '_rawDs'          => count($dsDetail),
            '_dsHeaders'      => self::$lastDsHeaders,
            '_dsDebug'        => self::$lastDsDebug,
            '_dateSamples'    => self::$lastDateSamples,
            '_churnFields'    => self::$lastChurnFields,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function buildMonthStr(string $monthVal, string $yearVal): string
    {
        if ($yearVal === '') $yearVal = (string)date('Y');
        $y = (int)$yearVal;
        if ($y < 2020 || $y > 2035) return '';

        // Try numeric month
        $mn = (int)$monthVal;
        if ($mn >= 1 && $mn <= 12) {
            return $y . '-' . str_pad((string)$mn, 2, '0', STR_PAD_LEFT);
        }

        // Try Russian month name
        $norm = mb_strtolower(trim($monthVal));
        if (isset(self::RU_MONTHS[$norm])) {
            return $y . '-' . str_pad((string)self::RU_MONTHS[$norm], 2, '0', STR_PAD_LEFT);
        }

        return '';
    }

    private static function parseMonth(string $raw): string
    {
        if ($raw === '') return '';
        // ISO: 2026-03-28 или 2026-03-28T10:00
        if (preg_match('/^(\d{4})-(\d{2})/', $raw, $m)) return $m[1] . '-' . $m[2];
        // DD.MM.YYYY / DD/MM/YYYY / DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $raw, $m)) {
            return $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        // Русский формат Airtable: "30 марта 2026 г." или "30 марта 2026"
        if (preg_match('/(\d{1,2})\s+([а-яёА-ЯЁ]+)\s+(\d{4})/u', $raw, $m)) {
            $mn = mb_strtolower(trim($m[2]), 'UTF-8');
            if (isset(self::RU_MONTHS[$mn])) {
                return $m[3] . '-' . str_pad((string)self::RU_MONTHS[$mn], 2, '0', STR_PAD_LEFT);
            }
        }
        // Только месяц и год: "март 2026" / "March 2026"
        if (preg_match('/^([а-яёА-ЯЁa-zA-Z]+)\s+(\d{4})$/u', trim($raw), $m)) {
            $mn = mb_strtolower(trim($m[1]), 'UTF-8');
            if (isset(self::RU_MONTHS[$mn])) {
                return $m[2] . '-' . str_pad((string)self::RU_MONTHS[$mn], 2, '0', STR_PAD_LEFT);
            }
        }
        return '';
    }

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
                    if ($i + 1 < $len && $line[$i+1] === '"') { $field .= '"'; $i++; }
                    else $inQ = false;
                } else { $field .= $c; }
            } else {
                if ($c === '"')  { $inQ = true; }
                elseif ($c === ',') { $result[] = $field; $field = ''; }
                else { $field .= $c; }
            }
        }
        $result[] = $field;
        return $result;
    }

    private static function str(mixed $v): string
    {
        if ($v === null) return '';
        if (is_array($v)) $v = $v[0] ?? '';
        return trim((string)$v);
    }

    private static function amt(mixed $raw): float
    {
        if ($raw === null || $raw === '') return 0.0;
        $str = trim((string)$raw);
        // Убираем сокращения валют ДО регекса, иначе "р.17 000" → "-.17000" = 0.17 вместо 17000
        $str = preg_replace('/р\.?\s*/u', '', $str) ?? $str;   // р. / р
        $str = preg_replace('/[₽$€£¥]/u',  '', $str) ?? $str; // символы валют
        $str = preg_replace('/руб\.?\s*/ui', '', $str) ?? $str; // руб / руб.
        $s = preg_replace('/[^\d.,\-]/', '', $str) ?? '';
        if ($s === '') return 0.0;
        $lc = strrpos($s, ',');
        $ld = strrpos($s, '.');
        if ($lc !== false && $ld !== false) {
            $s = $ld > $lc ? str_replace(',', '', $s) : str_replace(['.', ','], ['', '.'], $s);
        } elseif ($lc !== false) {
            $after = substr($s, $lc + 1);
            $s = strlen($after) <= 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        }
        return round((float)$s, 2);
    }
}

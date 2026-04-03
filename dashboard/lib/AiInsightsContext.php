<?php
declare(strict_types=1);

/**
 * Сбор компактного контекста для LLM и данных для графиков (кэши дашборда = снимок Airtable).
 */
final class AiInsightsContext
{
    private const MAX_CLIENTS_CHURN = 30;
    private const MAX_TOP_LEGAL = 22;
    private const MAX_ROWS_SAMPLE = 36;
    private const MAX_COMMENT_LEN = 140;

    public static function cacheDir(): string
    {
        return __DIR__ . '/../cache';
    }

    /** Для Chart.js на странице */
    public static function chartPayload(string $dir, string $airtableBaseId): array
    {
        $dz = self::loadJson($dir . '/dz-data-default.json');
        $churn = self::loadJson($dir . '/churn-report.json');
        $fact = self::loadJson($dir . '/churn-fact-report.json');

        $inner = self::dzInner($dz);
        $aging = $inner['aging'] ?? [];
        $agingLabels = ['0-15', '16-30', '31-60', '61-90', '91+'];
        $agingVals = [];
        foreach ($agingLabels as $k) {
            $agingVals[] = round(self::agingBucketAmount($aging, $k));
        }

        $segLabels = [];
        $segVals = [];
        foreach ($churn['bySegment'] ?? [] as $row) {
            $segLabels[] = (string) ($row['segment'] ?? '?');
            $segVals[] = round((float) ($row['mrr'] ?? 0));
        }

        $mgrLabels = [];
        $mgrVals = [];
        $bm = array_slice($inner['byManager'] ?? [], 0, 10);
        foreach ($bm as $row) {
            $mgrLabels[] = self::shortMgr((string) ($row['name'] ?? '?'));
            $mgrVals[] = round((float) ($row['amount'] ?? 0));
        }

        $monthly = null;
        if (!empty($fact['byMonth']) && is_array($fact['byMonth'])) {
            $months = array_slice($fact['byMonth'], -14);
            $monthly = [
                'labels' => array_map(static fn ($m) => (string) ($m['month'] ?? ''), $months),
                'churn' => array_map(static fn ($m) => round((float) ($m['churn'] ?? 0)), $months),
                'downsell' => array_map(static fn ($m) => round((float) ($m['downsell'] ?? 0)), $months),
            ];
        }

        // Потери по продуктам (YTD)
        $byProduct = null;
        if (!empty($fact['byProduct']) && is_array($fact['byProduct'])) {
            $prods = array_slice($fact['byProduct'], 0, 8);
            $byProduct = [
                'labels' => array_map(static fn ($p) => (string) ($p['product'] ?? '?'), $prods),
                'churn' => array_map(static fn ($p) => round((float) ($p['churn'] ?? 0)), $prods),
                'downsell' => array_map(static fn ($p) => round((float) ($p['downsell'] ?? 0)), $prods),
            ];
        }

        // ENT vs SMB по месяцам (из byMonthSegment)
        $segMonthly = null;
        if (!empty($fact['byMonthSegment']) && is_array($fact['byMonthSegment'])) {
            $segKeys = array_keys($fact['byMonthSegment']);
            sort($segKeys);
            $segKeys = array_slice($segKeys, -14);
            $segMonthly = [
                'labels' => $segKeys,
                'ent' => array_map(static fn ($k) => round((float) ($fact['byMonthSegment'][$k]['ent'] ?? 0)), $segKeys),
                'smb' => array_map(static fn ($k) => round((float) ($fact['byMonthSegment'][$k]['smb'] ?? 0)), $segKeys),
            ];
        }

        return [
            'airtableBaseId' => $airtableBaseId,
            'dzGeneratedAt' => $inner['generatedAt'] ?? null,
            'churnUpdatedAt' => $churn['updatedAt'] ?? null,
            'factUpdatedAt' => $fact['updatedAt'] ?? null,
            'dzAging' => ['labels' => $agingLabels, 'values' => $agingVals],
            'churnBySegment' => ['labels' => $segLabels, 'values' => $segVals],
            'dzByManager' => ['labels' => $mgrLabels, 'values' => $mgrVals],
            'factMonthly' => $monthly,
            'factByProduct' => $byProduct,
            'factSegMonthly' => $segMonthly,
        ];
    }

    /**
     * true, если по кэшу нечего рисовать (все нули / нет сегментов и менеджеров) — имеет смысл вызвать refreshCachesFromAirtable.
     */
    public static function chartPayloadLooksEmpty(array $charts): bool
    {
        $sumAging = 0.0;
        foreach ($charts['dzAging']['values'] ?? [] as $v) {
            $sumAging += abs((float) $v);
        }
        $sumSeg = 0.0;
        foreach ($charts['churnBySegment']['values'] ?? [] as $v) {
            $sumSeg += abs((float) $v);
        }
        $sumMgr = 0.0;
        foreach ($charts['dzByManager']['values'] ?? [] as $v) {
            $sumMgr += abs((float) $v);
        }
        $hasMonthly = !empty($charts['factMonthly']['labels']);

        return $sumAging < 1.0 && $sumSeg < 1.0 && $sumMgr < 1.0 && !$hasMonthly;
    }

    /**
     * ДЗ-графики пустые (aging + менеджеры), при этом Churn/потери могут быть из кэша — нужна догрузка ДЗ.
     */
    public static function chartPayloadDzDepleted(array $charts): bool
    {
        $sumAging = 0.0;
        foreach ($charts['dzAging']['values'] ?? [] as $v) {
            $sumAging += abs((float) $v);
        }
        $sumMgr = 0.0;
        foreach ($charts['dzByManager']['values'] ?? [] as $v) {
            $sumMgr += abs((float) $v);
        }

        return $sumAging < 1.0 && $sumMgr < 1.0;
    }

    /**
     * Сумма в корзине aging: учитывает разные варианты тире в ключах JSON (en dash / hyphen).
     *
     * @param array<string, mixed> $aging
     */
    private static function agingBucketAmount(array $aging, string $canonicalKey): float
    {
        $try = [$canonicalKey, str_replace('–', '-', $canonicalKey), str_replace('–', '—', $canonicalKey)];
        foreach ($try as $k) {
            if (array_key_exists($k, $aging)) {
                return (float) $aging[$k];
            }
        }
        $norm = self::normalizeAgingKey($canonicalKey);
        foreach ($aging as $k => $v) {
            if (is_string($k) && self::normalizeAgingKey($k) === $norm) {
                return (float) $v;
            }
        }

        return 0.0;
    }

    private static function normalizeAgingKey(string $k): string
    {
        $k = str_replace(['–', '—', '−'], '-', $k);

        return mb_strtolower(trim($k));
    }

    /**
     * Подписи под графиками ДЗ при пустых данных.
     *
     * @return array{aging: string, managers: string}
     */
    public static function chartHintsFromCharts(array $charts): array
    {
        $sumA = 0.0;
        foreach ($charts['dzAging']['values'] ?? [] as $v) {
            $sumA += abs((float) $v);
        }

        return [
            'aging' => $sumA < 1.0
                ? 'В корзинах нет сумм — кэш дебиторки пустой или не обновлён. Откройте «ДЗ» или дождитесь фоновой синхронизации.'
                : '',
            'managers' => empty($charts['dzByManager']['labels'])
                ? 'Нет разбивки по менеджерам: в данных нет заполненного «Аккаунт менеджер» по счетам или кэш ДЗ не подтянут.'
                : '',
        ];
    }

    /** JSON-строка для промпта (ограниченный размер). */
    public static function promptContext(string $dir, string $airtableBaseId): string
    {
        $bundle = self::buildBundle($dir, $airtableBaseId);
        $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return '{}';
        }
        if (strlen($json) > 280000) {
            $bundle['note'] = 'Данные усечены по размеру.';
            $bundle['churn']['clients'] = array_slice($bundle['churn']['clients'] ?? [], 0, 12);
            $bundle['dz']['topLegal'] = array_slice($bundle['dz']['topLegal'] ?? [], 0, 8);
            $bundle['dz']['rowSamples'] = array_slice($bundle['dz']['rowSamples'] ?? [], 0, 12);
            if (isset($bundle['factLosses']['churnLinesSample'])) {
                $bundle['factLosses']['churnLinesSample'] = array_slice($bundle['factLosses']['churnLinesSample'], 0, 20);
            }
            if (isset($bundle['factLosses']['downsellLinesSample'])) {
                $bundle['factLosses']['downsellLinesSample'] = array_slice($bundle['factLosses']['downsellLinesSample'], 0, 20);
            }
            $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }
        return $json;
    }

    /** @return array<string, mixed> */
    private static function buildBundle(string $dir, string $airtableBaseId): array
    {
        $dzRaw = self::loadJson($dir . '/dz-data-default.json');
        $churn = self::loadJson($dir . '/churn-report.json');
        $fact = self::loadJson($dir . '/churn-fact-report.json');
        $inner = self::dzInner($dzRaw);

        $kpi = $inner['kpi'] ?? [];
        $mrr = 0.0;
        $mrrObj = $inner['mrr'] ?? null;
        if (is_array($mrrObj) && isset($mrrObj['value'])) {
            $mrr = (float) $mrrObj['value'];
        }
        $aging = $inner['aging'] ?? [];
        $totalDebt = (float) ($kpi['totalDebt'] ?? 0);
        $overdue = (float) ($kpi['overdueDebt'] ?? 0);
        $debtToMrr = ($mrr > 0 && $totalDebt > 0) ? round($totalDebt / $mrr * 100, 1) : null;

        $topLegal = [];
        foreach (array_slice($inner['topLegal'] ?? [], 0, self::MAX_TOP_LEGAL) as $t) {
            $topLegal[] = [
                'name' => (string) ($t['name'] ?? ''),
                'amount' => round((float) ($t['amount'] ?? 0)),
                'invoices' => (int) ($t['count'] ?? 0),
            ];
        }

        $rows = [];
        $rawRows = $inner['rows'] ?? [];
        usort($rawRows, static function ($a, $b) {
            return ((float) ($b['amount'] ?? 0)) <=> ((float) ($a['amount'] ?? 0));
        });
        foreach (array_slice($rawRows, 0, self::MAX_ROWS_SAMPLE) as $r) {
            $comment = (string) ($r['nextStep'] ?? $r['comment'] ?? '');
            if (strlen($comment) > self::MAX_COMMENT_LEN) {
                $comment = mb_substr($comment, 0, self::MAX_COMMENT_LEN) . '…';
            }
            $rows[] = [
                'legal' => (string) ($r['legal'] ?? ''),
                'amount' => round((float) ($r['amount'] ?? 0)),
                'daysOverdue' => (int) ($r['daysOverdue'] ?? 0),
                'aging' => (string) ($r['agingBucket'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'manager' => (string) ($r['managers'][0] ?? ''),
                'note' => $comment,
            ];
        }

        $churnClients = [];
        $clist = $churn['clients'] ?? [];
        usort($clist, static function ($a, $b) {
            return ((float) ($b['mrrAtRisk'] ?? 0)) <=> ((float) ($a['mrrAtRisk'] ?? 0));
        });
        foreach (array_slice($clist, 0, self::MAX_CLIENTS_CHURN) as $c) {
            $churnClients[] = [
                'account' => (string) ($c['account'] ?? ''),
                'probability' => (int) ($c['probability'] ?? 0),
                'vertical' => (string) ($c['vertical'] ?? ''),
                'segment' => (string) ($c['segment'] ?? ''),
                'csm' => (string) ($c['csm'] ?? ''),
                'mrrAtRisk' => round((float) ($c['mrrAtRisk'] ?? 0)),
                'products' => $c['products'] ?? [],
            ];
        }

        $factLosses = self::buildFactLossesForAi($fact);
        $bundle = self::bundleFromParts($airtableBaseId, $inner, $kpi, $mrr, $aging, $totalDebt, $overdue, $debtToMrr, $topLegal, $rows, $churn, $churnClients, $factLosses);
        $bundle['churn'] = array_merge($bundle['churn'], self::churnExtrasForAi($churn));
        $bundle['dz'] = array_merge($bundle['dz'], self::dzExtrasForAi($inner));
        $bundle['crossDashboard'] = self::loadCrossDashboardCaches($dir);
        $bundle['source'] = 'Для этого ответа данные получены запросами к Airtable и отчётам непосредственно перед вызовом модели (рабочие файлы cache — результат свежего fetch, не «угадывание» по старому снимку). В JSON могут быть не все разделы — опирайся только на непустые поля.';

        return $bundle;
    }

    /**
     * Потери (факт): полная картина для LLM — помимо YTD, срезы по месяцам, продуктам, CSM, причинам, выборки строк.
     *
     * @param array<string, mixed> $fact
     * @return array<string, mixed>|null
     */
    private static function buildFactLossesForAi(array $fact): ?array
    {
        if ($fact === []) {
            return null;
        }
        $out = [
            'updatedAt' => $fact['updatedAt'] ?? null,
            'year' => $fact['year'] ?? null,
            'churnYtd' => round((float) ($fact['churnYtd'] ?? 0)),
            'downsellYtd' => round((float) ($fact['downsellYtd'] ?? 0)),
            'totalYtd' => round((float) ($fact['totalYtd'] ?? 0)),
            'entYtd' => round((float) ($fact['entYtd'] ?? 0)),
            'smbYtd' => round((float) ($fact['smbYtd'] ?? 0)),
            'targetTotal' => round((float) ($fact['targetTotal'] ?? 8_200_000)),
            'targetEnt' => $fact['targetEnt'] ?? null,
            'targetSmb' => $fact['targetSmb'] ?? null,
            'forecastYear' => round((float) ($fact['forecastYear'] ?? 0)),
            'forecastEnt' => round((float) ($fact['forecastEnt'] ?? 0)),
            'forecastSmb' => round((float) ($fact['forecastSmb'] ?? 0)),
            'devEntPct' => $fact['devEntPct'] ?? null,
            'devSmbPct' => $fact['devSmbPct'] ?? null,
            'byMonth' => array_slice($fact['byMonth'] ?? [], -14),
            'byMonthSegment' => $fact['byMonthSegment'] ?? [],
            'byProduct' => array_slice($fact['byProduct'] ?? [], 0, 25),
            'byCsm' => array_slice($fact['byCsm'] ?? [], 0, 22),
            'byChurnReason' => array_slice($fact['byChurnReason'] ?? [], 0, 22),
            'byDsReason' => array_slice($fact['byDsReason'] ?? [], 0, 22),
            'byVertical' => array_slice($fact['byVertical'] ?? [], 0, 22),
            'churnLinesSample' => array_slice($fact['churnDetail'] ?? [], 0, 50),
            'downsellLinesSample' => array_slice($fact['dsDetail'] ?? [], 0, 50),
        ];

        return $out;
    }

    /** @param array<string, mixed> $churn */
    private static function churnExtrasForAi(array $churn): array
    {
        return [
            'byProduct' => array_slice($churn['byProduct'] ?? [], 0, 22),
            'byCsm' => array_slice($churn['byCsm'] ?? [], 0, 22),
            'bySegmentProduct' => array_slice($churn['bySegmentProduct'] ?? [], 0, 28),
            'forecast3' => round((float) ($churn['forecast3'] ?? 0)),
            'forecast6' => round((float) ($churn['forecast6'] ?? 0)),
            'prob3count' => (int) ($churn['prob3count'] ?? 0),
            'entCount' => (int) ($churn['entCount'] ?? 0),
            'entProb3' => (int) ($churn['entProb3'] ?? 0),
            'prob3riskEnt' => round((float) ($churn['prob3riskEnt'] ?? 0)),
            'prob3riskSmb' => round((float) ($churn['prob3riskSmb'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $inner */
    private static function dzExtrasForAi(array $inner): array
    {
        return [
            'byStatus' => $inner['byStatus'] ?? [],
            'byManagerFull' => array_slice($inner['byManager'] ?? [], 0, 28),
            'byCompanyTop' => array_slice($inner['byCompany'] ?? [], 0, 22),
        ];
    }

    /**
     * Кэши с других экранов (weekly / manager): те же данные, что на графиках «Неделя» и таблицах трендов.
     *
     * @return array<string, mixed>
     */
    private static function loadCrossDashboardCaches(string $dir): array
    {
        $out = [];
        $weekly = self::loadJson($dir . '/dz-weekly-manager.json');
        if ($weekly !== []) {
            $out['dzWeeklyHistory'] = $weekly['points'] ?? $weekly;
        }
        $mrr = self::loadJson($dir . '/mrr-manager.json');
        if ($mrr !== []) {
            $out['mrrSnapshot'] = [
                'value' => $mrr['value'] ?? null,
                'yearMonth' => $mrr['yearMonth'] ?? null,
                'updatedAt' => $mrr['updatedAt'] ?? null,
                'note' => $mrr['note'] ?? null,
            ];
        }
        $ag = self::loadJson($dir . '/aging-transition-manager.json');
        if ($ag !== []) {
            $out['agingExtraBuckets'] = $ag;
        }
        $ct = self::loadJson($dir . '/client-trend-manager.json');
        if ($ct !== [] && is_array($ct)) {
            $top = [];
            $n = 0;
            foreach ($ct as $name => $delta) {
                if ($n++ >= 40) {
                    break;
                }
                $top[] = ['client' => (string) $name, 'weekDeltaRub' => round((float) $delta)];
            }
            $out['clientDebtWeekDeltaTop'] = $top;
        }

        return $out;
    }

    /**
     * Компактные числа для истории снимков (без длинных списков клиентов).
     *
     * @return array<string, mixed>
     */
    public static function metricsSnapshot(string $dir, string $airtableBaseId): array
    {
        $b = self::buildBundle($dir, $airtableBaseId);
        $dz = $b['dz'] ?? [];
        $k = $dz['kpi'] ?? [];
        $ag = $dz['aging'] ?? [];
        $ch = $b['churn'] ?? [];
        $f = $b['factLosses'] ?? null;
        return [
            'dzTotal' => (int) round((float) ($k['totalDebt'] ?? 0)),
            'dzOverdue' => (int) round((float) ($k['overdueDebt'] ?? 0)),
            'debtToMrrPct' => $k['debtToMrrPct'] ?? null,
            'aging91p' => (int) round((float) ($ag['91+'] ?? $ag['90+'] ?? 0)),
            'aging90p' => (int) round((float) ($ag['91+'] ?? $ag['90+'] ?? 0)), // обратная совместимость
            'churnRisk' => (int) round((float) ($ch['totalRiskMrr'] ?? 0)),
            'churnProb3' => (int) round((float) ($ch['prob3Mrr'] ?? 0)),
            'churnClients' => (int) ($ch['clientsAtRisk'] ?? 0),
            'factTotalYtd' => is_array($f) ? (int) round((float) ($f['totalYtd'] ?? 0)) : null,
            'factChurnYtd' => is_array($f) ? (int) round((float) ($f['churnYtd'] ?? 0)) : null,
            'factDownsellYtd' => is_array($f) ? (int) round((float) ($f['downsellYtd'] ?? 0)) : null,
        ];
    }

    /**
     * @param array<string, mixed> $inner
     * @param array<string, mixed> $kpi
     * @param list<array<string, mixed>> $topLegal
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $churn
     * @param list<array<string, mixed>> $churnClients
     * @param array<string, mixed>|null $factLosses
     * @return array<string, mixed>
     */
    private static function bundleFromParts(
        string $airtableBaseId,
        array $inner,
        array $kpi,
        float $mrr,
        array $aging,
        float $totalDebt,
        float $overdue,
        ?float $debtToMrr,
        array $topLegal,
        array $rows,
        array $churn,
        array $churnClients,
        ?array $factLosses
    ): array {
        return [
            'source' => 'Кэш дашборда (те же данные, что и таблицы/графики: Airtable → отчёты ДЗ, Churn, потери выручки).',
            'airtableBaseId' => $airtableBaseId,
            'dz' => [
                'generatedAt' => $inner['generatedAt'] ?? null,
                'kpi' => [
                    'totalDebt' => round($totalDebt),
                    'overdueDebt' => round($overdue),
                    'invoiceCount' => (int) ($kpi['invoiceCount'] ?? 0),
                    'legalEntityCount' => (int) ($kpi['legalEntityCount'] ?? 0),
                    'mrr' => round($mrr),
                    'debtToMrrPct' => $debtToMrr,
                ],
                'aging' => [
                    '0-15'  => round(self::agingBucketAmount($aging, '0-15')),
                    '16-30' => round(self::agingBucketAmount($aging, '16-30')),
                    '31-60' => round(self::agingBucketAmount($aging, '31-60')),
                    '61-90' => round(self::agingBucketAmount($aging, '61-90')),
                    '91+'   => round(self::agingBucketAmount($aging, '91+')),
                ],
                'topLegal' => $topLegal,
                'rowSamples' => $rows,
            ],
            'churn' => [
                'updatedAt' => $churn['updatedAt'] ?? null,
                'totalRiskMrr' => round((float) ($churn['totalRisk'] ?? 0)),
                'prob3Mrr' => round((float) ($churn['prob3mrr'] ?? 0)),
                'clientsAtRisk' => (int) ($churn['count'] ?? 0),
                'forecast3' => round((float) ($churn['forecast3'] ?? 0)),
                'bySegment' => $churn['bySegment'] ?? [],
                'byVertical' => array_slice($churn['byVertical'] ?? [], 0, 18),
                'clients' => $churnClients,
            ],
            'factLosses' => $factLosses,
        ];
    }

    /** @return array<string, mixed> */
    private static function loadJson(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Содержимое cache/dz-data-default.json: обёртка { ok, data } или плоский снимок (legacy).
     *
     * @param array<string, mixed> $dzRoot
     * @return array<string, mixed>
     */
    public static function unwrapDzCache(array $dzRoot): array
    {
        return self::dzInner($dzRoot);
    }

    /** @param array<string, mixed> $dzRoot */
    private static function dzInner(array $dzRoot): array
    {
        if (!isset($dzRoot['data']) && (isset($dzRoot['kpi']) || isset($dzRoot['totalDebt']))) {
            return $dzRoot;
        }
        $d = $dzRoot['data'] ?? [];
        if (isset($d['data']['kpi'])) {
            return $d['data'];
        }
        if (isset($d['kpi'])) {
            return $d;
        }
        return is_array($d) ? $d : [];
    }

    private static function shortMgr(string $email): string
    {
        if (str_contains($email, '@')) {
            return explode('@', $email, 2)[0];
        }
        return mb_substr($email, 0, 18);
    }

    /**
     * Перед вызовом LLM: актуальные данные из Airtable (дебиторка, churn-вьюхи),
     * затем потери (Google Sheets + расчёт), затем отчёт руководителя (недели, MRR, тренды клиентов).
     * Перезаписывает cache/dz-data-default.json, churn-report.json, churn-fact-report.json и вспомогательные файлы manager.
     *
     * @param array<string, mixed> $c Результат dashboard_config()
     */
    public static function refreshCachesFromAirtable(array $c): void
    {
        require_once __DIR__ . '/Airtable.php';
        require_once __DIR__ . '/DzReport.php';
        require_once __DIR__ . '/ChurnReport.php';
        require_once __DIR__ . '/ChurnFactReport.php';
        require_once __DIR__ . '/ManagerReport.php';

        $pat = trim((string) ($c['airtable_pat'] ?? ''));
        if ($pat === '') {
            throw new \RuntimeException('Не задан AIRTABLE_PAT.');
        }
        $base = trim((string) ($c['airtable_base_id'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException('Не задан AIRTABLE_BASE_ID.');
        }

        $cacheDir = self::cacheDir();
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException('Не удалось создать каталог cache.');
        }

        $payload = DzReport::fetchPayload(
            $pat,
            $base,
            (string) ($c['airtable_dz_table_id'] ?? ''),
            (string) ($c['airtable_dz_view_id'] ?? ''),
            (string) ($c['airtable_cs_table_id'] ?? ''),
            (string) ($c['airtable_churn_table_id'] ?? ''),
            '',
            (string) ($c['airtable_cs_view_id'] ?? ''),
            (string) ($c['airtable_churn_view_id'] ?? ''),
            (string) ($c['airtable_paid_view_id'] ?? '')
        );
        $dzFile = $cacheDir . '/dz-data-default.json';
        $toCache = ['ok' => true, 'schemaVersion' => 1, 'data' => $payload];
        if (file_put_contents($dzFile, json_encode($toCache, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            throw new \RuntimeException('Не удалось записать dz-data-default.json.');
        }

        @unlink($cacheDir . '/churn-report.json');
        $churnRisk = [];
        try {
            $churnRisk = ChurnReport::fetchReport($pat, $base);
        } catch (\Throwable $e) {
            // Churn некритичен для основного анализа ДЗ — логируем и продолжаем
            AiInsightsSupport::logLine('churn_fetch_warn', ['err' => $e->getMessage()]);
        }

        @unlink($cacheDir . '/churn-fact-report.json');
        try {
            ChurnFactReport::fetchReport(
                $pat,
                $base,
                (float) ($churnRisk['prob3mrr'] ?? 0),
                (float) ($churnRisk['prob3riskEnt'] ?? 0),
                (float) ($churnRisk['prob3riskSmb'] ?? 0)
            );
        } catch (\Throwable $e) {
            AiInsightsSupport::logLine('churn_fact_fetch_warn', ['err' => $e->getMessage()]);
        }

        try {
            ManagerReport::fetchReport($pat, $base);
        } catch (\Throwable $e) {
            AiInsightsSupport::logLine('manager_fetch_warn', ['err' => $e->getMessage()]);
        }
    }
}

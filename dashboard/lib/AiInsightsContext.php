<?php
declare(strict_types=1);

/**
 * Сбор компактного контекста для LLM и данных для графиков (кэши дашборда = снимок Airtable).
 */
final class AiInsightsContext
{
    private const MAX_CLIENTS_CHURN = 22;
    private const MAX_TOP_LEGAL = 18;
    private const MAX_ROWS_SAMPLE = 24;
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
        $agingLabels = ['0–30', '31–60', '61–90', '90+'];
        $agingVals = [];
        foreach ($agingLabels as $k) {
            $agingVals[] = round((float) ($aging[$k] ?? 0));
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

        return [
            'airtableBaseId' => $airtableBaseId,
            'dzGeneratedAt' => $inner['generatedAt'] ?? null,
            'churnUpdatedAt' => $churn['updatedAt'] ?? null,
            'factUpdatedAt' => $fact['updatedAt'] ?? null,
            'dzAging' => ['labels' => $agingLabels, 'values' => $agingVals],
            'churnBySegment' => ['labels' => $segLabels, 'values' => $segVals],
            'dzByManager' => ['labels' => $mgrLabels, 'values' => $mgrVals],
            'factMonthly' => $monthly,
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
        if (strlen($json) > 220000) {
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
        $bundle['source'] = 'Сводка по всем основным кэшам дашборда: ДЗ (dz-data-default), Churn (churn-report), потери YTD (churn-fact-report), плюс недельный тренд ДЗ, MRR, топ изменений долга по клиентам — те же файлы, что питают главную, ДЗ, Churn, «Потери», еженедельный отчёт.';

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
            'aging90p' => (int) round((float) ($ag['90+'] ?? 0)),
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
                    '0–30' => round((float) ($aging['0–30'] ?? 0)),
                    '31–60' => round((float) ($aging['31–60'] ?? 0)),
                    '61–90' => round((float) ($aging['61–90'] ?? 0)),
                    '90+' => round((float) ($aging['90+'] ?? 0)),
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

    /** @param array<string, mixed> $dzRoot */
    private static function dzInner(array $dzRoot): array
    {
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
}

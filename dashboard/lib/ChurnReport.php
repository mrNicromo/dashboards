<?php
declare(strict_types=1);

final class ChurnReport
{
    private const TABLE = 'tblIKAi1gcFayRJTn';
    private const VIEW  = 'viwBPiUGNh0PMLeV1'; // Угроза Churn

    /** Маппинг email → имя CSM */
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

    private static function normCsm(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        foreach (self::CSM_MAP as $email => $name) {
            if ($key === mb_strtolower($email)) return $name;
        }
        return $raw;
    }
    private const CACHE        = __DIR__ . '/../cache/churn-report.json';
    private const CACHE_BACKUP = __DIR__ . '/../cache/churn-report.backup.json';
    private const TTL_FRESH  = 900;  // 15 min — подаём без вопросов
    private const TTL_STALE  = 1800; // 30 min — подаём с флагом _stale для фонового обновления
    /** @deprecated используйте TTL_FRESH */
    private const TTL        = self::TTL_FRESH;

    /** MRR field and Stage field per product (ключи = единые отображаемые имена) */
    private const PRODUCTS = [
        'AnyQuery'       => ['stage' => 'Customer Stage AQ',         'mrr' => 'AQ MRR'],
        'AnyRecs'        => ['stage' => 'Customer Stage Recs',       'mrr' => 'Recs MRR'],
        'AnyImages'      => ['stage' => 'Customer Stage AnyImages',  'mrr' => 'AnyImages MRR'],
        'AnyReviews'     => ['stage' => 'Customer Stage AnyReviews', 'mrr' => 'AnyReviews MRR'],
        'AnyCollections' => ['stage' => 'Customer Stage AC',         'mrr' => 'AC MRR'],
        'APP'            => ['stage' => 'Customer Stage AQ APP',     'mrr' => 'APP MRR'],
        'Rees46'         => ['stage' => 'Customer Stage Rees46',     'mrr' => 'Rees46 MRR'],
    ];

    /** Segment thresholds by total MRR */
    private const SEGMENTS = [
        'ENT'  => 100000,
        'SME+' =>  70000,
        'SME'  =>  50000,
        'SME-' =>  30000,
        'SMB'  =>  15000,
        'SS'   =>      0,
    ];

    // ------------------------------------------------------------------ //

    /**
     * Читает кэш без обращения к Airtable.
     * Возвращает данные если кэш свежий/устаревший, null если кэш слишком старый или отсутствует.
     */
    public static function getCached(): ?array
    {
        if (!is_readable(self::CACHE)) return null;
        $cached = json_decode(file_get_contents(self::CACHE) ?: '', true);
        if (!is_array($cached)) return null;
        $age = time() - ($cached['_ts'] ?? 0);
        if ($age >= self::TTL_STALE) return null;
        if ($age >= self::TTL_FRESH) $cached['_stale'] = true;
        return $cached;
    }

    public static function fetchReport(string $pat, string $baseId): array
    {
        // ── Stale-while-revalidate (M4) ───────────────────────────
        // Свежий кэш (< 15 мин) — отдаём сразу
        // Устаревший кэш (15–30 мин) — отдаём с флагом _stale, JS сделает фоновый обновление
        // Очень старый (> 30 мин) — блокируем и фетчим заново
        if (is_readable(self::CACHE)) {
            $cached = json_decode(file_get_contents(self::CACHE) ?: '', true);
            if (is_array($cached)) {
                $age = time() - ($cached['_ts'] ?? 0);
                if ($age < self::TTL_FRESH) {
                    return $cached;                    // полностью свежий
                }
                if ($age < self::TTL_STALE) {
                    $cached['_stale'] = true;          // слегка устаревший — отдаём и просим обновить
                    return $cached;
                }
                // > 30 мин — фетчим принудительно (код ниже)
            }
        }

        try {
            $records = Airtable::fetchAllPages(
                $baseId,
                self::TABLE,
                [
                    'view'        => self::VIEW,
                    'cellFormat'  => 'string',
                    'timeZone'    => 'Europe/Moscow',
                    'userLocale'  => 'ru',
                ],
                $pat
            );

            $report = self::build($records);
            $report['_ts'] = time();

            if (!is_dir(dirname(self::CACHE))) mkdir(dirname(self::CACHE), 0775, true);
            $json = json_encode($report, JSON_UNESCAPED_UNICODE);
            file_put_contents(self::CACHE, $json);
            file_put_contents(self::CACHE_BACKUP, $json); // резервная копия на случай недоступности Airtable

            return $report;
        } catch (Throwable $e) {
            // Airtable недоступен — отдаём резервный кэш если есть
            if (is_readable(self::CACHE_BACKUP)) {
                $backup = json_decode(file_get_contents(self::CACHE_BACKUP) ?: '', true);
                if (is_array($backup)) {
                    $backup['_stale']  = true;
                    $backup['_backup'] = true;
                    return $backup;
                }
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ //

    private static function build(array $records): array
    {
        $clients    = [];
        $rawCount   = count($records);
        $stagesSeen = [];   // для отладки: уникальные значения стадий

        foreach ($records as $rec) {
            $f = $rec['fields'] ?? [];

            // ── Account name ──────────────────────────────────────────
            $account = self::str($f['Account'] ?? $f['Аккаунт'] ?? null);
            if ($account === '') continue;

            // ── Per-product: sum MRR и находим продукты с угрозой ─────
            $atRiskProducts = [];
            $productMrr     = [];
            $mrrAtRisk      = 0.0;
            $totalMrr       = 0.0;

            foreach (self::PRODUCTS as $prod => $cfg) {
                $stage = self::str($f[$cfg['stage']] ?? null);
                $mrr   = self::amt($f[$cfg['mrr']] ?? null);
                $totalMrr += $mrr;

                // Сбор уникальных значений стадий для отладки
                if ($stage !== '' && count($stagesSeen) < 30 && !in_array($stage, $stagesSeen, true)) {
                    $stagesSeen[] = $stage;
                }

                // Нечувствительное к регистру сравнение стадии
                if (mb_strtolower(trim($stage)) === 'угроза churn') {
                    $atRiskProducts[] = $prod;
                    $productMrr[$prod] = $mrr;
                    $mrrAtRisk += $mrr;
                }
            }

            // ── Раз запись в вью "Угроза Churn" — она at-risk по умолчанию.
            // Если ни одно поле Customer Stage не совпало (другое название),
            // используем суммарный MRR клиента как mrrAtRisk.
            if (empty($atRiskProducts)) {
                // Добавляем все продукты с ненулевым MRR как at-risk
                foreach (self::PRODUCTS as $prod => $cfg) {
                    $mrr = self::amt($f[$cfg['mrr']] ?? null);
                    if ($mrr > 0) {
                        $atRiskProducts[] = $prod;
                        $productMrr[$prod] = $mrr;
                    }
                }
                $mrrAtRisk = $totalMrr; // весь MRR под угрозой
            }

            // Если у клиента вообще нет MRR ни в одном продукте — пропускаем
            if ($totalMrr <= 0 && $mrrAtRisk <= 0) continue;

            // ── Дополнительные поля ───────────────────────────────────
            $probRaw  = self::str($f['Вероятность угрозы'] ?? $f['Probability'] ?? null);
            $prob     = $probRaw !== '' ? (int)$probRaw : null;
            $vertical = self::str($f['Вертикаль'] ?? $f['Vertical'] ?? null);
            $csm      = self::normCsm(self::str($f['CSM NEW'] ?? $f['CSM'] ?? null));

            $segRaw  = self::str($f['Segment'] ?? $f['Сегмент'] ?? null);
            $segment = $segRaw !== '' ? $segRaw : self::calcSegment($totalMrr);

            $clients[] = [
                'account'    => $account,
                'probability'=> $prob,
                'vertical'   => $vertical !== '' ? $vertical : '—',
                'csm'        => $csm !== '' ? $csm : 'Не указан',
                'segment'    => $segment,
                'totalMrr'   => round($totalMrr,  2),
                'mrrAtRisk'  => round($mrrAtRisk,  2),
                'products'   => $atRiskProducts,
                'productMrr' => $productMrr,
            ];
        }

        // ── Sort clients by MRR at risk desc ──────────────────────────
        usort($clients, static fn($a, $b) => $b['mrrAtRisk'] <=> $a['mrrAtRisk']);

        // ── Totals ────────────────────────────────────────────────────
        $totalRisk  = array_sum(array_column($clients, 'mrrAtRisk'));
        $prob3Cli   = array_filter($clients, static fn($c) => $c['probability'] === 3);
        $prob2Cli   = array_filter($clients, static fn($c) => $c['probability'] === 2);
        $prob3mrr   = array_sum(array_column([...$prob3Cli], 'mrrAtRisk'));
        $prob2mrr   = array_sum(array_column([...$prob2Cli], 'mrrAtRisk'));
        $entCli     = array_filter($clients, static fn($c) => $c['segment'] === 'ENT');
        $entProb3   = array_filter($clients, static fn($c) => $c['segment'] === 'ENT' && $c['probability'] === 3);

        // ── Aggregations ──────────────────────────────────────────────
        $bySegment        = self::aggBySegment($clients);
        $byProduct        = self::aggByProduct($clients);
        $byCsm            = self::aggByCsm($clients);
        $byVertical       = self::aggByVertical($clients);
        $bySegmentProduct = self::aggBySegmentProduct($clients);

        // prob3risk split ENT vs non-ENT — для прогноза в ChurnFactReport
        $prob3riskEnt = 0.0;
        $prob3riskSmb = 0.0;
        foreach ($clients as $c) {
            if ($c['probability'] === 3) {
                if ($c['segment'] === 'ENT') $prob3riskEnt += $c['mrrAtRisk'];
                else                          $prob3riskSmb += $c['mrrAtRisk'];
            }
        }

        return [
            'updatedAt'        => date('Y-m-d H:i'),
            'totalRisk'        => $totalRisk,
            'count'            => count($clients),
            'prob3mrr'         => $prob3mrr,
            'prob3count'       => count([...$prob3Cli]),
            'prob3riskEnt'     => round($prob3riskEnt, 2),
            'prob3riskSmb'     => round($prob3riskSmb, 2),
            'entCount'         => count([...$entCli]),
            'entProb3'         => count([...$entProb3]),
            'forecast3'        => round($prob3mrr + $prob2mrr,       2), // prob 2+3
            'forecast6'        => round($totalRisk,                   2), // all = 1+2+3
            'clients'          => array_values($clients),
            'bySegment'        => $bySegment,
            'byProduct'        => $byProduct,
            'byCsm'            => $byCsm,
            'byVertical'       => $byVertical,
            'bySegmentProduct' => $bySegmentProduct,
            // Отладочные поля
            '_rawCount'        => $rawCount,
            '_stages'          => $stagesSeen,
        ];
    }

    // ── Aggregations ──────────────────────────────────────────────────

    private static function aggBySegment(array $clients): array
    {
        $order = ['ENT', 'SME+', 'SME', 'SME-', 'SMB', 'SS'];
        $out   = [];
        foreach ($clients as $c) {
            $s = $c['segment'];
            if (!isset($out[$s])) $out[$s] = ['segment'=>$s,'mrr'=>0.0,'count'=>0,'prob'=>[1=>0.0,2=>0.0,3=>0.0,null=>0.0]];
            $out[$s]['mrr']   += $c['mrrAtRisk'];
            $out[$s]['count'] += 1;
            $out[$s]['prob'][$c['probability'] ?? null] = ($out[$s]['prob'][$c['probability'] ?? null] ?? 0.0) + $c['mrrAtRisk'];
        }
        // Sort by defined order
        uksort($out, static fn($a,$b) => (array_search($a,$order,true)??99) <=> (array_search($b,$order,true)??99));
        return array_values($out);
    }

    private static function aggByProduct(array $clients): array
    {
        $out = [];
        foreach (array_keys(self::PRODUCTS) as $prod) {
            $mrr   = 0.0;
            $count = 0;
            foreach ($clients as $c) {
                if (isset($c['productMrr'][$prod])) {
                    $mrr   += $c['productMrr'][$prod];
                    $count += 1;
                }
            }
            if ($mrr > 0) $out[$prod] = ['product'=>$prod,'mrr'=>round($mrr,2),'count'=>$count];
        }
        uasort($out, static fn($a,$b) => $b['mrr'] <=> $a['mrr']);
        return array_values($out);
    }

    private static function aggByCsm(array $clients): array
    {
        $out = [];
        foreach ($clients as $c) {
            $m = $c['csm'];
            if (!isset($out[$m])) $out[$m] = ['csm'=>$m,'mrr'=>0.0,'count'=>0,'prob3count'=>0,'prob3mrr'=>0.0,'prob'=>[1=>0.0,2=>0.0,3=>0.0]];
            $out[$m]['mrr']   += $c['mrrAtRisk'];
            $out[$m]['count'] += 1;
            $p = $c['probability'];
            if ($p !== null) $out[$m]['prob'][$p] = ($out[$m]['prob'][$p] ?? 0.0) + $c['mrrAtRisk'];
            if ($p === 3) { $out[$m]['prob3count']++; $out[$m]['prob3mrr'] += $c['mrrAtRisk']; }
        }
        uasort($out, static fn($a,$b) => $b['mrr'] <=> $a['mrr']);
        return array_values($out);
    }

    private static function aggByVertical(array $clients): array
    {
        $out = [];
        foreach ($clients as $c) {
            $v = $c['vertical'];
            if (!isset($out[$v])) $out[$v] = ['vertical'=>$v,'mrr'=>0.0,'count'=>0,'prob'=>[1=>0.0,2=>0.0,3=>0.0]];
            $out[$v]['mrr']   += $c['mrrAtRisk'];
            $out[$v]['count'] += 1;
            $p = $c['probability'];
            if ($p !== null) $out[$v]['prob'][$p] = ($out[$v]['prob'][$p] ?? 0.0) + $c['mrrAtRisk'];
        }
        uasort($out, static fn($a,$b) => $b['mrr'] <=> $a['mrr']);
        return array_values($out);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Пивот: строки = Segment+вероятность, колонки = Products, значение = сумма MRR.
     * Формат: [['segment'=>'ENT','prob'=>3,'products'=>['AnyQuery'=>1000,'AnyRecs'=>500,...]], ...]
     */
    private static function aggBySegmentProduct(array $clients): array
    {
        $out = []; // key: "ENT::3"
        foreach ($clients as $c) {
            $seg  = $c['segment'];
            $prob = $c['probability'] ?? 0;
            $key  = $seg . '::' . $prob;
            if (!isset($out[$key])) {
                $out[$key] = [
                    'segment'  => $seg,
                    'prob'     => $prob,
                    'total'    => 0.0,
                    'products' => [],
                ];
            }
            $out[$key]['total'] += $c['mrrAtRisk'];
            foreach ($c['productMrr'] ?? [] as $prod => $mrr) {
                $out[$key]['products'][$prod] = ($out[$key]['products'][$prod] ?? 0.0) + $mrr;
            }
        }
        // Sort by segment order, then prob desc
        $segOrder = array_flip(['ENT','SME+','SME','SME-','SMB','SS']);
        uasort($out, static function ($a, $b) use ($segOrder) {
            $si = $segOrder[$a['segment']] ?? 99;
            $sj = $segOrder[$b['segment']] ?? 99;
            if ($si !== $sj) return $si <=> $sj;
            return $b['prob'] <=> $a['prob'];
        });
        return array_values($out);
    }

    private static function calcSegment(float $mrr): string
    {
        foreach (self::SEGMENTS as $name => $threshold) {
            if ($mrr >= $threshold) return $name;
        }
        return 'SS';
    }

    private static function str(mixed $v): string
    {
        if ($v === null || $v === '') return '';
        if (is_array($v)) $v = $v[0] ?? '';
        return trim((string)$v);
    }

    private static function amt(mixed $raw): float
    {
        if ($raw === null || $raw === '') return 0.0;
        $s = trim((string)$raw);
        $s = preg_replace('/[^\d.,\-]/', '', $s) ?? '';
        if ($s === '') return 0.0;
        $lc = strrpos($s, ',');
        $ld = strrpos($s, '.');
        if ($lc !== false && $ld !== false) {
            // Both present — last one is decimal separator
            if ($ld > $lc) { $s = str_replace(',', '', $s); }
            else            { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        } elseif ($lc !== false) {
            $after = substr($s, $lc + 1);
            $s = strlen($after) <= 2
                ? str_replace(',', '.', $s)
                : str_replace(',', '', $s);
        }
        return round((float)$s, 2);
    }
}

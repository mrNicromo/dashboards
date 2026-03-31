<?php
declare(strict_types=1);

/**
 * Дашборд руководителя — загрузка и обработка данных из Airtable.
 *
 * Таблица ДЗ:       tblLEQYWypaYtAcp6
 *   Вид долги:      viw977k6GUNrkeRRy  (🔸Debt 15,30,60,90 Демидова)
 *   Вид оплаты:     viwNp3aOtWxmQuKp5  (♥️Оплачено CSM)
 * Таблица Клиенты:  tblIKAi1gcFayRJTn
 *   Вид CS ALL:     viwz7G1vPxxg0WvC3
 */
final class ManagerReport
{
    private const DEBT_TABLE  = 'tblLEQYWypaYtAcp6';
    private const DEBT_VIEW   = 'viw977k6GUNrkeRRy';
    private const PAID_VIEW   = 'viwNp3aOtWxmQuKp5';
    private const CS_TABLE    = 'tblIKAi1gcFayRJTn';
    private const CS_ALL_VIEW = 'viwz7G1vPxxg0WvC3';

    /** Маппинг значений поля «Группа просрочки» → ключ для фронтенда */
    private const AGING_MAP = [
        '16 - 30 дней' => '16-30',
        '31 - 60 дней' => '31-60',
        '61 - 90 дней' => '61-90',
        '91+ дней'     => '91+',
    ];

    // ------------------------------------------------------------------ //

    /**
     * Парсит сумму из любого формата Airtable.
     * Поддерживает: "10,000.00", "10 000,50", "9 701₽", числа.
     */
    private static function parseAmount(mixed $raw): float
    {
        if (is_int($raw) || is_float($raw)) return (float) $raw;
        $s = (string) $raw;
        if ($s === '' || $s === null) return 0.0;
        // Убираем все символы кроме цифр, точки, запятой, минуса
        $s = preg_replace('/[^\d.,\-]/', '', $s);
        // Определяем формат: если запятая — разделитель тысяч (1,000.00) или дробная часть (1000,50)
        $commas = substr_count($s, ',');
        $dots   = substr_count($s, '.');
        if ($commas > 0 && $dots > 0) {
            // Оба символа — ищем последний: "1,000.50" → удалить запятые; "1.000,50" → удалить точки, запятую→точку
            if (strrpos($s, '.') > strrpos($s, ',')) {
                $s = str_replace(',', '', $s); // запятая=тысячи, точка=дробная
            } else {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s); // точка=тысячи, запятая=дробная
            }
        } elseif ($commas === 1 && $dots === 0) {
            // Только запятая: если часть после запятой <= 2 цифры — дробная, иначе тысячи
            $parts = explode(',', $s);
            if (strlen($parts[1] ?? '') <= 2) {
                $s = str_replace(',', '.', $s); // "9701,50" → дробная
            } else {
                $s = str_replace(',', '', $s);  // "10,000" → тысячи
            }
        } elseif ($commas > 1) {
            $s = str_replace(',', '', $s); // "1,000,000" → тысячи
        }
        return (float) ($s ?: '0');
    }

    public static function fetchReport(string $pat, string $baseId): array
    {
        $debtRecs   = Airtable::fetchAllPages($baseId, self::DEBT_TABLE, ['view' => self::DEBT_VIEW, 'cellFormat' => 'string', 'timeZone' => 'Europe/Moscow', 'userLocale' => 'ru'], $pat);
        $paidRecs   = Airtable::fetchAllPages($baseId, self::DEBT_TABLE, ['view' => self::PAID_VIEW], $pat);
        $mrrData    = self::computeMrr($pat, $baseId);
        [$prevWed, $thisWed] = self::weekRange();

        // Нормализованный lookup: lowercase+trim → mrr value
        // Позволяет матчить имена с незначительными расхождениями в пробелах/регистре
        $rawClientMrr = $mrrData['clientMrr'] ?? [];
        $mrrNorm = [];
        foreach ($rawClientMrr as $k => $v) {
            $mrrNorm[mb_strtolower(trim($k))] = $v;
        }

        $report = self::build($debtRecs, $paidRecs, $mrrData['value'], $prevWed, $thisWed, $rawClientMrr, $mrrNorm);

        // Еженедельная история ДЗ — serious overdue = 61-90 + 91+
        $overdueDebt = ($report['groupTotals']['61-90'] ?? 0.0) + ($report['groupTotals']['91+'] ?? 0.0);
        $report['weeklyHistory']  = DzWeeklyHistory::recordAndGet('manager', $report['totalDebt'], $overdueDebt);

        // Еженедельные оплаты — серия по неделям для чарта
        $report['weeklyPayments'] = DzWeekPayments::weeklyPaidSeries($pat, $baseId, self::DEBT_TABLE, self::PAID_VIEW);

        // Мета MRR (месяц обновления, дата, заметка)
        $report['mrrMeta'] = $mrrData;

        // Aging transition (постарение долга нед/нед)
        $report['agingTransition'] = self::resolveAgingTransition($report['groupTotals']);

        // clientMrr уже встроен в allRows/clients в build() — дублируем для обратной совместимости
        $report['clientMrr'] = $rawClientMrr;

        return $report;
    }

    // ------------------------------------------------------------------ //

    /**
     * Сравнивает текущие суммы долга по клиентам с прошлым снимком (кэш).
     * Возвращает map: clientName => 'up' | 'down' | 'same' | 'new'
     *
     * @param array<string, array{client: string, total: float}> $clients
     * @return array<string, string>
     */
    private static function resolveClientTrends(array $clients): array
    {
        $path = __DIR__ . '/../cache/client-trend-manager.json';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Загрузить прошлый снимок
        $prev = [];
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $j   = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $prev = $j;
            }
        }

        // Текущие суммы
        $curr = [];
        foreach ($clients as $c) {
            $curr[$c['client']] = $c['total'];
        }

        // Вычислить тренды
        $trends = [];
        foreach ($curr as $name => $total) {
            if (!isset($prev[$name])) {
                $trends[$name] = 'new';
            } elseif ($total > $prev[$name] + 0.01) {
                $trends[$name] = 'up';
            } elseif ($total < $prev[$name] - 0.01) {
                $trends[$name] = 'down';
            } else {
                $trends[$name] = 'same';
            }
        }

        // Сохранить текущий снимок для следующего сравнения
        file_put_contents($path, json_encode($curr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $trends;
    }

    // ------------------------------------------------------------------ //

    /**
     * Сравнивает текущие итоги по группам просрочки с прошлым снимком.
     * Возвращает по каждой группе: current, previous, delta.
     * @param array<string, float> $current
     * @return array<string, array{current: float, previous: float, delta: float}>
     */
    private static function resolveAgingTransition(array $current): array
    {
        $path = __DIR__ . '/../cache/aging-transition-manager.json';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);

        $prev = [];
        if (is_readable($path)) {
            $j = json_decode(file_get_contents($path) ?: '', true);
            if (is_array($j)) $prev = $j;
        }

        $result = [];
        foreach (['16-30', '31-60', '61-90', '91+'] as $g) {
            $c = round((float)($current[$g] ?? 0), 2);
            $p = round((float)($prev[$g]    ?? 0), 2);
            $result[$g] = ['current' => $c, 'previous' => $p, 'delta' => round($c - $p, 2)];
        }

        file_put_contents($path, json_encode($current, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    // ------------------------------------------------------------------ //

    /**
     * Возвращает MRR из кэша (обновляется раз в месяц, 1-го числа).
     * @return array{value: float, yearMonth: string, updatedAt: string, note: string}
     */
    private static function computeMrr(string $pat, string $baseId): array
    {
        $rows      = Airtable::fetchAllPages($baseId, self::CS_TABLE, ['view' => self::CS_ALL_VIEW], $pat);
        $sum       = 0.0;
        $clientMrr = [];

        // Поля MRR по продуктам (нет единого «MRR sum» — суммируем сами)
        $mrrFields = ['AQ MRR', 'Recs MRR', 'AnyImages MRR', 'AnyReviews MRR', 'AC MRR', 'APP MRR', 'Rees46 MRR'];

        foreach ($rows as $r) {
            $f = $r['fields'] ?? [];

            // Сначала пробуем готовое поле-сумму, иначе суммируем продуктовые поля
            $mrr = 0.0;
            $mrrRaw = $f['MRR sum'] ?? $f['Total MRR'] ?? $f['MRR'] ?? null;
            if ($mrrRaw !== null && $mrrRaw !== '') {
                $mrr = self::parseAmount($mrrRaw);
            } else {
                foreach ($mrrFields as $field) {
                    if (isset($f[$field]) && $f[$field] !== '') {
                        $mrr += self::parseAmount($f[$field]);
                    }
                }
            }

            if ($mrr <= 0.0) continue;

            $sum += $mrr;

            // Build clientName → MRR map
            $accountRaw = $f['Account'] ?? $f['Accounts'] ?? null;
            $name = '';
            if (is_string($accountRaw) && $accountRaw !== '') {
                $name = trim(explode(',', $accountRaw)[0]);
            } elseif (is_array($accountRaw) && !empty($accountRaw)) {
                $first = $accountRaw[0];
                $name  = is_array($first) ? (string)($first['name'] ?? '') : (string)$first;
            }
            if ($name !== '') {
                $clientMrr[$name] = round($mrr, 2);
            }
        }
        $cache = DzMrrCache::resolve('manager', $sum);
        $cache['clientMrr'] = $clientMrr;
        return $cache;
    }

    /**
     * Возвращает [предыдущая среда Y-m-d, текущая среда Y-m-d] в московском времени.
     * Период анализа: среда прошлой недели 00:00 — среда текущей недели 23:59.
     *
     * @return array{string, string}
     */
    private static function weekRange(): array
    {
        $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        $dow = (int) $now->format('N'); // 1=Пн … 7=Вс, Ср=3
        // Кол-во дней назад до ближайшей прошедшей среды
        $daysBack = ($dow < 3) ? ($dow + 4) : ($dow - 3);
        $thisWed  = clone $now;
        $thisWed->modify("-{$daysBack} days");
        $prevWed = clone $thisWed;
        $prevWed->modify('-7 days');
        return [$prevWed->format('Y-m-d'), $thisWed->format('Y-m-d')];
    }

    // ------------------------------------------------------------------ //

    private static function build(
        array $debtRecs,
        array $paidRecs,
        float $mrr,
        string $prevWed,
        string $thisWed,
        array $clientMrr = [],    // точный map: clientName => mrr
        array $mrrNorm   = []     // нормализованный map: mb_strtolower(trim(name)) => mrr
    ): array {
        // Вспомогалка для поиска MRR по имени/сайту с нормализацией.
        // clientMrr кодируется по домену (Site ID из CS ALL), поэтому приоритет — site.
        $lookupMrr = static function (string $name, string $юл, string $site = '') use ($clientMrr, $mrrNorm): float {
            // 1. По домену/сайту (самое надёжное совпадение — CS ALL хранит именно домен)
            if ($site !== '' && isset($clientMrr[$site])) return $clientMrr[$site];
            if ($site !== '') {
                $normSite = mb_strtolower(trim($site));
                if (isset($mrrNorm[$normSite])) return $mrrNorm[$normSite];
            }
            // 2. Точное совпадение по display-имени
            if (isset($clientMrr[$name])) return $clientMrr[$name];
            // 3. Точное совпадение по ЮЛ
            if (isset($clientMrr[$юл])) return $clientMrr[$юл];
            // 4. Нормализованное совпадение (trim + lowercase)
            $normName = mb_strtolower(trim($name));
            $normЮл   = mb_strtolower(trim($юл));
            return $mrrNorm[$normName] ?? $mrrNorm[$normЮл] ?? 0.0;
        };
        // ── 1. Обрабатываем строки долгов ────────────────────────────────
        $clients   = [];          // client => агрегированные данные
        $grpTotals = ['16-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91+' => 0.0];
        $totalDz   = 0.0;
        $allRows   = [];          // детальные строки для фронтенда

        foreach ($debtRecs as $r) {
            $f      = $r['fields'] ?? [];
            $grpRaw = (string) ($f['Группа просрочки'] ?? '');
            $grpKey = self::AGING_MAP[$grpRaw] ?? null;
            if ($grpKey === null) {
                continue; // пропускаем 0 дней и прочие
            }

            $юл = trim((string) ($f['ЮЛ клиента'] ?? 'Без названия'));
            if ($юл === '') $юл = 'Без названия';

            // Отображаемое имя: берём из поля Accounts / Accounts (Связи), иначе ЮЛ клиента
            // cellFormat=string возвращает имена связанных записей через запятую
            $accountsRaw = $f['Accounts'] ?? $f['Accounts (Связи)'] ?? null;
            if (is_string($accountsRaw) && $accountsRaw !== '') {
                $client = trim(explode(',', $accountsRaw)[0]);
            } elseif (is_array($accountsRaw) && !empty($accountsRaw)) {
                // без cellFormat=string — массив record ID или объектов
                $first = $accountsRaw[0];
                $client = is_array($first) ? (string)($first['name'] ?? $first['value'] ?? $юл) : $юл;
            } else {
                $client = $юл;
            }

            // Сайт клиента: лукап-поле «Accounts (Связи) (from Связи)»
            $siteRaw = $f['Accounts (Связи) (from Связи)'] ?? $f['Site'] ?? $f['Сайт'] ?? null;
            $site = '';
            if (is_string($siteRaw) && $siteRaw !== '') {
                $site = trim(explode(',', $siteRaw)[0]);
            } elseif (is_array($siteRaw) && !empty($siteRaw)) {
                $first = $siteRaw[0];
                $site = is_string($first) ? trim($first) : (string)($first['name'] ?? '');
            }

            $amount = self::parseAmount($f['Фактическая задолженность'] ?? 0);
            $mgr    = '';
            $mgrRaw = $f['Аккаунт менеджер'] ?? null;
            if (is_string($mgrRaw) && $mgrRaw !== '') {
                // cellFormat=string — уже готовая строка
                $mgr = trim(explode(',', $mgrRaw)[0]);
            } elseif (is_array($mgrRaw) && !empty($mgrRaw)) {
                $first = $mgrRaw[0];
                $mgr   = is_array($first) ? (string) ($first['name'] ?? $first['email'] ?? '') : (string) $first;
            }

            if (!isset($clients[$юл])) {
                $clients[$юл] = [
                    'client'  => $client,   // отображаемое имя (Accounts или ЮЛ)
                    'юл'      => $юл,       // внутренний ключ для матчинга оплат
                    'site'    => $site,
                    'total'   => 0.0,
                    'groups'  => ['16-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91+' => 0.0],
                    'manager' => $mgr,
                    'mrr'     => $lookupMrr($client, $юл, $site),
                ];
            } elseif ($clients[$юл]['mrr'] == 0.0 && $site !== '') {
                // Если MRR ещё не найден, пробуем по сайту при следующей встрече записи
                $clients[$юл]['mrr'] = $lookupMrr($client, $юл, $site);
            }
            $clients[$юл]['total']           += $amount;
            $clients[$юл]['groups'][$grpKey] += $amount;
            if ($site !== '' && $clients[$юл]['site'] === '') {
                $clients[$юл]['site'] = $site;
            }
            $grpTotals[$grpKey]              += $amount;
            $totalDz                         += $amount;

            $allRows[] = [
                'client'    => $client,
                'site'      => $site,
                'amount'    => $amount,
                'group'     => $grpKey,
                'invoice'   => (string) ($f['Номер счета'] ?? ''),
                'dueDate'   => (string) ($f['Срок оплаты'] ?? ''),
                'manager'   => $mgr,
                'direction' => (string) ($f['Направление'] ?? ''),
                'company'   => (string) ($f['Наша компания'] ?? ''),
                'comment'   => mb_strimwidth((string) ($f['Комментарий по ДЗ'] ?? ''), 0, 80, '…'),
                'status'    => (string) ($f['Статус оплаты'] ?? ''),
                'mrr'       => $clients[$юл]['mrr'] ?? 0.0,
            ];
        }

        // ── Агрегация по менеджерам ───────────────────────────────────────────
        $byMgr     = [];
        $mgrMrrMap = []; // manager → сумма MRR его клиентов (для % портфеля)
        foreach ($clients as $c) {
            $m = $c['manager'] !== '' ? $c['manager'] : 'Не указан';
            if (!isset($byMgr[$m])) {
                $byMgr[$m] = [
                    'manager' => $m,
                    'total'   => 0.0,
                    'groups'  => ['16-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91+' => 0.0],
                    'clients' => 0,
                ];
            }
            $byMgr[$m]['total']   += $c['total'];
            $byMgr[$m]['clients'] += 1;
            foreach (['16-30', '31-60', '61-90', '91+'] as $g) {
                $byMgr[$m]['groups'][$g] += $c['groups'][$g];
            }
            // Суммируем MRR клиентов менеджера для расчёта % портфеля
            $mgrMrrMap[$m] = ($mgrMrrMap[$m] ?? 0.0) + $c['mrr'];
        }
        usort($byMgr, static fn($a, $b) => $b['total'] <=> $a['total']);

        // Сортируем клиентов по сумме долга, берём TOP-10
        usort($clients, static fn($a, $b) => $b['total'] <=> $a['total']);
        $allClients = array_values($clients);
        $top10      = array_slice($allClients, 0, 10);
        $top10Total = array_sum(array_column($top10, 'total'));
        $top10Pct   = $totalDz > 0 ? round($top10Total / $totalDz * 100, 1) : 0.0;

        // ── 2. Обрабатываем оплаты за неделю ─────────────────────────────
        $paidEntries = []; // все отдельные оплаты с датой
        $payByClient = []; // агрегировано по ЮЛ: [total, lastDate]

        foreach ($paidRecs as $r) {
            $f       = $r['fields'] ?? [];
            $payDate = (string) ($f['Дата оплаты счета'] ?? '');
            // Дата может прийти как "2025-03-20T00:00:00.000Z" — берём первые 10 символов
            if (strlen($payDate) > 10) $payDate = substr($payDate, 0, 10);
            if ($payDate === '' || $payDate < $prevWed || $payDate > $thisWed) {
                continue;
            }
            $client = trim((string) ($f['ЮЛ клиента'] ?? ''));
            if ($client === '') {
                continue;
            }
            $amount = self::parseAmount($f['Сумма счета'] ?? $f['Фактическая задолженность'] ?? 0);
            $paidEntries[] = ['client' => $client, 'amount' => $amount, 'date' => $payDate];

            if (!isset($payByClient[$client])) {
                $payByClient[$client] = ['total' => 0.0, 'lastDate' => $payDate];
            }
            $payByClient[$client]['total'] += $amount;
            if ($payDate > $payByClient[$client]['lastDate']) {
                $payByClient[$client]['lastDate'] = $payDate;
            }
        }

        // ТОП-5 крупнейших оплат (по отдельным записям)
        usort($paidEntries, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        $top5 = array_slice($paidEntries, 0, 5);

        // Кто из TOP-10 дебиторов оплатил на этой неделе (сравниваем по ЮЛ)
        $fromTop10 = [];
        foreach ($top10 as $c) {
            $юл = $c['юл'] ?? $c['client'];
            if (isset($payByClient[$юл])) {
                $fromTop10[] = [
                    'client' => $c['client'],
                    'amount' => $payByClient[$юл]['total'],
                    'date'   => $payByClient[$юл]['lastDate'],
                ];
            }
        }

        // ── 3. Тренд по клиентам (нед/нед) ───────────────────────────────
        $clientTrends = self::resolveClientTrends($clients);

        // ── 4. Кол-во счётов и % от ДЗ клиента — добавляем в allRows ──────
        $clientInvoiceCounts = [];
        foreach ($allRows as $row) {
            $clientInvoiceCounts[$row['client']] = ($clientInvoiceCounts[$row['client']] ?? 0) + 1;
        }

        // ── 5. Формируем итог ─────────────────────────────────────────────
        return [
            'generatedAt'     => (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i'),
            'weekStart'       => $prevWed,
            'weekEnd'         => $thisWed,
            'totalDebt'       => $totalDz,
            'groupTotals'     => $grpTotals,
            'top10'           => $top10,
            'top10Total'      => $top10Total,
            'top10Percent'    => $top10Pct,
            'mrr'             => $mrr,
            'debtToRevPct'    => $mrr > 0 ? round($totalDz / $mrr * 100, 1) : 0.0,
            'payments'        => [
                'top5'      => $top5,
                'fromTop10' => $fromTop10,
                'weekTotal' => array_sum(array_column(array_values($payByClient), 'total')),
                'count'     => count($payByClient),
            ],
            'allRows'         => $allRows,    // все строки для клиентской фильтрации
            'allClients'      => $allClients, // все клиенты (не только TOP-10)
            'byManager'       => array_values($byMgr), // агрегация по менеджерам
            'managerMrr'      => $mgrMrrMap,  // manager → суммарный MRR его портфеля
            'clientTrends'    => $clientTrends,    // тренд нед/нед: up|down|same|new
            'clientInvoiceCounts' => $clientInvoiceCounts, // кол-во счётов по клиенту
            'weeklyHistory'   => [],   // заполняется в fetchReport()
            'weeklyPayments'  => [],   // заполняется в fetchReport()
            'mrrMeta'         => [],   // заполняется в fetchReport()
        ];
    }
}

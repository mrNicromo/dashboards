<?php
declare(strict_types=1);

final class DzReport
{
    /**
     * Таблица с дебиторкой в Airtable (точное имя). Если в config не задан airtable_dz_table_id,
     * ID ищется через Meta API по этому имени.
     */
    private const DZ_TABLE_NAME = '🔸Debt (15,30,60,90)(Демидова) copy';

    /** Справочник клиентов для дашборда (полный импорт строк). */
    private const CS_ALL_TABLE_NAME = 'CS ALL';

    /** Прогноз оттока — сшивается с клиентами по тому же ключу, что CS ALL / ЮЛ. */
    private const CHURN_TABLE_NAME = '❤️ CHURN Prediction ☠️';

    /** Поля в порядке приоритета для названия клиента в списке CS ALL. */
    private const CS_ALL_LABEL_KEYS = [
        'ЮЛ клиента', 'ЮЛ', 'Account', 'Клиент', 'Client', 'Название', 'Name',
        'Company', 'Site ID', 'CS', 'Аккаунт', 'Account name',
    ];

    private const FILTER = "OR({Статус оплаты}='Не оплачен',{Статус оплаты}='Оплачен частично')";

    private const AGING_KEYS = ['0–30' => true, '31–60' => true, '61–90' => true, '90+' => true];

    /** Table ID вида tbl… для запроса к API. */
    public static function getResolvedDebtTableId(
        string $token,
        string $baseId,
        string $dzTableIdConfig = ''
    ): string {
        return self::resolveDzTableId($baseId, $token, $dzTableIdConfig);
    }

    /**
     * Список таблиц для выпадающего «Источник»: полный список из Meta API; при ошибке или пустом ответе — tbl… из config.
     *
     * @param array{airtable_base_id: string, airtable_dz_table_id?: string, airtable_cs_table_id?: string, airtable_churn_table_id?: string} $c
     *
     * @return array{tables: list<array{id: string, name: string}>, metaUnavailable: bool, metaError: ?string}
     */
    public static function listTablesForSourcePicker(array $c, string $token): array
    {
        $metaError = null;
        $tables = [];
        try {
            $tables = Airtable::listMetaTables($c['airtable_base_id'], $token);
        } catch (Throwable $e) {
            $metaError = $e->getMessage();
            $tables = [];
        }
        if ($tables !== []) {
            return [
                'tables' => self::mergeConfigExtraTableIds($c, $tables),
                'metaUnavailable' => false,
                'metaError' => null,
            ];
        }

        return [
            'tables' => self::fallbackTablesFromConfig($c, $token),
            'metaUnavailable' => true,
            'metaError' => $metaError,
        ];
    }

    /**
     * Добавить tbl… из airtable_extra_source_table_ids, если их не было в ответе Meta API.
     *
     * @param array{airtable_extra_source_table_ids?: string} $c
     * @param list<array{id: string, name: string}> $fromMeta
     *
     * @return list<array{id: string, name: string}>
     */
    private static function mergeConfigExtraTableIds(array $c, array $fromMeta): array
    {
        $byId = [];
        foreach ($fromMeta as $t) {
            if (!is_array($t) || !isset($t['id'])) {
                continue;
            }
            $id = trim((string) $t['id']);
            if ($id === '') {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'name' => (string) ($t['name'] ?? $id),
            ];
        }
        $extraRaw = trim((string) ($c['airtable_extra_source_table_ids'] ?? ''));
        if ($extraRaw !== '') {
            foreach (preg_split('/[\s,;]+/', $extraRaw, -1, PREG_SPLIT_NO_EMPTY) as $tid) {
                $tid = trim((string) $tid);
                if (preg_match('/^tbl[a-zA-Z0-9]{3,}$/', $tid) !== 1) {
                    continue;
                }
                if (!isset($byId[$tid])) {
                    $byId[$tid] = ['id' => $tid, 'name' => 'Таблица ' . $tid];
                }
            }
        }
        $out = array_values($byId);
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @param array{airtable_base_id: string, airtable_dz_table_id?: string, airtable_cs_table_id?: string, airtable_churn_table_id?: string, airtable_extra_source_table_ids?: string} $c
     *
     * @return list<array{id: string, name: string}>
     */
    private static function fallbackTablesFromConfig(array $c, string $token): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $id, string $name) use (&$out, &$seen): void {
            $id = trim($id);
            if ($id === '' || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'name' => $name];
        };
        $dzCfg = trim((string) ($c['airtable_dz_table_id'] ?? ''));
        if (preg_match('/^tbl[a-zA-Z0-9]{3,}$/', $dzCfg) === 1) {
            $add($dzCfg, self::DZ_TABLE_NAME);
        } else {
            try {
                $debtId = self::getResolvedDebtTableId($token, $c['airtable_base_id'], $dzCfg);
                $add($debtId, self::DZ_TABLE_NAME);
            } catch (Throwable $e) {
            }
        }
        $add(trim((string) ($c['airtable_cs_table_id'] ?? '')), self::CS_ALL_TABLE_NAME);
        $add(trim((string) ($c['airtable_churn_table_id'] ?? '')), self::CHURN_TABLE_NAME);

        $extraRaw = trim((string) ($c['airtable_extra_source_table_ids'] ?? ''));
        if ($extraRaw !== '') {
            foreach (preg_split('/[\s,;]+/', $extraRaw, -1, PREG_SPLIT_NO_EMPTY) as $tid) {
                $tid = trim((string) $tid);
                if (preg_match('/^tbl[a-zA-Z0-9]{3,}$/', $tid) === 1) {
                    $add($tid, 'Таблица ' . $tid);
                }
            }
        }

        return $out;
    }

    /**
     * @param string $requestedSourceTableId пусто или tbl… совпадающий с дебиторкой — режим ДЗ; иначе — полная выгрузка выбранной таблицы
     *
     * @return array<string, mixed>
     */
    public static function fetchPayload(
        string $token,
        string $baseId,
        string $dzTableIdConfig = '',
        string $dzViewIdConfig = '',
        string $csTableIdConfig = '',
        string $churnTableIdConfig = '',
        string $requestedSourceTableId = '',
        string $csViewIdConfig = '',
        string $churnViewIdConfig = '',
        string $paidViewIdConfig = ''
    ): array {
        $debtTid = self::resolveDzTableId($baseId, $token, $dzTableIdConfig);
        $req = trim($requestedSourceTableId);
        if ($req !== '' && $req !== $debtTid) {
            if (!preg_match('/^tbl[a-zA-Z0-9]{3,}$/', $req)) {
                throw new RuntimeException('Некорректный tableId (ожидается tbl… из Airtable).');
            }

            return self::fetchGenericTablePayload(
                $token,
                $baseId,
                $req,
                $debtTid,
                $csTableIdConfig,
                $churnTableIdConfig,
                $csViewIdConfig,
                $churnViewIdConfig
            );
        }

        $tableId = $debtTid;
        $t0 = microtime(true);
        $debtQuery = [
            'pageSize' => '100',
        ];
        $dzV = trim($dzViewIdConfig);
        $dzViewActive = $dzV !== '' && preg_match('/^viw[a-zA-Z0-9]{3,}$/', $dzV) === 1;
        if ($dzViewActive) {
            $debtQuery['view'] = $dzV;
        }
        $raw = Airtable::fetchAllPages($baseId, $tableId, $debtQuery, $token);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $todayDt = new DateTimeImmutable('today');

        $rows = [];
        $totalDebt = 0.0;
        $overdueTotal = 0.0;
        $aging = ['0–30' => 0.0, '31–60' => 0.0, '61–90' => 0.0, '90+' => 0.0];
        $byCompany = [];
        $byDirection = [];
        $byStatus = [];
        $byManager = [];
        $byLegal = [];

        foreach ($raw as $rec) {
            /** @var array<string, mixed> $f */
            $f = $rec['fields'] ?? [];
            $id = (string) ($rec['id'] ?? '');

            $status = self::selectName($f['Статус оплаты'] ?? null) ?? '—';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $amt = self::resolveDebtAmount($f, $status);

            $dueStr = self::dateStr($f['Срок оплаты'] ?? null);
            $due = $dueStr ? DateTimeImmutable::createFromFormat('Y-m-d', substr($dueStr, 0, 10)) : null;
            $daysOver = self::num($f['Кол-во дней просрочки'] ?? null);
            $bucket = trim((string) ($f['Группа просрочки'] ?? ''));

            $isOverdue = false;
            if ($daysOver !== null && $daysOver > 0) {
                $isOverdue = true;
            } elseif ($due !== null && $due < $todayDt && $status !== 'Оплачен') {
                $isOverdue = true;
            }

            $totalDebt += $amt;
            if ($isOverdue) {
                $overdueTotal += $amt;
            }

            if ($bucket !== '' && isset(self::AGING_KEYS[$bucket])) {
                $aging[$bucket] += $amt;
            } elseif ($isOverdue && $due !== null) {
                $secs = $todayDt->getTimestamp() - $due->getTimestamp();
                $d = max(0, (int) floor($secs / 86400));
                if ($d <= 30) {
                    $aging['0–30'] += $amt;
                } elseif ($d <= 60) {
                    $aging['31–60'] += $amt;
                } elseif ($d <= 90) {
                    $aging['61–90'] += $amt;
                } else {
                    $aging['90+'] += $amt;
                }
            }

            $our = self::selectName($f['Наша компания'] ?? null) ?? '—';
            $dir = self::selectName($f['Направление'] ?? null) ?? '—';
            $byCompany[$our] = ($byCompany[$our] ?? 0.0) + $amt;
            $byDirection[$dir] = ($byDirection[$dir] ?? 0.0) + $amt;

            $legal = trim((string) ($f['ЮЛ клиента'] ?? ''));
            if ($legal === '') {
                $legal = '—';
            }
            $byLegal[$legal] = ($byLegal[$legal] ?? 0.0) + $amt;

            $managers = self::managerList($f['Аккаунт менеджер'] ?? null);
            if ($managers === []) {
                $managers = ['Не указан'];
            }
            $share = $amt / count($managers);
            foreach ($managers as $m) {
                $byManager[$m] = ($byManager[$m] ?? 0.0) + $share;
            }

            $invNo = trim((string) ($f['Номер счета'] ?? ''));
            $invDateStr = self::dateStr($f['Дата счета'] ?? null);
            $rows[] = [
                'id' => $id,
                'legal' => $legal,
                'invoiceNo' => $invNo,
                'billingPeriod' => self::billingPeriodFromInvoice($invNo, $invDateStr),
                'invoiceId' => isset($f['ИД счета']) ? (is_scalar($f['ИД счета']) ? (string) $f['ИД счета'] : json_encode($f['ИД счета'], JSON_UNESCAPED_UNICODE)) : '',
                'amount' => round($amt, 2),
                'status' => $status,
                'dueDate' => $dueStr,
                'daysOverdue' => $daysOver,
                'agingBucket' => $bucket !== '' ? $bucket : null,
                'ourCompany' => $our,
                'direction' => $dir,
                'nextStep' => self::richText($f['Следующий шаг_ Статус_'] ?? null),
                'stepDue' => self::dateStr($f['Срок по шагу'] ?? null),
                'comment' => trim((string) ($f['Комментарий по ДЗ'] ?? '')),
                'managers' => $managers,
                'overdue' => $isOverdue,
                'invoiceDate' => $invDateStr,
                'shipmentStatus' => self::selectName($f['Статус отгрузки'] ?? null),
                'sendStatus' => self::selectName($f['Статус отправки'] ?? null),
                'litigation' => self::selectName($f['Иск'] ?? null),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['overdue'] !== $b['overdue']) {
                return $a['overdue'] ? -1 : 1;
            }
            $da = $a['daysOverdue'];
            $db = $b['daysOverdue'];
            if ($da !== null && $db !== null && $da !== $db) {
                return $db <=> $da;
            }
            return $b['amount'] <=> $a['amount'];
        });

        $managerRows = [];
        foreach ($byManager as $name => $sum) {
            $managerRows[] = ['name' => $name, 'amount' => round($sum, 2)];
        }
        usort($managerRows, static fn (array $x, array $y): int => $y['amount'] <=> $x['amount']);

        $companyRows = [];
        foreach ($byCompany as $name => $sum) {
            $companyRows[] = ['name' => $name, 'amount' => round($sum, 2)];
        }
        usort($companyRows, static fn (array $x, array $y): int => $y['amount'] <=> $x['amount']);

        $directionRows = [];
        foreach ($byDirection as $name => $sum) {
            $directionRows[] = ['name' => $name, 'amount' => round($sum, 2)];
        }
        usort($directionRows, static fn (array $x, array $y): int => $y['amount'] <=> $x['amount']);

        $legalCounts = [];
        foreach ($rows as $r) {
            $lg = $r['legal'];
            $legalCounts[$lg] = ($legalCounts[$lg] ?? 0) + 1;
        }
        $legalRows = [];
        foreach ($byLegal as $name => $sum) {
            $legalRows[] = [
                'name' => $name,
                'amount' => round($sum, 2),
                'count' => $legalCounts[$name] ?? 0,
            ];
        }
        usort($legalRows, static fn (array $x, array $y): int => $y['amount'] <=> $x['amount']);
        $legalTop = array_slice($legalRows, 0, 25);

        $unpaidN = ($byStatus['Не оплачен'] ?? 0) + ($byStatus['Оплачен частично'] ?? 0);
        $clientsN = count(array_unique(array_column($rows, 'legal')));

        $churnBundle = self::tryFetchChurnByLabel($token, $baseId, $churnTableIdConfig, $churnViewIdConfig);
        $csBundle = self::tryFetchCsAllClients(
            $token,
            $baseId,
            $csTableIdConfig,
            $byLegal,
            $churnBundle['byLabel'],
            $csViewIdConfig
        );

        $mrrFresh = (float) ($csBundle['mrrSum'] ?? 0.0);
        $mrrSlug = $baseId . '_' . (string) ($csBundle['meta']['tableId'] ?? 'cs');
        $mrrInfo = DzMrrCache::resolve($mrrSlug, $mrrFresh);
        $mrrVal = (float) ($mrrInfo['value'] ?? 0.0);

        $weeklySlug = $baseId . '_' . $tableId;
        $weeklyDebtTrend = DzWeeklyHistory::recordAndGet($weeklySlug, $totalDebt, $overdueTotal);
        $weeklyPayments = DzWeekPayments::weeklyPaidSeries(
            $token,
            $baseId,
            $tableId,
            $paidViewIdConfig
        );

        return [
            'schemaVersion' => 1,
            'generatedAt' => (new DateTimeImmutable('now'))->format('c'),
            'fetchMs' => $ms,
            'recordCount' => count($rows),
            'kpi' => [
                'totalDebt' => round($totalDebt, 2),
                'overdueDebt' => round($overdueTotal, 2),
                'invoiceCount' => $unpaidN,
                'legalEntityCount' => $clientsN,
            ],
            'aging' => $aging,
            'byStatus' => $byStatus,
            'byManager' => $managerRows,
            'byCompany' => $companyRows,
            'byDirection' => $directionRows,
            'topLegal' => $legalTop,
            'rows' => $rows,
            'csAllClients' => $csBundle['clients'],
            'weeklyDebtTrend' => $weeklyDebtTrend,
            'weeklyPayments' => $weeklyPayments,
            'mrr' => $mrrInfo,
            'debtToMrrPct' => $mrrVal > 0 ? round($totalDebt / $mrrVal * 100, 1) : null,
            'meta' => [
                'schemaVersion' => 1,
                'airtableBaseId' => $baseId,
                'dataMode' => 'debt',
                'defaultDebtTableId' => $tableId,
                'filter' => self::FILTER,
                'table' => $tableId,
                'tableName' => self::DZ_TABLE_NAME,
                'dzViewId' => $dzViewActive ? $dzV : null,
                'csAll' => $csBundle['meta'],
                'churn' => $churnBundle['meta'],
            ],
        ];
    }

    /**
     * Все записи произвольной таблицы — плоские поля в row['g'][имя поля].
     *
     * @return array<string, mixed>
     */
    private static function fetchGenericTablePayload(
        string $token,
        string $baseId,
        string $genericTableId,
        string $defaultDebtTableId,
        string $csTableIdConfig = '',
        string $churnTableIdConfig = '',
        string $csViewIdConfig = '',
        string $churnViewIdConfig = ''
    ): array {
        $t0 = microtime(true);
        $raw = Airtable::fetchAllPages($baseId, $genericTableId, [
            'pageSize' => '100',
        ], $token);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $allKeys = [];
        foreach ($raw as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            foreach (array_keys($rec['fields'] ?? []) as $k) {
                $allKeys[(string) $k] = true;
            }
        }
        $keysSorted = array_keys($allKeys);
        sort($keysSorted, SORT_STRING);

        $rows = [];
        foreach ($raw as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            /** @var array<string, mixed> $fields */
            $fields = $rec['fields'] ?? [];
            $flat = self::flattenGenericRecordFields($fields);
            $g = [];
            foreach ($keysSorted as $k) {
                $g[$k] = $flat[$k] ?? '';
            }
            $rows[] = [
                'id' => (string) ($rec['id'] ?? ''),
                'g' => $g,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        $n = count($rows);
        $tableName = self::lookupTableNameById($baseId, $token, $genericTableId) ?? '—';

        $churnBundle = self::tryFetchChurnByLabel($token, $baseId, $churnTableIdConfig, $churnViewIdConfig);
        $csBundle = self::tryFetchCsAllClients(
            $token,
            $baseId,
            $csTableIdConfig,
            [],
            $churnBundle['byLabel'],
            $csViewIdConfig
        );

        return [
            'schemaVersion' => 1,
            'generatedAt' => (new DateTimeImmutable('now'))->format('c'),
            'fetchMs' => $ms,
            'recordCount' => $n,
            'kpi' => [
                'totalDebt' => 0.0,
                'overdueDebt' => 0.0,
                'invoiceCount' => $n,
                'legalEntityCount' => $n,
            ],
            'aging' => ['0–30' => 0.0, '31–60' => 0.0, '61–90' => 0.0, '90+' => 0.0],
            'byStatus' => [],
            'byManager' => [],
            'byCompany' => [],
            'byDirection' => [],
            'topLegal' => [],
            'rows' => $rows,
            'csAllClients' => $csBundle['clients'],
            'weeklyDebtTrend' => [],
            'weeklyPayments' => [
                'bars' => [],
                'currentWeekTotal' => 0.0,
                'weekStart' => '',
                'weekEnd' => '',
                'error' => null,
            ],
            'mrr' => [
                'value' => 0.0,
                'yearMonth' => '',
                'updatedAt' => '',
                'note' => '',
            ],
            'debtToMrrPct' => null,
            'meta' => [
                'schemaVersion' => 1,
                'airtableBaseId' => $baseId,
                'dataMode' => 'generic',
                'defaultDebtTableId' => $defaultDebtTableId,
                'table' => $genericTableId,
                'tableName' => $tableName,
                'genericKeys' => $keysSorted,
                'filter' => null,
                'csAll' => $csBundle['meta'],
                'churn' => $churnBundle['meta'],
            ],
        ];
    }

    /**
     * Параметры пагинации Airtable + опционально view=viw… (вид внутри таблицы «Клиенты» и т.п.).
     *
     * @return array<string, string>
     */
    private static function airtablePagedQueryWithOptionalView(string $viewIdConfig = ''): array
    {
        $q = ['pageSize' => '100'];
        $v = trim($viewIdConfig);
        if ($v !== '' && preg_match('/^viw[a-zA-Z0-9]{3,}$/', $v) === 1) {
            $q['view'] = $v;
        }

        return $q;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, string>
     */
    private static function flattenGenericRecordFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $out[(string) $k] = self::genericFieldToString($v);
        }

        return $out;
    }

    /** @param mixed $v */
    private static function genericFieldToString($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            if (isset($v['name'])) {
                return (string) $v['name'];
            }
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return $enc !== false ? $enc : '—';
        }

        return '—';
    }

    private static function lookupTableNameById(string $baseId, string $token, string $tableId): ?string
    {
        try {
            foreach (Airtable::listMetaTables($baseId, $token) as $t) {
                if (($t['id'] ?? '') === $tableId) {
                    return (string) ($t['name'] ?? null);
                }
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Все строки CHURN → map normLower(ключ клиента) → строка деталей (остальные поля).
     *
     * @return array{byLabel: array<string, string>, meta: array<string, mixed>}
     */
    private static function tryFetchChurnByLabel(
        string $token,
        string $baseId,
        string $churnTableIdConfig,
        string $churnViewIdConfig = ''
    ): array {
        $vClean = trim($churnViewIdConfig);
        $viewOk = $vClean !== '' && preg_match('/^viw[a-zA-Z0-9]{3,}$/', $vClean) === 1;
        $emptyMeta = static function (?string $tid, ?string $err) use ($viewOk, $vClean): array {
            return [
                'tableName' => self::CHURN_TABLE_NAME,
                'tableId' => $tid,
                'viewId' => $viewOk ? $vClean : null,
                'count' => 0,
                'error' => $err,
            ];
        };

        try {
            $tid = self::resolveTableId(
                $baseId,
                $token,
                $churnTableIdConfig,
                self::CHURN_TABLE_NAME,
                'airtable_churn_table_id'
            );
        } catch (Throwable $e) {
            return [
                'byLabel' => [],
                'meta' => $emptyMeta(null, $e->getMessage()),
            ];
        }

        try {
            $raw = Airtable::fetchAllPages(
                $baseId,
                $tid,
                self::airtablePagedQueryWithOptionalView($churnViewIdConfig),
                $token
            );
        } catch (Throwable $e) {
            return [
                'byLabel' => [],
                'meta' => $emptyMeta($tid, $e->getMessage()),
            ];
        }

        $byLabel = [];
        foreach ($raw as $rec) {
            /** @var array<string, mixed> $f */
            $f = $rec['fields'] ?? [];
            $labelInfo = self::csAllPrimaryLabel($f);
            $label = $labelInfo['label'];
            if ($label === '—') {
                continue;
            }
            $k = self::normLower($label);
            $line = self::csAllDetailsLine($f, $labelInfo['key']);
            if (isset($byLabel[$k]) && $byLabel[$k] !== '') {
                $byLabel[$k] .= ' · ‖ ' . $line;
            } else {
                $byLabel[$k] = $line;
            }
        }

        return [
            'byLabel' => $byLabel,
            'meta' => [
                'tableName' => self::CHURN_TABLE_NAME,
                'tableId' => $tid,
                'viewId' => $viewOk ? $vClean : null,
                'count' => count($raw),
                'error' => null,
            ],
        ];
    }

    /**
     * @param array<string, float> $debtByLegal суммы ДЗ по «ЮЛ клиента» в текущем срезе
     * @param array<string, string> $churnByLabel детали CHURN по normLower(название клиента)
     * @return array{clients: list<array<string, mixed>>, meta: array<string, mixed>, mrrSum: float}
     */
    private static function tryFetchCsAllClients(
        string $token,
        string $baseId,
        string $csTableIdConfig,
        array $debtByLegal,
        array $churnByLabel = [],
        string $csViewIdConfig = ''
    ): array {
        $vClean = trim($csViewIdConfig);
        $viewOk = $vClean !== '' && preg_match('/^viw[a-zA-Z0-9]{3,}$/', $vClean) === 1;
        $emptyMeta = static function (?string $tid, ?string $err) use ($viewOk, $vClean): array {
            return [
                'tableName' => self::CS_ALL_TABLE_NAME,
                'tableId' => $tid,
                'viewId' => $viewOk ? $vClean : null,
                'count' => 0,
                'error' => $err,
            ];
        };

        try {
            $csTableId = self::resolveTableId(
                $baseId,
                $token,
                $csTableIdConfig,
                self::CS_ALL_TABLE_NAME,
                'airtable_cs_table_id'
            );
        } catch (Throwable $e) {
            return [
                'clients' => [],
                'meta' => $emptyMeta(null, $e->getMessage()),
                'mrrSum' => 0.0,
            ];
        }

        try {
            $raw = Airtable::fetchAllPages(
                $baseId,
                $csTableId,
                self::airtablePagedQueryWithOptionalView($csViewIdConfig),
                $token
            );
        } catch (Throwable $e) {
            return [
                'clients' => [],
                'meta' => $emptyMeta($csTableId, $e->getMessage()),
                'mrrSum' => 0.0,
            ];
        }

        $clients = [];
        $mrrSum = 0.0;
        foreach ($raw as $rec) {
            /** @var array<string, mixed> $f */
            $f = $rec['fields'] ?? [];
            $rid = (string) ($rec['id'] ?? '');
            $labelInfo = self::csAllPrimaryLabel($f);
            $label = $labelInfo['label'];
            $dz = $debtByLegal[$label] ?? 0.0;
            if ($dz <= 0.0 && $label !== '—') {
                $needle = self::normLower($label);
                foreach ($debtByLegal as $leg => $sum) {
                    if (self::normLower((string) $leg) === $needle) {
                        $dz = $sum;
                        break;
                    }
                }
            }

            $lk = self::normLower($label);
            $churnLine = $label !== '—' ? ($churnByLabel[$lk] ?? '') : '';

            $mrrCell = $f['MRR sum'] ?? null;
            if (is_int($mrrCell) || is_float($mrrCell)) {
                $mrrSum += (float) $mrrCell;
            } elseif (is_string($mrrCell) && $mrrCell !== '') {
                $parsed = self::money($mrrCell);
                if ($parsed !== null) {
                    $mrrSum += $parsed;
                }
            }

            $clients[] = [
                'id' => $rid,
                'label' => $label,
                'dzTotal' => round($dz, 2),
                'details' => self::csAllDetailsLine($f, $labelInfo['key']),
                'churnDetails' => $churnLine,
            ];
        }

        usort($clients, static function (array $a, array $b): int {
            return strcmp((string) $a['label'], (string) $b['label']);
        });

        return [
            'clients' => $clients,
            'meta' => [
                'tableName' => self::CS_ALL_TABLE_NAME,
                'tableId' => $csTableId,
                'viewId' => $viewOk ? $vClean : null,
                'count' => count($clients),
                'error' => null,
            ],
            'mrrSum' => round($mrrSum, 2),
        ];
    }

    /**
     * @param array<string, mixed> $f
     * @return array{label: string, key: string|null}
     */
    private static function csAllPrimaryLabel(array $f): array
    {
        foreach (self::CS_ALL_LABEL_KEYS as $k) {
            if (!array_key_exists($k, $f)) {
                continue;
            }
            $s = self::csAllScalarToString($f[$k]);
            if ($s !== null && $s !== '') {
                return ['label' => $s, 'key' => $k];
            }
        }

        return ['label' => '—', 'key' => null];
    }

    /**
     * @param array<string, mixed> $f
     */
    private static function csAllDetailsLine(array $f, ?string $skipKey): string
    {
        $keys = array_keys($f);
        sort($keys);
        $parts = [];
        foreach ($keys as $k) {
            if ($skipKey !== null && $k === $skipKey) {
                continue;
            }
            $val = self::csAllScalarToString($f[$k]);
            if ($val === null || $val === '') {
                continue;
            }
            $parts[] = $k . ': ' . $val;
            if (count($parts) >= 8) {
                break;
            }
        }

        return implode(' · ', $parts);
    }

    /** @param mixed $v */
    private static function csAllScalarToString($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'да' : 'нет';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $t = trim($v);

            return $t === '' ? null : $t;
        }
        if (is_array($v)) {
            if (isset($v['name'])) {
                $t = trim((string) $v['name']);

                return $t === '' ? null : $t;
            }
            if ($v === []) {
                return null;
            }
            $keys = array_keys($v);
            $isList = $keys === range(0, count($v) - 1);
            if ($isList) {
                $n = count($v);
                $first = $v[0];
                if (is_string($first)) {
                    return $n === 1 ? $first : $first . '… +' . ($n - 1);
                }

                return 'записей: ' . $n;
            }
        }

        return null;
    }

    private static function normLower(string $s): string
    {
        $t = trim($s);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($t, 'UTF-8');
        }

        return strtolower($t);
    }

    private static function resolveDzTableId(string $baseId, string $token, string $configured): string
    {
        return self::resolveTableId(
            $baseId,
            $token,
            $configured,
            self::DZ_TABLE_NAME,
            'airtable_dz_table_id'
        );
    }

    private static function resolveTableId(
        string $baseId,
        string $token,
        string $configured,
        string $exactTableName,
        string $configKeyHint
    ): string {
        $configured = trim($configured);
        if ($configured !== '') {
            return $configured;
        }
        try {
            foreach (Airtable::listMetaTables($baseId, $token) as $t) {
                if ($t['name'] === $exactTableName) {
                    return $t['id'];
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Не удалось получить список таблиц (Meta API). Укажите в config.php '
                . 'ключ ' . $configKeyHint . ' = Table ID вида tbl… для таблицы «'
                . $exactTableName
                . '» (см. https://airtable.com/api для вашей базы). Либо дайте токену доступ к метаданным. Ошибка: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        throw new RuntimeException(
            'В базе нет таблицы «' . $exactTableName . '». Укажите ' . $configKeyHint . ' в config.php (ID из Airtable API).'
        );
    }

    /** @param mixed $v */
    private static function selectName($v): ?string
    {
        if (is_array($v) && isset($v['name'])) {
            return (string) $v['name'];
        }
        if (is_string($v) && $v !== '') {
            return $v;
        }
        return null;
    }

    /**
     * Сумма ДЗ по строке: учёт частичной оплаты и строковых формул Airtable.
     *
     * @param array<string, mixed> $f
     */
    private static function resolveDebtAmount(array $f, string $status): float
    {
        $formula = self::money($f['Фактическая задолженность'] ?? null);
        $rest = self::money($f['Сумма остатка'] ?? null);
        $invoice = self::money($f['Сумма счета'] ?? null);
        if ($status === 'Оплачен частично') {
            if ($rest !== null && $rest > 0) {
                return $rest;
            }
            if ($formula !== null && $formula > 0) {
                return $formula;
            }
            if ($rest !== null) {
                return $rest;
            }
            if ($formula !== null) {
                return $formula;
            }

            return $invoice ?? 0.0;
        }
        if ($formula !== null && $formula > 0) {
            return $formula;
        }
        if ($rest !== null && $rest > 0) {
            return $rest;
        }
        if ($formula !== null) {
            return $formula;
        }
        if ($rest !== null) {
            return $rest;
        }

        return $invoice ?? 0.0;
    }

    /** @param mixed $v */
    private static function money($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (!is_string($v) || $v === '') {
            return null;
        }
        // Убираем пробелы, неразрывный пробел, знак валюты и прочий мусор
        $s = preg_replace('/[^\d.,\-]/', '', str_replace(["\xc2\xa0", ' '], '', $v)) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }
        $commas = substr_count($s, ',');
        $dots   = substr_count($s, '.');
        if ($commas > 0 && $dots > 0) {
            // "1,000.50" → точка последняя = точка десятичная; "1.000,50" → запятая последняя
            if (strrpos($s, '.') > strrpos($s, ',')) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            }
        } elseif ($commas === 1 && $dots === 0) {
            $parts = explode(',', $s);
            // если после запятой 1-2 цифры — дробная часть, иначе разделитель тысяч
            $s = strlen($parts[1] ?? '') <= 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($commas > 1) {
            $s = str_replace(',', '', $s); // "1,000,000"
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** @param mixed $v */
    private static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && $v !== '') {
            $n = str_replace(',', '.', preg_replace('/[^\d.,\-]/', '', $v) ?? '');
            if ($n !== '' && is_numeric($n)) {
                return (float) $n;
            }
        }
        return null;
    }

    /** @param mixed $v */
    private static function dateStr($v): ?string
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        return null;
    }

    /**
     * Период выставления счёта (YYYY-MM): сначала из «Номер счета», иначе из «Дата счета».
     * Квартал в номере (Q1 / К2 и т.д.) приводится к первому месяцу квартала.
     */
    private static function billingPeriodFromInvoice(string $invoiceNo, ?string $invoiceDateIso): string
    {
        $no = trim($invoiceNo);
        if ($no !== '') {
            if (preg_match('/\b(0?[1-9]|1[0-2])[\.\/\-](20[0-9]{2})\b/u', $no, $m)) {
                return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
            }
            if (preg_match('/\b(20[0-9]{2})[\.\/\-](0?[1-9]|1[0-2])\b/u', $no, $m)) {
                return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
            }
            if (preg_match('/\b(20[0-9]{2})(0[1-9]|1[0-2])\b/u', $no, $m)) {
                return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
            }
            if (preg_match('/\b(?:[QqКк])\s*([1-4])[\s.\-\/]*(20[0-9]{2})\b/u', $no, $m)) {
                $y = (int) $m[2];
                $q = (int) $m[1];

                return sprintf('%04d-%02d', $y, ($q - 1) * 3 + 1);
            }
            if (preg_match('/\b(20[0-9]{2})[\s.\-\/]*(?:[QqКк])\s*([1-4])\b/u', $no, $m)) {
                $y = (int) $m[1];
                $q = (int) $m[2];

                return sprintf('%04d-%02d', $y, ($q - 1) * 3 + 1);
            }
            $lower = self::lowerRu($no);
            $ruMonths = [
                'января' => 1, 'январь' => 1,
                'февраля' => 2, 'февраль' => 2,
                'марта' => 3, 'март' => 3,
                'апреля' => 4, 'апрель' => 4,
                'мая' => 5, 'май' => 5,
                'июня' => 6, 'июнь' => 6,
                'июля' => 7, 'июль' => 7,
                'августа' => 8, 'август' => 8,
                'сентября' => 9, 'сентябрь' => 9,
                'октября' => 10, 'октябрь' => 10,
                'ноября' => 11, 'ноябрь' => 11,
                'декабря' => 12, 'декабрь' => 12,
            ];
            $words = array_keys($ruMonths);
            usort($words, static function (string $a, string $b): int {
                $la = function_exists('mb_strlen') ? mb_strlen($a, 'UTF-8') : strlen($a);
                $lb = function_exists('mb_strlen') ? mb_strlen($b, 'UTF-8') : strlen($b);

                return $lb <=> $la;
            });
            $alt = implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words));
            if (preg_match('/(?<![0-9а-яёa-z])(' . $alt . ')\s+(20[0-9]{2})(?![0-9а-яёa-z])/u', $lower, $m)) {
                $mo = $ruMonths[$m[1]] ?? null;
                if ($mo !== null) {
                    return sprintf('%04d-%02d', (int) $m[2], $mo);
                }
            }
            if (preg_match('/(?<![0-9а-яёa-z])(20[0-9]{2})\s+(' . $alt . ')(?![0-9а-яёa-z])/u', $lower, $m)) {
                $mo = $ruMonths[$m[2]] ?? null;
                if ($mo !== null) {
                    return sprintf('%04d-%02d', (int) $m[1], $mo);
                }
            }
        }
        if ($invoiceDateIso !== null && $invoiceDateIso !== '' && preg_match('/^(20\d{2})-(\d{2})/', $invoiceDateIso, $dm)) {
            return $dm[1] . '-' . $dm[2];
        }

        return '—';
    }

    private static function lowerRu(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }

        return strtolower($s);
    }

    /** @param mixed $v */
    private static function richText($v): string
    {
        if (!is_string($v) || $v === '') {
            return '';
        }
        return $v;
    }

    /**
     * @param mixed $v
     * @return list<string>
     */
    private static function managerList($v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item) && isset($item['name'])) {
                $out[] = trim((string) $item['name']);
            }
        }
        return array_values(array_unique($out));
    }
}

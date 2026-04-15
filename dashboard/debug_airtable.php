<?php
declare(strict_types=1);
// Временный дебаг-эндпоинт — удалить после диагностики
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$c = dashboard_config();
$pat  = $c['airtable_pat'];
$base = $c['airtable_base_id'];

function testUrl(string $url, string $pat, array $checkFields = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pat]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = json_decode((string)$res, true);
    $recs = $body['records'] ?? [];
    $firstFields = isset($recs[0]['fields']) ? array_keys($recs[0]['fields']) : [];
    $result = [
        'http' => $code,
        'curl_err' => $err ?: null,
        'airtable_error' => $body['error'] ?? null,
        'records' => count($recs),
        'has_more' => isset($body['offset']),
        'first_record_fields' => $firstFields,
    ];
    foreach ($checkFields as $f) {
        $result['field_present_' . $f] = in_array($f, $firstFields, true);
    }
    return $result;
}

// Получить даты оплат из вида — показать диапазон чтобы понять, ограничен ли вид по дате
function paidViewDates(string $base, string $tableId, string $viewId, string $pat): array {
    $qs = http_build_query([
        'view'     => $viewId,
        'pageSize' => '100',
    ], '', '&', PHP_QUERY_RFC3986);
    $qs .= '&fields[]=' . rawurlencode('Дата оплаты счета')
         . '&fields[]=' . rawurlencode('Сумма счета');

    $allDates = [];
    $offset   = null;
    $pages    = 0;
    do {
        $q = $qs;
        if ($offset !== null) $q .= '&offset=' . rawurlencode($offset);
        $ch = curl_init("https://api.airtable.com/v0/{$base}/{$tableId}?{$q}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pat]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res  = curl_exec($ch);
        curl_close($ch);
        $body = json_decode((string)$res, true);
        foreach ($body['records'] ?? [] as $r) {
            $d = $r['fields']['Дата оплаты счета'] ?? null;
            if ($d) $allDates[] = substr((string)$d, 0, 10);
        }
        $offset = $body['offset'] ?? null;
        $pages++;
    } while ($offset !== null && $pages < 10);

    if (!$allDates) {
        return ['total_records' => 0, 'date_min' => null, 'date_max' => null, 'pages_fetched' => $pages];
    }
    sort($allDates);
    return [
        'total_records' => count($allDates),
        'has_more_pages' => $offset !== null,
        'date_min'      => $allDates[0],
        'date_max'      => $allDates[count($allDates) - 1],
        'pages_fetched' => $pages,
        'sample_dates'  => array_slice($allDates, 0, 10),
    ];
}

$results = [
    'pat_prefix'  => substr($pat, 0, 20) . '...',
    'base'        => $base,
    'checks' => [
        'dz_view'    => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_dz_table_id']}?view={$c['airtable_dz_view_id']}&maxRecords=1", $pat),
        'cs_view'    => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_cs_table_id']}?view={$c['airtable_cs_view_id']}&maxRecords=1", $pat),
        'churn_view' => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_churn_table_id']}?view={$c['airtable_churn_view_id']}&maxRecords=1", $pat),
        'paid_view'  => testUrl(
            "https://api.airtable.com/v0/{$base}/{$c['airtable_dz_table_id']}?view={$c['airtable_paid_view_id']}&maxRecords=3",
            $pat,
            ['Дата оплаты счета', 'Сумма счета', 'ЮЛ клиента', 'Фактическая задолженность']
        ),
        // Полный скан дат в paid view — показывает ограничен ли вид по диапазону дат
        'paid_view_date_scan' => paidViewDates(
            $base,
            $c['airtable_dz_table_id'],
            $c['airtable_paid_view_id'],
            $pat
        ),
        'churn_aq'   => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_cs_table_id']}?view=viw2n9PbsL1L0pyoZ&maxRecords=1", $pat),
    ],
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

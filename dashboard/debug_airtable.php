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
        'first_record_fields' => $firstFields,
    ];
    foreach ($checkFields as $f) {
        $result['field_present_' . $f] = in_array($f, $firstFields, true);
    }
    return $result;
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
        'paid_view_with_explicit_fields' => testUrl(
            "https://api.airtable.com/v0/{$base}/{$c['airtable_dz_table_id']}?view={$c['airtable_paid_view_id']}&maxRecords=3"
            . '&fields[]=' . rawurlencode('Дата оплаты счета')
            . '&fields[]=' . rawurlencode('Сумма счета')
            . '&fields[]=' . rawurlencode('ЮЛ клиента'),
            $pat,
            ['Дата оплаты счета', 'Сумма счета', 'ЮЛ клиента']
        ),
        'churn_aq'   => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_cs_table_id']}?view=viw2n9PbsL1L0pyoZ&maxRecords=1", $pat),
    ],
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

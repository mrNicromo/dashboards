<?php
declare(strict_types=1);
// Временный дебаг-эндпоинт — удалить после диагностики
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$c = dashboard_config();
$pat  = $c['airtable_pat'];
$base = $c['airtable_base_id'];

function testUrl(string $url, string $pat): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pat]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = json_decode((string)$res, true);
    return ['http' => $code, 'curl_err' => $err ?: null, 'airtable_error' => $body['error'] ?? null, 'records' => count($body['records'] ?? [])];
}

$results = [
    'pat_prefix'  => substr($pat, 0, 20) . '...',
    'base'        => $base,
    'checks' => [
        'dz_view'    => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_dz_table_id']}?view={$c['airtable_dz_view_id']}&maxRecords=1", $pat),
        'cs_view'    => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_cs_table_id']}?view={$c['airtable_cs_view_id']}&maxRecords=1", $pat),
        'churn_view' => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_churn_table_id']}?view={$c['airtable_churn_view_id']}&maxRecords=1", $pat),
        'paid_view'  => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_dz_table_id']}?view={$c['airtable_paid_view_id']}&maxRecords=1", $pat),
        'churn_aq'   => testUrl("https://api.airtable.com/v0/{$base}/{$c['airtable_cs_table_id']}?view=viw2n9PbsL1L0pyoZ&maxRecords=1", $pat),
    ],
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

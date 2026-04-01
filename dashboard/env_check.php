<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pat = dashboard_env('AIRTABLE_PAT');
$base = dashboard_env('AIRTABLE_BASE_ID');

echo json_encode([
    'ok' => true,
    'php_sapi' => PHP_SAPI,
    'airtable_pat_set' => $pat !== '',
    'airtable_pat_len' => strlen($pat),
    'airtable_base_id_set' => $base !== '',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


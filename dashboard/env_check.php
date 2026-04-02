<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$c = dashboard_config();
$pat = (string) ($c['airtable_pat'] ?? '');
$base = (string) ($c['airtable_base_id'] ?? '');

echo json_encode([
    'ok' => true,
    'php_sapi' => PHP_SAPI,
    'airtable_pat_set' => $pat !== '',
    'airtable_pat_len' => strlen($pat),
    'airtable_base_id_set' => $base !== '',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


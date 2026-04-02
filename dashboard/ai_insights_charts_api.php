<?php
declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$c = dashboard_config();
if (trim((string) ($c['airtable_pat'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Задайте AIRTABLE_PAT.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    AiInsightsContext::refreshCachesFromAirtable($c);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseId = (string) ($c['airtable_base_id'] ?? '');
$charts = AiInsightsContext::chartPayload($dir, $baseId);

echo json_encode([
    'ok' => true,
    'charts' => $charts,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/DzWeeklyHistory.php';
require_once __DIR__ . '/lib/DzWeekPayments.php';
require_once __DIR__ . '/lib/DzMrrCache.php';
require_once __DIR__ . '/lib/ManagerReport.php';

try {
    $c = dashboard_config();
    if ($c['airtable_pat'] === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Не задан AIRTABLE_PAT.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = ManagerReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
    ManagerReport::saveCache($data);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

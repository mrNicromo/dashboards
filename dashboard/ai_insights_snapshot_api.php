<?php
declare(strict_types=1);

/**
 * Сохранить только снимок метрик (без вызова Gemini) — для регулярных точек на графике истории.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsContext.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$c = dashboard_config();
$dir = AiInsightsContext::cacheDir();
if (!is_dir($dir) || !is_writable($dir)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Каталог cache недоступен для записи.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseId = (string) ($c['airtable_base_id'] ?? '');
$metrics = AiInsightsContext::metricsSnapshot($dir, $baseId);
AiInsightsHistory::appendMetricsOnly($metrics);
$hist = AiInsightsHistory::load();
$histCount = is_array($hist['items'] ?? null) ? count($hist['items']) : 0;

echo json_encode([
    'ok' => true,
    'savedAt' => gmdate('c'),
    'historyCount' => $histCount,
    'historyChart' => AiInsightsHistory::chartSeries(56),
], JSON_UNESCAPED_UNICODE);
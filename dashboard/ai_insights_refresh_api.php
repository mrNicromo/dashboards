<?php
declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsSupport.php';

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

$lock = AiInsightsSupport::tryAcquireLock($dir);
if ($lock === null) {
    http_response_code(423);
    echo json_encode(['ok' => false, 'error' => 'Уже выполняется другая операция (синхронизация или анализ). Подождите.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($lock === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Не удалось создать блокировку.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rl = AiInsightsSupport::checkRateLimit($dir, 'refresh', AiInsightsSupport::maxRefreshPerHour());
if ($rl !== null) {
    AiInsightsSupport::releaseLock();
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $rl], JSON_UNESCAPED_UNICODE);
    exit;
}

$forceNoCacheSrc = file_get_contents('php://input') ?: '{}';
$forceInput = json_decode($forceNoCacheSrc, true);
$forceNoCache = is_array($forceInput) && !empty($forceInput['force']);

if ($forceNoCache) {
    // Явная очистка всех кэшей перед refresh (кнопка "Анализ всех дашбордов")
    $cacheFiles = ['dz-data-default.json', 'churn-report.json', 'churn-fact-report.json', 'manager-report.json'];
    foreach ($cacheFiles as $cf) {
        @unlink($dir . '/' . $cf);
    }
}

try {
    $out = AiInsightsSupport::executeRefreshPipeline($c);
} catch (Throwable $e) {
    AiInsightsSupport::releaseLock();
    AiInsightsSupport::logLine('refresh_fail', ['err' => $e->getMessage()]);
    http_response_code(502);
    $baseId = trim((string) ($c['airtable_base_id'] ?? ''));
    $errMeta = AiInsightsSupport::classifyFetchError($e->getMessage(), $baseId);
    echo json_encode([
        'ok' => false,
        'error' => $errMeta['message'],
        'errorMeta' => $errMeta,
        'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

AiInsightsSupport::releaseLock();

AiInsightsSupport::logLine('refresh_ok', ['refreshMs' => $out['refreshMs']]);

echo json_encode([
    'ok' => true,
    'promptVersion' => AiInsightsSupport::PROMPT_VERSION,
    'refreshMs' => $out['refreshMs'],
    'charts' => $out['charts'],
    'chartHints' => $out['chartHints'],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/ChurnReport.php';
require_once __DIR__ . '/lib/ChurnFactReport.php';

// ── CSRF или DASHBOARD_API_SECRET (cron / curl без сессии) ─
csrf_check_or_api_secret();

$c = dashboard_config();
if ($c['airtable_pat'] === '') {
    echo json_encode(['ok' => false, 'error' => 'Не настроен PAT']);
    exit;
}

// ── Атомарный lock для churn-fact (H3) ────────────────────
$factCache = __DIR__ . '/cache/churn-fact-report.json';
$lockFile  = $factCache . '.lock';

$lockFp = @fopen($lockFile, 'x');
if ($lockFp === false) {
    if (is_file($factCache)) {
        $cached = @file_get_contents($factCache);
        if ($cached !== false) {
            $payload = json_decode($cached, true);
            if ($payload) {
                echo json_encode(['ok' => true, 'data' => $payload, '_cached' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    $waited = 0;
    while ($waited < 8 && file_exists($lockFile)) {
        usleep(300_000);
        $waited++;
    }
}

// Сбрасываем оба кэша для полного обновления
foreach ([
    $factCache,
    __DIR__ . '/cache/churn-report.json',
] as $f) {
    if (is_file($f)) @unlink($f);
}

try {
    $churnRisk    = ChurnReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
    $prob3risk    = (float)($churnRisk['prob3mrr']     ?? 0);
    $prob3riskEnt = (float)($churnRisk['prob3riskEnt'] ?? 0);
    $prob3riskSmb = (float)($churnRisk['prob3riskSmb'] ?? 0);
    $data = ChurnFactReport::fetchReport($c['airtable_pat'], $c['airtable_base_id'], $prob3risk, $prob3riskEnt, $prob3riskSmb);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} finally {
    if ($lockFp !== false) {
        fclose($lockFp);
        @unlink($lockFile);
    }
}

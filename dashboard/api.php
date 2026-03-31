<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/DzReport.php';
require_once __DIR__ . '/lib/ReportHtml.php';
require_once __DIR__ . '/lib/ReportArchive.php';

try {
    $action = isset($_GET['action']) ? (string) $_GET['action'] : 'refresh';
    if ($action === 'snapshots') {
        echo json_encode(['ok' => true, 'items' => ReportArchive::listSnapshots()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $c = dashboard_config();
    if ($c['airtable_pat'] === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Не задан AIRTABLE_PAT (config.php или переменная окружения).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'tables') {
        $cacheDir = __DIR__ . '/cache';
        $cacheFile = $cacheDir . '/meta-tables.json';
        $ttlSec = 300;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSec) {
            $rawCache = file_get_contents($cacheFile);
            $cached = is_string($rawCache) ? json_decode($rawCache, true) : null;
            if (is_array($cached)
                && isset($cached['tables'], $cached['defaultDebtTableId'])
                && is_array($cached['tables'])) {
                echo json_encode([
                    'ok' => true,
                    'schemaVersion' => 1,
                    'tables' => $cached['tables'],
                    'defaultDebtTableId' => $cached['defaultDebtTableId'],
                    'tablesSource' => $cached['tablesSource'] ?? 'meta',
                    'tablesNote' => $cached['tablesNote'] ?? null,
                    'cached' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
        $picked = DzReport::listTablesForSourcePicker($c, $c['airtable_pat']);
        $tables = $picked['tables'];
        $debtId = DzReport::getResolvedDebtTableId(
            $c['airtable_pat'],
            $c['airtable_base_id'],
            $c['airtable_dz_table_id'] ?? ''
        );
        $tablesSource = $picked['metaUnavailable'] ? 'config' : 'meta';
        $tablesNote = $picked['metaError'];
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        file_put_contents(
            $cacheFile,
            json_encode([
                'tables' => $tables,
                'defaultDebtTableId' => $debtId,
                'tablesSource' => $tablesSource,
                'tablesNote' => $tablesNote,
            ], JSON_UNESCAPED_UNICODE)
        );
        echo json_encode([
            'ok' => true,
            'schemaVersion' => 1,
            'tables' => $tables,
            'defaultDebtTableId' => $debtId,
            'tablesSource' => $tablesSource,
            'tablesNote' => $tablesNote,
            'cached' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $tableIdParam = isset($_GET['tableId']) ? trim((string) $_GET['tableId']) : '';

    // ── File cache (5 min TTL) ──────────────────────────────────────────
    $cacheDir  = __DIR__ . '/cache';
    $cacheKey  = 'dz-data-' . preg_replace('/[^a-zA-Z0-9_]/', '', $tableIdParam ?: 'default') . '.json';
    $cacheFile = $cacheDir . '/' . $cacheKey;
    $cacheTtl  = 300; // seconds
    $forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';

    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $raw = file_get_contents($cacheFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['cacheAge'] = time() - (int) filemtime($cacheFile);
                echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }
    }

    $payload = DzReport::fetchPayload(
        $c['airtable_pat'],
        $c['airtable_base_id'],
        $c['airtable_dz_table_id'] ?? '',
        $c['airtable_dz_view_id'] ?? '',
        $c['airtable_cs_table_id'] ?? '',
        $c['airtable_churn_table_id'] ?? '',
        $tableIdParam,
        $c['airtable_cs_view_id'] ?? '',
        $c['airtable_churn_view_id'] ?? '',
        $c['airtable_paid_view_id'] ?? ''
    );
    // Save to cache
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    $toCache = ['ok' => true, 'schemaVersion' => 1, 'data' => $payload, 'items' => ReportArchive::listSnapshots()];
    file_put_contents($cacheFile, json_encode($toCache, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

    if ($action === 'snapshot') {
        $html = ReportHtml::render($payload);
        $item = ReportArchive::saveHtmlSnapshot($payload, $html);
        if (!isset($payload['schemaVersion'])) {
            $payload['schemaVersion'] = 1;
        }
        echo json_encode(
            ['ok' => true, 'schemaVersion' => 1, 'data' => $payload, 'snapshot' => $item, 'items' => ReportArchive::listSnapshots()],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
    if (!isset($payload['schemaVersion'])) {
        $payload['schemaVersion'] = 1;
    }
    echo json_encode(
        ['ok' => true, 'schemaVersion' => 1, 'data' => $payload, 'items' => ReportArchive::listSnapshots()],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $msg = $e->getMessage();
    $rateLimited = $code === 429
        || stripos($msg, 'rate limit') !== false
        || stripos($msg, 'LIMIT') !== false
        || stripos($msg, 'лимит') !== false;
    http_response_code($rateLimited ? 429 : 500);
    echo json_encode([
        'ok' => false,
        'error' => $msg,
        'rateLimited' => $rateLimited,
        'rateLimitHint' => $rateLimited
            ? 'Лимит запросов Airtable. Обычно сброс около 20:00 по Москве (Europe/Moscow); точное время см. в аккаунте Airtable.'
            : null,
    ], JSON_UNESCAPED_UNICODE);
}

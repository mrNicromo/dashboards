<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/ChurnReport.php';

// ── CSRF или DASHBOARD_API_SECRET (cron / curl без сессии) ─
csrf_check_or_api_secret();

$c = dashboard_config();
if ($c['airtable_pat'] === '') {
    echo json_encode(['ok' => false, 'error' => 'Не настроен PAT']);
    exit;
}

// ── Атомарный lock файл против race condition (H3) ────────
// fopen('x') создаёт файл только если его ещё нет — атомарно на уровне ОС.
// Если другой запрос уже делает фетч — отдаём текущий кэш.
$cache    = __DIR__ . '/cache/churn-report.json';
$lockFile = $cache . '.lock';

$lockFp = @fopen($lockFile, 'x');
if ($lockFp === false) {
    // Другой процесс уже обновляет — отдаём текущий кэш если он есть
    if (is_file($cache)) {
        $cached = @file_get_contents($cache);
        if ($cached !== false) {
            $payload = json_decode($cached, true);
            if ($payload) {
                echo json_encode(['ok' => true, 'data' => $payload, '_cached' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    // Нет кэша — ждём до 8 сек пока lock снимется, затем продолжаем
    $waited = 0;
    while ($waited < 8 && file_exists($lockFile)) {
        usleep(300_000); // 300ms
        $waited++;
    }
}

// Удаляем кэш для принудительного обновления
if (is_file($cache)) @unlink($cache);

try {
    $data = ChurnReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} finally {
    // Снимаем lock в любом случае (success или exception)
    if ($lockFp !== false) {
        fclose($lockFp);
        @unlink($lockFile);
    }
}

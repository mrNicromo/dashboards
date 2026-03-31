<?php
/**
 * cron_warmup.php — прогрев кэша Churn и Churn Fact
 *
 * Запускать ежедневно в 22:00 (до следующего рабочего дня):
 *   0 19 * * *  php /path/to/dashboard/cron_warmup.php >> /var/log/cron_warmup.log 2>&1
 *   (19:00 UTC = 22:00 MSK)
 *
 * Использование:
 *   php cron_warmup.php [--force] [--only=churn|fact|manager]
 *
 *   --force        Удаляет кэш-файлы перед прогревом (иначе пропускает свежий кэш)
 *   --only=churn   Прогреть только ChurnReport
 *   --only=fact    Прогреть только ChurnFactReport
 *   --only=manager Прогреть только ManagerReport
 */
declare(strict_types=1);
set_time_limit(0);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Этот скрипт запускается только из CLI.\n");
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Airtable.php';
require_once __DIR__ . '/lib/ChurnReport.php';
require_once __DIR__ . '/lib/ChurnFactReport.php';
require_once __DIR__ . '/lib/ManagerReport.php';

// ── Разбор аргументов ────────────────────────────────────────
$opts  = getopt('', ['force', 'only:']);
$force = isset($opts['force']);
$only  = $opts['only'] ?? null; // null = все

// ── Конфиг ──────────────────────────────────────────────────
$c = dashboard_config();
if ($c['airtable_pat'] === '') {
    fwrite(STDERR, "[ERROR] Не настроен AIRTABLE_PAT. Проверьте config.php или переменную окружения.\n");
    exit(1);
}

// ── Хелперы ─────────────────────────────────────────────────
function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function clear_cache(string $path, bool $force): bool
{
    if ($force && is_file($path)) {
        @unlink($path);
        log_msg("Кэш удалён: $path");
        return true;
    }
    return false;
}

/** Возвращает возраст файла в секундах, или PHP_INT_MAX если файла нет. */
function cache_age(string $path): int
{
    return is_file($path) ? (int)(time() - filemtime($path)) : PHP_INT_MAX;
}

// ── Пути к кэш-файлам ───────────────────────────────────────
$cacheChurn   = __DIR__ . '/cache/churn-report.json';
$cacheFact    = __DIR__ . '/cache/churn-fact-report.json';
$cacheManager = __DIR__ . '/cache/manager-report.json';  // если используется

// Порог «свежести» — пропускаем прогрев, если кэш моложе 5 минут
const FRESH_TTL = 300;

$errors = 0;

// ══ 1. ChurnReport ══════════════════════════════════════════
if ($only === null || $only === 'churn' || $only === 'fact') {
    $tag = 'ChurnReport';
    if (!$force && cache_age($cacheChurn) < FRESH_TTL) {
        log_msg("$tag: кэш свежий, пропускаем (age=" . cache_age($cacheChurn) . "s).");
    } else {
        clear_cache($cacheChurn, $force);
        log_msg("$tag: начинаем прогрев…");
        $t0 = microtime(true);
        try {
            $churnData = ChurnReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
            $elapsed   = round(microtime(true) - $t0, 2);
            $clients   = count($churnData['clients'] ?? []);
            log_msg("$tag: OK — $clients клиентов, {$elapsed}s.");
        } catch (Throwable $e) {
            fwrite(STDERR, "[$tag ERROR] " . $e->getMessage() . "\n");
            $errors++;
            $churnData = null;
        }
    }
}

// ══ 2. ChurnFactReport ══════════════════════════════════════
if ($only === null || $only === 'fact') {
    $tag = 'ChurnFactReport';
    if (!$force && cache_age($cacheFact) < FRESH_TTL) {
        log_msg("$tag: кэш свежий, пропускаем (age=" . cache_age($cacheFact) . "s).");
    } else {
        clear_cache($cacheFact, $force);
        log_msg("$tag: начинаем прогрев…");
        $t0 = microtime(true);
        try {
            // Используем уже полученные данные ChurnReport, либо загружаем заново
            if (!isset($churnData)) {
                $churnData = ChurnReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
            }
            $prob3risk    = (float)($churnData['prob3mrr']     ?? 0);
            $prob3riskEnt = (float)($churnData['prob3riskEnt'] ?? 0);
            $prob3riskSmb = (float)($churnData['prob3riskSmb'] ?? 0);

            $factData = ChurnFactReport::fetchReport(
                $c['airtable_pat'],
                $c['airtable_base_id'],
                $prob3risk,
                $prob3riskEnt,
                $prob3riskSmb
            );
            $elapsed = round(microtime(true) - $t0, 2);
            $months  = count($factData['byMonth'] ?? []);
            log_msg("$tag: OK — $months месяцев, {$elapsed}s.");
        } catch (Throwable $e) {
            fwrite(STDERR, "[$tag ERROR] " . $e->getMessage() . "\n");
            $errors++;
        }
    }
}

// ══ 3. ManagerReport ════════════════════════════════════════
if ($only === null || $only === 'manager') {
    $tag = 'ManagerReport';
    if (!$force && is_file($cacheManager) && cache_age($cacheManager) < FRESH_TTL) {
        log_msg("$tag: кэш свежий, пропускаем.");
    } else {
        clear_cache($cacheManager, $force);
        log_msg("$tag: начинаем прогрев…");
        $t0 = microtime(true);
        try {
            $manData = ManagerReport::fetchReport($c['airtable_pat'], $c['airtable_base_id']);
            $elapsed = round(microtime(true) - $t0, 2);
            $top10   = count($manData['top10'] ?? []);
            log_msg("$tag: OK — top10={$top10} должников, {$elapsed}s.");
        } catch (Throwable $e) {
            fwrite(STDERR, "[$tag ERROR] " . $e->getMessage() . "\n");
            $errors++;
        }
    }
}

// ── Итог ────────────────────────────────────────────────────
if ($errors > 0) {
    log_msg("Прогрев завершён с ошибками: $errors.");
    exit(1);
}
log_msg("Прогрев завершён успешно.");
exit(0);

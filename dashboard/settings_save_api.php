<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Невалидный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Keys allowed to save to .env.local (no anthropic key - user adds manually)
$allowed = [
    'airtable_pat'            => 'AIRTABLE_PAT',
    'airtable_base_id'        => 'AIRTABLE_BASE_ID',
    'gemini_api_key'          => 'DASHBOARD_GEMINI_API_KEY',
    'groq_api_key'            => 'DASHBOARD_GROQ_API_KEY',
    'api_secret'              => 'DASHBOARD_API_SECRET',
    'auth_username'           => 'DASHBOARD_AUTH_USERNAME',
    'auth_password'           => 'DASHBOARD_AUTH_PASSWORD',
    'sheets_churn_csv'        => 'DASHBOARD_SHEETS_CHURN_CSV',
    'sheets_ds_csv'           => 'DASHBOARD_SHEETS_DS_CSV',
    'ai_auto_snapshot_hours'  => 'DASHBOARD_AI_AUTO_SNAPSHOT_HOURS',
    'ai_alert_overdue_pct'    => 'DASHBOARD_AI_ALERT_OVERDUE_PCT',
    'ai_alert_aging91_pct'    => 'DASHBOARD_AI_ALERT_AGING90_PCT',
    'ai_alert_churn_mrr'      => 'DASHBOARD_AI_ALERT_CHURN_MRR',
];

$envPath = dirname(__DIR__) . '/.env.local';

// Load existing .env.local
$existing = [];
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            $existing[] = $line;
            continue;
        }
        if (!str_contains($line, '=')) {
            $existing[] = $line;
            continue;
        }
        [$k] = explode('=', $line, 2);
        $existing[trim($k)] = $line;
    }
}

// Build map of env_name => value from submission
$toWrite = [];
foreach ($allowed as $formKey => $envName) {
    if (!array_key_exists($formKey, $body)) {
        continue;
    }
    $val = trim((string) $body[$formKey]);
    $toWrite[$envName] = $val;
}

// Merge into existing: update matching keys, keep others
$output = [];
$written = [];

foreach ($existing as $k => $line) {
    if (is_int($k)) {
        // comment or blank
        $output[] = $line;
        continue;
    }
    if (array_key_exists($k, $toWrite)) {
        if ($toWrite[$k] !== '') {
            $output[] = $k . '=' . quoteEnvValue($toWrite[$k]);
        }
        // if empty, drop the key
        $written[$k] = true;
    } else {
        $output[] = $line;
    }
}

// Append new keys not previously in file
foreach ($toWrite as $envName => $val) {
    if (!isset($written[$envName]) && $val !== '') {
        $output[] = $envName . '=' . quoteEnvValue($val);
    }
}

function quoteEnvValue(string $v): string
{
    // Quote if contains spaces, quotes, special chars
    if (preg_match('/[\s"\'#\\\\]/', $v)) {
        return '"' . str_replace(['"', '\\'], ['\\"', '\\\\'], $v) . '"';
    }
    return $v;
}

$content = implode("\n", $output);
if ($content !== '' && !str_ends_with($content, "\n")) {
    $content .= "\n";
}

$written_ok = file_put_contents($envPath, $content, LOCK_EX);
if ($written_ok === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось записать .env.local — проверьте права на запись в корне проекта.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'saved' => count($toWrite)], JSON_UNESCAPED_UNICODE);

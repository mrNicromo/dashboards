<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
$type = is_array($body) ? trim((string) ($body['type'] ?? '')) : '';
$key  = is_array($body) ? trim((string) ($body['key'] ?? '')) : '';

function curlGet(string $url, array $headers, int $timeout = 8): array
{
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'http' => 0, 'body' => 'curl_init failed'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['ok' => ($http >= 200 && $http < 300), 'http' => $http, 'body' => $err ?: $body];
}

function testAirtable(string $pat, string $baseId): array
{
    if ($pat === '') return ['ok' => false, 'msg' => 'PAT не задан'];
    // Проверяем whoami
    $r = curlGet('https://api.airtable.com/v0/meta/whoami', ['Authorization: Bearer ' . $pat]);
    if (!$r['ok']) {
        $j = json_decode($r['body'], true);
        $msg = $j['error']['message'] ?? ('HTTP ' . $r['http']);
        return ['ok' => false, 'msg' => 'Ошибка Airtable: ' . $msg];
    }
    $j = json_decode($r['body'], true);
    $email = $j['email'] ?? '';
    $result = ['ok' => true, 'msg' => 'Airtable: ОК' . ($email ? ' · ' . $email : '')];
    // Проверяем доступ к базе
    if ($baseId !== '') {
        $r2 = curlGet("https://api.airtable.com/v0/meta/bases/{$baseId}/tables", ['Authorization: Bearer ' . $pat]);
        if (!$r2['ok']) {
            $j2 = json_decode($r2['body'], true);
            $msg2 = $j2['error']['message'] ?? ('HTTP ' . $r2['http']);
            $result['msg'] .= ' · База: ' . $msg2;
            $result['ok'] = false;
        } else {
            $j2 = json_decode($r2['body'], true);
            $cnt = is_array($j2['tables'] ?? null) ? count($j2['tables']) : '?';
            $result['msg'] .= ' · База: ' . $cnt . ' таблиц';
        }
    }
    return $result;
}

function testGemini(string $key): array
{
    if ($key === '') return ['ok' => false, 'msg' => 'Ключ не задан'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key) . '&pageSize=1';
    $r = curlGet($url, ['Content-Type: application/json']);
    if (!$r['ok']) {
        $j = json_decode($r['body'], true);
        $msg = $j['error']['message'] ?? ('HTTP ' . $r['http']);
        return ['ok' => false, 'msg' => 'Gemini: ' . $msg];
    }
    return ['ok' => true, 'msg' => 'Gemini: ОК — ключ действителен'];
}

function testGroq(string $key): array
{
    if ($key === '') return ['ok' => false, 'msg' => 'Ключ не задан'];
    $url = 'https://api.groq.com/openai/v1/models';
    $r = curlGet($url, ['Authorization: Bearer ' . $key, 'Content-Type: application/json']);
    if (!$r['ok']) {
        $j = json_decode($r['body'], true);
        $msg = $j['error']['message'] ?? ('HTTP ' . $r['http']);
        return ['ok' => false, 'msg' => 'Groq: ' . $msg];
    }
    $j = json_decode($r['body'], true);
    $cnt = is_array($j['data'] ?? null) ? count($j['data']) : '?';
    return ['ok' => true, 'msg' => 'Groq: ОК · ' . $cnt . ' моделей'];
}

$c = dashboard_config();

$result = match ($type) {
    'airtable' => testAirtable(
        $key ?: trim((string) ($c['airtable_pat'] ?? '')),
        trim((string) ($c['airtable_base_id'] ?? ''))
    ),
    'gemini'   => testGemini($key ?: trim((string) (dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($c['gemini_api_key'] ?? '')))),
    'groq'     => testGroq($key ?: trim((string) (dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($c['groq_api_key'] ?? '')))),
    default    => ['ok' => false, 'msg' => 'Неизвестный тип: ' . htmlspecialchars($type)],
};

echo json_encode(['ok' => $result['ok'], 'msg' => $result['msg']], JSON_UNESCAPED_UNICODE);

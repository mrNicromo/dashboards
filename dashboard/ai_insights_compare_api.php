<?php
declare(strict_types=1);

/**
 * Сравнение двух снимков из истории.
 * POST { idx1: 0, idx2: 5 }  — индексы (0 = самый новый)
 * Возвращает дельту метрик и тексты анализа обоих снимков.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AiInsightsHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$bodyIn = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($bodyIn)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hist = AiInsightsHistory::load();
$items = $hist['items'] ?? [];
$count = count($items);

if ($count < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Недостаточно снимков в истории (нужно минимум 2).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// idx1/idx2 — индексы с конца (0 = самый новый)
$idx1 = max(0, min((int) ($bodyIn['idx1'] ?? 0), $count - 1));
$idx2 = max(0, min((int) ($bodyIn['idx2'] ?? 1), $count - 1));

if ($idx1 === $idx2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Выберите два разных снимка.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Нумерация с конца (0 = последний)
$item1 = $items[$count - 1 - $idx1];
$item2 = $items[$count - 1 - $idx2];

$m1 = $item1['metrics'] ?? [];
$m2 = $item2['metrics'] ?? [];

$metricKeys = [
    'dzTotal'        => 'ДЗ всего, ₽',
    'dzOverdue'      => 'ДЗ просрочка, ₽',
    'debtToMrrPct'   => 'ДЗ/MRR, %',
    'aging90p'       => 'Корзина 90+, ₽',
    'churnRisk'      => 'Churn риск MRR, ₽',
    'churnProb3'     => 'Prob=3 MRR, ₽',
    'churnClients'   => 'Клиентов в риске',
    'factTotalYtd'   => 'Потери YTD, ₽',
    'factChurnYtd'   => 'Churn YTD, ₽',
    'factDownsellYtd' => 'Downsell YTD, ₽',
];

$delta = [];
foreach ($metricKeys as $key => $label) {
    $v1 = isset($m1[$key]) && $m1[$key] !== null && $m1[$key] !== '' ? (float) $m1[$key] : null;
    $v2 = isset($m2[$key]) && $m2[$key] !== null && $m2[$key] !== '' ? (float) $m2[$key] : null;
    $diff = ($v1 !== null && $v2 !== null) ? $v1 - $v2 : null;
    $pct = ($diff !== null && $v2 !== null && $v2 != 0) ? round($diff / abs($v2) * 100, 1) : null;
    $delta[] = [
        'key'   => $key,
        'label' => $label,
        'a'     => $v1,
        'b'     => $v2,
        'diff'  => $diff !== null ? round($diff) : null,
        'pct'   => $pct,
    ];
}

echo json_encode([
    'ok'    => true,
    'count' => $count,
    'a'     => [
        'idx'      => $idx1,
        't'        => $item1['t'] ?? '',
        'metrics'  => $m1,
        'hasAnalysis' => isset($item1['analysis']) && $item1['analysis'] !== null && $item1['analysis'] !== '',
        'analysis' => mb_substr((string) ($item1['analysis'] ?? ''), 0, 8000),
    ],
    'b'     => [
        'idx'      => $idx2,
        't'        => $item2['t'] ?? '',
        'metrics'  => $m2,
        'hasAnalysis' => isset($item2['analysis']) && $item2['analysis'] !== null && $item2['analysis'] !== '',
        'analysis' => mb_substr((string) ($item2['analysis'] ?? ''), 0, 8000),
    ],
    'delta' => $delta,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

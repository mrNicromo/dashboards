<?php
declare(strict_types=1);

final class ReportHtml
{
    /** @param array<string, mixed> $payload */
    public static function render(array $payload): string
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        if (($meta['dataMode'] ?? '') === 'generic') {
            return self::renderGeneric($payload);
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $kpi = is_array($payload['kpi'] ?? null) ? $payload['kpi'] : [];
        $aging = is_array($payload['aging'] ?? null) ? $payload['aging'] : [];
        $generatedAt = self::esc((string) ($payload['generatedAt'] ?? ''));

        $kpiHtml = self::kpiCard('Непогашенная ДЗ', self::rub((float) ($kpi['totalDebt'] ?? 0.0)));
        $kpiHtml .= self::kpiCard('Просроченная ДЗ', self::rub((float) ($kpi['overdueDebt'] ?? 0.0)));
        $kpiHtml .= self::kpiCard('Счетов', (string) ($kpi['invoiceCount'] ?? 0));
        $kpiHtml .= self::kpiCard('ЮЛ', (string) ($kpi['legalEntityCount'] ?? 0));

        $agingRows = '';
        foreach (['0–30', '31–60', '61–90', '90+'] as $bucket) {
            $agingRows .= '<tr><td>' . self::esc($bucket) . '</td><td class="num">' . self::rub((float) ($aging[$bucket] ?? 0.0)) . '</td></tr>';
        }

        $csRows = '';
        $csList = is_array($payload['csAllClients'] ?? null) ? $payload['csAllClients'] : [];
        foreach ($csList as $c) {
            if (!is_array($c)) {
                continue;
            }
            $csRows .= '<tr><td>' . self::esc((string) ($c['label'] ?? '—')) . '</td>'
                . '<td class="num">' . self::rub((float) ($c['dzTotal'] ?? 0.0)) . '</td>'
                . '<td>' . self::esc((string) ($c['details'] ?? '')) . '</td>'
                . '<td>' . self::esc((string) ($c['churnDetails'] ?? '')) . '</td></tr>';
        }

        $tableRows = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $overdueClass = !empty($row['overdue']) ? ' class="overdue"' : '';
            $tableRows .= '<tr' . $overdueClass . '>';
            $tableRows .= '<td>' . self::esc((string) ($row['legal'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['invoiceNo'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['billingPeriod'] ?? '—')) . '</td>';
            $tableRows .= '<td class="num">' . self::rub((float) ($row['amount'] ?? 0.0)) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['status'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['dueDate'] ?? '—')) . '</td>';
            $tableRows .= '<td class="num">' . self::esc((string) ($row['daysOverdue'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['agingBucket'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['ourCompany'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['direction'] ?? '—')) . '</td>';
            $tableRows .= '<td>' . self::esc(implode(', ', (array) ($row['managers'] ?? []))) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['nextStep'] ?? '')) . '</td>';
            $tableRows .= '<td>' . self::esc((string) ($row['comment'] ?? '')) . '</td>';
            $tableRows .= '</tr>';
        }

        return '<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отчет ДЗ</title>
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f6f8fb;color:#1a2233;margin:0}
    .wrap{max-width:1320px;margin:0 auto;padding:20px}
    .head{margin-bottom:16px}
    .head h1{margin:0 0 6px}
    .muted{color:#5f6f87;font-size:13px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px}
    .kpi{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:10px}
    .kpi .v{font-weight:700;font-size:20px;color:#1b5ce6}
    .kpi .l{font-size:12px;color:#5f6f87}
    .block{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:12px;margin-bottom:14px}
    .block h2{margin:0 0 10px;font-size:14px;color:#5f6f87;text-transform:uppercase}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{border-bottom:1px solid #edf1f7;padding:7px;text-align:left;vertical-align:top}
    th{background:#f8fafe;position:sticky;top:0}
    .num{text-align:right;white-space:nowrap}
    tr.overdue td:first-child{box-shadow:inset 3px 0 0 #d93025}
    .scroll{overflow:auto;max-height:70vh}
    @media print{body{background:#fff}.block,.kpi{break-inside:avoid}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Отчет по дебиторской задолженности</h1>
      <div class="muted">Сформировано: ' . $generatedAt . '</div>
    </div>
    <div class="kpis">' . $kpiHtml . '</div>
    <section class="block">
      <h2>Возрастная структура</h2>
      <div class="scroll">
        <table><thead><tr><th>Группа</th><th class="num">Сумма</th></tr></thead><tbody>' . $agingRows . '</tbody></table>
      </div>
    </section>
    <section class="block">
      <h2>Клиенты (CS ALL)</h2>
      <div class="scroll">
        <table><thead><tr><th>Клиент</th><th class="num">ДЗ в срезе</th><th>Детали</th><th>CHURN</th></tr></thead><tbody>' . $csRows . '</tbody></table>
      </div>
    </section>
    <section class="block">
      <h2>Детализация счетов</h2>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>ЮЛ</th><th>Счет</th><th>Период (YYYY-MM)</th><th class="num">Сумма</th><th>Статус</th><th>Срок оплаты</th><th class="num">Дней</th>
              <th>Группа</th><th>Наша компания</th><th>Направление</th><th>Менеджеры</th><th>След. шаг</th><th>Комментарий</th>
            </tr>
          </thead>
          <tbody>' . $tableRows . '</tbody>
        </table>
      </div>
    </section>
  </div>
</body>
</html>';
    }

    /** @param array<string, mixed> $payload */
    private static function renderGeneric(array $payload): string
    {
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $keys = is_array($meta['genericKeys'] ?? null) ? $meta['genericKeys'] : [];
        $generatedAt = self::esc((string) ($payload['generatedAt'] ?? ''));
        $title = self::esc((string) ($meta['tableName'] ?? 'Таблица'));
        $kpi = is_array($payload['kpi'] ?? null) ? $payload['kpi'] : [];

        $kpiHtml = self::kpiCard('Записей', (string) ($kpi['invoiceCount'] ?? count($rows)));
        $kpiHtml .= self::kpiCard('Полей', (string) count($keys));

        $th = '<th>id</th>';
        foreach ($keys as $k) {
            $th .= '<th>' . self::esc((string) $k) . '</th>';
        }
        $tableRows = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $g = is_array($row['g'] ?? null) ? $row['g'] : [];
            $tableRows .= '<tr><td>' . self::esc((string) ($row['id'] ?? '')) . '</td>';
            foreach ($keys as $k) {
                $tableRows .= '<td>' . self::esc((string) ($g[$k] ?? '')) . '</td>';
            }
            $tableRows .= '</tr>';
        }

        return '<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Выгрузка Airtable</title>
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;background:#f6f8fb;color:#1a2233;margin:0}
    .wrap{max-width:1320px;margin:0 auto;padding:20px}
    .head{margin-bottom:16px}
    .muted{color:#5f6f87;font-size:13px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px}
    .kpi{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:10px}
    .kpi .v{font-weight:700;font-size:20px;color:#1b5ce6}
    .kpi .l{font-size:12px;color:#5f6f87}
    .block{background:#fff;border:1px solid #dbe2ef;border-radius:10px;padding:12px;margin-bottom:14px}
    .block h2{margin:0 0 10px;font-size:14px;color:#5f6f87;text-transform:uppercase}
    table{width:100%;border-collapse:collapse;font-size:12px}
    th,td{border-bottom:1px solid #edf1f7;padding:6px;text-align:left;vertical-align:top}
    th{background:#f8fafe;position:sticky;top:0}
    .scroll{overflow:auto;max-height:75vh}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Произвольная таблица: ' . $title . '</h1>
      <div class="muted">Сформировано: ' . $generatedAt . '</div>
    </div>
    <div class="kpis">' . $kpiHtml . '</div>
    <section class="block">
      <h2>Строки</h2>
      <div class="scroll">
        <table><thead><tr>' . $th . '</tr></thead><tbody>' . $tableRows . '</tbody></table>
      </div>
    </section>
  </div>
</body>
</html>';
    }

    private static function kpiCard(string $label, string $value): string
    {
        return '<div class="kpi"><div class="v">' . $value . '</div><div class="l">' . self::esc($label) . '</div></div>';
    }

    private static function rub(float $value): string
    {
        return number_format($value, 2, ',', ' ') . ' ₽';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php
declare(strict_types=1);

final class ReportArchive
{
    private const DIR = __DIR__ . '/../snapshots';
    private const MANIFEST = __DIR__ . '/../snapshots/manifest.json';

    /** @param array<string, mixed> $payload */
    public static function saveHtmlSnapshot(array $payload, string $html): array
    {
        self::ensureDir();
        $now = new DateTimeImmutable('now');
        $slug = $now->format('Ymd-His');
        $fileName = 'dz-report-' . $slug . '.html';
        $abs = self::DIR . '/' . $fileName;
        file_put_contents($abs, $html);

        $item = [
            'id' => $slug,
            'file' => $fileName,
            'url' => 'snapshots/' . rawurlencode($fileName),
            'createdAt' => $now->format('c'),
            'recordCount' => (int) ($payload['recordCount'] ?? 0),
            'totalDebt' => (float) (($payload['kpi']['totalDebt'] ?? 0.0)),
            'sizeBytes' => filesize($abs) ?: 0,
        ];

        $manifest = self::listSnapshots();
        array_unshift($manifest, $item);
        $manifest = array_slice($manifest, 0, 300);
        file_put_contents(self::MANIFEST, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $item;
    }

    /** @return list<array<string, mixed>> */
    public static function listSnapshots(): array
    {
        self::ensureDir();
        if (is_file(self::MANIFEST)) {
            $raw = file_get_contents(self::MANIFEST);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }
        }

        $items = [];
        $files = scandir(self::DIR) ?: [];
        foreach ($files as $f) {
            if (!is_string($f) || strpos($f, 'dz-report-') !== 0 || substr($f, -5) !== '.html') {
                continue;
            }
            $abs = self::DIR . '/' . $f;
            $mtime = filemtime($abs) ?: time();
            $items[] = [
                'id' => preg_replace('/[^0-9-]/', '', $f),
                'file' => $f,
                'url' => 'snapshots/' . rawurlencode($f),
                'createdAt' => date('c', $mtime),
                'recordCount' => null,
                'totalDebt' => null,
                'sizeBytes' => filesize($abs) ?: 0,
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['createdAt'], (string) $a['createdAt']));
        return $items;
    }

    private static function ensureDir(): void
    {
        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0775, true);
        }
    }
}

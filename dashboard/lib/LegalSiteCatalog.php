<?php
declare(strict_types=1);

/**
 * Подстановка сайта и ссылки по ЮЛ / домену (data/legal_site_map.json).
 */
final class LegalSiteCatalog
{
    /** @var list<array{display: string, url: string, all?: list<string>, any?: list<string>}>|null */
    private static ?array $rules = null;

    /** @return list<array{display: string, url: string, all?: list<string>, any?: list<string>}> */
    private static function loadRules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $path = __DIR__ . '/../data/legal_site_map.json';
        if (!is_readable($path)) {
            self::$rules = [];

            return self::$rules;
        }
        $j = json_decode((string) file_get_contents($path), true);
        $raw = is_array($j) && isset($j['rules']) && is_array($j['rules']) ? $j['rules'] : [];
        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r) || !isset($r['display'], $r['url'])) {
                continue;
            }
            $row = [
                'display' => (string) $r['display'],
                'url'     => (string) $r['url'],
            ];
            if (!empty($r['all']) && is_array($r['all'])) {
                $row['all'] = array_values(array_filter(array_map('strval', $r['all'])));
            }
            if (!empty($r['any']) && is_array($r['any'])) {
                $row['any'] = array_values(array_filter(array_map('strval', $r['any'])));
            }
            if (isset($row['all']) || isset($row['any'])) {
                $out[] = $row;
            }
        }
        self::$rules = $out;

        return self::$rules;
    }

    private static function haystack(string $legalRaw, string $siteHint, string $fallback): string
    {
        return mb_strtolower(trim($legalRaw . ' ' . $siteHint . ' ' . $fallback));
    }

    private static function matchesAll(string $hay, array $parts): bool
    {
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                return false;
            }
            if (mb_stripos($hay, mb_strtolower($p)) === false) {
                return false;
            }
        }

        return true;
    }

    private static function matchesAny(string $hay, array $parts): bool
    {
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '' && mb_stripos($hay, mb_strtolower($p)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{display: string, url: string}
     */
    public static function resolve(string $legalRaw, string $siteHint, string $fallbackDisplay): array
    {
        $hay = self::haystack($legalRaw, $siteHint, $fallbackDisplay);
        foreach (self::loadRules() as $rule) {
            if (isset($rule['all']) && $rule['all'] !== []) {
                if (self::matchesAll($hay, $rule['all'])) {
                    return ['display' => $rule['display'], 'url' => $rule['url']];
                }
                continue;
            }
            if (isset($rule['any']) && $rule['any'] !== [] && self::matchesAny($hay, $rule['any'])) {
                return ['display' => $rule['display'], 'url' => $rule['url']];
            }
        }

        return ['display' => $fallbackDisplay, 'url' => ''];
    }

    /** Убирает хвост «ООО», «ПАО» и т.п. для отображения, если каталог не сработал */
    public static function stripLegalFormSuffix(string $s): string
    {
        $t = trim($s);
        if ($t === '') {
            return $t;
        }
        $t = preg_replace('/\s+(ООО|ОАО|ЗАО|ПАО|АО|НПО|ТОО|ИП)(\s+|$)/ui', ' ', $t) ?? $t;

        return trim($t);
    }
}

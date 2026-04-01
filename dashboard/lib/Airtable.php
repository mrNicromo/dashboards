<?php
declare(strict_types=1);

final class Airtable
{
    private const API = 'https://api.airtable.com/v0/';

    /** @param array<string, string> $query */
    public static function get(string $baseId, string $tableId, array $query, string $token): array
    {
        $url = self::API . rawurlencode($baseId) . '/' . rawurlencode($tableId);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $body = self::httpGet($url, $token);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Airtable');
        }
        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            throw new RuntimeException('Airtable error: ' . $msg);
        }
        return $decoded;
    }

    /**
     * Cascade: PHP curl → file_get_contents → Windows curl.exe → PowerShell
     */
    private static function httpGet(string $url, string $token): string
    {
        // 1. PHP curl extension
        if (function_exists('curl_init')) {
            try { return self::fetchCurl($url, $token); } catch (Throwable $e) {}
        }

        // 2. file_get_contents (needs allow_url_fopen + openssl)
        if (ini_get('allow_url_fopen')) {
            try { return self::fetchFgc($url, $token); } catch (Throwable $e) {}
        }

        // 3. Windows curl.exe (built-in on Windows 10/11)
        try { return self::fetchCurlExe($url, $token); } catch (Throwable $e) {}

        // 4. PowerShell Invoke-RestMethod
        return self::fetchPowerShell($url, $token);
    }

    private static function fetchCurl(string $url, string $token): string
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('curl_init failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno !== 0) throw new RuntimeException('cURL: ' . $err, $errno);
        if ($body === false) throw new RuntimeException('Empty cURL response');
        if ($code >= 400) {
            $d = json_decode((string)$body, true);
            throw new RuntimeException('Airtable HTTP ' . $code . ': ' . (is_array($d) ? ($d['error']['message'] ?? $body) : $body), $code);
        }
        return (string)$body;
    }

    private static function fetchFgc(string $url, string $token): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
                'timeout'       => 120,
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new RuntimeException('file_get_contents failed');
        $code = 200;
        if (function_exists('http_get_last_response_headers')) {
            $rh = http_get_last_response_headers();
            if (is_array($rh) && $rh !== [] && preg_match('/HTTP\/\S+\s+(\d+)/', (string) $rh[0], $m)) {
                $code = (int)$m[1];
            }
        } elseif (!empty($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($code >= 400) {
            $d = json_decode($body, true);
            throw new RuntimeException('Airtable HTTP ' . $code . ': ' . (is_array($d) ? ($d['error']['message'] ?? $body) : $body), $code);
        }
        return $body;
    }

    private static function fetchCurlExe(string $url, string $token): string
    {
        // Windows 10/11 has curl.exe built-in
        $tmp = tempnam(sys_get_temp_dir(), 'aq_');
        if ($tmp === false) throw new RuntimeException('tempnam failed');

        $cmd = 'curl.exe -s --max-time 120 --insecure '
             . '-H "Authorization: Bearer ' . $token . '" '
             . '-o "' . $tmp . '" '
             . '"' . $url . '" 2>nul';

        @shell_exec($cmd);

        $body = @file_get_contents($tmp);
        @unlink($tmp);

        if ($body === false || $body === '') throw new RuntimeException('curl.exe failed or not available');
        return $body;
    }

    private static function fetchPowerShell(string $url, string $token): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aq_') . '.json';

        $cmd = 'powershell -NoProfile -Command '
             . '"Invoke-RestMethod -Uri \'' . $url . '\' '
             . '-Headers @{Authorization=\'Bearer ' . $token . '\'} '
             . '| ConvertTo-Json -Depth 20 -Compress '
             . '| Out-File -Encoding UTF8 \'' . $tmp . '\'" 2>nul';

        @shell_exec($cmd);

        $body = @file_get_contents($tmp);
        @unlink($tmp);

        if ($body === false || $body === '') {
            throw new RuntimeException('All HTTP methods failed: no curl extension, allow_url_fopen=Off, curl.exe unavailable, PowerShell failed');
        }

        // PowerShell Invoke-RestMethod returns already-parsed object — re-encode to normalize
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('PowerShell returned invalid JSON');
        return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, string> $baseQuery
     * @return list<array<string, mixed>>
     */
    public static function fetchAllPages(string $baseId, string $tableId, array $baseQuery, string $token): array
    {
        $records = [];
        $offset  = null;
        do {
            $q = $baseQuery;
            if ($offset !== null) $q['offset'] = $offset;
            $page    = self::get($baseId, $tableId, $q, $token);
            foreach ($page['records'] ?? [] as $r) $records[] = $r;
            $offset  = $page['offset'] ?? null;
        } while ($offset !== null);
        return $records;
    }

    /** @return list<array{id: string, name: string}> */
    public static function listMetaTables(string $baseId, string $token): array
    {
        $url     = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($baseId) . '/tables';
        $body    = self::httpGet($url, $token);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid JSON from Airtable meta');
        $out = [];
        foreach ($decoded['tables'] ?? [] as $t) {
            if (is_array($t) && isset($t['id'], $t['name'])) {
                $out[] = ['id' => (string)$t['id'], 'name' => (string)$t['name']];
            }
        }
        return $out;
    }

    /** @var array<string, array<string, string>> */
    private static array $fieldIdToNameCache = [];

    /**
     * Имя поля по field id (из URL вида …/fldXXXX/…). Нужен scope schema.bases:read.
     *
     * @return non-empty-string|null
     */
    public static function getFieldNameById(string $baseId, string $tableId, string $fieldId, string $token): ?string
    {
        $ck = $baseId . '|' . $tableId;
        if (!isset(self::$fieldIdToNameCache[$ck])) {
            $url     = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($baseId) . '/tables';
            $body    = self::httpGet($url, $token);
            $decoded = json_decode($body, true);
            $map     = [];
            if (is_array($decoded)) {
                foreach ($decoded['tables'] ?? [] as $t) {
                    if (!is_array($t) || ($t['id'] ?? '') !== $tableId) {
                        continue;
                    }
                    foreach ($t['fields'] ?? [] as $fld) {
                        if (is_array($fld) && isset($fld['id'], $fld['name'])) {
                            $map[(string) $fld['id']] = (string) $fld['name'];
                        }
                    }
                    break;
                }
            }
            self::$fieldIdToNameCache[$ck] = $map;
        }
        $name = self::$fieldIdToNameCache[$ck][$fieldId] ?? null;
        return ($name !== null && $name !== '') ? $name : null;
    }
}

<?php
declare(strict_types=1);

// Корневой .env, затем .env.local (перекрывает; .env.local в .gitignore — реальные секреты).
$__root = dirname(__DIR__);
foreach ([$__root . '/.env', $__root . '/.env.local'] as $__dotenv) {
    if (!is_readable($__dotenv)) {
        continue;
    }
    $lines = file($__dotenv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') {
            continue;
        }
        if (
            strlen($v) >= 2
            && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))
        ) {
            $v = substr($v, 1, -1);
        }
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}
unset($__root, $__dotenv, $lines, $line, $k, $v);

// ── Session + CSRF (M6) ────────────────────────────────────
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Проверяет CSRF-токен из заголовка X-CSRF-Token.
 * Вызывать в API-эндпоинтах, которые меняют состояние.
 */
function csrf_check(): void
{
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '') {
        $sent = (string)($_POST['csrf_token'] ?? '');
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
}

/** Возвращает токен для вставки в HTML */
function csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Секрет для вызова API без браузерной сессии (Railway cron, curl).
 * Приоритет: переменная окружения DASHBOARD_API_SECRET, затем api_secret в config.php.
 */
function dashboard_api_secret(): string
{
    $fromEnv = dashboard_env('DASHBOARD_API_SECRET');
    if ($fromEnv !== '') {
        return $fromEnv;
    }
    $c = dashboard_config();

    return trim((string) ($c['api_secret'] ?? ''));
}

/**
 * Запрос с валидным общим секретом (Bearer / X-Api-Key / api_secret).
 * Используется для cron и сервер-сервер без браузерной сессии.
 */
function dashboard_request_has_valid_api_secret(): bool
{
    $secret = dashboard_api_secret();
    if ($secret === '') {
        return false;
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        if (hash_equals($secret, $m[1])) {
            return true;
        }
    }
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key !== '' && hash_equals($secret, $key)) {
        return true;
    }
    $fromReq = (string) ($_GET['api_secret'] ?? $_POST['api_secret'] ?? '');
    if ($fromReq !== '' && hash_equals($secret, $fromReq)) {
        return true;
    }
    return false;
}

/** Текущий PHP-скрипт — JSON API (api.php, *_api.php), не HTML-страница. */
function dashboard_auth_is_api_script(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return $script === 'api.php' || str_ends_with($script, '_api.php');
}

/**
 * Как csrf_check(), но допускает запрос с общим секретом (если он настроен):
 * - заголовок Authorization: Bearer &lt;secret&gt;
 * - заголовок X-Api-Key: &lt;secret&gt;
 * - параметр api_secret (GET/POST), напр. churn_api.php?api_secret=…
 *
 * Секрет в query попадает в логи — для продакшена предпочтительнее Bearer.
 */
function csrf_check_or_api_secret(): void
{
    if (dashboard_request_has_valid_api_secret()) {
        return;
    }
    csrf_check();
}

/**
 * Railway/PHP могут прокидывать env по-разному (getenv, $_ENV, $_SERVER).
 * Возвращаем первое непустое значение.
 */
function dashboard_env(string $name): string
{
    $v = getenv($name);
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    $v = $_ENV[$name] ?? null;
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    $v = $_SERVER[$name] ?? null;
    if (is_string($v) && trim($v) !== '') {
        return trim($v);
    }
    return '';
}

/**
 * Встроенные значения, если нет переменных окружения и пустой/отсутствует config.php.
 * Меняйте здесь при смене ключей/таблиц.
 *
 * @return array<string, string>
 */
function dashboard_config_builtin(): array
{
    return [
        'airtable_pat' => 'patwrU0X43xOzIDT5.d3c4c23c581f8d8df72989c1bd1ef7a2d77e1d45b1e87404f57cad22cfffdcde',
        'airtable_base_id' => 'appEAS1rPKpevoIel',
        'airtable_dz_table_id' => 'tblLEQYWypaYtAcp6',
        'airtable_dz_view_id' => 'viw977k6GUNrkeRRy',
        'airtable_cs_table_id' => 'tblIKAi1gcFayRJTn',
        'airtable_churn_table_id' => 'tblIKAi1gcFayRJTn',
        'airtable_cs_view_id' => 'viwz7G1vPxxg0WvC3',
        'airtable_churn_view_id' => 'viwBPiUGNh0PMLeV1',
        'airtable_extra_source_table_ids' => '',
        'airtable_paid_view_id' => 'viwNp3aOtWxmQuKp5',
        'auth_enabled' => '1',
        'auth_username' => 'admin',
        'auth_password' => '',
        'auth_password_hash' => '',
        'api_secret' => 'dummy_api_secret_64_chars_replace_later_0123456789abcdef0123456789ab',
        'gemini_api_key' => 'AIzaSyDemoGeminiKey_ReplacedLater_0123456789abcd',
        'groq_api_key' => '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',
        'anthropic_api_key' => '',
    ];
}

/**
 * Приоритет: переменная окружения → непустое из config.php → встроенное (dashboard_config_builtin).
 */
function dashboard_config_pick(string $envName, array $merged, array $builtin, string $configKey): string
{
    $e = dashboard_env($envName);
    if ($e !== '') {
        return $e;
    }
    if (array_key_exists($configKey, $merged)) {
        $m = $merged[$configKey];
        if ($m !== null && $m !== '') {
            return trim((string) $m);
        }
    }

    return trim((string) ($builtin[$configKey] ?? ''));
}

/**
 * @return array{airtable_pat: string, airtable_base_id: string, airtable_dz_table_id: string, airtable_dz_view_id: string, airtable_cs_table_id: string, airtable_churn_table_id: string, airtable_cs_view_id: string, airtable_churn_view_id: string, airtable_extra_source_table_ids: string, airtable_paid_view_id: string, auth_enabled: string|int|bool, auth_username: string, auth_password: string, auth_password_hash: string}
 */
function dashboard_config(): array
{
    $builtin = dashboard_config_builtin();
    $defaults = array_fill_keys(array_keys($builtin), '');

    $path = __DIR__ . '/config.php';
    $fromFile = (is_readable($path)) ? require $path : [];
    if (!is_array($fromFile)) {
        $fromFile = [];
    }
    $merged = array_merge($defaults, $fromFile);

    $pat = dashboard_config_pick('AIRTABLE_PAT', $merged, $builtin, 'airtable_pat');
    $base = dashboard_config_pick('AIRTABLE_BASE_ID', $merged, $builtin, 'airtable_base_id');
    $dzTable = dashboard_config_pick('AIRTABLE_DZ_TABLE_ID', $merged, $builtin, 'airtable_dz_table_id');
    $dzView = dashboard_config_pick('AIRTABLE_DZ_VIEW_ID', $merged, $builtin, 'airtable_dz_view_id');
    $csTable = dashboard_config_pick('AIRTABLE_CS_TABLE_ID', $merged, $builtin, 'airtable_cs_table_id');
    $churnTable = dashboard_config_pick('AIRTABLE_CHURN_TABLE_ID', $merged, $builtin, 'airtable_churn_table_id');
    $csView = dashboard_config_pick('AIRTABLE_CS_VIEW_ID', $merged, $builtin, 'airtable_cs_view_id');
    $churnView = dashboard_config_pick('AIRTABLE_CHURN_VIEW_ID', $merged, $builtin, 'airtable_churn_view_id');
    $extraSource = dashboard_config_pick('AIRTABLE_EXTRA_SOURCE_TABLE_IDS', $merged, $builtin, 'airtable_extra_source_table_ids');
    $paidView = dashboard_config_pick('AIRTABLE_PAID_VIEW_ID', $merged, $builtin, 'airtable_paid_view_id');
    $authUser = dashboard_config_pick('DASHBOARD_AUTH_USERNAME', $merged, $builtin, 'auth_username');
    $authPass = dashboard_config_pick('DASHBOARD_AUTH_PASSWORD', $merged, $builtin, 'auth_password');
    $authPassHash = dashboard_config_pick('DASHBOARD_AUTH_PASSWORD_HASH', $merged, $builtin, 'auth_password_hash');
    $apiSecret = dashboard_config_pick('DASHBOARD_API_SECRET', $merged, $builtin, 'api_secret');
    $geminiKey = dashboard_config_pick('DASHBOARD_GEMINI_API_KEY', $merged, $builtin, 'gemini_api_key');
    $groqKey = dashboard_config_pick('DASHBOARD_GROQ_API_KEY', $merged, $builtin, 'groq_api_key');
    $anthropicKey = dashboard_config_pick('DASHBOARD_ANTHROPIC_API_KEY', $merged, $builtin, 'anthropic_api_key');

    $authEnabled = dashboard_env('DASHBOARD_AUTH_ENABLED');
    if ($authEnabled === '') {
        $m = $merged['auth_enabled'] ?? null;
        if ($m === null || $m === '') {
            $authEnabled = $builtin['auth_enabled'];
        } else {
            $authEnabled = $m;
        }
    }

    return [
        'airtable_pat' => trim((string) $pat),
        'airtable_base_id' => trim((string) $base),
        'airtable_dz_table_id' => trim((string) $dzTable),
        'airtable_dz_view_id' => trim((string) $dzView),
        'airtable_cs_table_id' => trim((string) $csTable),
        'airtable_churn_table_id' => trim((string) $churnTable),
        'airtable_cs_view_id' => trim((string) $csView),
        'airtable_churn_view_id' => trim((string) $churnView),
        'airtable_extra_source_table_ids' => trim((string) $extraSource),
        'airtable_paid_view_id' => trim((string) $paidView),
        'auth_enabled' => $authEnabled,
        'auth_username' => trim((string) $authUser),
        'auth_password' => (string) $authPass,
        'auth_password_hash' => trim((string) $authPassHash),
        'api_secret' => trim((string) $apiSecret),
        'gemini_api_key' => trim((string) $geminiKey),
        'groq_api_key' => trim((string) $groqKey),
        'anthropic_api_key' => trim((string) $anthropicKey),
    ];
}

/** @return array{enabled: bool, username: string, password: string, password_hash: string, configured: bool} */
function dashboard_auth_config(): array
{
    $c = dashboard_config();
    $username = trim((string) ($c['auth_username'] ?? ''));
    $password = (string) ($c['auth_password'] ?? '');
    $passwordHash = trim((string) ($c['auth_password_hash'] ?? ''));

    $rawEnabled = $c['auth_enabled'] ?? '';
    $enabledExplicit = null;
    if (is_bool($rawEnabled)) {
        $enabledExplicit = $rawEnabled;
    } else {
        $v = strtolower(trim((string) $rawEnabled));
        if ($v !== '') {
            $enabledExplicit = in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
    }

    $configured = ($username !== '') && ($password !== '' || $passwordHash !== '');
    $enabled = $enabledExplicit ?? $configured;

    return [
        'enabled' => $enabled,
        'username' => $username,
        'password' => $password,
        'password_hash' => $passwordHash,
        'configured' => $configured,
    ];
}

function dashboard_auth_is_enabled(): bool
{
    return dashboard_auth_config()['enabled'];
}

function dashboard_auth_is_api_request(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($script, '_api.php') || $script === 'api.php') {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

function dashboard_auth_is_logged_in(): bool
{
    if (!dashboard_auth_is_enabled()) {
        return true;
    }
    return ($_SESSION['dashboard_auth_ok'] ?? false) === true;
}

function dashboard_auth_verify_credentials(string $username, string $password): bool
{
    $auth = dashboard_auth_config();
    if (!$auth['enabled'] || !$auth['configured']) {
        return false;
    }
    if (!hash_equals($auth['username'], trim($username))) {
        return false;
    }
    if ($auth['password_hash'] !== '') {
        return password_verify($password, $auth['password_hash']);
    }
    return hash_equals($auth['password'], $password);
}

function dashboard_auth_login(string $username): void
{
    $_SESSION['dashboard_auth_ok'] = true;
    $_SESSION['dashboard_auth_user'] = $username;
    $_SESSION['dashboard_auth_at'] = time();
    session_regenerate_id(true);
}

function dashboard_auth_logout(): void
{
    unset($_SESSION['dashboard_auth_ok'], $_SESSION['dashboard_auth_user'], $_SESSION['dashboard_auth_at']);
    session_regenerate_id(true);
}

function dashboard_auth_require_login(): void
{
    $auth = dashboard_auth_config();
    if (!$auth['enabled']) {
        return;
    }

    if (!$auth['configured']) {
        if (dashboard_auth_is_api_request()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Авторизация включена, но не настроены auth_username + auth_password(_hash).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Авторизация включена, но не настроены auth_username + auth_password(_hash) в config.php или DASHBOARD_AUTH_* в окружении.";
        exit;
    }

    /* Машинный доступ к JSON API при включённой веб-авторизации (cron, интеграции). */
    if (dashboard_auth_is_api_script() && dashboard_request_has_valid_api_secret()) {
        return;
    }

    if (dashboard_auth_is_logged_in()) {
        return;
    }

    if (dashboard_auth_is_api_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Требуется авторизация', 'login' => '/login.php'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $next = (string) ($_SERVER['REQUEST_URI'] ?? '/index.php');
    header('Location: /login.php?next=' . rawurlencode($next));
    exit;
}

if (PHP_SAPI !== 'cli') {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'login.php' && $script !== 'logout.php') {
        dashboard_auth_require_login();
    }
}

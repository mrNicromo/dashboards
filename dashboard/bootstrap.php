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
 * @return array{airtable_pat: string, airtable_base_id: string, airtable_dz_table_id: string, airtable_dz_view_id: string, airtable_cs_table_id: string, airtable_churn_table_id: string, airtable_cs_view_id: string, airtable_churn_view_id: string, airtable_extra_source_table_ids: string, airtable_paid_view_id: string, auth_enabled: string|int|bool, auth_username: string, auth_password: string, auth_password_hash: string}
 */
function dashboard_config(): array
{
    $defaults = [
        'airtable_pat' => '',
        'airtable_base_id' => 'appEAS1rPKpevoIel',
        'airtable_dz_table_id' => '',
        'airtable_dz_view_id' => '',
        'airtable_cs_table_id' => '',
        'airtable_churn_table_id' => '',
        'airtable_cs_view_id' => '',
        'airtable_churn_view_id' => '',
        'airtable_extra_source_table_ids' => '',
        'airtable_paid_view_id' => '',
        'auth_enabled' => '',
        'auth_username' => '',
        'auth_password' => '',
        'auth_password_hash' => '',
        'api_secret' => '',
        'gemini_api_key' => '',
        'groq_api_key' => '',
    ];
    $path = __DIR__ . '/config.php';
    $fromFile = (is_readable($path)) ? require $path : [];
    if (!is_array($fromFile)) {
        $fromFile = [];
    }
    $merged = array_merge($defaults, $fromFile);
    $pat = dashboard_env('AIRTABLE_PAT') ?: ($merged['airtable_pat'] ?? '');
    $base = dashboard_env('AIRTABLE_BASE_ID') ?: ($merged['airtable_base_id'] ?? $defaults['airtable_base_id']);
    $dzTable = dashboard_env('AIRTABLE_DZ_TABLE_ID') ?: ($merged['airtable_dz_table_id'] ?? '');
    $dzView = dashboard_env('AIRTABLE_DZ_VIEW_ID') ?: ($merged['airtable_dz_view_id'] ?? '');
    $csTable = dashboard_env('AIRTABLE_CS_TABLE_ID') ?: ($merged['airtable_cs_table_id'] ?? '');
    $churnTable = dashboard_env('AIRTABLE_CHURN_TABLE_ID') ?: ($merged['airtable_churn_table_id'] ?? '');
    $csView = dashboard_env('AIRTABLE_CS_VIEW_ID') ?: ($merged['airtable_cs_view_id'] ?? '');
    $churnView = dashboard_env('AIRTABLE_CHURN_VIEW_ID') ?: ($merged['airtable_churn_view_id'] ?? '');
    $extraSource = dashboard_env('AIRTABLE_EXTRA_SOURCE_TABLE_IDS') ?: ($merged['airtable_extra_source_table_ids'] ?? '');
    $paidView = dashboard_env('AIRTABLE_PAID_VIEW_ID') ?: ($merged['airtable_paid_view_id'] ?? '');
    $authEnabled = dashboard_env('DASHBOARD_AUTH_ENABLED');
    if ($authEnabled === '') {
        $authEnabled = $merged['auth_enabled'] ?? '';
    }
    $authUser = dashboard_env('DASHBOARD_AUTH_USERNAME') ?: ($merged['auth_username'] ?? '');
    $authPass = dashboard_env('DASHBOARD_AUTH_PASSWORD') ?: ($merged['auth_password'] ?? '');
    $authPassHash = dashboard_env('DASHBOARD_AUTH_PASSWORD_HASH') ?: ($merged['auth_password_hash'] ?? '');
    $apiSecret    = dashboard_env('DASHBOARD_API_SECRET') ?: ($merged['api_secret'] ?? '');
    $geminiKey    = dashboard_env('DASHBOARD_GEMINI_API_KEY') ?: ($merged['gemini_api_key'] ?? '');
    $groqKey      = dashboard_env('DASHBOARD_GROQ_API_KEY') ?: ($merged['groq_api_key'] ?? '');

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

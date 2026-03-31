<?php
declare(strict_types=1);

// ── Session + CSRF (M6) ────────────────────────────────────
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
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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
    ];
    $path = __DIR__ . '/config.php';
    $fromFile = (is_readable($path)) ? require $path : [];
    if (!is_array($fromFile)) {
        $fromFile = [];
    }
    $merged = array_merge($defaults, $fromFile);
    $pat = getenv('AIRTABLE_PAT') ?: ($merged['airtable_pat'] ?? '');
    $base = getenv('AIRTABLE_BASE_ID') ?: ($merged['airtable_base_id'] ?? $defaults['airtable_base_id']);
    $dzTable = getenv('AIRTABLE_DZ_TABLE_ID') ?: ($merged['airtable_dz_table_id'] ?? '');
    $dzView = getenv('AIRTABLE_DZ_VIEW_ID') ?: ($merged['airtable_dz_view_id'] ?? '');
    $csTable = getenv('AIRTABLE_CS_TABLE_ID') ?: ($merged['airtable_cs_table_id'] ?? '');
    $churnTable = getenv('AIRTABLE_CHURN_TABLE_ID') ?: ($merged['airtable_churn_table_id'] ?? '');
    $csView = getenv('AIRTABLE_CS_VIEW_ID') ?: ($merged['airtable_cs_view_id'] ?? '');
    $churnView = getenv('AIRTABLE_CHURN_VIEW_ID') ?: ($merged['airtable_churn_view_id'] ?? '');
    $extraSource = getenv('AIRTABLE_EXTRA_SOURCE_TABLE_IDS') ?: ($merged['airtable_extra_source_table_ids'] ?? '');
    $paidView = getenv('AIRTABLE_PAID_VIEW_ID') ?: ($merged['airtable_paid_view_id'] ?? '');
    $authEnabled = getenv('DASHBOARD_AUTH_ENABLED');
    if ($authEnabled === false || $authEnabled === '') {
        $authEnabled = $merged['auth_enabled'] ?? '';
    }
    $authUser = getenv('DASHBOARD_AUTH_USERNAME') ?: ($merged['auth_username'] ?? '');
    $authPass = getenv('DASHBOARD_AUTH_PASSWORD') ?: ($merged['auth_password'] ?? '');
    $authPassHash = getenv('DASHBOARD_AUTH_PASSWORD_HASH') ?: ($merged['auth_password_hash'] ?? '');

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

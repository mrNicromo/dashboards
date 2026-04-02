<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!dashboard_auth_is_enabled()) {
    header('Location: /index.php');
    exit;
}

$auth = dashboard_auth_config();
$error = '';
$next = isset($_GET['next']) ? (string) $_GET['next'] : '/index.php';
if ($next === '' || $next[0] !== '/') {
    $next = '/index.php';
}
if (str_starts_with($next, '/login.php') || str_starts_with($next, '/logout.php')) {
    $next = '/index.php';
}

if (dashboard_auth_is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sentCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $sentCsrf)) {
        $error = 'Неверный CSRF токен. Обновите страницу.';
    } elseif (!$auth['configured']) {
        $error = 'Авторизация включена, но логин/пароль не настроены.';
    } else {
        $user = (string) ($_POST['username'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if (dashboard_auth_verify_credentials($user, $pass)) {
            dashboard_auth_login($user);
            header('Location: ' . $next);
            exit;
        }
        $error = 'Неверный логин или пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru" id="html-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <title>Вход в дашборд</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=16">
  <script src="assets/aq-theme-boot.js?v=1"></script>
  <style>
    .login-bar {
      position: fixed;
      top: 0;
      right: 0;
      z-index: 10;
      padding: 12px 16px;
    }
    .login-wrap {
      min-height: 100dvh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 20px 32px;
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 24px 22px 22px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
    }
    [data-theme="dark"] .login-card {
      box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35);
    }
    .login-card h1 {
      margin: 0 0 12px;
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--text);
    }
    .login-note {
      margin: 0 0 18px;
      color: var(--muted);
      font-size: 0.9rem;
      line-height: 1.45;
    }
    .login-field label {
      display: block;
      margin: 12px 0 6px;
      font-size: 0.88rem;
      font-weight: 500;
      color: var(--text);
    }
    .login-field input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--elevated);
      color: var(--text);
      font-size: 0.95rem;
      font-family: inherit;
    }
    .login-field input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-dim);
    }
    .login-submit {
      margin-top: 18px;
      width: 100%;
      padding: 11px 14px;
      border-radius: 8px;
      border: none;
      background: var(--accent);
      color: #fff;
      font-weight: 600;
      font-size: 0.95rem;
      cursor: pointer;
      font-family: inherit;
      transition: opacity 0.15s, filter 0.15s;
    }
    .login-submit:hover {
      filter: brightness(1.06);
    }
    .login-submit:active {
      filter: brightness(0.96);
    }
    .login-err {
      margin: 12px 0 0;
      color: var(--danger);
      font-size: 0.88rem;
    }
    .login-hint {
      margin-top: 14px;
      font-size: 0.78rem;
      color: var(--muted);
      line-height: 1.4;
    }
  </style>
</head>
<body>
  <div class="login-bar">
    <button type="button" class="btn-icon" id="btn-theme" title="Светлая тема" aria-label="Переключить тему">☀️</button>
  </div>
  <div class="login-wrap">
    <form class="login-card" method="post" action="/login.php?next=<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
      <h1>Вход в дашборд</h1>
      <p class="login-note">Введите логин и пароль для доступа.</p>

      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div class="login-field">
        <label for="username">Логин</label>
        <input id="username" name="username" autocomplete="username" required>
      </div>
      <div class="login-field">
        <label for="password">Пароль</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="login-submit">Войти</button>
      <?php if ($error !== ''): ?>
        <p class="login-err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <p class="login-hint">Если забыли пароль — обновите <code>config.php</code> на сервере.</p>
    </form>
  </div>
  <script>
    (function () {
      var btn = document.getElementById('btn-theme');
      var root = document.getElementById('html-root');
      if (!btn || !root) return;
      function sync() {
        var dark = root.getAttribute('data-theme') === 'dark';
        btn.textContent = dark ? '☀️' : '🌙';
        btn.title = dark ? 'Светлая тема' : 'Тёмная тема';
        btn.setAttribute('aria-label', dark ? 'Переключить на светлую тему' : 'Переключить на тёмную тему');
      }
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try {
          localStorage.setItem('aq_theme', next);
          localStorage.removeItem('dz-theme');
        } catch (e) {}
        sync();
      });
      sync();
    })();
  </script>
</body>
</html>

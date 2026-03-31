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
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вход в дашборд</title>
  <style>
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:#0f1115; color:#e8eaed; }
    .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { width:100%; max-width:420px; background:#171a21; border:1px solid #2a3040; border-radius:14px; padding:22px; }
    h1 { margin:0 0 14px; font-size:22px; }
    p.note { margin:0 0 16px; color:#b7c0cf; font-size:14px; }
    label { display:block; margin:10px 0 6px; font-size:14px; color:#c7cfdb; }
    input { width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #33405b; background:#0f141d; color:#fff; }
    button { margin-top:14px; width:100%; padding:11px 14px; border-radius:10px; border:0; background:#4f8cff; color:#fff; font-weight:600; cursor:pointer; }
    .err { margin:8px 0 0; color:#ff9aa9; font-size:14px; }
    .hint { margin-top:10px; font-size:12px; color:#8d99ae; }
  </style>
</head>
<body>
  <div class="wrap">
    <form class="card" method="post" action="/login.php?next=<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
      <h1>Вход в дашборд</h1>
      <p class="note">Введите логин и пароль для доступа.</p>

      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <label for="username">Логин</label>
      <input id="username" name="username" autocomplete="username" required>

      <label for="password">Пароль</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>

      <button type="submit">Войти</button>
      <?php if ($error !== ''): ?>
        <p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <p class="hint">Если забыли пароль — обновите `config.php` на сервере.</p>
    </form>
  </div>
</body>
</html>

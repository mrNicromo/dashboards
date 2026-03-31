<?php
declare(strict_types=1);
// Буферизуем весь вывод: любые PHP-предупреждения/notice не сломают JSON-ответ
ob_start();

require_once __DIR__ . '/bootstrap.php';

// Сбрасываем всё, что могло вывести bootstrap или PHP itself (warnings и т.д.)
ob_clean();
header('Content-Type: application/json; charset=utf-8');

$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$action    = $_REQUEST['action'] ?? $_GET['action'] ?? 'status';

if (in_array($action, ['install', 'remove'], true)) {
    csrf_check();
}

$launcherWin = str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../LAUNCH.bat')  ?: '');
$launcherMac = realpath(__DIR__ . '/../LAUNCH.command') ?: '';
$plistLabel  = 'com.anyquery.dashboard';
$plistPath   = (getenv('HOME') ?: '') . '/Library/LaunchAgents/' . $plistLabel . '.plist';

// Ключ реестра для Windows (не требует прав администратора)
const REG_KEY  = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run';
const REG_NAME = 'AnyQuery Dashboard';

function aq_status(bool $isWindows, string $plistPath): bool {
    if ($isWindows) {
        $out = (string) shell_exec('reg query "' . REG_KEY . '" /v "' . REG_NAME . '" 2>&1');
        return strpos($out, REG_NAME) !== false;
    }
    return file_exists($plistPath);
}

/** Конвертирует вывод Windows-команд из CP866/1251 в UTF-8 */
function win_to_utf8(string $s): string {
    $u = @iconv('CP866', 'UTF-8//IGNORE', $s);
    if ($u !== false && mb_check_encoding($u, 'UTF-8')) return $u;
    $u = @iconv('Windows-1251', 'UTF-8//IGNORE', $s);
    if ($u !== false && mb_check_encoding($u, 'UTF-8')) return $u;
    return $s; // fallback — оставляем как есть, json_encode заменит невалидное
}

function aq_install(bool $isWindows, string $launcherWin, string $launcherMac, string $plistPath, string $plistLabel): array {
    if ($isWindows) {
        if ($launcherWin === '') return ['ok'=>false, 'error'=>'LAUNCH.bat не найден'];
        // HKCU\Run — не требует прав администратора.
        // Путь в кавычках (на случай пробелов), без вложенных кавычек внутри /d.
        $quotedPath = '"' . str_replace('"', '', $launcherWin) . '"';
        $cmd = 'reg add "' . REG_KEY . '" /v "' . REG_NAME . '" /t REG_SZ /d ' . $quotedPath . ' /f 2>&1';
        $out   = win_to_utf8((string) shell_exec($cmd));
        $ok    = stripos($out, 'success') !== false
              || stripos($out, 'успешно') !== false
              || stripos($out, 'завершена') !== false;
        if (!$ok) return ['ok'=>false, 'error'=>trim($out)];
        return ['ok'=>true, 'message'=>'Авто-запуск зарегистрирован (Реестр, без прав админа)'];
    }

    // macOS
    if ($launcherMac === '') return ['ok'=>false, 'error'=>'LAUNCH.command не найден'];
    $home = getenv('HOME') ?: '';
    $logDir = $home . '/.anyquery';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$plistLabel}</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/zsh</string>
        <string>{$launcherMac}</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>StandardOutPath</key>
    <string>{$logDir}/dashboard.log</string>
    <key>StandardErrorPath</key>
    <string>{$logDir}/dashboard.log</string>
</dict>
</plist>
XML;

    $dir = dirname($plistPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($plistPath, $plist) === false) {
        return ['ok'=>false, 'error'=>'Не удалось записать plist'];
    }

    $out = shell_exec('launchctl load ' . escapeshellarg($plistPath) . ' 2>&1');
    return ['ok'=>true, 'message'=>'Авто-запуск зарегистрирован (LaunchAgent)', 'detail'=>trim((string)$out)];
}

function aq_remove(bool $isWindows, string $plistPath): array {
    if ($isWindows) {
        $out = win_to_utf8((string) shell_exec('reg delete "' . REG_KEY . '" /v "' . REG_NAME . '" /f 2>&1'));
        $ok  = stripos($out, 'success') !== false
            || stripos($out, 'успешно') !== false
            || stripos($out, 'завершена') !== false
            || stripos($out, 'удал') !== false;
        if (!$ok) return ['ok'=>false, 'error'=>trim($out)];
        return ['ok'=>true, 'message'=>'Авто-запуск удалён'];
    }

    if (file_exists($plistPath)) {
        shell_exec('launchctl unload ' . escapeshellarg($plistPath) . ' 2>&1');
        unlink($plistPath);
    }
    return ['ok'=>true, 'message'=>'Авто-запуск удалён'];
}

try {
    if ($action === 'status') {
        $result = ['ok'=>true, 'installed'=> aq_status($isWindows, $plistPath), 'os'=> PHP_OS];
    } elseif ($action === 'install') {
        $result = aq_install($isWindows, $launcherWin, $launcherMac, $plistPath, $plistLabel);
    } elseif ($action === 'remove') {
        $result = aq_remove($isWindows, $plistPath);
    } else {
        $result = ['ok'=>false, 'error'=>'Unknown action'];
    }
} catch (Throwable $e) {
    $result = ['ok'=>false, 'error'=>$e->getMessage()];
}

// Сбрасываем всё что могло попасть в буфер ВО ВРЕМЯ логики (warnings и т.д.)
ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
ob_end_flush();

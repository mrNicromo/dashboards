# ╔══════════════════════════════════════════════════════════════╗
# ║       AnyQuery Dashboards — Windows Launcher v1.0           ║
# ║  Устанавливает зависимости и запускает сервер одной командой ║
# ╚══════════════════════════════════════════════════════════════╝
#
# Запуск (из PowerShell):
#   .\start.ps1
#
# Если PowerShell блокирует скрипт:
#   Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned
#   .\start.ps1

param(
  [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$LogFile   = Join-Path $ScriptDir 'server.log'

# ── Константы для PHP ──────────────────────────────────────────
$PhpVersion = '8.3.6'
$PhpZipUrl  = "https://windows.php.net/downloads/releases/php-$PhpVersion-nts-Win32-vs16-x64.zip"
$LocalPhpDir  = Join-Path $ScriptDir 'php'
$LocalPhpExe  = Join-Path $LocalPhpDir 'php.exe'

# ── Цвета ─────────────────────────────────────────────────────
function Write-Ok   { param($msg) Write-Host "  " -NoNewline; Write-Host "✓" -ForegroundColor Green -NoNewline; Write-Host "  $msg" }
function Write-Fail { param($msg) Write-Host "  " -NoNewline; Write-Host "✗" -ForegroundColor Red -NoNewline; Write-Host "  $msg" }
function Write-Wait { param($msg) Write-Host "  " -NoNewline; Write-Host "◌" -ForegroundColor Yellow -NoNewline; Write-Host "  $msg" }
function Write-Info { param($msg) Write-Host "      " -NoNewline; Write-Host $msg -ForegroundColor DarkGray }
function Write-Arrow{ param($msg) Write-Host "  " -NoNewline; Write-Host "→" -ForegroundColor Cyan -NoNewline; Write-Host "  $msg" }

# ── Прогресс-бар ──────────────────────────────────────────────
$TotalSteps   = 6
$CurrentStep  = 0

function Show-Progress {
  param([string]$Label)
  $pct    = [int]($CurrentStep * 100 / $TotalSteps)
  $filled = [int]($CurrentStep * 40 / $TotalSteps)
  $empty  = 40 - $filled
  $bar    = ('█' * $filled) + ('░' * $empty)
  Write-Host "`r  " -NoNewline
  Write-Host "[" -NoNewline -ForegroundColor Cyan
  Write-Host $bar -NoNewline -ForegroundColor Cyan
  Write-Host "]" -NoNewline -ForegroundColor Cyan
  Write-Host (" {0,3}%  " -f $pct) -NoNewline -ForegroundColor White
  Write-Host $Label -ForegroundColor DarkGray -NoNewline
  Write-Host ("          ") -NoNewline
}

function Step-Done {
  param([string]$Msg)
  Write-Host ""
  Write-Ok $Msg
  $script:CurrentStep++
}

# ── Спиннер (анимация в одну строку) ──────────────────────────
function Start-Spinner {
  param([string]$Msg, [scriptblock]$Action)
  $frames = @('⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏')
  $job    = Start-Job -ScriptBlock $Action
  $i      = 0
  while ($job.State -eq 'Running') {
    $f = $frames[$i % $frames.Length]
    Write-Host "`r  " -NoNewline
    Write-Host $f -NoNewline -ForegroundColor Yellow
    Write-Host "  $Msg     " -NoNewline -ForegroundColor DarkGray
    Start-Sleep -Milliseconds 100
    $i++
  }
  Write-Host "`r" -NoNewline
  $result = Receive-Job $job
  Remove-Job $job
  return $result
}

# ── Баннер ────────────────────────────────────────────────────
Clear-Host
Write-Host ""
Write-Host "  ╔══════════════════════════════════════════════════════╗" -ForegroundColor Blue
Write-Host "  ║        AnyQuery Dashboards — Windows Launcher       ║" -ForegroundColor Blue
Write-Host "  ╚══════════════════════════════════════════════════════╝" -ForegroundColor Blue
Write-Host ""
Write-Host "  Рабочая папка: " -NoNewline -ForegroundColor DarkGray
Write-Host $ScriptDir -ForegroundColor DarkGray
Write-Host ""

# ════════════════════════════════════════════════════════════════
# ШАГ 1 — Найти PHP
# ════════════════════════════════════════════════════════════════
Show-Progress "Поиск PHP..."

$PhpExe = $null

# 1a. PHP уже в PATH
if (Get-Command 'php.exe' -ErrorAction SilentlyContinue) {
  $PhpExe = (Get-Command 'php.exe').Source
}
# 1b. XAMPP
elseif (Test-Path 'C:\xampp\php\php.exe') {
  $PhpExe = 'C:\xampp\php\php.exe'
}
# 1c. Laragon
elseif (Test-Path 'C:\laragon\bin\php') {
  $found = Get-ChildItem 'C:\laragon\bin\php' -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($found) { $PhpExe = $found.FullName }
}
# 1d. Локальная копия в папке проекта
elseif (Test-Path $LocalPhpExe) {
  $PhpExe = $LocalPhpExe
}

if ($PhpExe) {
  $PhpVer = & $PhpExe -r 'echo PHP_VERSION;' 2>$null
  $PhpMaj = [int]($PhpVer.Split('.')[0])
  if ($PhpMaj -ge 8) {
    Step-Done "PHP $PhpVer найден: $PhpExe"
  } else {
    Write-Host ""
    Write-Fail "PHP $PhpVer слишком старый (нужен >= 8.1) — скачиваем свежий"
    $PhpExe = $null
  }
}

# ── Если PHP не найден — скачать автоматически ────────────────
if (-not $PhpExe) {
  Write-Host ""
  Write-Wait "PHP не найден — скачиваем PHP $PhpVersion (NTS x64)..."
  Write-Info "Размер архива: ~30 МБ"

  if (-not (Test-Path $LocalPhpDir)) {
    New-Item -ItemType Directory -Path $LocalPhpDir | Out-Null
  }

  $ZipPath = Join-Path $ScriptDir "php_download.zip"

  # Скачать с прогресс-баром
  try {
    $wc = New-Object System.Net.WebClient
    $wc.DownloadProgressChanged += {
      param($s, $e)
      $pct    = $e.ProgressPercentage
      $filled = [int]($pct * 30 / 100)
      $empty  = 30 - $filled
      $bar    = ('█' * $filled) + ('░' * $empty)
      Write-Host "`r  Скачивание  [$bar] $pct%   " -NoNewline
    }

    $task = $wc.DownloadFileTaskAsync($PhpZipUrl, $ZipPath)
    while (-not $task.IsCompleted) { Start-Sleep -Milliseconds 200 }
    Write-Host ""

    if ($task.IsFaulted) { throw $task.Exception }

    Write-Wait "Распаковываем архив..."
    Expand-Archive -Path $ZipPath -DestinationPath $LocalPhpDir -Force
    Remove-Item $ZipPath -Force

    # Настроить php.ini
    $iniSample = Join-Path $LocalPhpDir 'php.ini-production'
    $ini       = Join-Path $LocalPhpDir 'php.ini'
    if (Test-Path $iniSample) {
      Copy-Item $iniSample $ini
      # Включить нужные расширения
      (Get-Content $ini) `
        -replace ';extension=curl',     'extension=curl'     `
        -replace ';extension=mbstring', 'extension=mbstring' `
        -replace ';extension=openssl',  'extension=openssl'  `
        | Set-Content $ini
    }

    $PhpExe = $LocalPhpExe
    $PhpVer = & $PhpExe -r 'echo PHP_VERSION;' 2>$null
    Step-Done "PHP $PhpVer скачан и установлен в папку проекта"

  } catch {
    Write-Host ""
    Write-Fail "Не удалось скачать PHP: $_"
    Write-Host ""
    Write-Arrow "Установите вручную одним из способов:"
    Write-Info "  A) XAMPP:    https://www.apachefriends.org/download.html"
    Write-Info "  B) PHP Win:  https://windows.php.net/download (NTS x64)"
    Write-Host ""
    Read-Host "Нажмите Enter для выхода"
    exit 1
  }
}

# ════════════════════════════════════════════════════════════════
# ШАГ 2 — PHP-расширения
# ════════════════════════════════════════════════════════════════
Show-Progress "Проверка расширений PHP..."

$extensions = @('curl', 'json', 'mbstring')
$missing    = @()
foreach ($ext in $extensions) {
  $ok = & $PhpExe -r "if(!extension_loaded('$ext')){exit(1);}else{exit(0);}" 2>$null
  if ($LASTEXITCODE -ne 0) { $missing += $ext }
}

if ($missing.Count -eq 0) {
  Step-Done "Расширения PHP: curl, json, mbstring — все активны"
} else {
  Write-Host ""
  Write-Fail "Отсутствуют расширения: $($missing -join ', ')"
  Write-Info "Проверьте php.ini: $($PhpExe -replace 'php.exe','php.ini')"
  exit 1
}

# ════════════════════════════════════════════════════════════════
# ШАГ 3 — Папка кэша
# ════════════════════════════════════════════════════════════════
Show-Progress "Подготовка папки кэша..."

$CacheDir = Join-Path $ScriptDir 'cache'
if (Test-Path $CacheDir) {
  Step-Done "Папка cache\ существует"
} else {
  New-Item -ItemType Directory -Path $CacheDir | Out-Null
  Step-Done "Папка cache\ создана"
}

# ════════════════════════════════════════════════════════════════
# ШАГ 4 — config.php
# ════════════════════════════════════════════════════════════════
Show-Progress "Проверка конфигурации..."

$ConfigFile = Join-Path $ScriptDir 'config.php'
$SampleFile = Join-Path $ScriptDir 'config.sample.php'

function Test-PatFilled {
  $result = & $PhpExe -r @"
    `$c = require '$($ConfigFile -replace '\\','\\')';
    echo (isset(`$c['airtable_pat']) && strlen(`$c['airtable_pat']) > 10) ? 'yes' : 'no';
"@ 2>$null
  return $result -eq 'yes'
}

function Ask-Pat {
  Write-Host ""
  Write-Arrow "Введите ваш Airtable Personal Access Token:"
  Write-Info  "(получить: airtable.com → Account → Developer Hub → Personal access tokens)"
  Write-Host  "  PAT: " -NoNewline -ForegroundColor Cyan
  $pat = Read-Host
  return $pat.Trim()
}

function Set-Pat {
  param([string]$Pat)
  $content = Get-Content $ConfigFile -Raw
  $content = $content -replace "'airtable_pat'\s*=>\s*''", "'airtable_pat' => '$Pat'"
  Set-Content $ConfigFile $content -Encoding UTF8
}

if (Test-Path $ConfigFile) {
  if (Test-PatFilled) {
    Step-Done "config.php настроен  (AIRTABLE_PAT заполнен ✓)"
  } else {
    Write-Host ""
    Write-Fail "config.php есть, но AIRTABLE_PAT пустой"
    $pat = Ask-Pat
    if ($pat) {
      Set-Pat $pat
      Step-Done "AIRTABLE_PAT сохранён в config.php"
    } else {
      Write-Host ""
      Write-Host "  ⚠  PAT не введён — дашборды покажут пустые данные" -ForegroundColor Yellow
      Write-Host ""
      $script:CurrentStep++
    }
  }
} elseif (Test-Path $SampleFile) {
  Copy-Item $SampleFile $ConfigFile
  Write-Host ""
  Write-Wait "config.php создан из шаблона"
  $pat = Ask-Pat
  if ($pat) {
    Set-Pat $pat
    Step-Done "config.php создан и AIRTABLE_PAT сохранён"
  } else {
    Write-Host ""
    Write-Host "  ⚠  PAT не введён" -ForegroundColor Yellow
    $script:CurrentStep++
  }
} else {
  Write-Host ""
  Write-Fail "config.sample.php не найден — проверьте папку проекта"
  exit 1
}

# ════════════════════════════════════════════════════════════════
# ШАГ 5 — Проверить порт / запустить сервер
# ════════════════════════════════════════════════════════════════
Show-Progress "Запуск PHP-сервера..."

# Освободить порт если занят
$used = netstat -ano 2>$null | Select-String ":$Port\s.*LISTENING"
if ($used) {
  $pid_ = ($used -split '\s+')[-1]
  try { Stop-Process -Id $pid_ -Force -ErrorAction SilentlyContinue } catch {}
  Start-Sleep -Milliseconds 500
}

# Запустить сервер
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName        = $PhpExe
$psi.Arguments       = "-S localhost:$Port"
$psi.WorkingDirectory= $ScriptDir
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError  = $true
$psi.UseShellExecute        = $false
$psi.CreateNoWindow         = $true

$proc = New-Object System.Diagnostics.Process
$proc.StartInfo = $psi

# Логировать вывод сервера
$proc.OutputDataReceived += { param($s,$e); if ($e.Data) { Add-Content $LogFile $e.Data } }
$proc.ErrorDataReceived  += { param($s,$e); if ($e.Data) { Add-Content $LogFile $e.Data } }

$proc.Start() | Out-Null
$proc.BeginOutputReadLine()
$proc.BeginErrorReadLine()

Start-Sleep -Milliseconds 1200

if (-not $proc.HasExited) {
  Step-Done "PHP сервер запущен  PID=$($proc.Id)  →  http://localhost:$Port"
} else {
  Write-Host ""
  Write-Fail "Сервер завершился сразу — смотрите server.log"
  if (Test-Path $LogFile) { Get-Content $LogFile | Select-Object -Last 10 }
  exit 1
}

# ════════════════════════════════════════════════════════════════
# ШАГ 6 — Открыть браузер
# ════════════════════════════════════════════════════════════════
Show-Progress "Открываем браузер..."
Start-Sleep -Milliseconds 300
Start-Process "http://localhost:$Port/"
Step-Done "Браузер открыт"

# ════════════════════════════════════════════════════════════════
# Финальный экран
# ════════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "  ════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "  ✓  Всё готово!" -ForegroundColor Green
Write-Host "  ════════════════════════════════════════════════" -ForegroundColor Green
Write-Host ""
Write-Arrow "Главная:           http://localhost:$Port/"
Write-Arrow "Угроза Churn:      http://localhost:$Port/churn.php"
Write-Arrow "Потери выручки:    http://localhost:$Port/churn_fact.php"
Write-Arrow "Дебиторка:         http://localhost:$Port/manager.php"
Write-Host ""
Write-Host "  Лог сервера:  $LogFile" -ForegroundColor DarkGray
Write-Host "  Остановить:   закройте это окно или нажмите Ctrl+C" -ForegroundColor DarkGray
Write-Host ""

# ── Показывать лог в реальном времени ─────────────────────────
try {
  Write-Host "  ── Лог сервера (Ctrl+C для выхода) ─────────────────" -ForegroundColor DarkGray
  while ($true) {
    if (Test-Path $LogFile) {
      $lines = Get-Content $LogFile -Tail 1 -ErrorAction SilentlyContinue
      if ($lines) { Write-Host "  $lines" -ForegroundColor DarkGray }
    }
    Start-Sleep -Milliseconds 800
    if ($proc.HasExited) {
      Write-Host ""
      Write-Fail "Сервер остановился неожиданно"
      break
    }
  }
} finally {
  Write-Host ""
  Write-Host "  Останавливаем сервер..." -ForegroundColor Yellow
  if (-not $proc.HasExited) { $proc.Kill() }
  Write-Ok "Сервер остановлен."
}

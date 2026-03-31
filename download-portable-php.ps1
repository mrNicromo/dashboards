# Скачивает переносимый PHP в portable\php рядом с этим скриптом (без установки в систему).
# Запуск: powershell -NoProfile -ExecutionPolicy Bypass -File download-portable-php.ps1
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$PortableRoot = Join-Path $Root 'portable'
$PhpDir = Join-Path $PortableRoot 'php'

if (Test-Path (Join-Path $PhpDir 'php.exe')) {
    Write-Host "OK: portable PHP already exists: $PhpDir"
    exit 0
}

$urls = @(
    'https://windows.php.net/downloads/releases/php-8.5.4-Win32-vs17-x64.zip',
    'https://windows.php.net/downloads/releases/php-8.4.15-Win32-vs17-x64.zip',
    'https://windows.php.net/downloads/releases/php-8.3.23-Win32-vs17-x64.zip'
)

New-Item -ItemType Directory -Force $PortableRoot | Out-Null
$zip = Join-Path $PortableRoot 'php-download.zip'
$tmp = Join-Path $PortableRoot '_php_extract'

$ok = $false
foreach ($url in $urls) {
    try {
        Write-Host "Trying $url ..."
        Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
        $ok = $true
        break
    } catch {
        Write-Host "  (failed, next fallback)"
    }
}

if (-not $ok) {
    Write-Host @"

Не удалось скачать PHP (сеть или блокировка).
Сделайте вручную:
  1) Откройте https://windows.php.net/download/
  2) Скачайте VS16/x64 или VS17 x64, ZIP, Thread Safe или Non Thread Safe — подойдёт NTS.
  3) Распакуйте ВСЁ содержимое архива в папку:
     $PhpDir
     (внутри должен лежать php.exe рядом с папкой ext)

Затем снова запустите LAUNCH.bat
"@
    exit 1
}

Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
Expand-Archive -Path $zip -DestinationPath $tmp -Force
$sub = Get-ChildItem $tmp -Directory | Select-Object -First 1
if (-not $sub) {
    Write-Host 'ERROR: unexpected zip layout'
    exit 1
}

Remove-Item $PhpDir -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $PhpDir | Out-Null
Copy-Item (Join-Path $sub.FullName '*') $PhpDir -Recurse -Force
Remove-Item $tmp -Recurse -Force
Remove-Item $zip -Force

$iniDev = Join-Path $PhpDir 'php.ini-development'
$ini = Join-Path $PhpDir 'php.ini'
if (Test-Path $iniDev) {
    Copy-Item $iniDev $ini -Force
    $lines = Get-Content $ini
    $lines = $lines | ForEach-Object {
        if ($_ -match '^\s*;\s*extension\s*=\s*curl') { 'extension=curl' }
        elseif ($_ -match '^\s*;\s*extension\s*=\s*openssl') { 'extension=openssl' }
        elseif ($_ -match '^\s*;\s*extension\s*=\s*mbstring') { 'extension=mbstring' }
        else { $_ }
    }
    $lines | Set-Content $ini -Encoding UTF8
}

Write-Host "OK: portable PHP installed to $PhpDir"
exit 0

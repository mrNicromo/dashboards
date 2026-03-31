@echo off
cd /d "%~dp0"
echo Cleaning up folder...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Set-Location '%~dp0';" ^
  "$old='_old';" ^
  "if(-not(Test-Path $old)){New-Item $old -ItemType Directory | Out-Null};" ^
  "$keep=@('LAUNCH.bat','LAUNCH.command','cleanup.bat','cleanup.command','dashboard','_old','.claude','README.txt');" ^
  "Get-ChildItem -Force | Where-Object { $keep -notcontains $_.Name } | ForEach-Object {" ^
  "  Move-Item $_.FullName (Join-Path $old $_.Name) -Force;" ^
  "  Write-Host ('  -> ' + $_.Name)" ^
  "};" ^
  "Write-Host '';" ^
  "Write-Host 'Done. Check _old\ and delete when ready.'"

echo.
pause

@echo off
cd /d "%~dp0"
title AnyQuery Dashboards

set PORT=9876
set "APP_DIR=%~dp0dashboard"
set "CONFIG=%~dp0dashboard\config.php"
set "CACHE_DIR=%~dp0dashboard\cache"
set "LOG=%~dp0dashboard\.server.log"
set "AQ_PAT="
set "AQ_BASE=appEAS1rPKpevoIel"

cls
echo.
echo  ======================================================
echo   AnyQuery Dashboards  -  Windows Launcher
echo  ======================================================
echo.
echo  Dir: %~dp0
echo.

:: STEP 1: Find PHP
echo  [1/5] PHP...
set PHP_EXE=
where php.exe >nul 2>&1
if not errorlevel 1 (
  for /f "tokens=*" %%p in ('where php.exe') do (
    if not defined PHP_EXE set PHP_EXE=%%p
  )
)
if not defined PHP_EXE if exist "C:\xampp\php\php.exe"               set PHP_EXE=C:\xampp\php\php.exe
if not defined PHP_EXE if exist "C:\wamp64\bin\php\php8.3.0\php.exe" set PHP_EXE=C:\wamp64\bin\php\php8.3.0\php.exe
if not defined PHP_EXE if exist "C:\tools\php85\php.exe"             set PHP_EXE=C:\tools\php85\php.exe
if not defined PHP_EXE if exist "C:\tools\php\php.exe"               set PHP_EXE=C:\tools\php\php.exe
if not defined PHP_EXE if exist "%~dp0php\php.exe"                   set PHP_EXE=%~dp0php\php.exe

if not defined PHP_EXE (
  echo  ERROR: PHP not found.
  echo  Install XAMPP: https://www.apachefriends.org
  pause
  exit /b 1
)
echo  OK  %PHP_EXE%

:: STEP 2: Cache dir
echo  [2/5] Cache dir...
if not exist "%CACHE_DIR%" mkdir "%CACHE_DIR%"
echo  OK

:: STEP 3: config.php
echo  [3/5] Config...
if not exist "%CONFIG%" (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "$nl=[char]10; $q=[char]39; $t='<?php'+$nl+'declare(strict_types=1);'+$nl+$nl+'return ['+$nl+'    '+$q+'airtable_pat'+$q+'                    => '+$q+'%AQ_PAT%'+$q+','+$nl+'    '+$q+'airtable_base_id'+$q+'                => '+$q+'%AQ_BASE%'+$q+','+$nl+'    '+$q+'airtable_dz_table_id'+$q+'            => '+$q+'tblLEQYWypaYtAcp6'+$q+','+$nl+'    '+$q+'airtable_dz_view_id'+$q+'             => '+$q+'viw977k6GUNrkeRRy'+$q+','+$nl+'    '+$q+'airtable_cs_table_id'+$q+'            => '+$q+'tblIKAi1gcFayRJTn'+$q+','+$nl+'    '+$q+'airtable_churn_table_id'+$q+'         => '+$q+'tblIKAi1gcFayRJTn'+$q+','+$nl+'    '+$q+'airtable_cs_view_id'+$q+'             => '+$q+'viwz7G1vPxxg0WvC3'+$q+','+$nl+'    '+$q+'airtable_churn_view_id'+$q+'          => '+$q+'viwBPiUGNh0PMLeV1'+$q+','+$nl+'    '+$q+'airtable_extra_source_table_ids'+$q+' => '+$q+$q+','+$nl+'    '+$q+'airtable_paid_view_id'+$q+'           => '+$q+$q+','+$nl+'];'+$nl; [IO.File]::WriteAllText('%CONFIG%',$t,[Text.Encoding]::UTF8)"
  echo  OK  config.php created
) else (
  echo  OK  config.php exists
)

:: Convert *.command to LF for macOS
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem '%~dp0*.command' -ErrorAction SilentlyContinue | ForEach-Object { $b=[IO.File]::ReadAllBytes($_.FullName); $s=[Text.Encoding]::UTF8.GetString($b); $s=$s.Replace([char]13+[char]10,[char]10).Replace([char]13,[char]10); [IO.File]::WriteAllBytes($_.FullName,[Text.Encoding]::UTF8.GetBytes($s)) }" 2>nul

:: STEP 4: Start server
echo  [4/5] Starting server on port %PORT%...

for /f "tokens=5" %%p in ('netstat -ano 2^>nul ^| findstr ":%PORT% "') do (
  taskkill /f /pid %%p >nul 2>&1
)
timeout /t 1 /nobreak >nul

if exist "%LOG%" del /q "%LOG%"
start /B "" "%PHP_EXE%" -S 127.0.0.1:%PORT% -t "%APP_DIR%" > "%LOG%" 2>&1
timeout /t 2 /nobreak >nul

netstat -ano 2>nul | findstr ":%PORT% " | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
  echo  ERROR: server did not start. Log:
  if exist "%LOG%" type "%LOG%"
  echo.
  pause
  exit /b 1
)
echo  OK  http://127.0.0.1:%PORT%

:: STEP 5: Open browser
echo  [5/5] Opening browser...
timeout /t 1 /nobreak >nul
start "" "http://127.0.0.1:%PORT%/index.php"

echo.
echo  ======================================================
echo   Running at http://127.0.0.1:%PORT%/index.php
echo   Close this window to stop.
echo  ======================================================
echo.

:loop
timeout /t 10 /nobreak >nul
netstat -ano 2>nul | findstr ":%PORT% " | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
  echo  Server stopped. Restart LAUNCH.bat to continue.
  pause
  exit /b 0
)
goto :loop

@echo off
rem ==========================================================================
rem  ITBbot anonymous exam module - start everything (idempotent)
rem  Output is plain ASCII on purpose (no mojibake in any console code page).
rem ==========================================================================
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"
set "AX_ROOT=%CD%"
if not exist ".run" mkdir ".run"
if not exist "storage\logs" mkdir "storage\logs" 2>nul

echo.
echo === ITBbot anonymous exam: start ===
echo Project: %AX_ROOT%
echo.

rem ---------------------------------------------------------------- 1. git
where git >nul 2>&1 || (echo [ERROR] git not found. Install Git for Windows: https://git-scm.com/download/win & goto :fail)

rem ---------------------------------------------------------------- 2. PHP 8.2 - 8.5 (Laravel 12)
set "PHP="
if defined PHP_BIN call :try_php "%PHP_BIN%"
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP call :try_php "%%P"
if not defined PHP for /d %%D in ("C:\laragon\bin\php\php-8.5*" "C:\laragon\bin\php\php-8.4*" "C:\laragon\bin\php\php-8.3*" "C:\laragon\bin\php\php-8.2*") do if not defined PHP call :try_php "%%~D\php.exe"
if not defined PHP call :try_php "C:\xampp\php\php.exe"
if not defined PHP (
  echo [ERROR] PHP 8.2 - 8.5 not found ^(PATH, C:\laragon\bin\php, C:\xampp\php^).
  echo         Install PHP 8.5 ^(or 8.2+^), or set PHP_BIN to the php.exe path.
  goto :fail
)
for %%I in ("%PHP%") do set "PHP_DIR=%%~dpI"
set "PHPOPTS="
for %%E in (mbstring pdo_mysql curl openssl zip fileinfo) do (
  "%PHP%" !PHPOPTS! -r "exit(extension_loaded('%%E')?0:1);" >nul 2>&1
  if errorlevel 1 (
    if exist "!PHP_DIR!ext\php_%%E.dll" (
      set "PHPOPTS=!PHPOPTS! -d extension=%%E"
    ) else (
      echo [ERROR] PHP extension %%E is missing. Enable extension=%%E in php.ini of !PHP_DIR!
      goto :fail
    )
  )
)
"%PHP%" -r "echo PHP_VERSION;" > ".run\phpver.txt" 2>nul
set /p PHPVER=<".run\phpver.txt"
echo [ok] PHP %PHPVER%  %PHP% %PHPOPTS%
set "AX_PHP=%PHP%"
set "AX_PHPOPTS=%PHPOPTS%"

rem ---------------------------------------------------------------- 3. composer
set "COMPOSER_PHAR="
for /f "delims=" %%C in ('where composer 2^>nul') do if not defined COMPOSER_PHAR if exist "%%~dpCcomposer.phar" set "COMPOSER_PHAR=%%~dpCcomposer.phar"
if not defined COMPOSER_PHAR if exist "C:\laragon\bin\composer\composer.phar" set "COMPOSER_PHAR=C:\laragon\bin\composer\composer.phar"
if not defined COMPOSER_PHAR if exist "C:\composer\composer.phar" set "COMPOSER_PHAR=C:\composer\composer.phar"
if not defined COMPOSER_PHAR if exist "C:\ProgramData\ComposerSetup\bin\composer.phar" set "COMPOSER_PHAR=C:\ProgramData\ComposerSetup\bin\composer.phar"
if not defined COMPOSER_PHAR (echo [ERROR] composer not found. Install from https://getcomposer.org/download/ & goto :fail)
echo [ok] composer %COMPOSER_PHAR%

rem ---------------------------------------------------------------- 4. source code
if not exist "artisan" (
  echo [..] importing source from upstream main2 into branch exam-bot
  if not exist ".git" git init || goto :fail
  git remote get-url upstream >nul 2>&1 || git remote add upstream https://github.com/saber13812002/bot-generator-bale-telegram-laravel-10.git
  git fetch --depth 1 upstream main2 || goto :fail
  git checkout -f -B exam-bot FETCH_HEAD || goto :fail
)

rem ---------------------------------------------------------------- 5. dependencies
set "NEED_COMPOSER="
if not exist "vendor\autoload.php" set "NEED_COMPOSER=1"
if not exist ".run\composer.lock.installed" set "NEED_COMPOSER=1"
if not defined NEED_COMPOSER fc /b "composer.lock" ".run\composer.lock.installed" >nul 2>&1 || set "NEED_COMPOSER=1"
if defined NEED_COMPOSER (
  echo [..] composer install
  "%PHP%" %PHPOPTS% "%COMPOSER_PHAR%" install --no-interaction --prefer-dist || (echo [ERROR] composer install failed & goto :fail)
  copy /y "composer.lock" ".run\composer.lock.installed" >nul
) else (
  echo [ok] vendor is up to date
)

rem ---------------------------------------------------------------- 6. .env  (asks for the bot token locally if empty)
"%PHP%" %PHPOPTS% artisan exam:env-setup || goto :fail
findstr /r /c:"^APP_KEY=$" .env >nul 2>&1 && "%PHP%" %PHPOPTS% artisan key:generate --force
call :env_value BALE_UPDATE_MODE MODE polling
call :env_value BALE_BOT_TOKEN TOKEN ""
call :env_value QUEUE_CONNECTION QUEUE sync
call :env_value APP_URL APPURL http://127.0.0.1:8000

rem ---------------------------------------------------------------- 7. MySQL
call :mysql_up
if errorlevel 1 (
  if exist "C:\xampp\mysql\bin\mysqld.exe" (
    echo [..] starting XAMPP MySQL
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$p = Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini','--standalone' -WindowStyle Hidden -PassThru; [IO.File]::WriteAllText((Join-Path $env:AX_ROOT '.run\mysql_started_by_us'), [string]$p.Id)"
    for /l %%i in (1,1,20) do (
      call :mysql_up && goto :mysql_ok
      ping -n 2 127.0.0.1 >nul
    )
  )
  echo [ERROR] MySQL is not answering on 127.0.0.1:3306.
  echo         Laragon: click Start All ^(or Menu ^> MySQL ^> Start^). XAMPP: start MySQL. Then run start.bat again.
  goto :fail
)
:mysql_ok
echo [ok] MySQL is up
"%PHP%" %PHPOPTS% artisan exam:db-ensure || goto :fail

rem ---------------------------------------------------------------- 8. migrations
echo [..] migrations
"%PHP%" %PHPOPTS% artisan migrate --force > "storage\logs\run-migrate.log" 2>&1
if errorlevel 1 (
  echo [warn] full migrate failed ^(legacy migrations, see storage\logs\run-migrate.log^); running module migrations only
  "%PHP%" %PHPOPTS% artisan migrate --path=database/migrations/anonymous_exam --force || (echo [ERROR] module migrations failed & goto :fail)
)
if not exist "public\storage" "%PHP%" %PHPOPTS% artisan storage:link >nul 2>&1
"%PHP%" %PHPOPTS% artisan db:seed --class=AnonymousExamSeeder --force >nul 2>&1

rem ---------------------------------------------------------------- 9. first admin
"%PHP%" %PHPOPTS% artisan exam:has-admin >nul 2>&1
if errorlevel 1 (
  echo.
  echo No panel admin yet - create the first one:
  "%PHP%" %PHPOPTS% artisan exam:make-admin --role=owner || goto :fail
)

rem ---------------------------------------------------------------- 10. processes
echo [..] starting processes
call :pick_port
call :launch serve "serve --host=127.0.0.1 --port=!PORT!"
> ".run\serve.port" echo !PORT!
if not "!PORT!"=="8000" echo   [warn] port 8000 is used by another program - the panel runs on port !PORT! instead
call :launch schedule "schedule:work"
if /i "%MODE%"=="polling" (
  if "%TOKEN%"=="" (
    echo   [skip] exam:poll - BALE_BOT_TOKEN is empty
  ) else (
    call :launch poll "exam:poll"
  )
) else (
  echo   [skip] exam:poll - BALE_UPDATE_MODE=%MODE%
)
if /i "%QUEUE%"=="database" call :launch queue "queue:work --tries=3 --sleep=1"

rem ---------------------------------------------------------------- 11. health check
set "HEALTH="
for /l %%i in (1,1,30) do (
  if not defined HEALTH (
    "%PHP%" -r "$c=@file_get_contents('http://127.0.0.1:!PORT!/exam-admin/login',false,stream_context_create(['http'=>['timeout'=>2]]));exit(isset($http_response_header[0]) && strpos($http_response_header[0],' 200') > 0 ? 0 : 1);" >nul 2>&1 && set "HEALTH=1"
    if not defined HEALTH ping -n 2 127.0.0.1 >nul
  )
)
if not defined HEALTH (echo [ERROR] panel did not answer within 30s - see storage\logs\run-serve*.log & goto :fail)
echo [ok] panel http://127.0.0.1:!PORT!/exam-admin answers 200

if "%TOKEN%"=="" (
  echo [warn] BALE_BOT_TOKEN is empty - bot is offline. Put it in .env and run start.bat again.
) else (
  "%PHP%" %PHPOPTS% artisan exam:bot-check
)

if not defined AX_NO_BROWSER start "" "http://127.0.0.1:!PORT!/exam-admin"

echo.
echo === running ===
for %%F in (.run\*.pid) do (
  set /p P=<"%%F"
  echo   %%~nF  PID !P!
)
echo   panel       : http://127.0.0.1:!PORT!/exam-admin
echo   update mode : %MODE%
echo   logs        : storage\logs\run-*.log
echo   stop with   : stop.bat
echo.
endlocal
exit /b 0

rem ==========================================================================
:try_php
rem accepts a php.exe whose version is >= 8.2 and < 8.6
if not exist "%~1" exit /b 1
"%~1" -r "exit(version_compare(PHP_VERSION,'8.2.0','>=') && version_compare(PHP_VERSION,'8.6.0','<') ? 0 : 1);" >nul 2>&1 && set "PHP=%~1"
exit /b 0

:env_value
rem %1 key, %2 variable name, %3 default
set "%~2=%~3"
for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b /c:"%~1=" .env 2^>nul`) do set "%~2=%%~B"
exit /b 0

:pick_port
rem keeps the port of a still-running serve; otherwise EXAM_PORT (default 8000) or the next free one up to +10
set "PORT="
if exist ".run\serve.pid" if exist ".run\serve.port" (
  set /p RUNPID=<".run\serve.pid"
  tasklist /FI "PID eq !RUNPID!" /NH 2>nul | find " !RUNPID! " >nul && set /p PORT=<".run\serve.port"
)
if defined PORT exit /b 0
call :env_value EXAM_PORT PORT 8000
set /a PORT_END=PORT+10
:pick_port_loop
netstat -ano -p tcp | findstr /r /c:":!PORT! .*LISTENING" >nul
if errorlevel 1 exit /b 0
set /a PORT+=1
if !PORT! GTR !PORT_END! (echo [ERROR] no free port found & exit /b 1)
goto :pick_port_loop

:mysql_up
"%PHP%" -r "exit(@fsockopen('127.0.0.1',3306,$e,$s,2)?0:1);" >nul 2>&1
exit /b %errorlevel%

:launch
rem %1 name, %2 artisan arguments. Skips when the recorded PID is still alive.
set "AX_NAME=%~1"
set "AX_ARGS=%~2"
set "PIDFILE=.run\%~1.pid"
if exist "%PIDFILE%" (
  set /p OLDPID=<"%PIDFILE%"
  tasklist /FI "PID eq !OLDPID!" /NH 2>nul | find " !OLDPID! " >nul && (
    echo   [skip] %~1 already running ^(PID !OLDPID!^)
    exit /b 0
  )
  del "%PIDFILE%" >nul 2>&1
)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$q=[char]34; $log=Join-Path $env:AX_ROOT ('storage\logs\run-' + $env:AX_NAME + '.log'); $err=Join-Path $env:AX_ROOT ('storage\logs\run-' + $env:AX_NAME + '.err.log'); $cl='cmd.exe /c ' + $q + $q + $env:AX_PHP + $q + ' ' + $env:AX_PHPOPTS + ' ' + $q + (Join-Path $env:AX_ROOT 'artisan') + $q + ' ' + $env:AX_ARGS + ' > ' + $q + $log + $q + ' 2> ' + $q + $err + $q + $q; $r=Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{CommandLine=$cl; CurrentDirectory=$env:AX_ROOT}; if ($r.ReturnValue -eq 0) { [IO.File]::WriteAllText((Join-Path $env:AX_ROOT ('.run\' + $env:AX_NAME + '.pid')), [string]$r.ProcessId) }"
if exist "%PIDFILE%" (
  set /p NEWPID=<"%PIDFILE%"
  echo   [ok] %~1 started ^(PID !NEWPID!^)
) else (
  echo   [ERROR] could not start %~1
)
exit /b 0

:fail
echo.
echo start.bat stopped. Fix the problem above and run it again.
endlocal
exit /b 1

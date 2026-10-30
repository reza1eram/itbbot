@echo off
rem ==========================================================================
rem  ITBbot anonymous exam module - stop everything started by start.bat
rem  Never touches the Bale webhook and never kills PHP processes of other projects.
rem ==========================================================================
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"
set "AX_ROOT=%CD%"

echo.
echo === ITBbot anonymous exam: stop ===

set "STOPPED=0"
if exist ".run\*.pid" (
  for %%F in (.run\*.pid) do (
    set "PID="
    set /p PID=<"%%F"
    if defined PID (
      tasklist /FI "PID eq !PID!" /NH 2>nul | find " !PID! " >nul
      if not errorlevel 1 (
        taskkill /PID !PID! /T /F >nul 2>&1
        echo   [stopped] %%~nF ^(PID !PID!^)
        set /a STOPPED+=1
      ) else (
        echo   [gone]    %%~nF ^(PID !PID! no longer running^)
      )
    )
    del "%%F" >nul 2>&1
  )
) else (
  echo   no PID files in .run\
)

rem Safety net: php.exe processes of THIS project only (command line contains this folder)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$root=$env:AX_ROOT; $n=0; foreach ($p in Get-CimInstance Win32_Process -Filter 'Name=''php.exe''') { $c=[string]$p.CommandLine; if ($c.IndexOf($root,[StringComparison]::OrdinalIgnoreCase) -ge 0 -and $c -match 'artisan.{0,3}\s+(serve|exam:poll|schedule:work|schedule:run|queue:work)|server\.php') { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue; Write-Output ('  [stopped] stray php PID ' + $p.ProcessId); $n++ } }; if ($n -eq 0) { Write-Output '  no stray php processes of this project' }"

rem MySQL only when start.bat started it
if exist ".run\mysql_started_by_us" (
  set /p MPID=<".run\mysql_started_by_us"
  if defined MPID (
    taskkill /PID !MPID! /T /F >nul 2>&1 && echo   [stopped] MySQL started by start.bat ^(PID !MPID!^)
  )
  del ".run\mysql_started_by_us" >nul 2>&1
) else (
  echo   MySQL left running ^(not started by start.bat^)
)

echo   webhook: untouched
echo === done ===
echo.
endlocal
exit /b 0

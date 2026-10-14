@echo off
rem Instalacja na Windowsie: dwuklik albo z opcjami, np.  install.cmd -Port 8766 -Forma f
rem Szczegoly: README.md, sekcja "Instalacja (Windows)".
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
set WORKLOG_EXIT=%ERRORLEVEL%
echo.
pause
exit /b %WORKLOG_EXIT%

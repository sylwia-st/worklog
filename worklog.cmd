@echo off
rem Obsluga aplikacji na Windowsie, np.  worklog.cmd status  (lista polecen:  worklog.cmd help)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0worklog.ps1" %*
exit /b %ERRORLEVEL%

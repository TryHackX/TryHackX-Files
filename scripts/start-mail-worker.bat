@echo off
setlocal
if exist "%~dp0filehost-env.bat" call "%~dp0filehost-env.bat"
cd /d "%~dp0\.."
echo [TryHackX Files] Starting the e-mail outbox worker...
php scripts\mail-worker.php --loop --limit=25 --sleep=5

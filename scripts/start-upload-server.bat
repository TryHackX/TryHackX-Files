@echo off
rem TryHackX Files upload server launcher for Windows.
rem Creates the virtual environment on first use and starts upload_server.py.

setlocal
if exist "%~dp0filehost-env.bat" call "%~dp0filehost-env.bat"
cd /d "%~dp0.."

if not exist "venv\Scripts\python.exe" (
    echo [TryHackX Files] Creating the Python environment...
    python -m venv venv || goto :error
    venv\Scripts\python -m pip install --require-hashes -r requirements-lock.txt || goto :error
)

echo [TryHackX Files] Starting the upload server on port 8001...
venv\Scripts\python upload_server.py
goto :eof

:error
echo [TryHackX Files] Python dependency installation failed. Verify that Python 3.11+ is installed.
pause

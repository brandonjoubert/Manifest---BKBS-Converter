@echo off
REM BKBS Converter — Local PC installer (Windows, Python edition)
set ROOT=%~dp0..\..
cd /d "%ROOT%"

echo === BKBS Converter · Local PC install (Python) ===
echo Install path: %CD%
echo.

where python >nul 2>nul
if errorlevel 1 (
  echo ERROR: python not found. Install Python 3.10+ from python.org and re-run.
  exit /b 1
)

python -m venv .venv
call .venv\Scripts\activate.bat
python -m pip install --upgrade pip
pip install -r requirements.txt

if not exist .env copy .env.example .env

if not exist data mkdir data
if not exist data\exports mkdir data\exports
if not exist data\live mkdir data\live

echo.
echo Install complete.
echo Start with:
echo   cd /d "%CD%"
echo   .venv\Scripts\activate
echo   uvicorn app.main:app --host 127.0.0.1 --port 8765
echo.
echo Then open http://127.0.0.1:8765
pause

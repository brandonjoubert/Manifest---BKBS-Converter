@echo off
REM Manifest BKBS Converter — Local PC installer (Windows, Python edition)
set ROOT=%~dp0..\..
cd /d "%ROOT%"

echo === Manifest BKBS Converter · Local PC install (Python) ===
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
if not exist data\live-public mkdir data\live-public

echo.
echo Running smoke checks...
pytest -q
if errorlevel 1 (
  echo Tests failed.
  exit /b 1
)
python scripts\verify_exports.py --edition python
if errorlevel 1 (
  echo Stage 0 export verify failed.
  exit /b 1
)
echo Smoke checks passed.

echo.
echo Install complete.
echo Start with:
echo   cd /d "%CD%"
echo   .venv\Scripts\activate
echo   uvicorn app.main:app --host 127.0.0.1 --port 8765
echo.
echo Then open http://127.0.0.1:8765
echo Workflow: Add site - Scan - Edit pending entities - Approve - Publish live
echo Local demo publish root: %CD%\data\live-public
echo Docs: INSTALL.md
pause

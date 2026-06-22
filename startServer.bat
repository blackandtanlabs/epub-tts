@echo off
title Piper Flask Server (WSL)

REM === CONFIG ===
set WSL_DISTRO=Ubuntu
set WSL_WORKDIR=/mnt/c/xampp/htdocs/TTS
set FLASK_FILE=TTSserver_v3_single_hot.py
set LOG_FILE=/mnt/c/xampp/htdocs/TTS/piper_server.log
set PORT=8077

echo.
echo Starting Piper server in %WSL_DISTRO% ...
echo Working directory: %WSL_WORKDIR%
echo Flask app: %FLASK_FILE%
echo Log file: %LOG_FILE%
echo Port: %PORT%
echo.

REM === Launch inside WSL ===
wsl
cd "$PROJECT"
echo [WSL] Activating venv if present...
source ./venv/bin/activate
echo [WSL] Launching Flask on port %PORT%...
python3 %FLASK_FILE%
"

pause

@echo off
REM This file is part of EPUB TTS, created by Patrick Clark.
REM
REM EPUB TTS is free software: you can redistribute it and/or modify it under the
REM terms of the GNU General Public License, version 3 or (at your option) any
REM later version, as published by the Free Software Foundation. It comes with NO
REM WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
REM
REM Copyright (C) 2016-2026 Patrick Clark and family.
REM
REM Patrick built EPUB TTS over many years. The GPL licensing was applied by his
REM family when the project was made public, to keep his work free for everyone --
REM honoring his wishes. It was not part of the original source.
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
wsl -d %WSL_DISTRO% --cd %WSL_WORKDIR% bash -ic "
echo [WSL] Activating venv if present...
if [ -f ./venv/bin/activate ]; then source ./venv/bin/activate; fi;
echo [WSL] Launching Flask on port %PORT%...
python3 %FLASK_FILE% 2>&1 | tee %LOG_FILE%
"

pause

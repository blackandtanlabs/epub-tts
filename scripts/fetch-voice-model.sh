#!/usr/bin/env bash
# This file is part of EPUB TTS, created by Patrick Clark.
#
# EPUB TTS is free software: you can redistribute it and/or modify it under the
# terms of the GNU General Public License, version 3 or (at your option) any
# later version, as published by the Free Software Foundation. It comes with NO
# WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
#
# Copyright (C) 2016-2026 Patrick Clark and family.
#
# Patrick built EPUB TTS over many years. The GPL licensing was applied by his
# family when the project was made public, to keep his work free for everyone --
# honoring his wishes. It was not part of the original source.
# Download the neural voice model the engine speaks with.
#
# The model is the standard Piper "en_US-libritts_r-medium" voice — 904
# speakers, the same library the voice tuning in labelCheck.db was built for.
# It is ~75 MB, so it lives outside the repository and is fetched on demand.
set -euo pipefail

cd "$(dirname "$0")/.."

MODEL="en_US-libritts_r-medium.onnx"
CONFIG="en_US-libritts_r-medium.onnx.json"
BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/libritts_r/medium"

fetch() {
    local name="$1"
    if [ -f "$name" ]; then
        echo "  already present: $name"
        return
    fi
    echo "  downloading: $name"
    curl -fL --progress-bar -o "$name" "$BASE/$name?download=true"
}

echo "Fetching the voice model into $(pwd) ..."
fetch "$MODEL"
fetch "$CONFIG"
echo "Done. The engine will load $MODEL on first request."

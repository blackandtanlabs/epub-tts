#!/usr/bin/env bash
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

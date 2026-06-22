<?php
declare(strict_types=1);

/**
 * config.php — central configuration for the TTS reader
 *
 * Adjust these for your environment.
 */

// --- Flask Piper server (running in WSL) ---
const FLASK_SPEAK_URL = 'http://127.0.0.1:8077/speak';

// --- Client paragraph provider ---
// Exposes: /client/para?book=<bookNo>&p=<int>
// Example: http://127.0.0.1:8888/client/para
const CLIENT_BASE_URL = 'http://127.0.0.1:8888';

// --- Audio access safety ---
// Only allow streaming files from these Windows folders (prefix match, case-insensitive).
// Add your Piper output folders here.
const ALLOWED_AUDIO_ROOTS = [
    'C:\\xampp\\htdocs\\piper\\audio',
    'C:\\xampp\\htdocs\\piper\\tts_project\\audio',
    'C:\\piper\\audio',
];

// --- Buffering (bytes, not sentences) ---
const BUFFER_MIN_BYTES = 2621440;  // 2.5 MB to start/resume
const BUFFER_MAX_BYTES = 10485760; // 10  MB ceiling to avoid wasted CPU

// --- Concurrency ---
// How many /speak requests to have in flight at once (1 is safest; raise to 2–3 if your server queues internally)
const SPEAK_MAX_INFLIGHT = 1;

// --- Reader UI defaults ---
const THEME_DEFAULT = 'dark'; // 'dark' or 'light'

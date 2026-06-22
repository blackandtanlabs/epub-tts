<?php
/*
 * This file is part of EPUB TTS, created by Patrick Clark.
 *
 * EPUB TTS is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License, version 3 or (at your option) any
 * later version, as published by the Free Software Foundation. It comes with NO
 * WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
 *
 * Copyright (C) 2016-2026 Patrick Clark and family.
 *
 * Patrick built EPUB TTS over many years. The GPL licensing was applied by his
 * family when the project was made public, to keep his work free for everyone --
 * honoring his wishes. It was not part of the original source.
 */
declare(strict_types=1);
/**
 * api_speak_proxy.php — Minimal CORS-avoiding relay to Flask /speak
 * Reads JSON body { sid, text }, POSTs to http://127.0.0.1:8077/speak, returns JSON.
 */

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '1');
//error_log("[speak] hit from ".$_SERVER['REMOTE_ADDR']);
//error_log("[speak.php] forwarding to http://127.0.0.1:8077/speak");
// If you later expose across TailScale/LAN, you can still keep this proxy local.
require_once __DIR__ . '/config.php';
$FLASK_URL = ENGINE_URL;

// Read inbound JSON
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'empty_body']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['sid']) || !isset($payload['text'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'invalid_body']);
    exit;
}
$payload['text'] = preg_replace('/“/', '', $payload['text']);
$payload['text'] = preg_replace('/\s\s+/', ' ', $payload['text']);
if (mb_substr($payload['text'], 0, 2) !== '⦃v') 
  $breakpoint=1;
// Forward to Flask
$ch = curl_init($FLASK_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 0, // let Flask block until ready
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
if ($errno !== 0) {
    http_response_code(502);
    echo json_encode(['ok'=>false, 'error'=>'flask_unreachable', 'detail'=>$err]);
    exit;
}

http_response_code($http ?: 200);
echo $resp ?: json_encode(['ok'=>false, 'error'=>'empty_response']);

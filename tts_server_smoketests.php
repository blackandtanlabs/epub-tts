<?php
// tts_server_smoketests_rtf.php
// Run a few requests against /speak and report Real-Time Factor (RTF).
// RTF(server) = server.elapsed_sec / wav_duration
// RTF(client) = wall_time_for_HTTP / wav_duration

$BASE = "http://127.0.0.1:8077";
$ENDPOINT = "$BASE/speak";
$OUTDIR = __DIR__ . DIRECTORY_SEPARATOR . "audio";

// ---------- helpers ----------
function curl_json_post($url, $payload, &$http_code, &$wall_sec, &$raw_resp) {
    $ch = curl_init($url);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json; charset=utf-8"],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HEADER => false,
    ]);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $t1 = microtime(true);
    $wall_sec = $t1 - $t0;
    $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    $raw_resp = $raw;
    if ($errno !== 0) {
        return ["ok" => false, "error" => "curl_errno_$errno", "detail" => $err];
    }
    $dec = json_decode($raw, true);
    if ($dec === null) {
        // Non-JSON (e.g., 500 with plain text)
        return ["ok" => false, "error" => "non_json_response", "detail" => $raw];
    }
    return $dec;
}

function fmtf($x, $d=3) { return number_format((float)$x, $d, '.', ''); }

// Parse essential WAV fields and compute duration (seconds)
function read_wav_meta($path) {
    $f = @fopen($path, 'rb');
    if (!$f) return [false, "cannot_open", null];

    // Basic RIFF/WAVE header (we’ll search for the "fmt " and "data" chunks)
    $hdr = fread($f, 12);
    if (strlen($hdr) < 12 || substr($hdr,0,4)!="RIFF" || substr($hdr,8,4)!="WAVE") {
        fclose($f);
        return [false, "not_wav", null];
    }

    $fmt_found = false;
    $data_found = false;
    $num_channels = null;
    $sample_rate = null;
    $bits_per_sample = null;
    $data_size = null;

    while (!feof($f)) {
        $chunk = fread($f, 8);
        if (strlen($chunk) < 8) break;
        $ck_id = substr($chunk, 0, 4);
        $ck_size = unpack("V", substr($chunk, 4, 4))[1];
        if ($ck_id === "fmt ") {
            $fmt = fread($f, $ck_size);
            if (strlen($fmt) >= 16) {
                $num_channels    = unpack("v", substr($fmt, 2, 2))[1];   // offset 2
                $sample_rate     = unpack("V", substr($fmt, 4, 4))[1];   // offset 4
                $bits_per_sample = unpack("v", substr($fmt,14, 2))[1];   // offset 14
                $fmt_found = true;
            } else {
                fseek($f, $ck_size, SEEK_CUR);
            }
        } elseif ($ck_id === "data") {
            $data_size = $ck_size;
            fseek($f, $ck_size, SEEK_CUR);
            $data_found = true;
        } else {
            fseek($f, $ck_size, SEEK_CUR);
        }

        if ($fmt_found && $data_found) break;
    }
    fclose($f);

    if (!$fmt_found || !$data_found || !$sample_rate || !$num_channels || !$bits_per_sample || $data_size===null) {
        return [false, "wav_chunks_missing", null];
    }

    $bytes_per_sample = $bits_per_sample / 8.0;
    if ($bytes_per_sample <= 0) return [false, "bad_bits_per_sample", null];

    $duration = $data_size / ($sample_rate * $num_channels * $bytes_per_sample);
    $meta = [
        "sr" => $sample_rate,
        "ch" => $num_channels,
        "bits" => $bits_per_sample,
        "size_bytes" => $data_size,
        "duration_sec" => $duration
    ];
    return [true, null, $meta];
}

function print_header($title) {
    echo "=== $title ===\n";
}

function print_divider() {
    echo str_repeat("-", 72) . "\n";
}

// ---------- tests ----------
$tests = [
    [
        "title" => "SID_V_ONLY",
        "body"  => ["sid"=>"SID_V_ONLY", "text"=>"⦃v:50⦄Hello world. ⦃v:51⦄This is baseline speec"],
    ],
    [
        "title" => "SID_WAITS",
        "body"  => ["sid"=>"SID_WAITS", "text"=>"⦃v:50⦄Lead ⦃w:400⦄ Middle ⦃w:300⦄ pause ⦃w:300⦄ tail."],
    ],
    [
        "title" => "SID_CHANNELS",
        "body"  => ["sid"=>"SID_CHANNELS", "text"=>"⦃v:50⦄⦃c:left⦄Left only. ⦃c:center⦄Center. ⦃c:in-head⦄In head. ⦃c:right⦄Right only."],
    ],
    [
        "title" => "SID_SILENCE_OVERRIDE",
        "body"  => ["sid"=>"SID_SILENCE_OVERRIDE", "text"=>"⦃v:50⦄Base stop. ⦃r:longer⦄Longer inter-sentence pause active here.⦃r⦄ Base again."],
    ],
];

// ---------- run ----------
$ok_count = 0;
$rtf_server_sum = 0.0;
$rtf_client_sum = 0.0;
$rtf_samples = 0;

foreach ($tests as $t) {
    $title = $t["title"];
    $body  = $t["body"];

    $http = 0;
    $wall = 0.0;
    $raw  = "";

    print_header($title);

    $resp = curl_json_post($ENDPOINT, $body, $http, $wall, $raw);

    echo $http >= 200 && $http < 300
        ? "→ HTTP $http | "
        : "→ HTTP $http | ";

    if (!isset($resp["ok"]) || $resp["ok"] !== true) {
        // Show error detail
        $detail = isset($resp["detail"]) ? $resp["detail"] : "(no detail)";
        echo "ERR ❌ | " . (isset($resp["error"]) ? $resp["error"] : "unknown") . " | detail: $detail\n";
        print_divider();
        continue;
    }

    // Success path
    $ok_count++;
    $wav_path = normalize_wav_path($resp) ?? "(missing)";
    $elapsed  = isset($resp["elapsed_sec"]) ? (float)$resp["elapsed_sec"] : NAN;

    echo "OK ✅ | wav_path: $wav_path | elapsed(server): " . fmtf($elapsed, 3) . "s | http_wall: " . fmtf($wall, 3) . "s\n";

    // Inspect WAV if present
    if (!is_string($wav_path) || !file_exists($wav_path)) {
        echo "  WAV: (missing)\n";
        print_divider();
        continue;
    }

    // Basic file size
    $fsize = filesize($wav_path);

    list($ok, $err, $meta) = read_wav_meta($wav_path);
    if (!$ok) {
        echo "  WAV: size=" . number_format($fsize) . " bytes | meta_error=$err\n";
        print_divider();
        continue;
    }

    $dur = (float)$meta["duration_sec"];
    $sr  = $meta["sr"];
    $ch  = $meta["ch"];
    $bits= $meta["bits"];

    // Compute RTFs
    $rtf_server = ($dur > 0.0 && is_finite($elapsed)) ? ($elapsed / $dur) : NAN;
    $rtf_client = ($dur > 0.0) ? ($wall / $dur) : NAN;

    // Accumulate summary
    if (is_finite($rtf_server)) { $rtf_server_sum += $rtf_server; $rtf_samples++; }
    if (is_finite($rtf_client)) { $rtf_client_sum += $rtf_client; }

    echo "  WAV: size=" . number_format($fsize) . " bytes | sr=$sr ch=$ch bits=$bits | dur=" . fmtf($dur, 3) . "s\n";
    echo "  RTF(server)= " . fmtf($rtf_server, 3) . " | RTF(client_wall)= " . fmtf($rtf_client, 3) . "\n";

    // Optional: show brief segment breakdown from response
    if (!empty($resp["segments"]) && is_array($resp["segments"])) {
        $seg_count = count($resp["segments"]);
        echo "  segments: $seg_count (speech+silence)\n";
    }

    print_divider();
}

// ---------- summary ----------
if ($rtf_samples > 0) {
    $avg_server = $rtf_server_sum / $rtf_samples;
    $avg_client = $rtf_client_sum / $rtf_samples;
    echo "SUMMARY: $ok_count OK out of " . count($tests) . " | avg RTF(server)= " . fmtf($avg_server, 3) . " | avg RTF(client_wall)= " . fmtf($avg_client, 3) . "\n";
} else {
    echo "SUMMARY: no successful tests to compute RTF.\n";
}
function normalize_wav_path(array $resp): ?string {
    // Prefer explicit Windows path if the server provides it
    if (!empty($resp['wav_path_win'])) {
        return $resp['wav_path_win'];
    }
    // Fallback: convert WSL path (/mnt/c/...) -> Windows path (C:\...)
    if (!empty($resp['wav_path']) && preg_match('#^/mnt/([a-zA-Z])/(.*)$#', $resp['wav_path'], $m)) {
        $drive = strtoupper($m[1]);
        $rest  = str_replace('/', '\\', $m[2]);
        return $drive . ':\\' . $rest;
    }
    return $resp['wav_path'] ?? null;
}


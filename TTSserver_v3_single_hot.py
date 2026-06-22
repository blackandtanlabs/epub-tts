# -*- coding: utf-8 -*-
"""
TTSserver_v3_single_hot_pyapi.py
Single Flask server using the Piper Python API (piper-tts 1.3.x) to keep the model hot.
Implements your embedded-token protocol (⦃v/r/c/w⦄), SQLite params, SoX post, and final join.
- Recipes: NULL means "no change" (multiplicative for non-NULL fields).
- Volume: multiplicative (applied to float PCM).
- Channel: left/right/center/head (earwax only for center).
- Pitch: via SoX 'pitch <cents>'.
- Output: 44.1kHz, 16-bit, stereo PCM WAV.
"""

import os, re, json, math, time, uuid, wave, sqlite3, shutil, tempfile, threading, subprocess, traceback, logging
import unicodedata, sys
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple
from flask import Flask, request, jsonify

# ----------------------------- CONFIG (run under WSL) -----------------------------
CWD          = "/mnt/c/xampp/htdocs/TTS"
DB_PATH      = os.path.join(CWD, "labelCheck.db")
MODEL_PATH   = os.path.join(CWD, "en_US-libritts_r-medium.onnx")
AUDIO_DIR    = os.path.join(CWD, "audio")      # final outputs
TMP_DIR_ROOT = os.path.join(CWD, "_tmp_v3")    # temp working dir

SOX_EXE      = "sox"   # apt install sox libsox-fmt-all
HOST, PORT, DEBUG   = "127.0.0.1", 8077, True

FINAL_SR     = 44100
FINAL_CH     = 2
FINAL_BITS   = 16

TEXT_MAX_CHARS    = 10000
SEGMENT_MAX_COUNT = 2000
WAIT_MAX_MS       = 10000

VALID_CHANNELS = {"left", "right", "center", "head"}
DEFAULT_CHANNEL = "head"

logging.basicConfig(level=logging.DEBUG)

# ----------------------------- UTILS -----------------------------
def ensure_dirs():
    os.makedirs(AUDIO_DIR, exist_ok=True)
    os.makedirs(TMP_DIR_ROOT, exist_ok=True)

def ts() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")

def safe_basename(name: str) -> str:
    name = name.strip().replace("\\", "/").split("/")[-1]
    return re.sub(r"[^A-Za-z0-9_\-\.]+", "_", name)

def text_cleanup_for_piper(text: str) -> str:
    """Replaces smart quotes/apostrophes with straight quotes/apostrophes."""
    return (
        text.replace("“", '')
            .replace("”", '')
            .replace("‘", "'")
            .replace("’", "'")
    )

def bad_request(code: str, detail: str):
    logging.info("ok: ", False, "error: ", code, "detail: ", detail)
    return jsonify({"ok": False, "error": code, "detail": detail}), 400

def server_error(detail: str):
    logging.info("ok: ", False, "detail: ", detail)
    # logging.info(jsonify({"ok": False, "error": "synthesis_failed", "detail": detail}))
    return jsonify({"ok": False, "error": "synthesis_failed", "detail": detail}), 500

# ----------------------------- DB -----------------------------
def db_get_voice(conn: sqlite3.Connection, voice_number: int) -> Optional[Dict[str, Any]]:
    cur = conn.execute(
        "SELECT voice_number, newSpeed, pitch, volume, noise, noise_w, silence FROM voice_params WHERE voice_number=?",
        (voice_number,)
    )
    row = cur.fetchone()
    if not row: return None
    return dict(zip(["voice_number","newSpeed","pitch","volume","noise","noise_w","silence"], row))

def db_get_recipe(conn: sqlite3.Connection, name: str) -> Optional[Dict[str, Any]]:
    cur = conn.execute(
        "SELECT name, speed, pitch, volume, noise, noise_w, silence FROM recipes WHERE name=?",
        (name,)
    )
    row = cur.fetchone()
    if not row: return None
    return dict(zip(["name","speed","pitch","volume","noise","noise_w","silence"], row))

# ----------------------------- TOKENS -----------------------------
# Matches ⦃v:n⦄, ⦃r:name⦄, ⦃x:⦄, ⦃c:name⦄, ⦃w:ms⦄
TOKEN_RE = re.compile(
    r"[\u27C3\u2983]\s*(?:(v:)(\d+)|(r:)([\w\-]+)|(x:)|(c:)(left|right|center|head)|(w:)([\d.]+))\s*[\u27C4\u2984]",
    flags=re.I,
)


class SegItem:
    def __init__(self, index:int, kind:str, text:str="", ms:int=0, voice:Optional[int]=None, recipes:Optional[List[str]]=None, channel:str=DEFAULT_CHANNEL):
        self.index=index; self.kind=kind; self.text=text; self.ms=ms
        self.voice=voice; self.recipes=list(recipes or []); self.channel=channel

def parse_text_into_segments(text: str) -> Tuple[List[SegItem], List[str]]:
    errs: List[str] = []
    segs: List[SegItem] = []
    idx=0
    cur_voice: Optional[int] = None
    cur_recipes: List[str] = []
    cur_channel: str = DEFAULT_CHANNEL
    buf: List[str] = []
    pending_ms = 0
    last = 0

    def flush_speech():
        nonlocal idx, buf
        if buf:
            t = "".join(buf)
            if t:
                segs.append(SegItem(idx, "speech", t, voice=cur_voice, recipes=cur_recipes, channel=cur_channel))
                idx += 1
            buf.clear()

    for m in TOKEN_RE.finditer(text):
        s,e = m.span()
        if pending_ms>0 and s>last:
            flush_speech()
            segs.append(SegItem(idx, "silence", ms=pending_ms)); idx += 1
            pending_ms = 0

        if s>last: buf.append(text[last:s])
        last=e

        v_tag, v_num, r_push, r_name, r_pop, c_tag, c_name, w_tag, w_ms = m.groups()
        if pending_ms>0:
            flush_speech()
            segs.append(SegItem(idx, "silence", ms=pending_ms)); idx += 1
            pending_ms=0

        if v_tag:
            flush_speech()
            try: cur_voice = int(v_num)
            except: errs.append("malformed_voice"); continue
            cur_recipes = []  # clear stack on voice switch
        elif r_push and r_name:
            flush_speech()
            cur_recipes = cur_recipes + [r_name]
        elif r_pop:
            flush_speech()
            if cur_recipes: cur_recipes = cur_recipes[:-1]
        elif c_tag and c_name:
            flush_speech()
            cur_channel = c_name.lower()
            if cur_channel not in VALID_CHANNELS: errs.append("unknown_channel")
        elif w_tag and w_ms:
            try: val = int(w_ms)
            except: errs.append("malformed_wait"); continue
            if val<0 or val>WAIT_MAX_MS: errs.append("malformed_wait"); continue
            pending_ms += val

    if last < len(text):
        if pending_ms>0:
            flush_speech()
            segs.append(SegItem(idx,"silence",ms=pending_ms)); idx+=1; pending_ms=0
        buf.append(text[last:])

    if pending_ms>0:
        flush_speech()
        segs.append(SegItem(idx,"silence",ms=pending_ms)); idx+=1; pending_ms=0

    flush_speech()

    for seg in segs:
        if seg.kind=="speech" and seg.voice is None:
            errs.append("unknown_voice"); break

    segs = [s for s in segs if not (s.kind=="speech" and not s.text)]
    return segs, errs

# ----------------------------- PARAM RESOLUTION -----------------------------
def resolve_params_for_segment(conn: sqlite3.Connection, seg: SegItem) -> Tuple[Dict[str, Any], Optional[str]]:
    v = db_get_voice(conn, seg.voice)
    if not v: return {}, "unknown_voice"
    # Base params
    speed   = float(v["newSpeed"])
    pitch   = float(v["pitch"])
    volume  = float(v["volume"])
    noise   = float(v["noise"])
    noise_w = float(v["noise_w"])
    silence = float(v["silence"])

    # Apply recipes; NULL => no change; multiplicative where present        CHANGED pitch TO BE ADDITIVE AND ABOVE OR BELOW ZERO
    for rname in seg.recipes:
        r = db_get_recipe(conn, rname)
        if not r: return {}, "unknown_recipe"
        if r["speed"]: speed   = round(speed   * float(r["speed"]),   2)
        if r["pitch"]: pitch   = round(pitch   * float(r["pitch"]),   2)
        if r["volume"]: volume  = round(volume  * float(r["volume"]),  2)
        if r["noise"]: noise   = round(noise   * float(r["noise"]),   2)
        if r["noise_w"]: noise_w = round(noise_w * float(r["noise_w"]), 2)
        if r["silence"]: silence = float(r["silence"])
    if speed<=0:  return {}, "malformed_speed"
    if volume<=0: return {}, "malformed_volume"
    if noise<0 or noise_w<0 or silence<0: return {}, "malformed_params"

    return {
        "speed": float(speed),
        "pitch": float(pitch),
        "volume": float(volume),
        "noise": float(noise),
        "noise_w": float(noise_w),
        "silence": float(silence)
    }, None

# ----------------------------- HOT PIPER (Python API) -----------------------------
from piper import PiperVoice
import inspect
from piper.config import SynthesisConfig

class HotPiperPython:
    """
    Keeps a PiperVoice loaded in-memory. Thread-safe synth() writes mono WAVs at model sample rate.
    Applies multiplicative volume on float PCM before writing.
    Handles piper-tts variants and AudioChunk iterations.
    """
    def __init__(self, model_path: str):
        self.model_path = model_path
        self.voice = None
        self.sample_rate = None
        self.lock = threading.RLock()
        self._load()

    def _load(self):
        import inspect
        self.voice = PiperVoice.load(self.model_path, None)
 
        try:
            sig = inspect.signature(self.voice.synthesize)
        except Exception as e:
            logging.warning("inspect.signature failed: %s", e)

        self.sample_rate = int(getattr(self.voice, "sample_rate", 22050))
        # Warm once to avoid first-phoneme clipping
        try:
            self._synth_to_wav(";", speaker=0, length_scale=1.0, noise_scale=0.667,
                               noise_w=0.8, volume=1.0, out_wav=None,
                               sentence_silence=0.8, warmup=True)
        except Exception:
            pass

    def _mix_to_mono(self, arr, channels: int):
        import numpy as np
        if channels and channels > 1:
            if arr.ndim == 1 and arr.size % channels == 0:
                arr = arr.reshape(-1, channels).mean(axis=1)
            elif arr.ndim == 2 and arr.shape[1] == channels:
                arr = arr.mean(axis=1)
        return arr

    def _extract_samples(self, item):
        """
        Extract numpy float32 samples from AudioChunk-like objects or arrays:
        - Prefer float arrays: item.audio_float_array
        - Else int16 arrays/bytes: audio_int16_array / audio_int16_bytes  -> convert to float32 / 32767
        - Else generic .samples/.audio/.pcm/.data
        - Else tuples/lists/ndarrays
        Also mixes down to mono if sample_channels > 1.
        """
        import numpy as np
        # Attrs specific to your build
        if hasattr(item, "audio_float_array"):
            arr = np.asarray(item.audio_float_array, dtype=np.float32)
        elif hasattr(item, "audio_int16_array"):
            arr = np.asarray(item.audio_int16_array, dtype=np.int16).astype(np.float32) / 32767.0
        elif hasattr(item, "audio_int16_bytes"):
            arr = np.frombuffer(item.audio_int16_bytes, dtype=np.int16).astype(np.float32) / 32767.0
        else:
            # Common generic attrs
            for attr in ("samples", "audio", "pcm", "data", "buffer"):
                if hasattr(item, attr):
                    try:
                        arr = np.asarray(getattr(item, attr), dtype=np.float32)
                        break
                    except Exception:
                        continue
            else:
                # Tuples like (samples, sr)
                if isinstance(item, tuple) and len(item) >= 1:
                    arr = np.asarray(item[0], dtype=np.float32)
                elif isinstance(item, (list, tuple)):
                    arr = np.asarray(item, dtype=np.float32)
                else:
                    raise TypeError(f"unknown audio chunk type: {type(item)!r} attrs={dir(item)!r}")

        # Discover SR/channels if present
        sr = getattr(item, "sample_rate", None)
        ch = getattr(item, "sample_channels", 1)
        arr = self._mix_to_mono(arr, int(ch) if ch else 1)

        # Update sample rate once if Piper reported it
        if sr and (self.sample_rate is None or self.sample_rate <= 0):
            try: self.sample_rate = int(sr)
            except: pass

        return arr

    def _synth_to_wav(self, text: str, *, speaker: int, length_scale: float,
                        noise_scale: float, noise_w: float, volume: float,
                        out_wav: Optional[str], sentence_silence: float,
                        warmup: bool=False) -> Optional[str]:
            """
            Call PiperVoice.synthesize, focusing on robust iterator processing.
            """
            import numpy as np

            config_kwargs = {}
            # Speaker ID is confirmed valid (0-903)
            if self.voice.config.num_speakers > 1:
                config_kwargs['speaker_id'] = int(speaker)

            config_kwargs['length_scale'] = float(length_scale)
            config_kwargs['noise_scale'] = float(noise_scale)
            config_kwargs['noise_w_scale'] = float(noise_w) 
            config_kwargs['volume'] = float(volume) 
            config_kwargs['normalize_audio'] = False
            
            try:
                config = SynthesisConfig(**config_kwargs) 
                pcm_iter = self.voice.synthesize(
                    text, 
                    syn_config=config                )
            except Exception as e:
                logging.error("Piper synthesize failed during config/setup: %s", e)
                raise 

            # --- CRUCIAL CHANGE: ITERATOR HANDLING ---
            chunks = []
            try:
                # We iterate directly over the chunks returned by the new API
                for chunk in pcm_iter:
                    # Use the dedicated extraction method for robustness
                    arr = self._extract_samples(chunk) 

                    if arr is not None and arr.size:
                        chunks.append(arr)
            except Exception as e:
                # If the error is here, the iterator is the problem.
                import traceback
                traceback.print_exc()
                logging.error("Failure while processing audio chunks (post-synthesis): %s", e)
                raise 
            # --- END CRUCIAL CHANGE ---
            
            # 3. Concatenate and process
            pcm = np.concatenate(chunks, axis=0)

            # Apply volume *** moved volume to SoX
            if not warmup and volume not in (None, 1.0):
                pcm *= float(volume)
            
            if out_wav is None:
                return None  # warmup path

            # Write mono int16 WAV at model sample rate
            if not self.sample_rate or self.sample_rate <= 0:
                self.sample_rate = 22050  # conservative fallback
            pcm = np.clip(pcm, -1.0, 1.0)
            pcm_i16 = (pcm * 32767.0).astype("int16")
            with wave.open(out_wav, 'wb') as w:
                w.setnchannels(1)
                w.setsampwidth(2)
                w.setframerate(self.sample_rate)
                w.writeframes(pcm_i16.tobytes())
            return out_wav

    def synth(self, text: str, *, speaker: int, length_scale: float,
                noise_scale: float, noise_w: float, volume: float, out_wav: str) -> Optional[str]:
        with self.lock:
            clean = text.replace("\r"," ").replace("\n"," ")
            return self._synth_to_wav(text, speaker=speaker, length_scale=length_scale,
                                        noise_scale=noise_scale, noise_w=noise_w,
                                        volume=volume, out_wav=out_wav,
                                        sentence_silence=0.8, warmup=False)

# ----------------------------- SOX / JOIN -----------------------------
def channel_recipe(chan: str) -> Tuple[List[str], bool]:
    if chan == "left":    return (["remix","1","0"], False)
    if chan == "head": return (["remix","1","1"], False)
    if chan == "center":  return (["remix","1","1"], True)   # earwax only for center
    if chan == "right":   return (["remix","0","1"], False)
    raise ValueError("unknown_channel")

def sox_postprocess(in_wav: str, chan: str, pitch: float, volume: float, tmp_dir: str) -> Tuple[int, Optional[str], str]:
    out_wav = os.path.join(tmp_dir, f"seg_{uuid.uuid4().hex[:8]}_final.wav")
    try:
        remix_args, need_earwax = channel_recipe(chan)
    except ValueError:
        return -1, None, "unknown_channel"

    effects: List[str] = []
    effects += remix_args
    effects += ["channels","2","rate",str(FINAL_SR)]
    effects += ["pitch", str(pitch)]
    if need_earwax: effects += ["earwax"]

    cmd = [SOX_EXE, "-D", "--multi-threaded", in_wav, "-b", str(FINAL_BITS), out_wav] + effects
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if p.returncode != 0 or not os.path.exists(out_wav):
        return p.returncode, None, (p.stderr.strip() or p.stdout.strip() or "sox_failed")
    return 0, out_wav, ""
def wsl_to_winpath(p: str) -> str:
    if p.startswith("/mnt/") and len(p) >= 7 and p[5].isalpha() and p[6] == "/":
        drive = p[5].upper()
        rest = p[7:]
        return f"{drive}:\\" + rest.replace("/", "\\")
    return p
def join_wavs(final_path: str, parts: List[Tuple[Optional[str], Optional[int]]]):
    sampwidth = FINAL_BITS // 8
    silence_cache: Dict[int, bytes] = {}
    with wave.open(final_path, 'wb') as out_w:
        out_w.setnchannels(FINAL_CH)
        out_w.setsampwidth(sampwidth)
        out_w.setframerate(FINAL_SR)
        for wav_path, ms in parts:
            if wav_path:
                with wave.open(wav_path, 'rb') as in_w:
                    out_w.writeframes(in_w.readframes(in_w.getnframes()))
            else:
                ms = int(ms or 0)
                if ms<=0: continue
                nframes = int(round(ms/1000.0 * FINAL_SR))
                nbytes  = nframes * FINAL_CH * sampwidth
                buf = silence_cache.get(nbytes)
                if buf is None:
                    buf = b"\x00" * nbytes
                    silence_cache[nbytes] = buf
                out_w.writeframes(buf)

# ----------------------------- FLASK -----------------------------
app = Flask(__name__)

@app.errorhandler(Exception)
def handle_any_exception(e):
    traceback.print_exc()
    logging.info(jsonify({"ok": False, "error": "synthesis_failed", "detail": f"internal_error: {e}"}))
    return jsonify({"ok": False, "error": "synthesis_failed", "detail": f"internal_error: {e}"}), 500

HOT = None  # HotPiperPython instance (lazy)

@app.route("/speak", methods=["POST"])
def speak():
    global HOT
    t0 = time.time()
    ensure_dirs()

    if not request.is_json:
        return bad_request("invalid_json", "Content-Type must be application/json")
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return bad_request("invalid_json", "Body must be an object")

    sid  = data.get("sid")
    text = data.get("text")
    if not sid or not isinstance(sid, str):
        return bad_request("missing_sid", "sid is required")
    if not text or not isinstance(text, str):
        return bad_request("missing_text", "text is required")
    if len(text) > TEXT_MAX_CHARS:
        return bad_request("too_long", f"TEXT_MAX_CHARS={TEXT_MAX_CHARS}")
    clean_text = text_cleanup_for_piper(text)
    segments, parse_errs = parse_text_into_segments(clean_text)
    if parse_errs:
        code = parse_errs[0]
        if code == "malformed_wait":  return bad_request("malformed_wait", "w: value invalid or out of range")
        if code == "unknown_channel": return bad_request("unknown_channel", "c: name invalid")
        if code == "unknown_voice":   return bad_request("unknown_voice",  "no initial ⦃v:n⦄ before speech")
        if code == "malformed_voice": return bad_request("malformed_voice","v: value invalid")
        if code == "unknown_recipe":  return bad_request("unknown_recipe","r:name invalid")
        return bad_request("malformed_tokens", ",".join(parse_errs))
    if len(segments) > SEGMENT_MAX_COUNT:
        return bad_request("too_many_segments", f"SEGMENT_MAX_COUNT={SEGMENT_MAX_COUNT}")

    if not os.path.exists(DB_PATH):
        return bad_request("db_missing", f"DB not found at {DB_PATH}")
    try:
        conn = sqlite3.connect(DB_PATH)
    except Exception as e:
        return server_error(f"db_open_failed: {e}")

    tmp_root = tempfile.mkdtemp(prefix="tts_v3_", dir=TMP_DIR_ROOT)

    if HOT is None:
        try:
            HOT = HotPiperPython(MODEL_PATH)
        except Exception as e:
            shutil.rmtree(tmp_root, ignore_errors=True)
            return server_error(f"piper_load_failed: {e}")

    try:
        parts: List[Tuple[Optional[str], Optional[int]]] = []
        seg_meta: List[Dict[str, Any]] = []

        for seg in segments:
            if seg.kind == "silence":
                parts.append((None, seg.ms))
                seg_meta.append({"index": seg.index, "kind":"silence", "ms": seg.ms})
                continue

            params, perr = resolve_params_for_segment(conn, seg)
            if perr:
                if perr == "unknown_voice":
                    return bad_request("unknown_voice", f"voice_number {seg.voice}")
                if perr == "unknown_recipe":
                    bad = next((r for r in seg.recipes if not db_get_recipe(conn, r)), "unknown")
                    return bad_request("unknown_recipe", bad)
                if perr == "malformed_speed":  return bad_request("malformed_speed","speed must be > 0")
                #if perr == "malformed_pitch":  return bad_request("malformed_pitch","pitch must be > 0")
                if perr == "malformed_volume": return bad_request("malformed_volume","volume must be > 0")
                return bad_request("malformed_params", perr)

            length_scale = round(1.0 / float(params["speed"]), 2)
            out_mono = os.path.join(tmp_root, f"{safe_basename(sid)}_{seg.index:04d}_mono.wav")

            # Piper synth (hot, in-process)

            try:
                got = HOT.synth(
                    seg.text,
                    speaker=int(seg.voice),
                    length_scale=length_scale,
                    noise_scale=float(params["noise"]),
                    noise_w=float(params["noise_w"]),
                    volume=float(params["volume"]),
                    out_wav=out_mono
                )
            except Exception as e:
                shutil.rmtree(tmp_root, ignore_errors=True)
                return server_error(f"piper_synthesize_failed: {e}")

            if not got or not os.path.exists(out_mono):
                shutil.rmtree(tmp_root, ignore_errors=True)
                return server_error(f"piper_synthesize_failed: no output at segment {seg.index}")

            # SoX post (channel/pitch/resample to final stereo)
            try:
                rc, stereo_wav, err = sox_postprocess(out_mono, seg.channel, float(params["pitch"]), float(params["volume"]), tmp_root)
            except Exception as e:
                shutil.rmtree(tmp_root, ignore_errors=True)
                return server_error(f"sox_exception: {e}")

            if rc != 0 or not stereo_wav:
                shutil.rmtree(tmp_root, ignore_errors=True)
                if err == "unknown_channel":
                    return bad_request("unknown_channel", seg.channel or "")
                return server_error(f"sox_failed rc={rc} {err}")

            parts.append((stereo_wav, None))
            # post_ms = int(round(float(params["silence"]) * 1000.0))
            # if post_ms > 0:
            #     parts.append((None, post_ms))

            seg_meta.append({
                "index": seg.index, "kind":"speech", "text": seg.text,
                "params": {"s": float(params["speed"]), "n": float(params["noise"]), "w": float(params["noise_w"]), "x": float(params["silence"]), "volume": float(params["volume"])},
                "voice_number": seg.voice, "recipes_applied": seg.recipes, "channel": seg.channel
            })

        # Join parts to final WAV
        out_name = safe_basename(f"{sid}.wav")
        out_path = os.path.join(AUDIO_DIR, out_name)
        join_wavs(out_path, parts)

        # Verify existence and collect metadata
        exists = os.path.exists(out_path)
        wav_bytes = os.path.getsize(out_path) if exists else 0
        wav_duration = 0.0
        if exists:
            try:
                with wave.open(out_path, "rb") as wf:
                    wav_duration = wf.getnframes() / float(wf.getframerate() or 1)
            except Exception as _e:
                logging.warning("WAV open failed: %r", _e)

        if not exists or wav_bytes == 0:
            shutil.rmtree(tmp_root, ignore_errors=True)
            return server_error(f"final_wav_missing_or_empty: {out_path}")

        out_path_win = wsl_to_winpath(out_path)

        elapsed = round(time.time() - t0, 3)
        return jsonify({
            "ok": True,
            "sid": sid,
            "job_id": datetime.now().strftime("%Y%m%d_%H%M%S_") + uuid.uuid4().hex[:8],
            "wav_path": out_path,                 # WSL path
            "wav_path_win": out_path_win,         # Windows path for PHP
            "wav_bytes": wav_bytes,
            "wav_duration_sec": round(wav_duration, 3),
            "elapsed_sec": elapsed,
            "final_format": {"sample_rate_hz": FINAL_SR, "channels": FINAL_CH, "bit_depth": FINAL_BITS},
            "segments": seg_meta,
            "ts": ts()
        }), 200
    except Exception as e:
        # *** TEMPORARY: PRINT STACK TRACE HERE ***
        import traceback
        traceback.print_exc()

    finally:
        try: conn.close()
        except: pass
        try: shutil.rmtree(tmp_root, ignore_errors=True)
        except: pass

# ----------------------------- MAIN -----------------------------
if __name__ == "__main__":
    ensure_dirs()
    app.run(host=HOST, port=PORT, debug=True)

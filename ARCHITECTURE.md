# Architecture

EPUB TTS has two halves that run side by side and share a folder:

```
            browser (phone / tablet / computer)
                          │  HTTP
                          ▼
   ┌──────────────────────────────────────────┐
   │  web  —  PHP + Apache  (port 8888)         │
   │  • index.php → page1.html  (the menu)      │
   │  • Library + Calibre catalog browsing      │
   │  • readBook.php  — prepare a book          │
   │  • reader.php    — the listening player    │
   │  • client_para.php / speak.php / audio.php │
   └──────────────────────────────────────────┘
                 │  POST /speak (JSON)
                 ▼
   ┌──────────────────────────────────────────┐
   │  engine — Python + Flask  (port 8077)      │
   │  • Piper neural TTS, model kept hot        │
   │  • SoX post-processing (pitch/pan/mix)     │
   └──────────────────────────────────────────┘

   shared volume:  the application directory
   • labelCheck.db        voices, recipes, pronunciations, name lists
   • <bookID>/CLEAN|PRE|TTS   per-book working text
   • audio/               generated WAV clips
   • library/             your EPUBs (+ optional Calibre metadata.db)
```

Originally these were a Windows XAMPP install and a Piper server under WSL,
both reaching the same `htdocs` folder. The two containers reproduce that split
on any platform; the application directory is mounted into both.

## The pieces

### Web side (PHP)

- **`index.php` → `page1.html`** — the front menu ("Browse Books").
- **`pages/library.php`** — lists the EPUBs in the library folder and links each
  to be read. Works with no catalog database.
- **Calibre catalog pages** — `allAuthors`, `oneAuthor`, `popularGenres`,
  `oneGenre`, `series`, `oneSeries`, `titles`, `recent`, `topAuthors`,
  `popularAuthors`, `rating`, `showBook`, `showComments`. These read a Calibre
  `metadata.db` for richer browsing and show a friendly note if none is present.
- **`pages/readBook.php`** — prepares a book: unpacks the EPUB, cleans each
  spine file to text, splits it into numbered paragraphs (`CLEAN`), renders a
  display copy (`PRE`), and produces speech-marked copy (`TTS`). It also detects
  speakers and records chapter starts.
- **`pages/reader.php`** — the player. Buffers ahead, shows the text with the
  current paragraph highlighted, offers chapter jumps and the speaker/voice
  tuning menus, and remembers your place.
- **`pages/client_para.php`** — returns one paragraph's display and speech text.
- **`pages/speak.php`** — relays a paragraph's speech text to the engine.
- **`pages/audio.php`** — streams a generated WAV back to the player (with HTTP
  range support) and notes the listening position.
- **`pages/config.php`** — one place for the database, library, and engine
  locations, all driven by environment variables with sensible defaults.

### Engine side (Python)

- **`TTSserver_v3_single_hot.py`** — a Flask server exposing `POST /speak`. It
  keeps the Piper voice model loaded in memory ("hot") and warms it once on
  start to avoid a first-word glitch. For each request it parses the narration
  notation into segments, synthesizes each with the right voice and recipe,
  post-processes with SoX for pitch / stereo placement / final format, joins the
  segments, and returns a single WAV plus metadata.
- **`serverContract.txt`** — the precise specification of the `/speak` request
  and the narration notation.

### Data

- **`labelCheck.db`** (SQLite) — the curated voices (`voice_params`), the
  recipes, pronunciation rules, and the name/word lists. This is shipped
  reference data; processing a book also writes per-book working tables into it.
- **Per-book folders** `<bookID>/CLEAN`, `/PRE`, `/TTS` — the three passes of
  prepared text, kept so a book is only processed once.
- **`audio/`** — generated clips; old ones are pruned automatically.

## Request flow when listening

1. The player asks `client_para.php` for paragraph *N* (display + speech text).
2. It sends the speech text to `speak.php`, which relays it to the engine.
3. The engine synthesizes and returns a WAV path.
4. The player streams that WAV via `audio.php` and plays it, advancing to *N+1*.
5. It keeps a buffer of upcoming paragraphs synthesized ahead of playback.

## Configuration

`pages/config.php` and the engine read these from the environment:

| Setting | Meaning | Default |
| --- | --- | --- |
| `LIBRARY_ROOT` | folder holding your EPUBs | `./library` |
| `CALIBRE_DB` | optional Calibre catalog | `LIBRARY_ROOT/metadata.db` |
| `APP_DB` | the program's own database | `./labelCheck.db` |
| `FLASK_SPEAK_URL` | where the web side reaches the engine | `http://engine:8077/speak` |
| `MODEL_PATH` | the neural voice model | `./en_US-libritts_r-medium.onnx` |

See `docker-compose.yml` for how these are set for the two containers.

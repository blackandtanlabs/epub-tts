# Setup

EPUB TTS runs as two containers (a web reader and a speech engine). You need
[Docker](https://docs.docker.com/get-docker/) with Compose. It works on Windows,
macOS, and Linux.

## 1. Get the code

```bash
git clone https://github.com/blackandtanlabs/epub-tts.git
cd epub-tts
```

## 2. Get the voice model

The neural voice (~75 MB) is not stored in the repository. Fetch it once:

```bash
./scripts/fetch-voice-model.sh
```

This downloads `en_US-libritts_r-medium.onnx` (and its `.json`) into the project
folder — the same LibriTTS voice the ~900-voice library was built for.

## 3. Add your books

EPUB TTS reads ordinary, unlocked EPUB files. It does not remove copy
protection; bring books you are free to read.

Put them under a `library/` folder, laid out the way Calibre stores them:

```
library/
  Mark Twain/
    The Adventures of Tom Sawyer (74)/
      tomsawyer.epub
```

The `(74)` in the folder name is the book's id — any number is fine, as long as
each book has one. If you already keep a Calibre library, point `LIBRARY_ROOT`
at it (see below) and its `metadata.db` will also light up the author / genre /
series browsing.

No public-domain book to test with? [Project Gutenberg](https://www.gutenberg.org/)
and [Standard Ebooks](https://standardebooks.org/) are good sources.

## 4. Start it

```bash
docker compose up --build
```

The first build installs Piper, SoX, and the PHP extensions; later starts are
quick. When it is running, open:

- **<http://localhost:8888/>** — the reader (the front menu)

From other devices on your home network, use the host machine's address, e.g.
`http://192.168.1.20:8888/`. The original setup used [Tailscale](https://tailscale.com/)
to reach a home server privately from anywhere; that still works and is a good
fit.

## 5. Read a book

Open **Library**, choose a book, and pick **Female Narrator** or **Male
Narrator**. The first time, it prepares the whole book (a few seconds), then
drops you into the reader. Press **Play** and wait a few seconds for it to
buffer — then it reads aloud. See [USAGE.md](USAGE.md).

## Configuration

Defaults work out of the box. To change them, set environment variables (for
example in `docker-compose.yml`):

| Variable | Meaning | Default |
| --- | --- | --- |
| `LIBRARY_ROOT` | folder holding your EPUBs | `./library` |
| `CALIBRE_DB` | optional Calibre catalog | `LIBRARY_ROOT/metadata.db` |
| `APP_DB` | the program's database | `./labelCheck.db` |
| `MODEL_PATH` | the neural voice model | `./en_US-libritts_r-medium.onnx` |
| `FLASK_SPEAK_URL` | where the web side reaches the engine | `http://engine:8077/speak` |

## Notes

- **The voice library is in `labelCheck.db`.** Keep that file — it holds the
  curated voices, recipes, pronunciations, and name lists. Processing a book
  also writes per-book working tables into it; that is normal.
- **Generated audio** lands in `audio/` and is pruned automatically.
- **A book is only fully prepared once.** Its prepared text is kept in a
  `<bookID>/` folder next to the pages.

# EPUB TTS

A personal audiobook reader that turns your own EPUB library into narrated,
multi-voice audio. Browse your books in a web page on any device on your home
network, pick a narrator, and listen — with different voices for different
characters, spoken aloud by a neural text-to-speech engine running on your own
machine.

This is not a cloud service and not a product. It is a home-built reader,
designed for one household's bookshelf and shared here in the hope that the
ideas in it are useful to others.

## What makes it special

Most text-to-speech tools read in a single flat voice. This one was built by
someone who loved books and listened to a great many of them, and it shows in
the details:

- **A voice for each character.** It reads dialogue, works out who is speaking,
  and gives that character a consistent voice — drawn from a hand-curated
  library of roughly 900 voices, each auditioned and scored for quality.
- **Voices you can shape.** Small named "recipes" — *older*, *shy*, *loud*,
  *warm* — nudge a voice without replacing it, so one speaker can sound aged or
  hushed without becoming someone else.
- **Placement in the room.** A character can sound from the left or the right,
  centered, or intimately "in your head" for inner thoughts.
- **Careful reading of real prose.** Numbers, dates, money, abbreviations, and
  the difference between a real question and a rhetorical one are all handled by
  rules gathered over years of listening and correcting.
- **Listen, correct, continue.** When a name or a pronunciation comes out wrong,
  you fix it in the reader and the book picks the change up from that point on —
  and your place is remembered across devices.

There is more on each of these in [HEART-AND-SOUL.md](HEART-AND-SOUL.md).

## Running it

It runs in two containers — a web reader and a speech engine — so it works the
same on Windows, macOS, or Linux. The short version:

```bash
git clone https://github.com/blackandtanlabs/epub-tts.git
cd epub-tts
./scripts/fetch-voice-model.sh      # downloads the ~75 MB neural voice
docker compose up --build
```

Then open <http://localhost:8888/> and choose **Library**. Full instructions,
including how to point it at your books, are in [SETUP.md](SETUP.md), and the
day-to-day flow is in [USAGE.md](USAGE.md).

You bring your own books. EPUB TTS reads ordinary, unlocked EPUB files; it does
not remove any form of copy protection. A folder of EPUBs is enough; a
[Calibre](https://calibre-ebook.com/) library is used for richer browsing if you
have one.

## About this project

This software was written, and rewritten, over many years by my father. After
he passed away I gathered everything of it I could find, pieced it back
together, and brought it up to a state where it runs cleanly on a modern
machine and where others can read the code and run it themselves.

The heart of it is entirely his: the voice library he auditioned by hand, the
markup he invented for narration, the long-accumulated rules for reading prose
aloud well. My part was to make it whole and portable, to finish a few things
that were close to done, and to write it all down. I have tried to do that with
a light touch, in keeping with how he built it.

— *Maintained by [Black & Tan Labs](https://github.com/blackandtanlabs).
Created by [name].*

## Documentation

- [HEART-AND-SOUL.md](HEART-AND-SOUL.md) — the ideas worth keeping, in plain language
- [ARCHITECTURE.md](ARCHITECTURE.md) — how the pieces fit together
- [SETUP.md](SETUP.md) — installing and pointing it at your books
- [USAGE.md](USAGE.md) — processing a book and listening

## License

To be decided. Copyright is retained by the family.

# Using EPUB TTS

Day-to-day: pick a book, listen, fix anything that comes out wrong, keep going.

## Browsing to a book

Open <http://localhost:8888/>. From the front menu you can reach books two ways:

- **Library** — a simple list of the EPUBs in your library folder. Always
  available, no catalog needed.
- **Authors / Genre / Series / Title / Recently Obtained / etc.** — richer
  browsing, drawn from a Calibre `metadata.db` if you have one. Without a
  catalog these show a note pointing you to the Library.

Each book offers **Female Narrator** and **Male Narrator** — this chooses the
main narrating voice; characters still get their own voices.

## Listening

The first time you open a book it is prepared (cleaned, laid out, and marked for
speech) — a few seconds for a typical novel. Then you are in the reader:

- **Play** — starts playback. It buffers a cushion of audio first, so give it
  10–20 seconds the first time; watch the **Buf** counter climb. The current
  paragraph is highlighted as it reads.
- **Chapters…** — jump to any chapter.
- **Aa+ / Aa−** — text size.
- **Menu** — the correction tools (below).

Your position is saved as you listen, so you can close the tab and come back —
on the same device or another — and pick up where you left off.

## Fixing things as you listen

Open **Menu**. There are two panels.

### Change a speaker

If a character's dialogue is read in the wrong voice:

1. Note the paragraph number (shown beside the text).
2. In **Change Speaker**, enter that paragraph and the correct character name.
3. Submit.

The voice is corrected at that paragraph and carried forward through that
character's continuing dialogue, until a different named character speaks. (It
reaches back a few paragraphs and re-voices from there so the change is seamless
as you listen on.)

### Fix a pronunciation

In the same panel, the **Pronunciation** fields take a pattern and its
replacement (with options for plain text vs. a regular expression, and where to
apply it). Pronunciation fixes are remembered and applied to future reading.

### Adjust a voice

The **Change Voice Params** panel tunes a voice by number — speed, pitch,
volume, breathiness, and so on. The values multiply the voice's current
settings, so `1.1` is "a little more" and `0.9` is "a little less." You can also
set a voice's sex or disable it. Changes persist for every book that uses that
voice.

### Skip

**Skip Forward or Backward** jumps a number of paragraphs.

## Tips

- **Give Play a moment.** It is buffering ahead on purpose; the wait is once, at
  the start.
- **Correct early.** Names and pronunciations fixed near the start of a book
  carry forward, so the rest reads better with little effort.
- **Resuming.** Opening a book returns you to your last position automatically;
  add `?start=N` to the reader URL to begin at a specific paragraph.

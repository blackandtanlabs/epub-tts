# How EPUB TTS Reads Text Aloud

This describes the parts of the pipeline that decide *how* words are spoken —
pauses, pronunciation, numbers, and loudness — where each lives, and the
practices that keep it sounding good. It reflects how the system actually works,
plus measurements taken to calibrate it.

## The pipeline, end to end

```
EPUB HTML
  → clean to text            cleanHTML.php        (strip tags, keep emphasis, fix scene breaks, image junk)
  → numbers to words         DigitsToWords.php    ("1876" → "eighteen seventy-six")
  → mark speakers + pauses   readBook.php         (voice tokens ⦃v:⦄, pause tokens ⦃w:⦄, chapters)
  → fix pronunciation        client_para.php      (handlePronunciation: respelling rules, at read time)
  → synthesize               TTSserver…py + Piper (eSpeak phonemes → neural voice → SoX)
```

Two stages matter most for "how it sounds": **pauses** (decided during
processing) and **pronunciation** (applied every time a paragraph is spoken).

## Pauses

Pauses are inserted as `⦃w:milliseconds⦄` tokens in the text, then rendered as
real silence by the engine.

**Punctuation rules** (`readBook.php`):

| Trigger | Pause | Where |
| --- | --- | --- |
| line break `<br>` | 222 ms | inline rule |
| em-dash `—`, ellipsis `…` | 99 ms | inline rule |
| sentence end (`. ! ?`) | 444 ms | `insertPauses()` |
| new chapter | 1200 ms | `ChapterMarker` |

`insertPauses()` is the considered one: it adds a sentence pause only after
enough has been read (~20 words in a sentence, or ~30 running words), so it
breathes at natural intervals instead of stopping after every short line. It
also refuses to treat a period as a sentence end when it follows a **capital
letter** (so "Mr. Smith" doesn't pause) or a **digit** (so "3.14" doesn't).

The engine (`TTSserver…py`) turns each `⦃w:⦄` token into silence between clips
(`join_wavs`), and Piper adds its own ~800 ms sentence silence.

> Dormant lever: each voice has a `silence` setting in the database that is read
> but not currently applied (the code is commented out). If you ever want to
> lengthen pauses globally, that is where to wire it in.

**To change pacing:** edit `insertPauses()` and the inline rules in
`readBook.php`, then reprocess the book.

## Pronunciation

Three layers, from your rules down to the raw engine:

1. **Respelling rules** — the `pronunciation` table (regex pattern → replacement),
   applied to every paragraph by `handlePronunciation()` in `client_para.php`.
   You add them through the reader's **Menu → Pronunciation** panel. The trick is
   to respell a word so the engine says it right (e.g. `aka` → "eh K eh").
2. **Numbers and abbreviations** — `DigitsToWords.php` and `expand_abbreviations()`
   in `cleanHTML.php`, during processing.
3. **The phoneme engine** — Piper hands words to **eSpeak-ng** to turn letters
   into sounds (`"phoneme_type": "espeak"` in the model's `.json`).

### Best practices (and why the design is right)

Measuring the phoneme engine on a battery of hard words showed that **eSpeak is
already very good**: `colonel`→"kernel", `Worcestershire`→"wuster-sher",
`Sean`→"Shawn", `quay`→"kee", `genre`, `FBI`, `CEO`, `RSVP`, `USA` are all
correct on their own. So:

- **Respell only what's actually wrong.** Don't add rules for words eSpeak
  already handles — extra rules are clutter and risk over-matching. The right
  approach is exactly the one in this project: a small set of corrections on top
  of a capable engine.
- **Guard rules against ordinary words.** A title like `Col.` should only become
  "Colonel" when a name follows (the rules require a following capital letter),
  so "I will color it" and "he gave it a rev." are left untouched.
- **Avoid the homograph trap.** Words like `read`, `lead`, `live`, `bass` change
  pronunciation by meaning, which a fixed rule can't know. Forcing one reading
  just moves the error. These are best left to the engine's default.
- **Verify by ear and by phoneme.** Each rule here was checked two ways: that it
  changes the right text and nothing else, and that the result phonemizes
  correctly.

### Rules added on this basis

eSpeak reads several common abbreviations as letters. Added, in the existing
style, with the guards above:

- `vs.` → "versus"
- `mph` → "miles per hour"
- Titles before a name: `Capt.` `Col.` `Lt.` `Gen.` `Maj.` `Rev.` `Gov.` `Sen.`
  `Prof.` → Captain / Colonel / Lieutenant / General / Major / Reverend /
  Governor / Senator / Professor

## Loudness

The engine does **not** auto-normalize (`--no-normalize`); instead each voice has
a `volume` in the database, applied before the SoX post-processing, with recipes
multiplying on top.

Measurement found the voices are *naturally* consistent and at a healthy level
(RMS ~0.10, peaks ~0.5–0.9), but the stored volumes had drifted — attenuating
unevenly so the library was quiet and the narrator sat ~18 dB below the
character voices. Following the loudness calibration the original author was
working toward (his scores tracked a per-voice loudness error), each voice's
`volume` was reset to hit a consistent target with a peak ceiling. Result: the
per-voice loudness spread dropped from ~18 dB to ~3 dB, with no clipping.

**To re-balance loudness:** measure each voice's natural RMS at `volume = 1.0`,
then set `volume = target_rms / measured_rms`, capped so `peak × volume` stays
below ~0.95. Recipes (loud/soft) still layer on top for intentional variation.

## A note on speaking rate

The LibriTTS voices read quickly (~250+ words per minute versus an audiobook
norm nearer 150). The default speed is set to 0.85 to gentle that. Any voice's
pace can be tuned live from **Menu → Change Voice Params** (the speed
multiplier), or globally via the voices' `newSpeed`.

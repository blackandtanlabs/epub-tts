# Provenance

This file records, plainly, what in EPUB TTS is Patrick Clark's original work
and what was done after his death to bring it to a releasable state. It exists
so the project can stand honestly as his — neither overstating nor erasing the
hands that prepared it for others to use.

## A caveat

I assembled this from what I could recover off my father's computer after he
passed, before I had any chance to sit with him and validate it. As far as I
can tell this is everything — but he is not here to confirm it, and there may
be parts of the program, or of what he intended for it, that I have not yet
found or fully understood. What is here is as faithful as I could make it.

## What is Patrick's

The substance of the program is entirely his, built and rebuilt from 2016
onward:

- the web reader that turns a book into a guided listen
- the neural voice library, auditioned and tuned by hand across hundreds of
  voices
- the markup he invented for assigning voices to narration and to each
  speaker, and the forward-propagation of those assignments through a book
- the text-processing pipeline: HTML cleaning, number and abbreviation
  expansion, name detection, and the long-accumulated pronunciation rules
- the synthesis server built around Piper and SoX, and the per-book working
  cache that drives the reader
- his own written notes on voice tuning and loudness, which guided the work
  below

## What was done for the 1.0 release

After Patrick passed away in June 2026, the scattered copies he left were
gathered into a single working whole and brought up to run cleanly on a modern
machine. Nothing here changes the heart of the program; the aim throughout was
a light touch.

Made whole and portable
- assembled one coherent source tree from the copies he left behind
- added a container runtime so it runs on any platform, not only his Windows
  setup, and made the reader find its files wherever it is installed
- pointed the catalog and library pages at a plain folder of books, degrading
  gracefully when optional metadata is absent

Repaired with time
- kept center-channel narration working on current versions of SoX and Piper
- let whole books synthesize without stumbling on empty pieces
- made the reader fetch paragraphs and stream audio reliably
- stopped stray image-file references from being read aloud
- restored the pause button as a play/pause toggle
- restored scene-break pauses in the HTML cleaner
- showed each book's real title rather than its file name

Finished what was close to done
- completed the forward part of a speaker correction he had begun
- wired chapter navigation into the reader

Applied from his own notes
- his documented voice-tuning settings, across the library
- the loudness normalization he had measured but not yet finished, evening out
  voices that had drifted apart in volume
- a small set of additional pronunciation rules for titles and common
  abbreviations, in the style of the rules he already kept

Written down
- documentation of the architecture, setup, usage, and text processing
- GPL-3.0 licensing, source-file notices, and third-party notices
- an appreciation of the ideas in the program worth keeping

— *Created by Patrick Clark. Prepared for release and maintained by his family
at [Black & Tan Labs](https://github.com/blackandtanlabs).*

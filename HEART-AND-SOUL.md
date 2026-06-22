# The Heart and Soul of EPUB TTS

This document is an appreciation. It walks through the ideas in this program
that are genuinely original — the parts worth understanding before changing
anything, because they are what make it more than a plain text-to-speech tool.
Everything here describes what the code actually does.

## 1. A narration language written into the text itself

Most systems that control speech wrap the words in heavy markup. This one uses
a small, quiet notation tucked right into the line of prose, in delimiters that
never occur in ordinary text:

```
⦃v:7⦄    switch to voice 7
⦃r:older⦄   apply the "older" recipe
⦃c:left⦄    place this voice on the left
⦃w:250⦄     pause 250 milliseconds
```

A paragraph of dialogue ends up looking like this:

```
⦃v:93⦄⦃r:narrator⦄⦃c:head⦄ Tom said, “⦃v:7⦄⦃c:left⦄Honest injun?”
```

The reader strips this notation before showing the text; the engine reads it to
decide how to speak. Switching voices clears the active recipes, so each
character naturally starts fresh. It is a small, legible format that keeps the
*intent* of the narration sitting right next to the words it applies to.

— *In the code:* `TTSserver_v3_single_hot.py`, the token pattern and
`parse_text_into_segments`; the full specification is in `serverContract.txt`.

## 2. Recipes: shaping a voice without replacing it

A character should be able to sound tired, or younger, or hushed, without
turning into a different person. The "recipes" make that possible. Each recipe
is a set of gentle adjustments — speed, pitch, volume, breathiness — and the
clever part is how they combine:

> A recipe only changes the fields it actually sets. Everything else is left
> exactly as the base voice had it, and the changes multiply rather than
> replace.

So *loud* only raises volume; *older* slows the pace and drops the pitch; and
you can stack them. The base voice stays recognizably itself underneath. Pauses
are the one exception — a silence is an absolute length, not a multiplier,
because a pause is a measure of time, not a flavor of voice.

— *In the code:* `resolve_params_for_segment()`; the recipes live in the
`recipes` table and `dbwork/recipes.csv`.

## 3. Placing voices in the room

Audiobooks are usually flat — everything dead center. This program treats the
stereo field as a tool for storytelling. A voice can be placed:

- **left** or **right** — useful for two characters in conversation
- **center** — the main narration
- **in your head** — for a character's inner thoughts

The "in your head" placement adds a subtle psychoacoustic effect (SoX's
`earwax`) that makes the voice feel as though it is sounding inside the
listener's skull rather than in front of them — an intimate register for
interior monologue. This kind of spatial narration is rare even in commercial
audiobooks.

— *In the code:* `channel_recipe()` and `sox_postprocess()`.

## 4. A hand-curated library of ~900 voices

The neural model can produce roughly 900 distinct speakers. Not all of them are
good, and they vary wildly in pace and loudness. Rather than trust them blindly,
each voice was **auditioned and scored** — measured for speaking rate, clarity,
and loudness, sorted into male and female, and rated for whether it makes a good
narrator. That scoring is what lets the program reach for "a good female
narrator voice" or assign distinct, pleasant voices to a cast of characters.

This represents a great deal of patient listening. It is the single most
irreplaceable asset in the project.

— *In the code:* the `voice_params` table and `dbwork/narrator_scores.csv`.

## 5. Reading prose the way a person would

A large part of the work is invisible: turning messy book HTML into text that
*sounds* right when read aloud. Among the things it handles:

- **Numbers in context** — `1876` as a year ("eighteen seventy-six") versus a
  plain count; money, dates, ordinals, phone numbers, highways, percentages —
  each with a confidence score rather than a blind guess.
- **Emphasis that survives** — italics and bold are carried through the
  HTML-to-text conversion as invisible marks, so emphasis can inform the speech
  instead of being thrown away.
- **Who is speaking** — names are matched against long, hand-built lists
  (including names by gender and by national origin), pronouns are tracked, and
  words right after prepositions are skipped, with the nearest name to the
  speech verb trusted most.
- **A question or not?** — a dedicated decider tells a real question from a
  rhetorical or echoing one ("So you did *what*?") so the intonation lands
  correctly.

These rules accumulated over years of listening, noticing a wrong reading, and
encoding the fix. The breadth of the word lists is a record of that work.

— *In the code:* `cleanHTML.php`, `DigitsToWords.php`,
`QuestionPunctuationDecider.php`, the speaker logic in `readBook.php`, and the
word lists under `pages/`.

## 6. The listening workflow: process, listen, correct, continue

A book is prepared in three passes — cleaned, laid out for display, then marked
for speech — and the results are kept so a book is only fully processed once.
While listening, if a character is given the wrong voice or a word is
mispronounced, you correct it in the reader and the change is applied from that
point forward through that speaker's dialogue. Your position is saved as you
go, so you can stop on one device and resume on another.

It is a workflow built by someone who actually used it, night after night: make
it good enough to enjoy, fix the rough spots as you hit them, and never lose
your place.

— *In the code:* the processing pipeline in `readBook.php`, playback in
`reader.php`, paragraph serving in `client_para.php`, and the speech relay in
`speak.php`.

---

If you take away one thing: this is not a generic engine that happens to read
books. It is a reader's instrument, shaped around how books are actually
written and how they sound when read aloud well.

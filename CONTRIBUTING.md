# Contributing

Thank you for your interest in EPUB TTS. This began as one person's personal
reader and is shared so others can run it and build on it.

## Getting set up

See [SETUP.md](SETUP.md) to get it running in Docker, and
[ARCHITECTURE.md](ARCHITECTURE.md) to understand how the web reader and the
speech engine fit together. [HEART-AND-SOUL.md](HEART-AND-SOUL.md) explains the
ideas at the core of the project — please read it before changing the
narration notation, the recipes, the voice handling, or the speaker detection,
so their intent is preserved.

## A note on style

The codebase has a distinct voice and history. When changing it:

- **Match the surrounding code** — its naming, spacing, and structure.
- **Keep changes focused and reversible.** Prefer small, clear edits over broad
  rewrites.
- **Preserve the original design.** Where something is deliberately unusual, it
  is usually that way for a reason worth understanding first.

## Reporting issues

Helpful bug reports include the book (or a public-domain one that reproduces
it), what you expected to hear or see, what happened instead, and any relevant
output from the two containers (`docker compose logs`).

## Pull requests

- Keep each pull request to a single, well-described change.
- Verify it end to end: process a public-domain book, listen, and confirm the
  behavior you changed.
- Note anything you could not test.

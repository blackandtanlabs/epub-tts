# Third-Party Components

EPUB TTS bundles a few components written by others. They keep their own
licenses and copyright, and are not covered by the project's GPL-3.0 license.
Each retains its original notice in its source file.

| Component | Files | Author | License as labeled |
| --- | --- | --- | --- |
| ZipFile class | `ZipFile.class.php` | Didier Corbière | "GPL 2" |
| Hyphenator | `pages/Hyphenator.php` | Andreas Heigl | MIT |
| phpLiteAdmin | `phpliteadmin.php`, `pages/phpliteadmin*` | phpLiteAdmin project | GPL-3.0 |

It also relies on external tools and a neural voice it does not redistribute:

- **Piper** (neural text-to-speech) and the **en_US-libritts_r-medium** voice
  model, fetched separately — see [SETUP.md](SETUP.md).
- **SoX** (audio post-processing), installed in the engine container.

A note on compatibility: MIT (the Hyphenator) is permissive and fine alongside
GPL-3.0, and phpLiteAdmin is itself GPL-3.0. The `ZipFile.class.php` header
reads "GPL 2" without stating "or later"; GPL-2-only and GPL-3.0 are not
strictly compatible, so before a formal release it is worth confirming that this
component is "GPL-2.0-or-later" (the form its upstream project uses), or
replacing it. It is used only to read entries from EPUB archives.

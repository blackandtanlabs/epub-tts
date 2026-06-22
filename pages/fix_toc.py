# This file is part of EPUB TTS, created by Patrick Clark.
#
# EPUB TTS is free software: you can redistribute it and/or modify it under the
# terms of the GNU General Public License, version 3 or (at your option) any
# later version, as published by the Free Software Foundation. It comes with NO
# WARRANTY. See the LICENSE file or <https://www.gnu.org/licenses/>.
#
# Copyright (C) 2016-2026 Patrick Clark and family.
#
# Patrick built EPUB TTS over many years. The GPL licensing was applied by his
# family when the project was made public, to keep his work free for everyone --
# honoring his wishes. It was not part of the original source.
import sys
import os

# We must use Calibre's internal logic
from calibre.gui2.tweak_book.epub import EpubContainer
from calibre.gui2.tweak_book.toc import create_toc_from_headings

def run_fix(epub_path):
    abs_path = os.path.abspath(epub_path)
    # Open the container
    container = EpubContainer(abs_path, None)
    
    # This is the EXACT code behind that menu item
    # It scans for all <h1> through <h6> tags
    create_toc_from_headings(container)
    
    # Save and release the file
    container.commit()
    print(f"ToC generated from headings for: {os.path.basename(abs_path)}")

if __name__ == '__main__':
    if len(sys.argv) > 1:
        run_fix(sys.argv[1])
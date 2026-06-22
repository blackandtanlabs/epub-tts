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
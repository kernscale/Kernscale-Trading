# Local macOS PDF extraction

`deploy/dev/pdftotext.m` is a project-private development adapter using macOS PDFKit. It accepts only `-layout -enc UTF-8 -nopgbrk <server-file> -` and writes UTF-8 extracted text to stdout. PDFKit preserves readable text; it does not implement Poppler's layout fidelity. Production Linux uses Poppler instead.

Build from the repository root with the installed Xcode, without installing global tools:

```sh
mkdir -p .orchestration/tools .orchestration/cache/pdfkit-module-cache
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer xcrun clang -Wall -Wextra -Werror -fobjc-arc -fmodules -fmodules-cache-path=.orchestration/cache/pdfkit-module-cache -framework Foundation -framework PDFKit deploy/dev/pdftotext.m -o .orchestration/tools/pdftotext
```

The operator can configure `TRADING_PDFTOTEXT_BINARY` with the absolute path to this binary. Keep the generated binary, module cache and synthetic fixtures untracked. This adapter does not change attachment authorization, server-controlled paths, parent process timeout or upload validation.

Invalid, encrypted, locked, blank/image-only documents and extracted text exceeding 2 MiB fail without partial stdout. Native parser diagnostics are suppressed; stderr contains only a generic error. Synthetic local checks and metadata-only results are in `.orchestration/cache/pdfkit-selfcheck/` (`fixtures.m`, `check.py`, `receipt.json`). No real attachments are needed for those checks.

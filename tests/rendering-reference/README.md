# Certloom development reference renderer

This directory is **test/development infrastructure only**. It is deliberately excluded from the installable Certloom ZIP and is not a WordPress runtime dependency.

`generate_reference.py` converts the six permanent rendering fixtures to SVG, PNG and PDF with a fixed local development font and Inkscape. These artifacts are a visual/geometry oracle used to certify future PHP renderer adapters. They do **not** select the production renderer and they do not weaken the rule that production artifact generation must run inside the customer's WordPress installation.

The generator uses a fixed `SOURCE_DATE_EPOCH` so Inkscape/Cairo PDF metadata is reproducible. SVG, PNG **and PDF** reference hashes must therefore remain byte-stable when the same development toolchain and fixtures are used.

The reference suite covers exact A4/Letter geometry, real Arabic/Urdu shaping, mixed-script presentation, long-name shrink behavior, arbitrary `17.5°` rotation, and QR rendering. `validate_reference.py` independently rechecks every stored hash, PDF physical page size, PNG raster geometry, the actual shrink decision, RTL direction, arbitrary rotation, and decodes the QR from the generated PNG to prove the raster artifact carries the exact verification URL.

Run from the plugin source root:

```bash
python tests/rendering-reference/generate_reference.py
python tests/rendering-reference/validate_reference.py
```

Development-only requirements: Python 3, Pillow with RAQM/HarfBuzz, `qrcode`, OpenCV, Inkscape and `pdfinfo`.

A future PHP adapter is not approved merely because it can create a PDF. It must be compared against these fixtures for visible geometry, Unicode shaping, directionality, overflow behavior, rotation, QR structure and image output geometry. Any material cross-engine difference should be moved into shared Certloom Core rather than hidden in the adapter.

No font binary is stored or distributed by this test harness; the manifest records only the local development font identity/hash used to produce the oracle.

# Rendering golden-reference tooling

This directory is **development/release-engineering tooling only**. It is not a production renderer and is excluded from the installable plugin package.

`generate_reference.py` converts the six permanent rendering fixtures to SVG, PNG, JPEG and PDF using one fixed local development font and a fixed local toolchain. These artifacts are a visual/geometry oracle used to certify future PHP renderer adapters. They do **not** select the production renderer and they do not weaken the rule that production artifact generation must run inside the customer's WordPress installation.

The generator uses a fixed `SOURCE_DATE_EPOCH` so Inkscape/Cairo PDF metadata is reproducible. PNG/PDF/SVG references retain their pre-1.6.24 bytes. JPEG references are deterministic Pillow outputs encoded at quality 95, subsampling 0, optimize=false, progressive=false and 96 DPI. The manifest is schema v3 and records exact hashes/geometry for all four reference formats.

The permanent fixtures are:

- `english`
- `urdu-rtl`
- `mixed-script`
- `long-name-shrink`
- `rotation` (17.5°)
- `qr`

`validate_reference.py` independently re-checks manifest/file hashes, PDF and raster geometry, the long-name/RTL/rotation fixture facts, and exact verification-URL QR decode from both PNG and JPEG reference rasters.

Development-only requirements: Python 3, Pillow with RAQM/HarfBuzz, `qrcode`, OpenCV, Inkscape and `pdfinfo`.

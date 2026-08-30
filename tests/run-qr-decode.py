#!/usr/bin/env python3
"""Decode the actual SVG emitted by Certloom's PHP QR service with an independent scanner."""
from pathlib import Path
import subprocess, tempfile, shutil, sys, re
import cv2

ROOT = Path(__file__).resolve().parents[1]
PHP_FIXTURE = ROOT / "tests" / "qr-public-svg.php"
INKSCAPE = shutil.which("inkscape")
if not INKSCAPE:
    raise SystemExit("FAIL  Inkscape is required for public QR decode acceptance")

vectors = [
    "https://example.test/certloom/verify/CERT-QR-001/",
    "https://example.test/certloom/verify/" + "A" * 80 + "/",
    "https://example.test/certloom/verify/" + "B" * 180 + "/",
    "https://example.test/?certloom_verify=CERT%2F2026%2F%D8%A7%D8%B1%D8%AF%D9%88-001",
]

def tracked(url: str) -> str:
    return url + ('&' if '?' in url else '?') + 'certloom_source=qr'

detector = cv2.QRCodeDetector()
with tempfile.TemporaryDirectory(prefix="certloom-qr-") as tmp:
    tmp = Path(tmp)
    for index, url in enumerate(vectors, 1):
        svg = tmp / f"qr-{index}.svg"
        png = tmp / f"qr-{index}.png"
        result = subprocess.run(["php", str(PHP_FIXTURE), url], check=True, capture_output=True)
        svg.write_bytes(result.stdout)
        match = re.search(rb'viewBox="0 0 ([0-9]+) ([0-9]+)"', result.stdout)
        if not match or match.group(1) != match.group(2):
            raise SystemExit(f"FAIL  public QR vector {index} has invalid SVG viewBox")
        view_size = int(match.group(1))
        raster_size = view_size * 16  # exact integer pixels per module/quiet-zone unit
        subprocess.run([
            INKSCAPE, str(svg), "--export-type=png", f"--export-filename={png}",
            f"--export-width={raster_size}", f"--export-height={raster_size}"
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        image = cv2.imread(str(png), cv2.IMREAD_GRAYSCALE)
        if image is None:
            raise SystemExit(f"FAIL  public QR vector {index} did not rasterize")
        decoded, points, _ = detector.detectAndDecode(image)
        expected = tracked(url)
        if decoded != expected:
            raise SystemExit(f"FAIL  public QR vector {index} decoded to {decoded!r}; expected {expected!r}")
        print(f"PASS  public PHP SVG vector {index} independently decodes to canonical URL plus QR attribution")
print(f"\n{len(vectors)} public QR raster decode vectors, 0 failures")

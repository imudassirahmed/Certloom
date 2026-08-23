#!/usr/bin/env python3
"""Independent raster decode gate for QR-02 logo QR and local Code 128."""
from pathlib import Path
import subprocess, tempfile, shutil, re
import cv2
from PIL import Image
from pyzbar.pyzbar import decode as zbar_decode

ROOT = Path(__file__).resolve().parents[1]
QR_FIXTURE = ROOT / "tests" / "qr02-logo-svg.php"
BAR_FIXTURE = ROOT / "tests" / "barcode-public-svg.php"
INKSCAPE = shutil.which("inkscape")
if not INKSCAPE:
    raise SystemExit("FAIL  Inkscape is required for QR-02 raster decode acceptance")

qr_vectors = [
    "https://example.test/certloom/verify/CERT-LOGO-001/",
    "https://example.test/certloom/verify/" + "A" * 48 + "/",
    "https://example.test/certloom/verify/" + "B" * 96 + "/",
    "https://example.test/certloom/verify/" + "C" * 180 + "/",
    "https://example.test/?certloom_verify=CERT%2F2026%2F%D8%A7%D8%B1%D8%AF%D9%88-001",
    "https://example.test/certloom/verify/CERT-2026-000001/?source=qr%20logo",
]
qr_scales = (8, 12, 16)
qr_detector = cv2.QRCodeDetector()

barcode_vectors = [
    "CERT-2026-000001",
    "12345678901234567890",
    "ABC123XYZ",
    "CERT/2026-A_B.9",
    "TRAINING-ALPHA-2026-004281",
    "A1B2C3D4E5F6G7H8I9J0",
    "A !\"#$%&'()*+,-./0123456789",
    ":;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ",
    "[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~",
]

with tempfile.TemporaryDirectory(prefix="certloom-qr02-") as tmp_dir:
    tmp = Path(tmp_dir)
    qr_checks = 0
    for index, url in enumerate(qr_vectors, 1):
        svg = tmp / f"logo-qr-{index}.svg"
        result = subprocess.run(["php", str(QR_FIXTURE), url], check=True, capture_output=True)
        svg.write_bytes(result.stdout)
        if b"certloom-qr-logo-image" not in result.stdout or b"data:image/png;base64," not in result.stdout:
            raise SystemExit(f"FAIL  logo QR vector {index} did not contain the validated local center image")
        match = re.search(rb'viewBox="0 0 ([0-9]+) ([0-9]+)"', result.stdout)
        if not match or match.group(1) != match.group(2):
            raise SystemExit(f"FAIL  logo QR vector {index} has invalid SVG viewBox")
        view_size = int(match.group(1))
        for scale in qr_scales:
            png = tmp / f"logo-qr-{index}-{scale}.png"
            raster_size = view_size * scale
            subprocess.run([
                INKSCAPE, str(svg), "--export-type=png", f"--export-filename={png}",
                f"--export-width={raster_size}", f"--export-height={raster_size}"
            ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            image = cv2.imread(str(png), cv2.IMREAD_GRAYSCALE)
            if image is None:
                raise SystemExit(f"FAIL  logo QR vector {index} scale {scale} did not rasterize")
            decoded, points, _ = qr_detector.detectAndDecode(image)
            if decoded != url:
                raise SystemExit(f"FAIL  logo QR vector {index} scale {scale} decoded to {decoded!r}")
            qr_checks += 1
            print(f"PASS  logo QR vector {index} at {scale}px/module decodes to exact canonical URL")

    barcode_checks = 0
    for index, value in enumerate(barcode_vectors, 1):
        svg = tmp / f"code128-{index}.svg"
        png = tmp / f"code128-{index}.png"
        result = subprocess.run(["php", str(BAR_FIXTURE), value], check=True, capture_output=True)
        svg.write_bytes(result.stdout)
        match = re.search(rb'viewBox="0 0 ([0-9]+) 40"', result.stdout)
        if not match:
            raise SystemExit(f"FAIL  Code 128 vector {index} has invalid viewBox")
        view_width = int(match.group(1))
        raster_width = view_width * 4
        subprocess.run([
            INKSCAPE, str(svg), "--export-type=png", f"--export-filename={png}",
            f"--export-width={raster_width}", "--export-height=180"
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        decoded = [item for item in zbar_decode(Image.open(png)) if item.type == "CODE128"]
        values = [item.data.decode("ascii") for item in decoded]
        if value not in values:
            raise SystemExit(f"FAIL  Code 128 vector {index} decoded values {values!r}")
        barcode_checks += 1
        print(f"PASS  Code 128 vector {index} independently decodes to exact certificate number")

print(f"\n{qr_checks} logo-QR raster decodes + {barcode_checks} Code 128 raster decodes, 0 failures")

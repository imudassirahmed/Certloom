#!/usr/bin/env python3
"""Supplementary real catalog checks; this does not grant renderer authority.

Input comes from wp-integration/run-template-rendering-live.php. Requirements:
Pillow, numpy, opencv-python-headless, zxing-cpp and Poppler's pdfinfo/pdftoppm.
The immutable eleven-fixture production ceremony remains separate and unchanged.
"""
from pathlib import Path
import argparse
import hashlib
import html
import json
import os
import re
import subprocess

import cv2
from PIL import Image, ImageDraw
import zxingcpp


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('artifacts', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--poppler-bin', type=Path)
    args = parser.parse_args()
    artifacts, output = args.artifacts.resolve(), args.output.resolve()
    if not artifacts.is_dir() or output.exists() or output == artifacts or artifacts.is_relative_to(output):
        raise ValueError('Use an existing artifact directory and a fresh, separate review directory.')
    manifest = artifacts / 'results.json'
    data = json.loads(manifest.read_text(encoding='utf-8'))
    if data.get('schema') != 'certloom-template-runtime-review' or data.get('version') != 1:
        raise ValueError('Unsupported catalog review manifest.')
    standard = 'https://example.invalid/certloom/verify/CL-PREVIEW-STANDARD'
    long_url = 'https://example.invalid/certloom/verify/CL-PREVIEW-VERY-LONG-NAME'
    if data.get('verification_url') != standard or data.get('long_verification_url') != long_url:
        raise ValueError('The expected synthetic verification payloads have changed.')
    presets = data['presets']
    keys = [row['preset'] for row in presets]
    if len(keys) != 42 or len(set(keys)) != 42 or any(not re.fullmatch(r'[a-z0-9_-]+', key) for key in keys):
        raise ValueError('Expected the entire 42-design catalog without duplicate or unsafe identifiers.')

    def artifact(name, digest, size=None):
        if not re.fullmatch(r'[a-z0-9_-]+\.(pdf|png|jpg)', name):
            raise ValueError('Unsafe artifact name.')
        path = artifacts / name
        if path.is_symlink() or path.resolve().parent != artifacts or not path.is_file():
            raise ValueError('Artifact is not a regular local file.')
        payload = path.read_bytes()
        if hashlib.sha256(payload).hexdigest() != digest or (size is not None and len(payload) != size):
            raise ValueError('Artifact bytes no longer match the producer receipt: ' + name)
        return path

    # Validate every retained artifact before creating any review output.
    for row in presets:
        if set(row['formats']) != {'pdf', 'png', 'jpeg'} or len(row['qr']) != 1:
            raise ValueError('Every design needs three formats and one verification QR.')
        for descriptor in row['formats'].values():
            artifact(descriptor['file'], descriptor['sha256'], descriptor['bytes'])
        artifact(row['long_name_png'], row['long_name_sha256'])
    output.mkdir(parents=True, exist_ok=False)
    detector = cv2.QRCodeDetector()
    checks = []
    pages = []
    for row in presets:
        key = row['preset']
        pdf = artifacts / row['formats']['pdf']['file']
        executable = lambda name: str(args.poppler_bin / (name + '.exe')) if args.poppler_bin else name
        info = subprocess.run([executable('pdfinfo'), str(pdf)], capture_output=True, check=True).stdout.decode('utf-8')
        page_count = int(next(line.split(':', 1)[1].strip() for line in info.splitlines() if line.startswith('Pages:')))
        pages.append({'preset': key, 'pages': page_count})
        if page_count != 1:
            raise ValueError(f'{key}: expected one PDF page, got {page_count}')
        prefix = output / (key + '-pdf')
        subprocess.run([executable('pdftoppm'), '-r', '120', '-singlefile', '-png', str(pdf), str(prefix)], capture_output=True, check=True)
        geometry, qr = row['page'], row['qr'][0]
        files = [('pdf', prefix.with_suffix('.png')), ('png', artifacts / row['formats']['png']['file']),
                 ('jpeg', artifacts / row['formats']['jpeg']['file']), ('long', artifacts / row['long_name_png'])]
        for kind, file in files:
            expected = long_url if kind == 'long' else standard
            bitmap = cv2.imread(str(file))
            if bitmap is None:
                raise ValueError('Unreadable artifact bitmap: ' + str(file))
            height, width = bitmap.shape[:2]
            x = lambda mm: int(round(mm * width / geometry['width_mm']))
            y = lambda mm: int(round(mm * height / geometry['height_mm']))
            margin = 3
            crop = bitmap[max(0, y(qr['y_mm'] - margin)):min(height, y(qr['y_mm'] + qr['height_mm'] + margin)),
                          max(0, x(qr['x_mm'] - margin)):min(width, x(qr['x_mm'] + qr['width_mm'] + margin))]
            zxing = [code.text for code in zxingcpp.read_barcodes(bitmap, formats=zxingcpp.BarcodeFormat.QRCode)]
            if expected not in zxing and crop.size:
                zxing += [code.text for code in zxingcpp.read_barcodes(crop, formats=zxingcpp.BarcodeFormat.QRCode)]
            opencv = ''
            if crop.size:
                for factor in (1, 2, 3, 4):
                    enlarged = cv2.resize(crop, None, fx=factor, fy=factor, interpolation=cv2.INTER_NEAREST)
                    opencv, _, _ = detector.detectAndDecode(enlarged)
                    if opencv == expected:
                        break
            checks.append({'preset': key, 'format': kind, 'width': width, 'height': height,
                           'zxing_matches': expected in zxing, 'opencv_matches': opencv == expected,
                           'matches': expected in zxing or opencv == expected})
        print('Checked ' + key, flush=True)

    for kind in ('png', 'long', 'pdf'):
        for start in range(0, len(presets), 6):
            canvas = Image.new('RGB', (1800, 1020), '#e5e7eb')
            draw = ImageDraw.Draw(canvas)
            for index, row in enumerate(presets[start:start + 6]):
                file = output / (row['preset'] + '-pdf.png') if kind == 'pdf' else artifacts / (row['long_name_png'] if kind == 'long' else row['formats']['png']['file'])
                picture = Image.open(file).convert('RGB')
                picture.thumbnail((580, 460), Image.Resampling.LANCZOS)
                left, top = (index % 3) * 600, (index // 3) * 510
                draw.text((left + 8, top + 8), row['name'] + ' / ' + kind, fill='#111827')
                canvas.paste(picture, (left + (600 - picture.width) // 2, top + 34 + (460 - picture.height) // 2))
            canvas.save(output / f'catalog-{kind}-{start // 6 + 1}.png')
    report = {'schema': 'certloom-template-catalog-verification', 'version': 1,
              'producer_manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest(),
              'designs': len(presets), 'single_page_pdfs': len(pages), 'qr_checks': len(checks),
              'qr_passes': sum(check['matches'] for check in checks), 'checks': checks,
              'scope': 'Supplementary 42-design standard/very-long-name checks; no release authority or phone-camera acceptance.'}
    (output / 'verification.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    cards = []
    href = lambda file: html.escape(os.path.relpath(file, output).replace('\\', '/'), quote=True)
    for row in presets:
        links = ' · '.join(f'<a href="{href(artifacts / item["file"])}">{html.escape(kind.upper())}</a>' for kind, item in row['formats'].items())
        cards.append(f'<article><h2>{html.escape(row["name"])}</h2><p>{links}</p><img loading="lazy" src="{href(artifacts / row["formats"]["png"]["file"])}" alt="Standard synthetic sample"><details><summary>Very long recipient name</summary><img loading="lazy" src="{href(artifacts / row["long_name_png"])}" alt="Very long synthetic name"></details></article>')
    page = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Certloom template review</title><style>body{font:16px system-ui;margin:24px;background:#f1f5f9;color:#0f172a}main{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:24px}article{padding:16px;background:white;border-radius:8px}img{width:100%;height:auto}h2{font-size:20px}summary{cursor:pointer;padding:12px 0}a{color:#1d4ed8}</style><h1>Certloom: all 42 built-in designs</h1><p>Synthetic samples only. Open each actual PDF, PNG and JPEG to review. This page does not enable production downloads.</p><main>' + ''.join(cards) + '</main>'
    (output / 'index.html').write_text(page, encoding='utf-8')
    print(json.dumps({key: report[key] for key in ('designs', 'single_page_pdfs', 'qr_checks', 'qr_passes')}))
    if report['qr_passes'] != report['qr_checks']:
        raise SystemExit(1)


if __name__ == '__main__':
    main()

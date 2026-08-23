#!/usr/bin/env python3
"""Capture exact provenance for Certloom's independent PDF-04 verifier.

This script describes the verifier, not the renderer candidate. A production
candidate is never allowed to choose or modify these facts.
"""
from __future__ import annotations
import argparse, hashlib, json, platform, subprocess, sys
from pathlib import Path
import cv2
import numpy as np
from PIL import Image

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def version_output(command: list[str]) -> str:
    proc = subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=True)
    line = ' '.join(x.strip() for x in proc.stdout.splitlines() if x.strip())
    if not line:
        raise RuntimeError('verifier tool did not report a version: ' + command[0])
    return line[:256]


def capture() -> dict:
    plugin = (ROOT / 'certloom.php').read_text(encoding='utf-8', errors='replace')
    import re
    match = re.search(r'^\s*\*\s*Version:\s*([^\s]+)', plugin, re.M)
    if not match:
        raise RuntimeError('Certloom plugin version is unavailable')
    return {
        'schema': 'certloom-renderer-verifier-environment',
        'version': 1,
        'plugin_version': match.group(1),
        'runtime': {
            'python_implementation': platform.python_implementation(),
            'python_version': platform.python_version(),
            'php_version': version_output(['php', '-r', 'echo PHP_VERSION;']),
            'platform': platform.platform(),
        },
        'tools': {
            'pdfinfo': version_output(['pdfinfo', '-v']),
            'pdftocairo': version_output(['pdftocairo', '-v']),
        },
        'packages': {
            'numpy': str(np.__version__),
            'opencv': str(cv2.__version__),
            'pillow': str(Image.__version__),
        },
        'scripts': {
            'verify_bundle_sha256': sha(HERE / 'verify-bundle.py'),
            'capture_environment_sha256': sha(Path(__file__).resolve()),
            'validate_candidate_sha256': sha(HERE / 'validate-candidate.php'),
            'verify_receipt_sha256': sha(HERE / 'verify-receipt.php'),
            'validate_environment_sha256': sha(HERE / 'validate-verifier-environment.php'),
            'validate_report_sha256': sha(HERE / 'validate-verification-report.php'),
        },
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--output')
    args = ap.parse_args()
    value = capture()
    text = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
    if args.output:
        Path(args.output).write_text(text, encoding='utf-8')
    print(text, end='')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())

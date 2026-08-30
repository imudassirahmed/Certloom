#!/usr/bin/env python3
"""Focused PDF-04 independent-verifier resource/symlink boundary regression."""
from importlib.util import spec_from_file_location, module_from_spec
from pathlib import Path
from types import SimpleNamespace
import subprocess
import sys
import tempfile
from PIL import Image

HERE = Path(__file__).resolve().parent
spec = spec_from_file_location('certloom_verify_bundle_resource_boundary', HERE / 'verify-bundle.py')
mod = module_from_spec(spec)
spec.loader.exec_module(mod)

checks = 0

def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)

def throws(fn, contains=None):
    try:
        fn()
    except RuntimeError as exc:
        if contains is not None and contains not in str(exc):
            raise AssertionError(f'wrong failure: {exc}') from exc
        return True
    raise AssertionError('expected RuntimeError')

with tempfile.TemporaryDirectory(prefix='certloom-verifier-boundary-') as td:
    root = Path(td) / 'bundle'
    root.mkdir()
    check(mod.safe_bundle_root(root) == root.resolve(), 'regular bundle root must resolve')

    root_link = Path(td) / 'bundle-link'
    root_link.symlink_to(root, target_is_directory=True)
    check(throws(lambda: mod.safe_bundle_root(root_link), 'may not be symbolic'), 'symbolic bundle root must fail closed')

    small = root / 'small.bin'
    small.write_bytes(b'abcd')
    check(mod.safe_file(root.resolve(), 'small.bin', 4, 'small evidence') == small.resolve(), 'bounded regular evidence must pass')
    check(throws(lambda: mod.safe_file(root.resolve(), 'small.bin', 3, 'small evidence'), 'resource boundary'), 'oversized evidence must fail before hashing/opening')

    leaf_link = root / 'leaf-link.bin'
    leaf_link.symlink_to(small.name)
    check(throws(lambda: mod.safe_file(root.resolve(), 'leaf-link.bin', 10, 'leaf evidence'), 'symbolic'), 'symbolic leaf evidence must fail')

    real_dir = root / 'real-dir'
    real_dir.mkdir()
    nested = real_dir / 'nested.bin'
    nested.write_bytes(b'x')
    dir_link = root / 'dir-link'
    dir_link.symlink_to(real_dir.name, target_is_directory=True)
    check(throws(lambda: mod.safe_file(root.resolve(), 'dir-link/nested.bin', 10, 'nested evidence'), 'symbolic'), 'symbolic parent evidence path must fail')

    png = root / 'image.png'
    Image.new('RGB', (8, 6), 'white').save(png, format='PNG')
    rgb = mod.image_to_rgb(png, 'PNG', (8, 6))
    check(rgb.shape == (6, 8, 3), 'exact bounded PNG geometry must decode')
    check(throws(lambda: mod.image_to_rgb(png, 'PNG', (7, 6)), 'raster geometry mismatch'), 'wrong PNG geometry must fail before array comparison')

    original_pixels = mod.MAX_RASTER_PIXELS
    mod.MAX_RASTER_PIXELS = 20
    try:
        check(throws(lambda: mod.image_to_rgb(png, 'PNG', (8, 6)), 'raster-pixel boundary'), 'pixel boundary must fail before full raster acceptance')
    finally:
        mod.MAX_RASTER_PIXELS = original_pixels

    check(
        throws(
            lambda: mod.run_command([sys.executable, '-c', 'import time; time.sleep(2)'], 'slow verifier tool', 1),
            'time boundary'
        ),
        'subprocess time boundary must fail closed'
    )

    original_run = mod.run_command
    try:
        mod.run_command = lambda *args, **kwargs: SimpleNamespace(stdout='Pages: 2\nPage size: 841.89 x 595.276 pts\n')
        check(throws(lambda: mod.pdf_size(small), 'exactly one page'), 'multi-page PDF metadata must fail closed')
    finally:
        mod.run_command = original_run

print('PASS  independent verifier enforces bounded non-symbolic evidence, exact raster geometry, one-page PDF metadata and subprocess time limits')
print(f'\n{checks} checks, 0 failures')

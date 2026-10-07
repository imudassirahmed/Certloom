#!/usr/bin/env python3
"""Exact Local data-file recovery rehearsal. No plugin/core source copies or SQL execution.

This is test tooling, not a portable Certloom backup format or site migration tool.
Restore creates only missing original data files; it refuses changed files and links.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import zipfile

SITE = Path(r'C:\Users\Admin\Local Sites\ecapla-test-wp-site')
ROOTS = {
    'uploads': SITE / 'app/public/wp-content/uploads',
    'private-app': SITE / 'app/certloom-private',
    'private-content': SITE / 'app/public/wp-content/certloom-private',
}
OUTPUT = Path(os.environ.get('TEMP', '')) / 'certloom-recovery-20260929'

def safe(path, root):
    assert root.is_relative_to(SITE) and path.is_relative_to(root), 'Path outside exact Local data root'
    for candidate in (path, *path.parents):
        if candidate.exists():
            info = candidate.lstat()
            assert not candidate.is_symlink() and not (getattr(info, 'st_file_attributes', 0) & 0x400), 'Reparse/link path refused'
        if candidate == SITE:
            return
    raise AssertionError('Missing root boundary')

def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()

def inventory():
    entries = {}
    for label, root in ROOTS.items():
        safe(root, root)
        if not root.exists():
            continue
        for path in sorted(root.rglob('*')):
            safe(path, root)
            if path.is_file():
                entries[label + '/' + path.relative_to(root).as_posix()] = {'sha256': digest(path), 'bytes': path.stat().st_size}
    return entries

def destination(name):
    label, relative = name.split('/', 1)
    assert label in ROOTS and relative and '\\' not in relative and ':' not in relative, 'Invalid archive path'
    parts = relative.split('/')
    assert all(part not in ('', '.', '..') for part in parts), 'Unsafe relative path'
    root = ROOTS[label]
    path = root.joinpath(*parts)
    safe(path, root)
    return root, path

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['capture', 'restore', 'verify'])
    parser.add_argument('--preserve-appended-logs', action='store_true', help='Retain appended WooCommerce diagnostic logs; require the original byte prefix and exact recovery of every other file')
    args = parser.parse_args()
    assert SITE.is_dir() and (SITE / 'app/public/wp-content/plugins/certloom').is_dir(), 'Exact Local site required'
    OUTPUT.mkdir(exist_ok=True)
    archive = OUTPUT / 'local-data-files-before-lifecycle.zip'
    if args.action == 'capture':
        assert not archive.exists(), 'Refusing to overwrite prior recovery files'
        entries = inventory()
        with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as package:
            for name in entries:
                _, path = destination(name)
                package.write(path, name)
            package.writestr('manifest.json', json.dumps({'schema': 'certloom-local-file-rehearsal-v1', 'entries': entries}, sort_keys=True))
        assert inventory() == entries, 'Data changed while capturing files'
        with zipfile.ZipFile(archive) as package:
            assert package.testzip() is None, 'Recovery ZIP CRC failure'
        print(json.dumps({'files': len(entries), 'bytes': sum(item['bytes'] for item in entries.values()), 'zip_sha256': digest(archive)}))
        return
    with zipfile.ZipFile(archive) as package:
        assert package.testzip() is None, 'Damaged recovery ZIP'
        manifest = json.loads(package.read('manifest.json'))
        assert manifest['schema'] == 'certloom-local-file-rehearsal-v1', 'Wrong rehearsal format'
        entries = manifest['entries']
        assert len(package.namelist()) == len(entries) + 1 and set(package.namelist()) == set(entries) | {'manifest.json'}, 'Unexpected/duplicate members'
        # Validate the entire archive and all destinations before creating anything.
        for name, info in entries.items():
            destination(name)
            data = package.read(name)
            assert len(data) == info['bytes'] and hashlib.sha256(data).hexdigest() == info['sha256'], 'Recovery asset hash mismatch'
        restored = 0
        preserved_logs = []
        expected = dict(entries)
        if args.action == 'restore':
            for name, info in entries.items():
                root, path = destination(name)
                if path.exists():
                    if args.preserve_appended_logs and name.startswith('uploads/wc-logs/') and name.endswith('.log') and path.is_file():
                        current = path.read_bytes()
                        original = package.read(name)
                        if len(current) > len(original) and current.startswith(original):
                            preserved_logs.append(name)
                            expected[name] = {'sha256': hashlib.sha256(current).hexdigest(), 'bytes': len(current)}
                            continue
                    assert path.is_file() and digest(path) == info['sha256'], 'Changed current file requires review; never overwritten'
                    continue
                path.parent.mkdir(parents=True, exist_ok=True)
                safe(path, root)
                with path.open('xb') as stream:
                    stream.write(package.read(name))
                restored += 1
        assert inventory() == expected, 'Original data-file inventory/content not restored exactly'
        print(json.dumps({'files': len(entries), 'restored_missing_files': restored, 'exact_data_files': len(entries) - len(preserved_logs), 'preserved_appended_logs': preserved_logs, 'zip_sha256': digest(archive)}))

if __name__ == '__main__':
    main()

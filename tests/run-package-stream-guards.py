#!/usr/bin/env python3
"""Reject unsafe package destinations and forged authority using the real source.

No repository, working copy, or extracted plugin is created. The only temporary
fixtures are two metadata files in an owned directory below the system temp root.
Run after staging reviewed source inputs; the package runner rejects untracked
inputs before checking authority.
"""
from pathlib import Path
import os
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parent.parent
runner = root / 'tests/package-stream.py'
temp_root = Path(tempfile.gettempdir()).resolve()
owned = Path(tempfile.mkdtemp(prefix='certloom-package-guards-', dir=temp_root)).resolve()
if owned.parent != temp_root or not owned.name.startswith('certloom-package-guards-'):
    raise RuntimeError('Temporary metadata directory escaped its owner.')
marker = owned / 'keep.txt'
fake = owned / 'forged-accepted.json'
checks = 0


def rejected(output, expected, version='1.7.0-alpha.4.87', epoch='1787356800', authority=''):
    global checks
    result = subprocess.run([sys.executable, str(runner), str(root), str(output), version, epoch, authority, ''],
                            capture_output=True, env=os.environ)
    text = result.stderr.decode('utf-8', 'replace')
    if result.returncode != 2 or expected not in text:
        raise RuntimeError(f'Expected refusal containing {expected!r}: exit={result.returncode}, stderr={text}')
    checks += 1
    print('PASS  ' + expected)
    return text


try:
    marker.write_bytes(b'Preserve pre-existing destination data.\n')
    rejected(root, 'fresh path outside and separate')
    rejected(root / 'output/package-guard-child', 'fresh path outside and separate')
    rejected(root.parent, 'fresh path outside and separate')
    rejected(owned, 'fresh path outside and separate')
    rejected(owned / 'invalid-version', 'Invalid build version.', version='../../escape')
    rejected(owned / 'invalid-epoch', 'outside the ZIP timestamp range.', epoch='0')
    fake.write_bytes(b'{}\n')
    error = rejected(owned / 'forged-authority', 'Certloom package refused:', authority=str(fake))
    # Check that this was an authority validation failure, not an earlier guard.
    if 'Untracked release inputs' in error or 'manifest' not in error.lower() or (owned / 'forged-authority').exists():
        raise RuntimeError('Forged authority did not reach manifest validation before publication.')
    if marker.read_bytes() != b'Preserve pre-existing destination data.\n' or sorted(p.name for p in owned.iterdir()) != ['forged-accepted.json', 'keep.txt']:
        raise RuntimeError('A refused build changed existing files or left output artifacts.')
    checks += 1
    print('PASS  Refused builds preserve existing data and create no output')
    print(f'\n{checks} package-stream guard checks, 0 failures')
finally:
    # Exact owned files only; no recursive deletion or shell-composed paths.
    if owned.resolve() != owned or owned.parent != temp_root or owned.is_symlink():
        raise RuntimeError('Refusing cleanup of a changed metadata owner.')
    fake.unlink(missing_ok=True)
    marker.unlink(missing_ok=True)
    owned.rmdir()

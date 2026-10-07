#!/usr/bin/env python3
"""Build deterministic ZIPs directly from tracked working files, without copies.

Full authority provenance is validated by build-release.sh before this entry
point. Manifests are checked again here against the exact runtime payload. Only
those metadata files may be injected; no plugin tree is staged or extracted.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
import zipfile

DEV_ROOTS = {'docs', 'tests', 'bin', 'audit', 'output', 'build', 'reports', 'qa-evidence', '.playwright-cli'}
AUTHORITY = {'resources/renderer/accepted.json', 'resources/domain/accepted.json'}


def run(args, env=None):
    result = subprocess.run(args, capture_output=True, check=False, env=env)
    if result.returncode:
        raise RuntimeError(result.stderr.decode('utf-8', 'replace') + result.stdout.decode('utf-8', 'replace'))
    return result.stdout


def residue(rel):
    return any(p in {'.git', '.svn', '__pycache__'} for p in rel.split('/')) or rel.lower().endswith(('.pyc', '.pyo')) or rel.split('/')[-1] == '.DS_Store'


def sha(path):
    with path.open('rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()


def regular(path, root):
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(f'Release input is not a regular file: {path}')
    if not path.resolve().is_relative_to(root):
        raise RuntimeError(f'Release input escapes its source: {path}')
    current = path
    while current != root:
        attributes = getattr(current.lstat(), 'st_file_attributes', 0)
        if current.is_symlink() or attributes & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0):
            raise RuntimeError(f'Release input follows a link: {path}')
        current = current.parent


def fingerprint(files):
    records = [{'path': rel, 'bytes': entry['bytes'], 'sha256': entry['sha256']}
               for rel, entry in sorted(files.items()) if rel not in AUTHORITY]
    identity = {'schema': 'certloom-runtime-payload', 'version': 1, 'files': records}
    canonical = json.dumps(identity, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()
    return {'schema': identity['schema'], 'version': 1, 'sha256': hashlib.sha256(canonical).hexdigest(),
            'file_count': len(records), 'total_bytes': sum(r['bytes'] for r in records)}


def zip_write(path, files, date):
    with zipfile.ZipFile(path, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for rel, entry in sorted(files.items()):
            info = zipfile.ZipInfo('certloom/' + rel, date)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            info._compresslevel = 9
            digest = hashlib.sha256()
            size = 0
            with entry['path'].open('rb') as source, archive.open(info, 'w') as target:
                while chunk := source.read(1024 * 1024):
                    digest.update(chunk)
                    size += len(chunk)
                    target.write(chunk)
            if size != entry['bytes'] or digest.hexdigest() != entry['sha256']:
                raise RuntimeError(f'Input changed while packaging: {rel}')


def zip_verify(path, files, date):
    with zipfile.ZipFile(path) as archive:
        expected = ['certloom/' + rel for rel in sorted(files)]
        if archive.namelist() != expected or archive.testzip() is not None:
            raise RuntimeError('ZIP file list or CRC verification failed.')
        for info in archive.infolist():
            entry = files[info.filename[len('certloom/'):]]
            with archive.open(info) as handle:
                digest = hashlib.file_digest(handle, 'sha256').hexdigest()
            if digest != entry['sha256'] or info.file_size != entry['bytes'] or info.date_time != date or (info.external_attr >> 16) != (stat.S_IFREG | 0o644):
                raise RuntimeError(f'ZIP byte/metadata parity failed: {info.filename}')


def main():
    root, out = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve()
    version, epoch = sys.argv[3], int(sys.argv[4])
    if out == root or out.is_relative_to(root) or root.is_relative_to(out) or out.exists():
        raise RuntimeError('Build output must be a fresh path outside and separate from the source.')
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?', version):
        raise RuntimeError('Invalid build version.')
    stamp = datetime.fromtimestamp(epoch, timezone.utc)
    if not 1980 <= stamp.year <= 2107:
        raise RuntimeError('SOURCE_DATE_EPOCH is outside the ZIP timestamp range.')
    date = (stamp.year, stamp.month, stamp.day, stamp.hour, stamp.minute, stamp.second // 2 * 2)
    if run(['git', '-C', str(root), 'ls-files', '--others', '--exclude-standard', '-z']):
        raise RuntimeError('Untracked release inputs exist. Review and add or remove them before packaging.')
    source = {}
    for raw in run(['git', '-C', str(root), 'ls-files', '-z', '--cached', '--']).split(b'\0'):
        if not raw:
            continue
        rel = raw.decode('utf-8', 'strict')
        if residue(rel):
            continue
        path = root / rel
        if not path.exists() and not path.is_symlink():
            continue  # Match tracked deletions in the working tree.
        regular(path, root)
        source[rel] = {'path': path, 'bytes': path.stat().st_size, 'sha256': sha(path)}
    for rel, supplied in zip(('resources/renderer/accepted.json', 'resources/domain/accepted.json'), sys.argv[5:7]):
        if supplied:
            if rel in source:
                raise RuntimeError('Refusing ambiguous authority inputs.')
            path = Path(supplied).resolve()
            regular(path, path.parent)
            source[rel] = {'path': path, 'bytes': path.stat().st_size, 'sha256': sha(path)}
    production = {rel: entry for rel, entry in source.items() if rel.split('/')[0] not in DEV_ROOTS}
    php = os.environ.get('CERTLOOM_BUILD_PHP', 'php')
    # Direct CLI invocation must also reject an invalid or stale manifest.
    for rel, validator in (('resources/renderer/accepted.json', 'renderer-candidate'),
                           ('resources/domain/accepted.json', 'domain-authority')):
        if rel in production:
            run([php, str(root / 'tests' / validator / 'validate-bundled-authority.php'), str(production[rel]['path'])])
    # PHP is the canonical release identity implementation. This parity check
    # also detects ignored production files which a tracked-only ZIP would omit.
    code = "require $argv[1].'/tests/bootstrap-autoload.php'; echo json_encode((new Certloom\\Application\\Rendering\\RuntimePayloadFingerprint())->fingerprint($argv[1]));"
    actual = json.loads(run([php, '-r', code, str(root)]))
    expected = fingerprint(production)
    if actual != expected:
        raise RuntimeError('Tracked package and canonical runtime payload differ; review ignored production inputs.')
    environment = dict(os.environ, SOURCE_DATE_EPOCH=str(epoch))
    with tempfile.TemporaryDirectory(prefix='certloom-package-metadata-') as metadata:
        pot = Path(metadata) / 'certloom.pot'
        run([sys.executable, str(root / 'tests/i18n/make-pot.py'), str(root), '-o', str(pot)], environment)
        if pot.read_bytes() != (root / 'languages/certloom.pot').read_bytes():
            raise RuntimeError(f'Translation catalog is stale. Regenerate it with SOURCE_DATE_EPOCH={epoch}.')
    run([sys.executable, str(root / 'tests/i18n/validate_language_packs.py'), str(root), '--manifest', 'languages/certloom-language-packs.json'])
    out.mkdir(parents=True, exist_ok=False)
    archives = []
    for suffix, files in (('-source', source), ('', production)):
        name = f'certloom-{version}{suffix}.zip'
        first, second = out / ('.' + name + '.first'), out / ('.' + name + '.second')
        zip_write(first, files, date)
        zip_verify(first, files, date)
        zip_write(second, files, date)
        zip_verify(second, files, date)
        if sha(first) != sha(second):
            raise RuntimeError('Repeated ZIP build is not byte-identical.')
        second.unlink()  # Owned archive only; never traverse/delete a tree.
        archives.append((first, out / name))
    for rel, entry in source.items():
        if entry['path'].stat().st_size != entry['bytes'] or sha(entry['path']) != entry['sha256']:
            raise RuntimeError(f'Input changed before publication: {rel}')
    if json.loads(run([php, '-r', code, str(root)])) != actual:
        raise RuntimeError('Canonical runtime payload changed before publication.')
    for temporary, final in archives:
        temporary.rename(final)
    for name, files in (('source', source), ('release', production)):
        (out / f'{name}-files.txt').write_text(''.join('./' + rel + '\n' for rel in sorted(files)), encoding='utf-8', newline='\n')
    (out / 'runtime-payload.json').write_text(json.dumps(actual, sort_keys=True, separators=(',', ':')) + '\n', encoding='utf-8', newline='\n')
    sums = ''.join(f'{sha(final)}  {final.name}\n' for _, final in reversed(archives))
    (out / f'certloom-{version}-SHA256SUMS.txt').write_text(sums, encoding='utf-8', newline='\n')
    print(f'source_files={len(source)}\nrelease_files={len(production)}\nproduction_only=0\nmodified_common=0\nreproducible_zip=yes\nsource_copies=0')
    print('renderer_authority=' + ('validated' if 'resources/renderer/accepted.json' in production else 'absent'))
    print('domain_authority=' + ('validated' if 'resources/domain/accepted.json' in production else 'absent'))
    print(sums, end='')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, OSError, IndexError) as error:
        print('Certloom package refused: ' + str(error), file=sys.stderr)
        sys.exit(2)

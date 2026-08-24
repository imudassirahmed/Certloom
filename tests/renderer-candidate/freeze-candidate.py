#!/usr/bin/env python3
"""Deterministically freeze exact renderer-candidate provenance for PDF-04.

Source-only release-engineering tool. It never installs dependencies and never
writes into the Certloom plugin tree. A prepared candidate root is normalized
into a deterministic ZIP, the exact dependency lock is copied, and a canonical
RendererCandidateManifest-compatible JSON record plus a freeze index are
published atomically.
"""
from __future__ import annotations
import argparse, hashlib, json, os, re, shutil, stat, tempfile, zipfile
from pathlib import Path, PurePosixPath

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1].resolve()
FIXED_ZIP_TIME = (2026, 8, 22, 0, 0, 0)
MAX_FILE_BYTES = 50 * 1024 * 1024
MAX_TOTAL_BYTES = 150 * 1024 * 1024
FORBIDDEN_PARTS = {'.git', '.svn', '__pycache__', '.DS_Store'}


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def canonical(value) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf-8')


def load_json(path: Path, label: str) -> dict:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f'{label} must be a regular non-symbolic file')
    value = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(value, dict):
        raise ValueError(f'{label} must be a JSON object')
    return value


def plugin_version() -> str:
    text = (ROOT / 'certloom.php').read_text(encoding='utf-8', errors='replace')
    m = re.search(r'^\s*\*\s*Version:\s*([^\s]+)', text, re.M)
    if not m:
        raise ValueError('Certloom plugin version is unavailable')
    return m.group(1)


def inside(child: Path, parent: Path) -> bool:
    try:
        child.resolve().relative_to(parent.resolve())
        return True
    except ValueError:
        return False


def validate_recipe(value: dict) -> dict:
    keys = {'schema','version','renderer_id','renderer_version','formats','implementation','security','requirements','archive','lock'}
    if set(value) != keys or value.get('schema') != 'certloom-renderer-candidate-freeze-recipe' or value.get('version') != 1:
        raise ValueError('candidate freeze recipe schema/version/shape is unsupported')
    if not re.fullmatch(r'[a-z][a-z0-9._-]{0,63}', str(value['renderer_id'])):
        raise ValueError('recipe renderer_id is invalid')
    if not isinstance(value['renderer_version'], str) or not value['renderer_version'] or len(value['renderer_version']) > 64:
        raise ValueError('recipe renderer_version is invalid')
    formats = sorted(value['formats']) if isinstance(value['formats'], list) else []
    if not formats or len(formats) != len(set(formats)) or any(x not in {'pdf','png','jpeg'} for x in formats):
        raise ValueError('recipe formats are invalid')
    imp = value['implementation']
    ikeys = {'package','package_version','provenance_mode','namespace_prefix','license'}
    if not isinstance(imp, dict) or set(imp) != ikeys:
        raise ValueError('recipe implementation shape is invalid')
    if imp['provenance_mode'] not in {'prefixed-package','certloom-native'}:
        raise ValueError('recipe provenance mode is invalid')
    if imp['provenance_mode'] == 'prefixed-package' and not str(imp['namespace_prefix']).startswith('CertloomVendor\\'):
        raise ValueError('prefixed recipe must use CertloomVendor namespace')
    if imp['provenance_mode'] == 'certloom-native' and not str(imp['namespace_prefix']).startswith('Certloom\\'):
        raise ValueError('native recipe must use real Certloom namespace')
    sec = value['security']
    if sec != {'execution_mode':'php-inprocess','input_schema':'certloom-render-plan:1','network':'none','processes':'none','remote_assets':False}:
        raise ValueError('recipe security profile violates Certloom policy')
    req = value['requirements']
    if not isinstance(req, dict) or set(req) != {'php_min','extensions','classes','functions'}:
        raise ValueError('recipe requirements shape is invalid')
    arch = value['archive']
    if not isinstance(arch, dict) or set(arch) != {'include_paths','required_paths','required_php_namespace','forbid_unprefixed_namespace'}:
        raise ValueError('recipe archive shape is invalid')
    if not isinstance(arch['include_paths'], list) or not arch['include_paths']:
        raise ValueError('recipe must include at least one archive path')
    lock = value['lock']
    if not isinstance(lock, dict) or set(lock) != {'kind','required_package','required_version','packages_dev_must_be_empty'}:
        raise ValueError('recipe lock shape is invalid')
    if lock['kind'] not in {'composer','native-json'}:
        raise ValueError('recipe lock kind is invalid')
    return value


def relative_clean(value: str) -> PurePosixPath:
    p = PurePosixPath(value)
    if p.is_absolute() or '..' in p.parts or not p.parts or str(p) in {'.',''}:
        raise ValueError('recipe archive path must be a bounded relative path')
    return p


def collect_files(root: Path, include_paths: list[str]) -> list[tuple[Path,str]]:
    out: dict[str,Path] = {}
    total = 0
    for item in include_paths:
        rel = relative_clean(str(item))
        path = root.joinpath(*rel.parts)
        if not path.exists() or path.is_symlink():
            raise ValueError(f'candidate include path is missing or symbolic: {rel}')
        candidates = [path] if path.is_file() else sorted(x for x in path.rglob('*') if x.is_file())
        for file in candidates:
            if file.is_symlink():
                raise ValueError('candidate archive may not contain symbolic files')
            frel = file.relative_to(root).as_posix()
            parts = PurePosixPath(frel).parts
            if any(part in FORBIDDEN_PARTS or part.endswith(('.pyc','.pyo')) for part in parts):
                raise ValueError(f'candidate archive contains forbidden development metadata: {frel}')
            size = file.stat().st_size
            if size < 0 or size > MAX_FILE_BYTES:
                raise ValueError(f'candidate file exceeds size policy: {frel}')
            total += size
            if total > MAX_TOTAL_BYTES:
                raise ValueError('candidate source archive exceeds total size policy')
            out[frel] = file
    if not out:
        raise ValueError('candidate source archive would be empty')
    return [(out[k], k) for k in sorted(out)]


def inspect_php_namespaces(files: list[tuple[Path,str]], required: str, forbid_unprefixed: str) -> None:
    joined = ''
    for path, rel in files:
        if rel.lower().endswith('.php'):
            joined += '\n' + path.read_text(encoding='utf-8', errors='replace')
    if required and required not in joined:
        raise ValueError('prepared candidate tree does not contain the required namespace prefix')
    if forbid_unprefixed:
        ns = re.escape(forbid_unprefixed.rstrip('\\'))
        if re.search(r'\b(?:namespace|use)\s+' + ns + r'\\', joined):
            raise ValueError('prepared candidate tree still contains an unprefixed renderer namespace')


def validate_lock(path: Path, recipe: dict) -> dict:
    data = load_json(path, 'candidate dependency lock')
    cfg = recipe['lock']
    if cfg['kind'] == 'composer':
        packages = data.get('packages')
        if not isinstance(packages, list):
            raise ValueError('composer lock must contain packages')
        if cfg['packages_dev_must_be_empty'] and data.get('packages-dev') not in ([], None):
            raise ValueError('production candidate composer lock may not contain packages-dev')
        found = [p for p in packages if isinstance(p, dict) and p.get('name') == cfg['required_package']]
        if len(found) != 1 or str(found[0].get('version','')).lstrip('v') != str(cfg['required_version']).lstrip('v'):
            raise ValueError('composer lock does not bind the required renderer package/version')
        if any(isinstance(p,dict) and p.get('type') == 'composer-plugin' for p in packages):
            raise ValueError('production candidate dependency lock may not contain Composer plugins')
        inventory = []
        for p in sorted((x for x in packages if isinstance(x,dict)), key=lambda x: str(x.get('name',''))):
            inventory.append({'name':str(p.get('name','')),'version':str(p.get('version','')),'license':sorted(str(x) for x in (p.get('license') or []))})
        return {'kind':'composer','packages':inventory}
    if data.get('schema') != 'certloom-native-renderer-lock' or data.get('version') != 1:
        raise ValueError('native renderer dependency lock schema/version is unsupported')
    if data.get('renderer_id') != recipe['renderer_id'] or data.get('requirements') != recipe['requirements']:
        raise ValueError('native renderer dependency lock does not match the recipe identity/requirements')
    return {'kind':'native-json','requirements':data['requirements']}


def write_zip(path: Path, files: list[tuple[Path,str]]) -> None:
    with zipfile.ZipFile(path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for src, rel in files:
            info = zipfile.ZipInfo(rel, FIXED_ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            z.writestr(info, src.read_bytes())


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--recipe', required=True)
    ap.add_argument('--candidate-root', required=True)
    ap.add_argument('--dependency-lock', required=True)
    ap.add_argument('--output', required=True)
    args = ap.parse_args()

    recipe_path = Path(args.recipe).resolve()
    candidate_root = Path(args.candidate_root).resolve()
    lock_path = Path(args.dependency_lock).resolve()
    output = Path(args.output).absolute()
    if inside(output, ROOT):
        raise ValueError('candidate freeze output may not be written inside the Certloom source tree')
    if output.exists() or output.is_symlink():
        raise ValueError('candidate freeze output must not already exist')
    if not candidate_root.is_dir() or candidate_root.is_symlink():
        raise ValueError('candidate root must be a real readable directory')

    recipe = validate_recipe(load_json(recipe_path, 'candidate freeze recipe'))
    files = collect_files(candidate_root, recipe['archive']['include_paths'])
    present = {rel for _, rel in files}
    for item in recipe['archive']['required_paths']:
        rel = relative_clean(str(item)).as_posix()
        if rel not in present:
            raise ValueError(f'candidate archive is missing required file: {rel}')
    inspect_php_namespaces(files, str(recipe['archive']['required_php_namespace']), str(recipe['archive']['forbid_unprefixed_namespace']))
    lock_inventory = validate_lock(lock_path, recipe)

    parent = output.parent
    parent.mkdir(parents=True, exist_ok=True)
    temp = Path(tempfile.mkdtemp(prefix='.' + output.name + '.tmp-', dir=str(parent)))
    try:
        os.chmod(temp, 0o700)
        source_zip = temp / 'candidate-source.zip'
        write_zip(source_zip, files)
        shutil.copyfile(lock_path, temp / 'dependency.lock')
        os.chmod(source_zip, 0o600); os.chmod(temp / 'dependency.lock', 0o600)
        runtime_root = temp / 'runtime'
        runtime_rows = []
        for src, rel in files:
            dst = runtime_root.joinpath(*PurePosixPath(rel).parts)
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(src, dst)
            os.chmod(dst, 0o400)
            runtime_rows.append({'path': rel, 'sha256': sha256_file(dst), 'size': dst.stat().st_size})
        for d in sorted((x for x in runtime_root.rglob('*') if x.is_dir()), key=lambda x: len(x.parts), reverse=True):
            os.chmod(d, 0o500)
        os.chmod(runtime_root, 0o500)
        runtime_tree_hash = hashlib.sha256(canonical(runtime_rows)).hexdigest()
        source_hash = sha256_file(source_zip)
        lock_hash = sha256_file(temp / 'dependency.lock')
        imp = dict(recipe['implementation'])
        imp.update({'source_sha256':source_hash,'dependency_lock_sha256':lock_hash})
        manifest = {
            'schema':'certloom-renderer-candidate','version':2,
            'renderer_id':recipe['renderer_id'],'renderer_version':recipe['renderer_version'],
            'formats':sorted(recipe['formats']),'implementation':imp,
            'security':recipe['security'],'requirements':recipe['requirements'],
        }
        manifest_bytes = canonical(manifest)
        manifest_hash = hashlib.sha256(manifest_bytes).hexdigest()
        (temp / 'candidate.json').write_bytes(manifest_bytes)
        os.chmod(temp / 'candidate.json', 0o600)
        recipe_hash = hashlib.sha256(canonical(recipe)).hexdigest()
        inventory_hash = hashlib.sha256(canonical(lock_inventory)).hexdigest()
        index = {
            'schema':'certloom-renderer-candidate-freeze-index','version':1,
            'plugin_version':plugin_version(),'renderer_id':recipe['renderer_id'],'renderer_version':recipe['renderer_version'],
            'recipe_sha256':recipe_hash,'candidate_manifest_sha256':manifest_hash,
            'source_archive_sha256':source_hash,'dependency_lock_sha256':lock_hash,
            'dependency_inventory_sha256':inventory_hash,'source_file_count':len(files),'runtime_tree_sha256':runtime_tree_hash,
        }
        (temp / 'freeze-index.json').write_bytes(canonical(index) + b'\n')
        (temp / 'recipe.json').write_bytes(canonical(recipe) + b'\n')
        os.chmod(temp / 'freeze-index.json', 0o600); os.chmod(temp / 'recipe.json', 0o600)
        os.replace(temp, output)
        print(json.dumps(index, sort_keys=True, separators=(',', ':')))
        return 0
    except Exception:
        shutil.rmtree(temp, ignore_errors=True)
        raise

if __name__ == '__main__':
    raise SystemExit(main())

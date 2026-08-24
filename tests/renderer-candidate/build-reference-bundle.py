#!/usr/bin/env python3
"""Build a deterministic development-reference acceptance bundle.

This bundle exercises Certloom's independent PDF-04 verifier against the frozen
reference artifacts. It is deliberately marked development-reference and must
never qualify as production renderer acceptance.
"""
from __future__ import annotations
import hashlib, json, shutil, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FIX = ROOT / 'tests' / 'fixtures' / 'rendering'
REF = FIX / 'reference'
PLAN = FIX / 'render-plan-manifest.json'
FIXTURES = ('english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr','primitives')
FORMATS = ('pdf','png','jpeg')


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def write_json(path: Path, value) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n', encoding='utf-8')


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit('usage: build-reference-bundle.py OUTPUT_DIR')
    out = Path(sys.argv[1]).resolve()
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)
    (out / 'manifests').mkdir()
    (out / 'candidates').mkdir()
    for run in ('a','b'):
        (out / 'artifacts' / run).mkdir(parents=True)

    shutil.copy2(REF / 'manifest.json', out / 'manifests' / 'golden.json')
    shutil.copy2(PLAN, out / 'manifests' / 'render-plan.json')
    plan = json.loads(PLAN.read_text(encoding='utf-8'))

    candidate_facts = {
        'pdf': ('development-reference-pdf', 'Development oracle only; uses source-side reference tooling and is not a WordPress production PDF adapter.'),
        'png': ('development-reference-png', 'Development oracle only; uses source-side reference tooling and is not a WordPress production PNG adapter.'),
        'jpeg': ('development-reference-jpeg', 'Development oracle only; uses source-side reference tooling and is not a WordPress production JPEG adapter.'),
    }
    for fmt,(renderer_id,note) in candidate_facts.items():
        write_json(out / 'candidates' / f'{fmt}.json', {
            'schema':'certloom-development-renderer-reference','version':1,
            'renderer_id':renderer_id,'renderer_version':'1','note':note
        })
        (out / 'candidates' / f'{fmt}-source.txt').write_text(f'development reference {fmt.upper()} oracle; not a production renderer\n', encoding='utf-8')
        (out / 'candidates' / f'{fmt}-lock.txt').write_text('development-reference\n', encoding='utf-8')

    runs = {}
    for run in ('a','b'):
        fixtures = {}
        for name in FIXTURES:
            fixtures[name] = {'render_plan_sha256': plan['fixtures'][name]['render_plan_sha256']}
            for fmt in FORMATS:
                src = REF / name / f'reference.{fmt}'
                rel = Path('artifacts') / run / f'{name}.{fmt}'
                dst = out / rel
                shutil.copy2(src, dst)
                fixtures[name][fmt] = {'path': rel.as_posix(), 'sha256': sha(dst), 'receipt': None}
        runs[run] = {'fixtures': fixtures}

    plugin_text = (ROOT / 'certloom.php').read_text(encoding='utf-8', errors='replace')
    import re
    version_match = re.search(r'^\s*\*\s*Version:\s*([^\s]+)', plugin_text, re.M)
    if not version_match:
        raise RuntimeError('Certloom plugin version is unavailable')

    renderers = {}
    for index,fmt in enumerate(FORMATS):
        renderer_id = candidate_facts[fmt][0]
        renderers[fmt] = {
            'id':renderer_id,'version':'1','context_hash':str(index)*64,
            'candidate_manifest':{'path':f'candidates/{fmt}.json','sha256':sha(out/'candidates'/f'{fmt}.json')},
            'source_archive':{'path':f'candidates/{fmt}-source.txt','sha256':sha(out/'candidates'/f'{fmt}-source.txt')},
            'dependency_lock':{'path':f'candidates/{fmt}-lock.txt','sha256':sha(out/'candidates'/f'{fmt}-lock.txt')},
        }

    manifest = {
        'schema':'certloom-renderer-acceptance-bundle','version':3,
        'qualification':'development-reference',
        'plugin_version':version_match.group(1),
        'fixture_manifest': {'path':'manifests/golden.json','sha256':sha(out/'manifests'/'golden.json')},
        'render_plan_manifest': {'path':'manifests/render-plan.json','sha256':sha(out/'manifests'/'render-plan.json')},
        'raster_dpi':96,
        'renderers': renderers,
        'runs': runs,
    }
    write_json(out / 'bundle.json', manifest)
    print(sha(out / 'bundle.json'))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

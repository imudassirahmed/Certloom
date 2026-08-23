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
FIXTURES = ('english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr')


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

    # Deliberately non-production candidate descriptions. The independent verifier
    # can exercise artifact checking, but --require-production must reject them.
    dev_pdf = {
        'schema':'certloom-development-renderer-reference','version':1,
        'renderer_id':'development-reference-pdf','renderer_version':'1',
        'note':'Development oracle only; uses source-side reference tooling and is not a WordPress production adapter.'
    }
    dev_png = {
        'schema':'certloom-development-renderer-reference','version':1,
        'renderer_id':'development-reference-png','renderer_version':'1',
        'note':'Development oracle only; uses source-side reference tooling and is not a WordPress production adapter.'
    }
    write_json(out / 'candidates' / 'pdf.json', dev_pdf)
    write_json(out / 'candidates' / 'png.json', dev_png)
    (out / 'candidates' / 'pdf-source.txt').write_text('development reference PDF oracle; not a production renderer\n', encoding='utf-8')
    (out / 'candidates' / 'png-source.txt').write_text('development reference PNG oracle; not a production renderer\n', encoding='utf-8')
    (out / 'candidates' / 'pdf-lock.txt').write_text('development-reference\n', encoding='utf-8')
    (out / 'candidates' / 'png-lock.txt').write_text('development-reference\n', encoding='utf-8')

    runs = {}
    for run in ('a','b'):
        fixtures = {}
        for name in FIXTURES:
            fixtures[name] = {'render_plan_sha256': plan['fixtures'][name]['render_plan_sha256']}
            for fmt in ('pdf','png'):
                src = REF / name / f'reference.{fmt}'
                rel = Path('artifacts') / run / f'{name}.{fmt}'
                dst = out / rel
                shutil.copy2(src, dst)
                fixtures[name][fmt] = {'path': rel.as_posix(), 'sha256': sha(dst), 'receipt': None}
        runs[run] = {'fixtures': fixtures}

    manifest = {
        'schema':'certloom-renderer-acceptance-bundle','version':1,
        'qualification':'development-reference',
        'plugin_version':'1.6.20',
        'fixture_manifest': {'path':'manifests/golden.json','sha256':sha(out/'manifests'/'golden.json')},
        'render_plan_manifest': {'path':'manifests/render-plan.json','sha256':sha(out/'manifests'/'render-plan.json')},
        'raster_dpi':96,
        'renderers': {
            'pdf': {'id':'development-reference-pdf','version':'1','context_hash':'0'*64,'candidate_manifest':{'path':'candidates/pdf.json','sha256':sha(out/'candidates'/'pdf.json')},'source_archive':{'path':'candidates/pdf-source.txt','sha256':sha(out/'candidates'/'pdf-source.txt')},'dependency_lock':{'path':'candidates/pdf-lock.txt','sha256':sha(out/'candidates'/'pdf-lock.txt')}},
            'png': {'id':'development-reference-png','version':'1','context_hash':'1'*64,'candidate_manifest':{'path':'candidates/png.json','sha256':sha(out/'candidates'/'png.json')},'source_archive':{'path':'candidates/png-source.txt','sha256':sha(out/'candidates'/'png-source.txt')},'dependency_lock':{'path':'candidates/png-lock.txt','sha256':sha(out/'candidates'/'png-lock.txt')}},
        },
        'runs': runs,
    }
    write_json(out / 'bundle.json', manifest)
    print(sha(out / 'bundle.json'))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

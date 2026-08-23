#!/usr/bin/env python3
"""Independently verify a Certloom PDF-04 artifact bundle.

The verifier distrusts the JSON booleans from a renderer harness. It re-opens
actual PDF/PNG bytes, binds them to the frozen fixture/render-plan manifests,
checks deterministic run A/B identity, page/raster geometry, QR decodability,
and PDF-to-PNG visual parity. Development-reference bundles can exercise the
verifier but cannot qualify for PDF-04 production acceptance.
"""
from __future__ import annotations
import argparse, hashlib, json, re, subprocess, tempfile, sys
from pathlib import Path
import cv2
import numpy as np
from PIL import Image

FIXTURES = ('english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr')
MM_TO_PT = 72.0 / 25.4


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def safe_file(root: Path, rel: str) -> Path:
    if not isinstance(rel, str) or not rel or rel.startswith(('/', '\\')) or '..' in Path(rel).parts:
        raise RuntimeError('bundle contains an unsafe relative path')
    path = (root / rel).resolve()
    if root != path and root not in path.parents:
        raise RuntimeError('bundle path escapes the bundle root')
    if not path.is_file():
        raise RuntimeError(f'bundle file is missing: {rel}')
    return path


def pdf_size(path: Path):
    out = subprocess.check_output(['pdfinfo', str(path)], text=True, stderr=subprocess.STDOUT)
    m = re.search(r'^Page size:\s+([0-9.]+) x ([0-9.]+) pts', out, re.M)
    if not m:
        raise RuntimeError(f'PDF page geometry unavailable: {path.name}')
    return float(m.group(1)), float(m.group(2))


def pdf_to_rgb(path: Path, dpi: int) -> np.ndarray:
    with tempfile.TemporaryDirectory(prefix='certloom-pdf04-') as td:
        base = Path(td) / 'page'
        subprocess.check_call(['pdftocairo','-png','-singlefile','-r',str(dpi),str(path),str(base)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return np.asarray(Image.open(str(base)+'.png').convert('RGB'), dtype=np.int16)


def check_shape(obj, keys, label):
    if not isinstance(obj, dict) or set(obj) != set(keys):
        raise RuntimeError(f'{label} has an invalid shape')


def capture_verifier_environment(output_path: Path | None = None):
    script = Path(__file__).with_name('capture-verifier-environment.py')
    proc = subprocess.run([sys.executable, str(script)], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if proc.returncode != 0:
        raise RuntimeError('verifier environment capture failed: ' + proc.stderr.strip())
    try:
        env = json.loads(proc.stdout)
    except Exception as error:
        raise RuntimeError('verifier environment capture returned invalid JSON') from error
    with tempfile.TemporaryDirectory(prefix='certloom-verifier-env-') as td:
        temp = Path(td) / 'environment.json'
        text = json.dumps(env, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
        temp.write_text(text, encoding='utf-8')
        validator = Path(__file__).with_name('validate-verifier-environment.php')
        checked = subprocess.run(['php', str(validator), str(temp)], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if checked.returncode != 0:
            raise RuntimeError('verifier environment failed Certloom policy validation: ' + checked.stderr.strip())
        try:
            normalized = json.loads(checked.stdout)
            digest = normalized['verifier_environment_sha256']
        except Exception as error:
            raise RuntimeError('verifier environment validator returned invalid output') from error
        if not re.match(r'^[0-9a-f]{64}$', str(digest)):
            raise RuntimeError('verifier environment canonical hash is invalid')
        if output_path is not None:
            output_path.parent.mkdir(parents=True, exist_ok=True)
            output_path.write_text(text, encoding='utf-8')
    return env, digest


def verify(bundle_dir: Path, require_production: bool, environment_report: Path | None = None):
    root = bundle_dir.resolve()
    verifier_environment, verifier_environment_hash = capture_verifier_environment(environment_report)
    bundle_path = safe_file(root, 'bundle.json')
    manifest = json.loads(bundle_path.read_text(encoding='utf-8'))
    check_shape(manifest, ('schema','version','qualification','plugin_version','fixture_manifest','render_plan_manifest','raster_dpi','renderers','runs'), 'bundle manifest')
    if manifest['schema'] != 'certloom-renderer-acceptance-bundle' or manifest['version'] != 1:
        raise RuntimeError('unsupported acceptance-bundle schema/version')
    qualification = manifest['qualification']
    if qualification not in ('development-reference','production-candidate'):
        raise RuntimeError('unsupported bundle qualification')
    if require_production and qualification != 'production-candidate':
        raise RuntimeError('development-reference bundles can never qualify for PDF-04')
    if not re.match(r'^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$', str(manifest['plugin_version'])):
        raise RuntimeError('invalid plugin version in bundle')
    dpi = manifest['raster_dpi']
    if not isinstance(dpi, int) or not (72 <= dpi <= 600):
        raise RuntimeError('raster DPI must be an integer between 72 and 600')

    for key in ('fixture_manifest','render_plan_manifest'):
        check_shape(manifest[key], ('path','sha256'), key)
        path = safe_file(root, manifest[key]['path'])
        if sha(path) != manifest[key]['sha256']:
            raise RuntimeError(f'{key} hash mismatch')
    golden_path = safe_file(root, manifest['fixture_manifest']['path'])
    plan_path = safe_file(root, manifest['render_plan_manifest']['path'])
    source_root = Path(__file__).resolve().parents[2]
    source_golden = source_root / 'tests' / 'fixtures' / 'rendering' / 'reference' / 'manifest.json'
    source_plan = source_root / 'tests' / 'fixtures' / 'rendering' / 'render-plan-manifest.json'
    if golden_path.read_bytes() != source_golden.read_bytes() or plan_path.read_bytes() != source_plan.read_bytes():
        raise RuntimeError('bundle fixture/render-plan manifests do not match the frozen source-tree acceptance state')
    golden = json.loads(golden_path.read_text(encoding='utf-8'))
    plan = json.loads(plan_path.read_text(encoding='utf-8'))
    if set(plan.get('fixtures', {})) != set(FIXTURES) or set(golden.get('fixtures', {})) != set(FIXTURES):
        raise RuntimeError('bundle manifests do not contain the six permanent fixtures')

    renderers = manifest['renderers']
    if not isinstance(renderers, dict) or set(renderers) != {'pdf','png'}:
        raise RuntimeError('bundle must identify PDF and PNG renderers')
    for fmt in ('pdf','png'):
        item = renderers[fmt]
        check_shape(item, ('id','version','context_hash','candidate_manifest','source_archive','dependency_lock'), f'{fmt} renderer')
        for bound_name in ('candidate_manifest','source_archive','dependency_lock'):
            check_shape(item[bound_name], ('path','sha256'), f'{fmt} {bound_name}')
            bound_path = safe_file(root, item[bound_name]['path'])
            if sha(bound_path) != item[bound_name]['sha256']:
                raise RuntimeError(f'{fmt} {bound_name} hash mismatch')
        candidate = safe_file(root, item['candidate_manifest']['path'])
        source_archive = safe_file(root, item['source_archive']['path'])
        dependency_lock = safe_file(root, item['dependency_lock']['path'])
        if qualification == 'production-candidate':
            validator = Path(__file__).with_name('validate-candidate.php')
            proc = subprocess.run(['php', str(validator), str(candidate)], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode != 0:
                raise RuntimeError(f'{fmt} production candidate manifest failed Certloom policy validation: {proc.stderr.strip()}')
            validated = json.loads(proc.stdout)
            if validated.get('candidate_manifest_sha256') != item['candidate_manifest']['sha256']:
                raise RuntimeError(f'{fmt} candidate manifest canonical hash does not match bundle provenance')
            if validated.get('source_sha256') != sha(source_archive) or validated.get('dependency_lock_sha256') != sha(dependency_lock):
                raise RuntimeError(f'{fmt} candidate source/dependency-lock bytes do not match normalized provenance')
            if validated.get('renderer_id') != item['id'] or validated.get('renderer_version') != item['version'] or fmt not in validated.get('formats', []):
                raise RuntimeError(f'{fmt} bundle identity does not match normalized candidate provenance')
        if not re.match(r'^[a-z][a-z0-9._-]{0,63}$', str(item['id'])) or not re.match(r'^[0-9a-f]{64}$', str(item['context_hash'])):
            raise RuntimeError(f'{fmt} renderer identity is invalid')

    runs = manifest['runs']
    if not isinstance(runs, dict) or set(runs) != {'a','b'}:
        raise RuntimeError('bundle must contain deterministic runs a and b')

    facts = {}
    for run in ('a','b'):
        data = runs[run]
        check_shape(data, ('fixtures',), f'run {run}')
        if set(data['fixtures']) != set(FIXTURES):
            raise RuntimeError(f'run {run} does not contain the six permanent fixtures')
        facts[run] = {}
        for name in FIXTURES:
            item = data['fixtures'][name]
            check_shape(item, ('render_plan_sha256','pdf','png'), f'{run}/{name}')
            expected_plan = plan['fixtures'][name]['render_plan_sha256']
            if item['render_plan_sha256'] != expected_plan:
                raise RuntimeError(f'{run}/{name} render-plan hash does not match the frozen plan manifest')
            facts[run][name] = {}
            for fmt in ('pdf','png'):
                check_shape(item[fmt], ('path','sha256','receipt'), f'{run}/{name}/{fmt}')
                path = safe_file(root, item[fmt]['path'])
                digest = sha(path)
                if digest != item[fmt]['sha256']:
                    raise RuntimeError(f'{run}/{name}/{fmt} artifact hash mismatch')
                receipt_info = item[fmt]['receipt']
                if qualification == 'production-candidate':
                    if not isinstance(receipt_info, dict):
                        raise RuntimeError(f'{run}/{name}/{fmt} production artifact is missing ArtifactReceipt v4')
                    check_shape(receipt_info, ('path','sha256'), f'{run}/{name}/{fmt}/receipt')
                    receipt_path = safe_file(root, receipt_info['path'])
                    if sha(receipt_path) != receipt_info['sha256']:
                        raise RuntimeError(f'{run}/{name}/{fmt} receipt file hash mismatch')
                    plan_item = plan['fixtures'][name]
                    validator = Path(__file__).with_name('verify-receipt.php')
                    renderer_item = renderers[fmt]
                    proc = subprocess.run([
                        'php', str(validator), str(receipt_path), str(path), fmt,
                        str(renderer_item['id']), str(renderer_item['version']), str(renderer_item['context_hash']),
                        str(plan_item['layout_sha256']), str(plan_item['render_request_sha256']), str(plan_item['render_plan_sha256'])
                    ], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    if proc.returncode != 0:
                        raise RuntimeError(f'{run}/{name}/{fmt} ArtifactReceipt verification failed: {proc.stderr.strip()}')
                elif receipt_info is not None:
                    raise RuntimeError(f'{run}/{name}/{fmt} development-reference artifact must not carry a production receipt')
                facts[run][name][fmt] = (path, digest)

    # Deterministic regeneration is independently proven by exact run A/B bytes.
    for name in FIXTURES:
        for fmt in ('pdf','png'):
            pa, ha = facts['a'][name][fmt]
            pb, hb = facts['b'][name][fmt]
            if ha != hb or pa.read_bytes() != pb.read_bytes():
                raise RuntimeError(f'{name}/{fmt} is not byte-identical across deterministic runs')

    parity = {}
    for name in FIXTURES:
        page = plan['fixtures'][name]['page']
        w_mm, h_mm = float(page['width_mm']), float(page['height_mm'])
        pdf_path = facts['a'][name]['pdf'][0]
        png_path = facts['a'][name]['png'][0]
        pw, ph = pdf_size(pdf_path)
        if abs(pw - w_mm * MM_TO_PT) >= 0.03 or abs(ph - h_mm * MM_TO_PT) >= 0.03:
            raise RuntimeError(f'{name} PDF physical page geometry mismatch')
        with Image.open(png_path) as im:
            expected = (round(w_mm * dpi / 25.4), round(h_mm * dpi / 25.4))
            if im.size != expected:
                raise RuntimeError(f'{name} PNG raster geometry mismatch: {im.size} != {expected}')
            png_rgb = np.asarray(im.convert('RGB'), dtype=np.int16)
        pdf_rgb = pdf_to_rgb(pdf_path, dpi)
        if pdf_rgb.shape != png_rgb.shape:
            raise RuntimeError(f'{name} PDF rasterization and PNG geometry differ')
        diff = np.abs(pdf_rgb - png_rgb)
        mae = float(diff.mean())
        changed = float((diff > 10).mean())
        if mae > 1.0 or changed > 0.02:
            raise RuntimeError(f'{name} PDF/PNG visual parity failed (mae={mae:.4f}, changed={changed:.4%})')
        parity[name] = {'mean_abs_error': round(mae,6), 'fraction_channels_delta_gt_10': round(changed,8)}

    # Decode the QR from the actual candidate PNG page.
    qr_layout = json.loads((Path(__file__).resolve().parents[1] / 'fixtures' / 'rendering' / 'qr.json').read_text(encoding='utf-8'))
    qr = qr_layout['elements'][0]
    image = cv2.imread(str(facts['a']['qr']['png'][0]))
    pad = 5.0
    mm_to_px = dpi / 25.4
    x = max(0, round((float(qr['x_mm']) - pad) * mm_to_px)); y = max(0, round((float(qr['y_mm']) - pad) * mm_to_px))
    x2 = min(image.shape[1], round((float(qr['x_mm']) + float(qr['width_mm']) + pad) * mm_to_px)); y2 = min(image.shape[0], round((float(qr['y_mm']) + float(qr['height_mm']) + pad) * mm_to_px))
    value, points, _ = cv2.QRCodeDetector().detectAndDecode(image[y:y2, x:x2])
    expected_url = plan['verification_url']
    if points is None or value != expected_url:
        raise RuntimeError('QR fixture does not decode from the actual PNG artifact to the frozen verification URL')

    report = {
        'schema':'certloom-renderer-bundle-verification','version':2,
        'verified':True,
        'qualification':qualification,
        'qualifies_pdf04': qualification == 'production-candidate',
        'bundle_manifest_sha256':sha(bundle_path),
        'fixture_manifest_sha256':sha(golden_path),
        'render_plan_manifest_sha256':sha(plan_path),
        'verifier_environment_sha256':verifier_environment_hash,
        'candidate_provenance_rechecked': qualification == 'production-candidate',
        'artifact_receipts_rechecked': qualification == 'production-candidate',
        'deterministic_runs':True,
        'pdf_geometry':True,
        'png_geometry':True,
        'qr_raster_decode':True,
        'pdf_png_visual_parity':True,
        'renderers': {fmt: {'id': renderers[fmt]['id'], 'version': renderers[fmt]['version'], 'candidate_manifest_sha256': renderers[fmt]['candidate_manifest']['sha256']} for fmt in ('pdf','png')},
        'fixtures': {name: {'render_plan_sha256': plan['fixtures'][name]['render_plan_sha256'], 'pdf_sha256': facts['a'][name]['pdf'][1], 'png_sha256': facts['a'][name]['png'][1]} for name in FIXTURES},
        'parity':parity,
    }
    return manifest, report


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('bundle_dir')
    ap.add_argument('--require-production', action='store_true')
    ap.add_argument('--report')
    ap.add_argument('--environment-report')
    args = ap.parse_args()
    try:
        manifest, report = verify(Path(args.bundle_dir), args.require_production, Path(args.environment_report) if args.environment_report else None)
    except Exception as error:
        print(json.dumps({'schema':'certloom-renderer-bundle-verification','version':2,'verified':False,'error':str(error)}, sort_keys=True, separators=(',', ':')), file=sys.stderr)
        return 1
    text = json.dumps(report, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
    if args.report:
        Path(args.report).write_text(text, encoding='utf-8')
    print(text, end='')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

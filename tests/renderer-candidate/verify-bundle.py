#!/usr/bin/env python3
"""Independently verify a Certloom PDF-04 artifact bundle.

The verifier distrusts renderer-harness booleans. It re-opens actual PDF/PNG/JPEG
bytes, binds them to the frozen fixture/render-plan manifests, checks deterministic
run A/B identity, physical/raster geometry, PNG/JPEG QR decodability, cross-format
parity, and structural parity against Certloom's frozen golden references.
Development-reference bundles can exercise the verifier but cannot qualify for
PDF-04 production acceptance.
"""
from __future__ import annotations
import argparse, hashlib, json, re, subprocess, tempfile, sys
from pathlib import Path
import cv2
import numpy as np
from PIL import Image

FIXTURES = ('english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr')
FORMATS = ('pdf','png','jpeg')
MM_TO_PT = 72.0 / 25.4
CROSS_FORMAT_MAX_MAE = 1.0
CROSS_FORMAT_MAX_CHANGED = 0.02
GOLDEN_FOREGROUND_THRESHOLD = 240
GOLDEN_TOLERANCE_RADIUS_PX = 2
GOLDEN_MIN_PRECISION = 0.92
GOLDEN_MIN_RECALL = 0.92


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
        with Image.open(str(base)+'.png') as image:
            return np.asarray(image.convert('RGB'), dtype=np.int16)


def image_to_rgb(path: Path, expected_format: str) -> np.ndarray:
    with Image.open(path) as image:
        if image.format != expected_format:
            raise RuntimeError(f'{path.name} is not a real {expected_format} image')
        return np.asarray(image.convert('RGB'), dtype=np.int16)


def check_shape(obj, keys, label):
    if not isinstance(obj, dict) or set(obj) != set(keys):
        raise RuntimeError(f'{label} has an invalid shape')


def cross_format_metric(a: np.ndarray, b: np.ndarray, label: str) -> dict:
    if a.shape != b.shape:
        raise RuntimeError(f'{label} raster geometry differs')
    diff = np.abs(a - b)
    mae = float(diff.mean())
    changed = float((diff > 10).mean())
    if mae > CROSS_FORMAT_MAX_MAE or changed > CROSS_FORMAT_MAX_CHANGED:
        raise RuntimeError(f'{label} visual parity failed (mae={mae:.4f}, changed={changed:.4%})')
    return {'mean_abs_error': round(mae,6), 'fraction_channels_delta_gt_10': round(changed,8)}


def foreground_mask(rgb: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(rgb.astype(np.uint8), cv2.COLOR_RGB2GRAY)
    return (gray < GOLDEN_FOREGROUND_THRESHOLD).astype(np.uint8)


def golden_structural_metric(candidate: np.ndarray, golden: np.ndarray, label: str) -> dict:
    if candidate.shape != golden.shape:
        raise RuntimeError(f'{label} does not match frozen golden raster geometry')
    candidate_mask = foreground_mask(candidate)
    golden_mask = foreground_mask(golden)
    candidate_count = int(candidate_mask.sum())
    golden_count = int(golden_mask.sum())
    if candidate_count < 1 or golden_count < 1:
        raise RuntimeError(f'{label} has no measurable foreground for golden comparison')
    size = GOLDEN_TOLERANCE_RADIUS_PX * 2 + 1
    kernel = np.ones((size,size), dtype=np.uint8)
    golden_dilated = cv2.dilate(golden_mask, kernel, iterations=1)
    candidate_dilated = cv2.dilate(candidate_mask, kernel, iterations=1)
    precision = float(np.logical_and(candidate_mask > 0, golden_dilated > 0).sum()) / candidate_count
    recall = float(np.logical_and(golden_mask > 0, candidate_dilated > 0).sum()) / golden_count
    if precision < GOLDEN_MIN_PRECISION or recall < GOLDEN_MIN_RECALL:
        raise RuntimeError(f'{label} fails frozen golden structural parity (precision={precision:.4f}, recall={recall:.4f})')
    return {'foreground_precision': round(precision,8), 'foreground_recall': round(recall,8)}


def decode_qr(rgb: np.ndarray, qr: dict, dpi: int) -> str:
    bgr = cv2.cvtColor(rgb.astype(np.uint8), cv2.COLOR_RGB2BGR)
    pad = 5.0
    mm_to_px = dpi / 25.4
    x = max(0, round((float(qr['x_mm']) - pad) * mm_to_px)); y = max(0, round((float(qr['y_mm']) - pad) * mm_to_px))
    x2 = min(bgr.shape[1], round((float(qr['x_mm']) + float(qr['width_mm']) + pad) * mm_to_px)); y2 = min(bgr.shape[0], round((float(qr['y_mm']) + float(qr['height_mm']) + pad) * mm_to_px))
    value, points, _ = cv2.QRCodeDetector().detectAndDecode(bgr[y:y2, x:x2])
    return value if points is not None else ''


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
    _verifier_environment, verifier_environment_hash = capture_verifier_environment(environment_report)
    bundle_path = safe_file(root, 'bundle.json')
    manifest = json.loads(bundle_path.read_text(encoding='utf-8'))
    check_shape(manifest, ('schema','version','qualification','plugin_version','fixture_manifest','render_plan_manifest','raster_dpi','renderers','runs'), 'bundle manifest')
    if manifest['schema'] != 'certloom-renderer-acceptance-bundle' or manifest['version'] != 2:
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
    source_reference = source_root / 'tests' / 'fixtures' / 'rendering' / 'reference'
    source_golden = source_reference / 'manifest.json'
    source_plan = source_root / 'tests' / 'fixtures' / 'rendering' / 'render-plan-manifest.json'
    if golden_path.read_bytes() != source_golden.read_bytes() or plan_path.read_bytes() != source_plan.read_bytes():
        raise RuntimeError('bundle fixture/render-plan manifests do not match the frozen source-tree acceptance state')
    golden = json.loads(golden_path.read_text(encoding='utf-8'))
    plan = json.loads(plan_path.read_text(encoding='utf-8'))
    if golden.get('version') != 3:
        raise RuntimeError('frozen golden manifest does not contain the PDF/PNG/JPEG v3 contract')
    if set(plan.get('fixtures', {})) != set(FIXTURES) or set(golden.get('fixtures', {})) != set(FIXTURES):
        raise RuntimeError('bundle manifests do not contain the six permanent fixtures')

    golden_rgb = {}
    for name in FIXTURES:
        facts = golden['fixtures'][name]
        png = source_reference / name / 'reference.png'
        jpeg = source_reference / name / 'reference.jpeg'
        if sha(png) != facts.get('png_sha256') or sha(jpeg) != facts.get('jpeg_sha256'):
            raise RuntimeError(f'{name} frozen golden raster bytes do not match the source manifest')
        golden_rgb[name] = {
            'png': image_to_rgb(png, 'PNG'),
            'jpeg': image_to_rgb(jpeg, 'JPEG'),
        }

    renderers = manifest['renderers']
    if not isinstance(renderers, dict) or set(renderers) != set(FORMATS):
        raise RuntimeError('bundle must identify PDF, PNG and JPEG renderers')
    for fmt in FORMATS:
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
            check_shape(item, ('render_plan_sha256','pdf','png','jpeg'), f'{run}/{name}')
            expected_plan = plan['fixtures'][name]['render_plan_sha256']
            if item['render_plan_sha256'] != expected_plan:
                raise RuntimeError(f'{run}/{name} render-plan hash does not match the frozen plan manifest')
            facts[run][name] = {}
            for fmt in FORMATS:
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

    for name in FIXTURES:
        for fmt in FORMATS:
            pa, ha = facts['a'][name][fmt]
            pb, hb = facts['b'][name][fmt]
            if ha != hb or pa.read_bytes() != pb.read_bytes():
                raise RuntimeError(f'{name}/{fmt} is not byte-identical across deterministic runs')

    parity = {}
    golden_parity = {}
    candidate_rgb = {}
    for name in FIXTURES:
        page = plan['fixtures'][name]['page']
        w_mm, h_mm = float(page['width_mm']), float(page['height_mm'])
        expected = (round(w_mm * dpi / 25.4), round(h_mm * dpi / 25.4))
        pdf_path = facts['a'][name]['pdf'][0]
        png_path = facts['a'][name]['png'][0]
        jpeg_path = facts['a'][name]['jpeg'][0]
        pw, ph = pdf_size(pdf_path)
        if abs(pw - w_mm * MM_TO_PT) >= 0.03 or abs(ph - h_mm * MM_TO_PT) >= 0.03:
            raise RuntimeError(f'{name} PDF physical page geometry mismatch')
        png_rgb = image_to_rgb(png_path, 'PNG')
        jpeg_rgb = image_to_rgb(jpeg_path, 'JPEG')
        if (png_rgb.shape[1], png_rgb.shape[0]) != expected:
            raise RuntimeError(f'{name} PNG raster geometry mismatch: {(png_rgb.shape[1],png_rgb.shape[0])} != {expected}')
        if (jpeg_rgb.shape[1], jpeg_rgb.shape[0]) != expected:
            raise RuntimeError(f'{name} JPEG raster geometry mismatch: {(jpeg_rgb.shape[1],jpeg_rgb.shape[0])} != {expected}')
        pdf_rgb = pdf_to_rgb(pdf_path, dpi)
        candidate_rgb[name] = {'pdf':pdf_rgb,'png':png_rgb,'jpeg':jpeg_rgb}
        parity[name] = {
            'pdf_png': cross_format_metric(pdf_rgb, png_rgb, f'{name} PDF/PNG'),
            'png_jpeg': cross_format_metric(png_rgb, jpeg_rgb, f'{name} PNG/JPEG'),
        }
        golden_parity[name] = {
            'pdf': golden_structural_metric(pdf_rgb, golden_rgb[name]['png'], f'{name} PDF/golden'),
            'png': golden_structural_metric(png_rgb, golden_rgb[name]['png'], f'{name} PNG/golden'),
            'jpeg': golden_structural_metric(jpeg_rgb, golden_rgb[name]['jpeg'], f'{name} JPEG/golden'),
        }

    qr_layout = json.loads((Path(__file__).resolve().parents[1] / 'fixtures' / 'rendering' / 'qr.json').read_text(encoding='utf-8'))
    qr = qr_layout['elements'][0]
    expected_url = plan['verification_url']
    for fmt in ('png','jpeg'):
        value = decode_qr(candidate_rgb['qr'][fmt], qr, dpi)
        if value != expected_url:
            raise RuntimeError(f'QR fixture does not decode from the actual {fmt.upper()} artifact to the frozen verification URL')

    report = {
        'schema':'certloom-renderer-bundle-verification','version':3,
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
        'jpeg_geometry':True,
        'qr_png_decode':True,
        'qr_jpeg_decode':True,
        'pdf_png_visual_parity':True,
        'png_jpeg_visual_parity':True,
        'golden_visual_parity':True,
        'renderers': {fmt: {'id': renderers[fmt]['id'], 'version': renderers[fmt]['version'], 'candidate_manifest_sha256': renderers[fmt]['candidate_manifest']['sha256']} for fmt in FORMATS},
        'fixtures': {name: {'render_plan_sha256': plan['fixtures'][name]['render_plan_sha256'], 'pdf_sha256': facts['a'][name]['pdf'][1], 'png_sha256': facts['a'][name]['png'][1], 'jpeg_sha256': facts['a'][name]['jpeg'][1]} for name in FIXTURES},
        'parity':parity,
        'golden_parity':golden_parity,
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
        _manifest, report = verify(Path(args.bundle_dir), args.require_production, Path(args.environment_report) if args.environment_report else None)
    except Exception as error:
        print(json.dumps({'schema':'certloom-renderer-bundle-verification','version':3,'verified':False,'error':str(error)}, sort_keys=True, separators=(',', ':')), file=sys.stderr)
        return 1
    text = json.dumps(report, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
    if args.report:
        Path(args.report).write_text(text, encoding='utf-8')
    print(text, end='')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

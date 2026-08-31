#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP="${TMPDIR:-/tmp}/certloom-renderer-bundle-$RANDOM-$$"
trap 'rm -rf "$TMP"' EXIT

python3 "$ROOT/tests/renderer-candidate/build-reference-bundle.py" "$TMP" >/dev/null
python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$TMP" --report "$TMP/report.json" --environment-report "$TMP/verifier-environment.json" >/dev/null
php "$ROOT/tests/renderer-candidate/validate-verification-report.php" "$TMP/report.json" >"$TMP/report-validation.json"
php "$ROOT/tests/renderer-candidate/validate-verifier-environment.php" "$TMP/verifier-environment.json" >"$TMP/environment-validation.json"
python3 - "$TMP/report.json" <<'PY'
import json,sys
r=json.load(open(sys.argv[1]))
assert r['version']==5 and r['verified'] is True
assert r['qualification']=='development-reference'
assert r['qualifies_pdf04'] is False
assert r['deterministic_runs'] and r['pdf_geometry'] and r['png_geometry'] and r['jpeg_geometry'] and r['qr_pdf_decode'] and r['qr_png_decode'] and r['qr_jpeg_decode'] and r['pdf_png_visual_parity'] and r['png_jpeg_visual_parity'] and r['golden_visual_parity'] and r['primitive_capability_coverage']
assert len(r['verifier_environment_sha256'])==64 and len(r['fixtures'])==7
PY
python3 - "$TMP/report-validation.json" "$TMP/environment-validation.json" <<'PY'
import json,sys
r=json.load(open(sys.argv[1])); e=json.load(open(sys.argv[2]))
assert r['qualification']=='development-reference' and r['qualifies_pdf04'] is False
assert r['verifier_environment_sha256']==e['verifier_environment_sha256']
PY

if bash "$ROOT/tests/renderer-candidate/verify-production-bundle.sh" "$TMP" "$TMP/production-proof" >/dev/null 2>&1; then
  echo "development reference unexpectedly passed the production verification entrypoint" >&2; exit 5
fi

if python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$TMP" --require-production >/dev/null 2>&1; then
  echo "development reference unexpectedly qualified as production" >&2; exit 2
fi

cp "$TMP/manifests/render-plan.json" "$TMP/manifests/render-plan.good"
printf '\n' >> "$TMP/manifests/render-plan.json"
if python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$TMP" >/dev/null 2>&1; then
  echo "tampered frozen render-plan manifest unexpectedly verified" >&2; exit 3
fi
mv "$TMP/manifests/render-plan.good" "$TMP/manifests/render-plan.json"

printf 'tamper' >> "$TMP/artifacts/b/english.png"
python3 - "$TMP/bundle.json" "$TMP/artifacts/b/english.png" <<'PY'
import hashlib,json,sys
p=sys.argv[2]
d=json.load(open(sys.argv[1]))
d['runs']['b']['fixtures']['english']['png']['sha256']=hashlib.sha256(open(p,'rb').read()).hexdigest()
open(sys.argv[1],'w').write(json.dumps(d,sort_keys=True,separators=(',',':'))+'\n')
PY
if python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$TMP" >/dev/null 2>&1; then
  echo "non-deterministic run unexpectedly verified" >&2; exit 4
fi

echo "PASS  independent renderer-bundle verifier accepts the frozen oracle but rejects production escalation, manifest tampering, and run drift"
echo
printf '5 scenarios, 0 failures\n'

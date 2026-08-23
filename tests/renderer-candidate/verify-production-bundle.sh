#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 2 ]]; then
  echo "usage: verify-production-bundle.sh BUNDLE_DIR OUTPUT_DIR" >&2
  exit 2
fi
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BUNDLE="$(cd "$1" && pwd)"
OUT="$2"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
REPORT="$OUT/verification-report.json"
ENV="$OUT/verifier-environment.json"

python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$BUNDLE" --require-production --report "$REPORT" --environment-report "$ENV" >/dev/null
REPORT_VALID="$(php "$ROOT/tests/renderer-candidate/validate-verification-report.php" "$REPORT")"
ENV_VALID="$(php "$ROOT/tests/renderer-candidate/validate-verifier-environment.php" "$ENV")"
python3 - "$BUNDLE/bundle.json" "$REPORT_VALID" "$ENV_VALID" "$OUT/verification-index.json" <<'PY'
import hashlib,json,sys
bundle,report_raw,env_raw,out=sys.argv[1:]
report=json.loads(report_raw); env=json.loads(env_raw)
if report['qualification']!='production-candidate' or report['qualifies_pdf04'] is not True:
    raise SystemExit('independent report did not qualify the production candidate')
if report['verifier_environment_sha256'] != env['verifier_environment_sha256']:
    raise SystemExit('report/environment canonical hashes do not agree')
h=hashlib.sha256(open(bundle,'rb').read()).hexdigest()
value={
 'schema':'certloom-renderer-verification-index','version':1,
 'bundle_manifest_sha256':h,
 'verification_report_sha256':report['verification_report_sha256'],
 'verifier_environment_sha256':env['verifier_environment_sha256'],
}
open(out,'w',encoding='utf-8').write(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n')
print(json.dumps(value,sort_keys=True,separators=(',',':')))
PY

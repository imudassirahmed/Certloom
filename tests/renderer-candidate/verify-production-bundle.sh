#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "usage: verify-production-bundle.sh BUNDLE_DIR OUTPUT_DIR" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
BUNDLE_INPUT="$1"
OUTPUT_INPUT="$2"

# Resolve and prepare paths before any verification evidence is written. The
# final output must be a new path outside the Certloom source tree; all work is
# performed in a private sibling staging directory so failed verification never
# leaves a partial result that can be mistaken for completed PDF-04 evidence.
mapfile -t CERTLOOM_VERIFY_PATHS < <(python3 - "$ROOT" "$BUNDLE_INPUT" "$OUTPUT_INPUT" <<'PY'
import os,re,sys,tempfile
root,bundle_arg,out_arg=sys.argv[1:]
root=os.path.realpath(root)

def fail(message):
    raise SystemExit(message)

def has_control(value):
    return any(ord(ch)<32 or ord(ch)==127 for ch in value)

def inside(path,base):
    try:
        return os.path.commonpath((os.path.realpath(path),base)) == base
    except ValueError:
        return False

if not bundle_arg or has_control(bundle_arg) or os.path.islink(bundle_arg):
    fail('production verification bundle path is invalid or symbolic')
bundle=os.path.realpath(bundle_arg)
if not os.path.isdir(bundle):
    fail('production verification bundle must be an existing directory')

if not out_arg or has_control(out_arg) or os.path.islink(out_arg):
    fail('production verification output path is invalid or symbolic')
for segment in re.split(r'[\\/]+',out_arg):
    if segment in ('.','..'):
        fail('production verification output path may not contain dot segments')
if os.path.lexists(out_arg):
    fail('production verification output must be a new path')

abs_out=os.path.abspath(out_arg)
parent=os.path.dirname(abs_out)
base=os.path.basename(abs_out)
if not base:
    fail('production verification output path is invalid')

ancestor=parent
while not os.path.lexists(ancestor):
    next_ancestor=os.path.dirname(ancestor)
    if next_ancestor == ancestor:
        break
    ancestor=next_ancestor
if os.path.islink(ancestor):
    fail('production verification output ancestor may not be symbolic')
real_ancestor=os.path.realpath(ancestor)
if not os.path.isdir(real_ancestor):
    fail('production verification output ancestor is invalid')
if inside(real_ancestor,root):
    fail('production verification output must never be written inside the Certloom source tree')

os.makedirs(parent,mode=0o700,exist_ok=True)
# Reject symbolic components after any missing parents have been created.
probe=os.path.abspath(os.sep)
parts=os.path.abspath(parent).split(os.sep)
for part in parts:
    if not part:
        continue
    probe=os.path.join(probe,part)
    if os.path.islink(probe):
        fail('production verification output parent may not contain symbolic components')
real_parent=os.path.realpath(parent)
if not os.path.isdir(real_parent) or not os.access(real_parent,os.W_OK):
    fail('production verification output parent is invalid or not writable')
if inside(real_parent,root):
    fail('production verification output must never be written inside the Certloom source tree')

target=os.path.join(real_parent,base)
if os.path.lexists(target):
    fail('production verification output appeared before staging could begin')
staging=tempfile.mkdtemp(prefix='.certloom-verification-',dir=real_parent)
os.chmod(staging,0o700)
for value in (bundle,target,staging):
    if has_control(value):
        fail('resolved production verification path contains control characters')
    print(value)
PY
)

if [[ ${#CERTLOOM_VERIFY_PATHS[@]} -ne 3 ]]; then
  echo "could not prepare production verification staging" >&2
  exit 3
fi

BUNDLE="${CERTLOOM_VERIFY_PATHS[0]}"
TARGET="${CERTLOOM_VERIFY_PATHS[1]}"
STAGING="${CERTLOOM_VERIFY_PATHS[2]}"
REPORT="$STAGING/verification-report.json"
ENV="$STAGING/verifier-environment.json"
INDEX="$STAGING/verification-index.json"
PUBLISHED=0

cleanup() {
  if [[ "$PUBLISHED" -eq 0 && -n "${STAGING:-}" && -d "$STAGING" && ! -L "$STAGING" ]]; then
    rm -rf -- "$STAGING"
  fi
}
trap cleanup EXIT INT TERM HUP

python3 "$ROOT/tests/renderer-candidate/verify-bundle.py" "$BUNDLE" --require-production --report "$REPORT" --environment-report "$ENV" >/dev/null
REPORT_VALID="$(php "$ROOT/tests/renderer-candidate/validate-verification-report.php" "$REPORT")"
ENV_VALID="$(php "$ROOT/tests/renderer-candidate/validate-verifier-environment.php" "$ENV")"

INDEX_JSON="$(python3 - "$BUNDLE/bundle.json" "$REPORT" "$ENV" "$REPORT_VALID" "$ENV_VALID" "$INDEX" <<'PY'
import hashlib,json,os,sys
bundle,report_path,environment_path,report_validation_raw,environment_validation_raw,out=sys.argv[1:]
report=json.load(open(report_path,encoding='utf-8'))
environment=json.load(open(environment_path,encoding='utf-8'))
bundle_manifest=json.load(open(bundle,encoding='utf-8'))
report_validation=json.loads(report_validation_raw)
environment_validation=json.loads(environment_validation_raw)
if report_validation['qualification']!='production-candidate' or report_validation['qualifies_pdf04'] is not True:
    raise SystemExit('independent report did not qualify the production candidate')
if report.get('qualification')!='production-candidate' or report.get('qualifies_pdf04') is not True:
    raise SystemExit('normalized report file did not qualify the production candidate')
if report.get('verifier_environment_sha256') != environment_validation['verifier_environment_sha256']:
    raise SystemExit('report/environment canonical hashes do not agree')
if environment.get('plugin_version') != bundle_manifest.get('plugin_version'):
    raise SystemExit('bundle/environment plugin versions do not agree')
h=hashlib.sha256(open(bundle,'rb').read()).hexdigest()
if report.get('bundle_manifest_sha256') != h:
    raise SystemExit('report/bundle canonical hashes do not agree')
value={
 'schema':'certloom-renderer-verification-index','version':1,
 'bundle_manifest_sha256':h,
 'verification_report_sha256':report_validation['verification_report_sha256'],
 'verifier_environment_sha256':environment_validation['verifier_environment_sha256'],
}
text=json.dumps(value,sort_keys=True,separators=(',',':'))+'\n'
with open(out,'x',encoding='utf-8') as handle:
    handle.write(text)
    handle.flush(); os.fsync(handle.fileno())
print(text,end='')
PY
)"

# Before publication require exactly the normalized three-file evidence set,
# regular non-symbolic files, and durable file/directory contents.
python3 - "$STAGING" "$TARGET" "$ROOT" <<'PY'
import os,sys
staging,target,root=sys.argv[1:]
expected={'verification-report.json','verifier-environment.json','verification-index.json'}
if os.path.islink(staging) or not os.path.isdir(staging):
    raise SystemExit('production verification staging directory is invalid')
if set(os.listdir(staging)) != expected:
    raise SystemExit('production verification staging file set is incomplete or unexpected')
for name in expected:
    path=os.path.join(staging,name)
    if os.path.islink(path) or not os.path.isfile(path) or os.path.getsize(path) < 2:
        raise SystemExit('production verification staging contains an invalid evidence file')
    fd=os.open(path,os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)
fd=os.open(staging,os.O_RDONLY)
try: os.fsync(fd)
finally: os.close(fd)
if os.path.lexists(target):
    raise SystemExit('production verification output appeared before atomic publication')
root=os.path.realpath(root); parent=os.path.realpath(os.path.dirname(target))
if os.path.commonpath((parent,root)) == root:
    raise SystemExit('production verification output must never be written inside the Certloom source tree')
PY

# Staging and target are siblings, so rename is a same-filesystem directory
# publication. The target was required absent before staging and immediately
# before this operation; any failure leaves cleanup responsible for staging.
if [[ -e "$TARGET" || -L "$TARGET" ]]; then
  echo "production verification output appeared before atomic publication" >&2
  exit 4
fi
mv -- "$STAGING" "$TARGET"
PUBLISHED=1
trap - EXIT INT TERM HUP

# Re-resolve and assert the published result before reporting success.
python3 - "$TARGET" "$ROOT" <<'PY'
import os,sys
target,root=sys.argv[1:]
if os.path.islink(target) or not os.path.isdir(target):
    raise SystemExit('published production verification directory is invalid')
real=os.path.realpath(target); root=os.path.realpath(root)
if os.path.commonpath((real,root)) == root:
    raise SystemExit('published production verification unexpectedly resolved inside the Certloom source tree')
expected={'verification-report.json','verifier-environment.json','verification-index.json'}
if set(os.listdir(real)) != expected:
    raise SystemExit('published production verification file set is invalid')
PY

printf '%s\n' "$INDEX_JSON"

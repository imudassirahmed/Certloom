#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RAW_OUT="${1:-}"; WP_BIN="${CERTLOOM_WP_BIN:-wp}"
if [[ -z "$RAW_OUT" ]]; then echo "Usage: run-base-gates.sh <external-output-dir>" >&2; exit 2; fi
if [[ "$RAW_OUT" =~ (^|/)\.\.?(/|$) ]]; then echo "Base-gate output path must not contain . or .. components." >&2; exit 2; fi
parent_raw="$(dirname "$RAW_OUT")"; mkdir -p "$parent_raw"; parent="$(realpath -e -- "$parent_raw")"; OUT="$parent/$(basename "$RAW_OUT")"
case "$OUT" in "$ROOT"|"$ROOT"/*) echo "Base-gate evidence must remain outside the Certloom source tree." >&2; exit 2;; esac
if [[ -e "$OUT" || -L "$OUT" ]]; then echo "Base-gate output path must be new." >&2; exit 2; fi
if ! command -v "$WP_BIN" >/dev/null 2>&1; then echo "WP-CLI is required for the real database gate." >&2; exit 2; fi
stage="$(mktemp -d "$parent/.certloom-db-base-gates.XXXXXX")"; cleanup(){ rm -rf "$stage"; }; trap cleanup EXIT
run_id="$(php -r 'echo bin2hex(random_bytes(16));')"; export CERTLOOM_DB_GATE_RUN_ID="$run_id"
declare -a gates=(numbering-policy domain-schema domain-premium domain-row-lock)
declare -A scripts=([numbering-policy]="run-numbering-policy.php" [domain-schema]="run-domain-schema.php" [domain-premium]="run-domain-premium-gate.php" [domain-row-lock]="run-domain-row-lock-gate.php")
for gate in "${gates[@]}"; do log="$stage/$gate.log"; if ! "$WP_BIN" eval-file "$ROOT/tests/wp-integration/${scripts[$gate]}" >"$log" 2>&1; then cat "$log" >&2; echo "Real WordPress/MySQL gate failed: $gate" >&2; exit 1; fi; cat "$log"; done
php "$ROOT/tests/wp-integration/build-base-gate-index.php" "$stage" "$run_id"; chmod 0700 "$stage"; mv -- "$stage" "$OUT"; trap - EXIT
echo "PASS  Certloom real WordPress/MySQL base gates published atomically: $OUT"

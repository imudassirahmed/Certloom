#!/usr/bin/env bash
#
# Certloom UI contract check.
#
# Catches the three mismatches between markup and stylesheet that have
# repeatedly shipped:
#
#   1. A screen wrapper that does not carry the design tokens.
#   2. A class emitted in markup with no CSS rule anywhere.
#   3. An enum value that becomes a CSS class but has no rule for that value.
#
# Run before every release. Exits non-zero on failure.

set -uo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

FAIL=0
CSS_FILES=( admin/assets/*.css public/assets/*.css )
ALLOW=bin/ui-contract-allowlist.txt

say()  { printf '\n== %s ==\n' "$1"; }
bad()  { printf '  FAIL  %s\n' "$1"; FAIL=1; }
good() { printf '  ok    %s\n' "$1"; }

# ---------------------------------------------------------------------------
# 1. Every admin screen root must carry the token class.
# ---------------------------------------------------------------------------
say "screen wrappers carry design tokens"
WRAP_FAIL=0
while IFS= read -r w; do
	[ -z "$w" ] && continue
	case "$w" in
		*ecapla-certverify-admin-wrap*) ;;
		*) bad "wrapper without tokens: $w"; WRAP_FAIL=1 ;;
	esac
done < <( grep -rhoE 'class=\\?"wrap[^"]*' --include=*.php admin src | sed 's/class=\\\?"//' | sort -u )
[ "$WRAP_FAIL" -eq 0 ] && good "all screen wrappers carry ecapla-certverify-admin-wrap"

# ---------------------------------------------------------------------------
# 2. No class used in markup without a CSS rule.
#    PHP interpolation fragments are filtered out; runtime-concatenated
#    prefixes are covered by check 3. Known-intentional hooks live in the
#    allowlist, so this fails only on NEW orphans.
# ---------------------------------------------------------------------------
say "orphan classes"
[ -f "$ALLOW" ] || : > "$ALLOW"

USED=$( grep -rhoE 'class=\\?"[^"]*' --include=*.php admin src includes public \
        | sed 's/class=\\\?"//' | tr ' ' '\n' \
        | grep -E '^(certloom|ecapla|cv|cvx|cvp)-[a-zA-Z0-9_-]+$' \
        | grep -vE '(<\?php|%[0-9]\$s|-$)' | sort -u )
DEFINED=$( grep -rhoE '\.(certloom|ecapla|cv|cvx|cvp)-[a-zA-Z0-9_-]+' "${CSS_FILES[@]}" \
           | sed 's/^\.//' | sort -u )
KNOWN=$( grep -vE '^[[:space:]]*(#|$)' "$ALLOW" | sort -u )

ORPHANS=$( comm -23 <( echo "$USED" ) <( echo "$DEFINED" ) )
NEW=$( comm -23 <( echo "$ORPHANS" ) <( echo "$KNOWN" ) )
if [ -n "$NEW" ]; then
	while IFS= read -r c; do
		[ -z "$c" ] && continue
		bad "no CSS rule for .$c  (style it, or add it to $ALLOW with a reason)"
	done <<< "$NEW"
else
	good "no new orphan classes"
fi

# ---------------------------------------------------------------------------
# 3. Enum values that become CSS classes.
# ---------------------------------------------------------------------------
enum_check() {
	local label="$1" prefix="$2" sheet="$3"; shift 3
	local missing=0
	for v in "$@"; do
		grep -q -- "${prefix}${v}" "$sheet" || { bad "$label: no rule for '${prefix}${v}'"; missing=1; }
	done
	[ "$missing" -eq 0 ] && good "$label fully covered"
}

say "enum values that become CSS classes"

VERDICTS=$( grep -oE "result\(\s*(true|false),\s*'[a-z_]+'" src/Domain/Credential/StatusService.php \
            | grep -oE "'[a-z_]+'" | tr -d "'" | sort -u )
# shellcheck disable=SC2086
enum_check "public verification verdicts" "cv-status-" public/assets/certloom-public.css $VERDICTS unknown withdrawn
# shellcheck disable=SC2086
enum_check "recipient portal verdicts" "certloom-my-credential-card__status--" public/assets/certloom-public.css $VERDICTS

LIFECYCLE=$( grep -oE "const [A-Z_]+[[:space:]]*=[[:space:]]*'[a-z_]+'" src/Application/Templates/TemplateLifecycle.php \
             | grep -oE "'[a-z_]+'" | tr -d "'" | sort -u )
# shellcheck disable=SC2086
enum_check "template lifecycle states" "certloom-template-state-" admin/assets/admin.css $LIFECYCLE

JOBSTATES=$( grep -oE "const [A-Z_]+[[:space:]]*=[[:space:]]*'[a-z_]+'" src/Domain/Job/JobStates.php \
             | grep -oE "'[a-z_]+'" | tr -d "'" | sort -u )
# shellcheck disable=SC2086
enum_check "job states" "certloom-bulk-status.is-" admin/assets/admin.css $JOBSTATES

# ---------------------------------------------------------------------------
say "result"
if [ "$FAIL" -eq 0 ]; then
	printf '  UI contract intact.\n\n'
else
	printf '  UI contract violations found. See above.\n\n'
fi
exit "$FAIL"

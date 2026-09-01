#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
fail=0
say_fail(){ printf 'FAIL  %s\n' "$1"; fail=1; }
say_pass(){ printf 'PASS  %s\n' "$1"; }

if grep -RIn --include='*.php' 'class="wrap certloom-shell' src admin >/tmp/certloom-shell.out; then
  cat /tmp/certloom-shell.out; say_fail 'deprecated .certloom-shell is still emitted'
else say_pass 'no deprecated .certloom-shell markup'; fi

for file in \
  src/Infrastructure/Admin/ManualIssuanceAdmin.php \
  src/Infrastructure/Admin/BulkIssuanceAdmin.php \
  src/Infrastructure/Admin/EmailDeliveryAdmin.php \
  src/Infrastructure/Admin/TemplateAdmin.php \
  src/Infrastructure/Admin/DomainDataToolsAdmin.php; do
  if grep -q 'ecapla-certverify-admin-wrap' "$file" && grep -q 'certloom-admin-shell' "$file"; then
    say_pass "$file uses the standard shell"
  else say_fail "$file is missing token/shell classes"; fi
done

# Every literal Certloom WordPress screen root must use the same token + layout shell.
# This deliberately checks emitted root markup, not arbitrary utility hooks.
mapfile -t screen_roots < <(grep -RhoE '<div class="wrap[^"]*' --include='*.php' admin src | sort -u)
for root in "${screen_roots[@]}"; do
  if [[ "$root" == *ecapla-certverify-admin-wrap* && "$root" == *certloom-admin-shell* ]]; then
    :
  else
    say_fail "screen root missing standard shell: $root"
  fi
done
if [[ "$fail" -eq 0 ]]; then say_pass "all literal Certloom screen roots use the standard shell"; fi

status_file=src/Domain/Credential/StatusService.php
public_css=public/assets/certloom-public.css
mapfile -t verdicts < <(grep -oE "result\( *\(?(true|false)\)?, *'[a-z_]+'" "$status_file" | grep -oE "'[a-z_]+'" | tr -d "'" | sort -u)
for verdict in "${verdicts[@]}"; do
  if grep -q "cv-status-${verdict} \.cv-status-mark" "$public_css"; then say_pass "public status rule: $verdict"; else say_fail "missing public status rule: $verdict"; fi
done
if grep -q 'cv-status-unknown \.cv-status-mark' "$public_css"; then say_pass 'unknown public status is explicit'; else say_fail 'unknown public status is not explicit'; fi

# A CSS class inventory is useful as a report, but dynamic class names make a
# blanket orphan failure unsafe. Keep the report deterministic and let permanent
# tests promote high-value mismatches to hard failures.
grep -rhoE 'class="[^"]+' --include='*.php' admin src includes public 2>/dev/null \
  | sed 's/^class="//' | tr ' ' '\n' | grep -E '^(certloom|ecapla|cvx?p?)-' | sort -u > /tmp/certloom-markup-classes.txt || true
grep -rhoE '\.(certloom|ecapla|cvx?p?)-[A-Za-z0-9_-]+' admin/assets/*.css public/assets/*.css 2>/dev/null \
  | sed 's/^\.//' | sort -u > /tmp/certloom-css-classes.txt || true
comm -23 /tmp/certloom-markup-classes.txt /tmp/certloom-css-classes.txt > /tmp/certloom-orphan-classes.txt || true
printf 'INFO  orphan-class candidates (review list, dynamic hooks included): %s\n' "$(wc -l </tmp/certloom-orphan-classes.txt | tr -d ' ')"

exit "$fail"

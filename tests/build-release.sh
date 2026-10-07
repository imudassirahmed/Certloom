#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/tests/support/shell-paths.sh"
ROOT_COMPARE="$(certloom_shell_compare_path "$ROOT")"
PLUGIN_VERSION="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "$ROOT/certloom.php" | head -n 1 | tr -d '\r')"
README_STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -n 1 | tr -d '\r')"
if [[ -z "$PLUGIN_VERSION" || "$README_STABLE_TAG" != "$PLUGIN_VERSION" ]]; then
  echo "Certloom release identity mismatch: plugin=$PLUGIN_VERSION readme=$README_STABLE_TAG" >&2
  exit 2
fi
# Release-hygiene ratchets are cheap enough to enforce on every package build.
php "$ROOT/tests/run-css-design-system-ratchet.php" >/dev/null
php "$ROOT/tests/run-runtime-guards.php" >/dev/null
VERSION="${CERTLOOM_BUILD_VERSION:-$PLUGIN_VERSION}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$ ]]; then
  echo "Invalid Certloom build version: $VERSION" >&2
  exit 2
fi
OUT="${1:-$ROOT/../build-$VERSION}"
# The build output is user-supplied. Resolve it before any mkdir or cleanup,
# and never replace a pre-existing directory, symlink, source ancestor, or
# source descendant. A fresh destination also prevents accidental data loss.
if ! OUT_REAL="$(realpath -m -- "$OUT" 2>/dev/null)"; then
  echo "Invalid Certloom build output path." >&2
  exit 2
fi
OUT_COMPARE="$(certloom_shell_compare_path "$OUT_REAL")"
case "$OUT_COMPARE" in
  "$ROOT_COMPARE"|"$ROOT_COMPARE"/*) echo "Refusing to build inside the plugin source tree." >&2; exit 2 ;;
esac
case "$ROOT_COMPARE" in
  "$OUT_COMPARE"/*) echo "Refusing to build into an ancestor of the plugin source tree." >&2; exit 2 ;;
esac
if [[ -e "$OUT_REAL" || -L "$OUT_REAL" ]]; then
  echo "Refusing to replace an existing build output path: $OUT_REAL" >&2
  exit 2
fi
OUT="$OUT_REAL"
EPOCH="${SOURCE_DATE_EPOCH:-1787356800}"
AUTHORITY_DIR="${CERTLOOM_RENDERER_AUTHORITY_DIR:-}"
AUTHORITY_SNAPSHOT=""
AUTHORITY_MANIFEST=""
DOMAIN_AUTHORITY_DIR="${CERTLOOM_DOMAIN_AUTHORITY_DIR:-}"
DOMAIN_AUTHORITY_SNAPSHOT=""
DOMAIN_AUTHORITY_MANIFEST=""
CLEANUP_DIRS=()
cleanup_authority_dirs() {
  local dir resolved expected_parent
  expected_parent="$(realpath -e -- "$(dirname "$OUT")" 2>/dev/null)" || return 0
  for dir in "${CLEANUP_DIRS[@]:-}"; do
    [[ -n "$dir" && -d "$dir" && ! -L "$dir" ]] || continue
    resolved="$(realpath -e -- "$dir" 2>/dev/null)" || continue
    # Delete only the exact generated metadata directory below the verified
    # output parent. Never follow a replaced link or a computed foreign path.
    [[ "$resolved" == "$dir" && "$(dirname "$resolved")" == "$expected_parent" ]] || continue
    case "$(basename "$resolved")" in
      .certloom-authorized-build.*|.certloom-domain-authorized-build.*) rm -rf -- "$resolved" ;;
    esac
  done
  return 0
}
trap cleanup_authority_dirs EXIT

# Renderer authority has exactly two legitimate packaging modes:
#  1. a previously accepted source archive already contains accepted.json; or
#  2. a newly finalized external authority directory is supplied explicitly.
# Never merge both paths because that would make the release authority source
# ambiguous. External authority is snapshotted first; the exact snapshot that is
# validated is the one whose canonical accepted.json enters the ZIP stream.
if [[ -n "$AUTHORITY_DIR" && -f "$ROOT/resources/renderer/accepted.json" ]]; then
  echo "Refusing ambiguous renderer authority: source already contains accepted.json and an external authority directory was supplied." >&2
  exit 5
fi

if [[ -n "$AUTHORITY_DIR" ]]; then
  if ! AUTHORITY_REAL="$(realpath -e -- "$AUTHORITY_DIR" 2>/dev/null)" || [[ ! -d "$AUTHORITY_REAL" ]]; then
    echo "Renderer authority directory is invalid or unavailable." >&2
    exit 5
  fi
  AUTHORITY_COMPARE="$(certloom_shell_compare_path "$AUTHORITY_REAL")"
  case "$AUTHORITY_COMPARE" in
    "$ROOT_COMPARE"|"$ROOT_COMPARE"/*) echo "Renderer authority directory must remain outside the Certloom source tree." >&2; exit 5 ;;
  esac
  parent="$(dirname "$OUT")"
  mkdir -p "$parent"
  authority_tmp="$(mktemp -d "$parent/.certloom-authorized-build.XXXXXX")"
  CLEANUP_DIRS+=("$authority_tmp")
  mkdir -p "$authority_tmp/authority"
  cp -a -- "$AUTHORITY_DIR"/. "$authority_tmp/authority/"
  AUTHORITY_SNAPSHOT="$authority_tmp/authority"
  AUTHORITY_MANIFEST="$authority_tmp/accepted.json"
  php "$ROOT/tests/renderer-candidate/validate-authorized-release.php" "$AUTHORITY_SNAPSHOT" "$AUTHORITY_MANIFEST" >/dev/null
elif [[ -f "$ROOT/resources/renderer/accepted.json" ]]; then
  php "$ROOT/tests/renderer-candidate/validate-bundled-authority.php" "$ROOT/resources/renderer/accepted.json" >/dev/null
fi

# Normalized-domain authority follows the same immutable release-metadata rule.
# The ordinary closed build contains no resources/domain/accepted.json. A new
# authority may enter packaging only through an explicitly supplied, finalized
# external DBAUTH directory; a previously accepted source archive may rebuild
# from its bundled manifest. Never combine the two sources.
if [[ -n "$DOMAIN_AUTHORITY_DIR" && -f "$ROOT/resources/domain/accepted.json" ]]; then
  echo "Refusing ambiguous normalized-domain authority: source already contains accepted.json and an external authority directory was supplied." >&2
  exit 7
fi
if [[ -n "$DOMAIN_AUTHORITY_DIR" ]]; then
  if ! DOMAIN_AUTHORITY_REAL="$(realpath -e -- "$DOMAIN_AUTHORITY_DIR" 2>/dev/null)" || [[ ! -d "$DOMAIN_AUTHORITY_REAL" ]]; then
    echo "Normalized-domain authority directory is invalid or unavailable." >&2
    exit 7
  fi
  DOMAIN_AUTHORITY_COMPARE="$(certloom_shell_compare_path "$DOMAIN_AUTHORITY_REAL")"
  case "$DOMAIN_AUTHORITY_COMPARE" in
    "$ROOT_COMPARE"|"$ROOT_COMPARE"/*) echo "Normalized-domain authority directory must remain outside the Certloom source tree." >&2; exit 7 ;;
  esac
  parent="$(dirname "$OUT")"
  mkdir -p "$parent"
  domain_authority_tmp="$(mktemp -d "$parent/.certloom-domain-authorized-build.XXXXXX")"
  CLEANUP_DIRS+=("$domain_authority_tmp")
  mkdir -p "$domain_authority_tmp/authority"
  cp -a -- "$DOMAIN_AUTHORITY_DIR"/. "$domain_authority_tmp/authority/"
  DOMAIN_AUTHORITY_SNAPSHOT="$domain_authority_tmp/authority"
  DOMAIN_AUTHORITY_MANIFEST="$domain_authority_tmp/accepted.json"
  php "$ROOT/tests/domain-authority/validate-authorized-release.php" "$DOMAIN_AUTHORITY_SNAPSHOT" "$DOMAIN_AUTHORITY_MANIFEST" >/dev/null
elif [[ -f "$ROOT/resources/domain/accepted.json" ]]; then
  php "$ROOT/tests/domain-authority/validate-bundled-authority.php" "$ROOT/resources/domain/accepted.json" >/dev/null
fi

# Stream tracked working files directly into ZIPs. No staging/extraction of
# either plugin tree is necessary. package-stream.py checks source/release byte
# parity, RuntimePayloadFingerprint, fixed-epoch POT and language-pack quality
# (validate_language_packs.py), and repeated ZIP byte determinism.
"${CERTLOOM_BUILD_PYTHON:-python3}" "$ROOT/tests/package-stream.py" "$ROOT" "$OUT" "$VERSION" "$EPOCH" \
  "$AUTHORITY_MANIFEST" "$DOMAIN_AUTHORITY_MANIFEST"

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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
EPOCH="${SOURCE_DATE_EPOCH:-1787356800}"
STAGE="$OUT/.stage"
AUTHORITY_DIR="${CERTLOOM_RENDERER_AUTHORITY_DIR:-}"
AUTHORITY_SNAPSHOT=""
AUTHORITY_MANIFEST=""
DOMAIN_AUTHORITY_DIR="${CERTLOOM_DOMAIN_AUTHORITY_DIR:-}"
DOMAIN_AUTHORITY_SNAPSHOT=""
DOMAIN_AUTHORITY_MANIFEST=""
CLEANUP_DIRS=()
cleanup_authority_dirs() { for dir in "${CLEANUP_DIRS[@]:-}"; do [[ -n "$dir" ]] && rm -rf -- "$dir" || true; done; return 0; }
trap cleanup_authority_dirs EXIT

case "$OUT" in
  "$ROOT"|"$ROOT"/*) echo "Refusing to build inside the plugin source tree." >&2; exit 2 ;;
esac

# Renderer authority has exactly two legitimate packaging modes:
#  1. a previously accepted source archive already contains accepted.json; or
#  2. a newly finalized external authority directory is supplied explicitly.
# Never merge both paths because that would make the release authority source
# ambiguous. External authority is snapshotted first; the exact snapshot that is
# validated is the one whose canonical accepted.json is injected into staging.
if [[ -n "$AUTHORITY_DIR" && -f "$ROOT/resources/renderer/accepted.json" ]]; then
  echo "Refusing ambiguous renderer authority: source already contains accepted.json and an external authority directory was supplied." >&2
  exit 5
fi

if [[ -n "$AUTHORITY_DIR" ]]; then
  if ! AUTHORITY_REAL="$(realpath -e -- "$AUTHORITY_DIR" 2>/dev/null)" || [[ ! -d "$AUTHORITY_REAL" ]]; then
    echo "Renderer authority directory is invalid or unavailable." >&2
    exit 5
  fi
  case "$AUTHORITY_REAL" in
    "$ROOT"|"$ROOT"/*) echo "Renderer authority directory must remain outside the Certloom source tree." >&2; exit 5 ;;
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
  case "$DOMAIN_AUTHORITY_REAL" in
    "$ROOT"|"$ROOT"/*) echo "Normalized-domain authority directory must remain outside the Certloom source tree." >&2; exit 7 ;;
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

rm -rf "$OUT"
mkdir -p "$STAGE/source/certloom" "$STAGE/release/certloom"
cp -a "$ROOT"/. "$STAGE/source/certloom/"
cp -a "$ROOT"/. "$STAGE/release/certloom/"

if [[ -n "$AUTHORITY_MANIFEST" ]]; then
  mkdir -p "$STAGE/source/certloom/resources/renderer" "$STAGE/release/certloom/resources/renderer"
  cp -- "$AUTHORITY_MANIFEST" "$STAGE/source/certloom/resources/renderer/accepted.json"
  cp -- "$AUTHORITY_MANIFEST" "$STAGE/release/certloom/resources/renderer/accepted.json"
  chmod 0644 "$STAGE/source/certloom/resources/renderer/accepted.json" "$STAGE/release/certloom/resources/renderer/accepted.json"
fi

if [[ -n "$DOMAIN_AUTHORITY_MANIFEST" ]]; then
  mkdir -p "$STAGE/source/certloom/resources/domain" "$STAGE/release/certloom/resources/domain"
  cp -- "$DOMAIN_AUTHORITY_MANIFEST" "$STAGE/source/certloom/resources/domain/accepted.json"
  cp -- "$DOMAIN_AUTHORITY_MANIFEST" "$STAGE/release/certloom/resources/domain/accepted.json"
  chmod 0644 "$STAGE/source/certloom/resources/domain/accepted.json" "$STAGE/release/certloom/resources/domain/accepted.json"
fi

# The shipped translation catalog is part of the deterministic release identity.
# Regenerate it from the exact staged source with the same fixed build epoch and
# fail closed if the committed POT is stale or carries a wall-clock timestamp.
pot_check="$STAGE/.certloom.pot"
SOURCE_DATE_EPOCH="$EPOCH" python3 "$STAGE/source/certloom/tests/i18n/make-pot.py" \
  "$STAGE/source/certloom" -o "$pot_check" >/dev/null
if ! cmp -s "$pot_check" "$STAGE/source/certloom/languages/certloom.pot"; then
  echo "Translation catalog is stale or non-deterministic. Regenerate languages/certloom.pot with SOURCE_DATE_EPOCH=$EPOCH." >&2
  exit 6
fi
rm -f "$pot_check"

# I18N-03 quality packs are release-bound to the exact POT and must remain
# honest. Planned locales cannot claim artifacts; candidate/reviewed catalogs
# are structurally validated; reviewed PO/MO/WordPress-JS artifacts must be
# complete, safe and byte-identical to the deterministic compiler output.
python3 "$STAGE/source/certloom/tests/i18n/validate_language_packs.py" \
  "$STAGE/source/certloom" --manifest languages/certloom-language-packs.json >/dev/null

# Generated/interpreter/VCS metadata is never part of a Certloom source or
# production artifact, even if local development tools created it in-tree.
find "$STAGE/source/certloom" "$STAGE/release/certloom" -type d \( -name __pycache__ -o -name .git -o -name .svn \) -prune -exec rm -rf {} +
find "$STAGE/source/certloom" "$STAGE/release/certloom" -type f \( -name '*.pyc' -o -name '*.pyo' -o -name '.DS_Store' \) -delete

rm -rf "$STAGE/release/certloom/docs" "$STAGE/release/certloom/tests" "$STAGE/release/certloom/bin"

# Development-only reproducibility: archive metadata must not depend on the
# operator's clock or filesystem traversal order.
find "$STAGE/source" "$STAGE/release" -exec touch -h -d "@$EPOCH" {} +

zip_sorted() {
  local base="$1" target="$2"
  rm -f "$target"
  (
    cd "$base"
    find certloom -type f -print | LC_ALL=C sort | zip -X -q "$target" -@
  )
}

zip_sorted "$STAGE/source" "$OUT/certloom-$VERSION-source.zip"
zip_sorted "$STAGE/release" "$OUT/certloom-$VERSION.zip"

unzip -tq "$OUT/certloom-$VERSION-source.zip" >/dev/null
unzip -tq "$OUT/certloom-$VERSION.zip" >/dev/null

source_list="$OUT/source-files.txt"
release_list="$OUT/release-files.txt"
(
  cd "$STAGE/source/certloom"
  find . -type f -print | LC_ALL=C sort > "$source_list"
)
(
  cd "$STAGE/release/certloom"
  find . -type f -print | LC_ALL=C sort > "$release_list"
)

LC_ALL=C sort -c "$source_list"
LC_ALL=C sort -c "$release_list"
if LC_ALL=C comm -13 "$source_list" "$release_list" | grep -q .; then
  echo "Production archive contains files absent from source." >&2
  exit 3
fi

while IFS= read -r rel; do
  src="$STAGE/source/certloom/${rel#./}"
  dst="$STAGE/release/certloom/${rel#./}"
  if ! cmp -s "$src" "$dst"; then
    echo "Source/release byte mismatch: $rel" >&2
    exit 4
  fi
done < "$release_list"

# Rebuild once from the same normalized stage and require identical bytes.
cp "$OUT/certloom-$VERSION-source.zip" "$OUT/.source-first.zip"
cp "$OUT/certloom-$VERSION.zip" "$OUT/.release-first.zip"
zip_sorted "$STAGE/source" "$OUT/certloom-$VERSION-source.zip"
zip_sorted "$STAGE/release" "$OUT/certloom-$VERSION.zip"
cmp -s "$OUT/.source-first.zip" "$OUT/certloom-$VERSION-source.zip"
cmp -s "$OUT/.release-first.zip" "$OUT/certloom-$VERSION.zip"
rm -f "$OUT/.source-first.zip" "$OUT/.release-first.zip"

sha256sum "$OUT/certloom-$VERSION.zip" "$OUT/certloom-$VERSION-source.zip" > "$OUT/certloom-$VERSION-SHA256SUMS.txt"

printf 'source_files=%s\n' "$(wc -l < "$source_list" | tr -d ' ')"
printf 'release_files=%s\n' "$(wc -l < "$release_list" | tr -d ' ')"
printf 'production_only=0\nmodified_common=0\nreproducible_zip=yes\n'
if [[ -f "$STAGE/release/certloom/resources/renderer/accepted.json" ]]; then printf 'renderer_authority=validated\n'; else printf 'renderer_authority=absent\n'; fi
if [[ -f "$STAGE/release/certloom/resources/domain/accepted.json" ]]; then printf 'domain_authority=validated\n'; else printf 'domain_authority=absent\n'; fi
cat "$OUT/certloom-$VERSION-SHA256SUMS.txt"

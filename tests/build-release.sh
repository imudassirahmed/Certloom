#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${CERTLOOM_BUILD_VERSION:-1.6.23}"
OUT="${1:-$ROOT/../build-$VERSION}"
EPOCH="${SOURCE_DATE_EPOCH:-1787356800}"
STAGE="$OUT/.stage"

case "$OUT" in
  "$ROOT"|"$ROOT"/*) echo "Refusing to build inside the plugin source tree." >&2; exit 2 ;;
esac

rm -rf "$OUT"
mkdir -p "$STAGE/source/certloom" "$STAGE/release/certloom"
cp -a "$ROOT"/. "$STAGE/source/certloom/"
cp -a "$ROOT"/. "$STAGE/release/certloom/"

# Generated/interpreter/VCS metadata is never part of a Certloom source or
# production artifact, even if local development tools created it in-tree.
find "$STAGE/source/certloom" "$STAGE/release/certloom" -type d \( -name __pycache__ -o -name .git -o -name .svn \) -prune -exec rm -rf {} +
find "$STAGE/source/certloom" "$STAGE/release/certloom" -type f \( -name '*.pyc' -o -name '*.pyo' -o -name '.DS_Store' \) -delete

rm -rf "$STAGE/release/certloom/docs" "$STAGE/release/certloom/tests"

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

if comm -13 "$source_list" "$release_list" | grep -q .; then
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
cat "$OUT/certloom-$VERSION-SHA256SUMS.txt"

#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(php -r '$s=file_get_contents($argv[1]); preg_match("/define\\( \\x27SPF_VERSION\\x27, \\x27([^\\x27]+)\\x27 \\)/",$s,$m); echo $m[1] ?? "";' "$ROOT/sabri-platform-foundation.php")"
[[ -n "$VERSION" ]] || { echo 'Unable to determine SPF_VERSION' >&2; exit 1; }
NAME="01-sabri-platform-foundation-${VERSION}-CORRECTIVE-CANDIDATE"
TOP="sabri-platform-foundation-01"
EPOCH="202608060100"
DIST="$ROOT/dist"
BUILD="$ROOT/build"
rm -rf "$BUILD" "$DIST"
mkdir -p "$BUILD/one/$TOP" "$BUILD/two/$TOP" "$DIST"

copy_package() {
  local target="$1"
  local files=(
    sabri-platform-foundation.php uninstall.php readme.txt README.md CHANGELOG.md
    TRACEABILITY.md MIGRATION.md ROLLBACK.md STAGING-ACCEPTANCE.md SECURITY.md
    PRIVACY.md REVIEW-ROUND-1.md ADVERSARIAL-REVIEW-ROUND-2.md REVIEW-ROUND-3.md
    ADVERSARIAL-REVIEW-ROUND-4.md QA-REPORT.md KNOWN-LIMITATIONS.md
    RELEASE-CHECKLIST.md SBOM.cdx.json DEPENDENCY-MANIFEST.json RELEASE-EVIDENCE-TEMPLATE.json
  )
  for file in "${files[@]}"; do cp "$ROOT/$file" "$target/"; done
  mkdir -p "$target/includes"
  cp "$ROOT"/includes/*.php "$target/includes/"
  (
    cd "$target"
    find . -type f ! -name 'SOURCE-MANIFEST.sha256' -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SOURCE-MANIFEST.sha256
  )
  find "$target" -exec touch -t "$EPOCH" {} +
}

make_zip() {
  local stage="$1" output="$2"
  (
    cd "$stage"
    LC_ALL=C find "$TOP" -type f -print | sort | zip -X -q "$output" -@
  )
}

copy_package "$BUILD/one/$TOP"
copy_package "$BUILD/two/$TOP"
make_zip "$BUILD/one" "$DIST/${NAME}-build1.zip"
make_zip "$BUILD/two" "$DIST/${NAME}-build2.zip"
cmp "$DIST/${NAME}-build1.zip" "$DIST/${NAME}-build2.zip"
mv "$DIST/${NAME}-build1.zip" "$DIST/${NAME}.zip"
rm "$DIST/${NAME}-build2.zip"
sha256sum "$DIST/${NAME}.zip" > "$DIST/${NAME}.zip.sha256"
unzip -t "$DIST/${NAME}.zip" >/dev/null
if unzip -Z1 "$DIST/${NAME}.zip" | grep -Ev '^sabri-platform-foundation-01/'; then
  echo 'Unsafe or non-canonical ZIP entry' >&2; exit 1
fi
rm -rf "$BUILD/verify"
mkdir -p "$BUILD/verify"
unzip -q "$DIST/${NAME}.zip" -d "$BUILD/verify"
(
  cd "$BUILD/verify/$TOP"
  sha256sum --check SOURCE-MANIFEST.sha256
)
# Package/source parity for runtime files.
for file in sabri-platform-foundation.php uninstall.php readme.txt; do cmp "$ROOT/$file" "$BUILD/verify/$TOP/$file"; done
for file in "$ROOT"/includes/*.php; do cmp "$file" "$BUILD/verify/$TOP/includes/$(basename "$file")"; done

echo "Deterministic package: $DIST/${NAME}.zip"
cat "$DIST/${NAME}.zip.sha256"

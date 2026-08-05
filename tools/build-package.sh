#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(php -r '$s=file_get_contents($argv[1]); preg_match("/define\\( \x27SPF_VERSION\x27, \x27([^\x27]+)\x27 \\)/",$s,$m); echo $m[1] ?? "";' "$ROOT/sabri-platform-foundation.php")"
if [[ -z "$VERSION" ]]; then
  echo "Unable to determine SPF_VERSION" >&2
  exit 1
fi

NAME="01-sabri-platform-foundation-${VERSION}-CORRECTIVE-CANDIDATE"
TOP="sabri-platform-foundation"
EPOCH="202608052200"
DIST="$ROOT/dist"
rm -rf "$ROOT/build" "$DIST"
mkdir -p "$ROOT/build/one/$TOP" "$ROOT/build/two/$TOP" "$DIST"

copy_package() {
  local target="$1"
  cp "$ROOT/sabri-platform-foundation.php" "$target/"
  cp "$ROOT/uninstall.php" "$target/"
  cp "$ROOT/readme.txt" "$target/"
  cp "$ROOT/README.md" "$target/"
  cp "$ROOT/CHANGELOG.md" "$target/"
  cp "$ROOT/TRACEABILITY.md" "$target/"
  cp "$ROOT/MIGRATION.md" "$target/"
  cp "$ROOT/ROLLBACK.md" "$target/"
  cp "$ROOT/STAGING-ACCEPTANCE.md" "$target/"
  cp "$ROOT/SECURITY.md" "$target/"
  cp "$ROOT/PRIVACY.md" "$target/"
  cp "$ROOT/REVIEW-ROUND-1.md" "$target/"
  cp "$ROOT/ADVERSARIAL-REVIEW-ROUND-2.md" "$target/"
  cp "$ROOT/QA-REPORT.md" "$target/"
  cp "$ROOT/KNOWN-LIMITATIONS.md" "$target/"
  cp "$ROOT/RELEASE-CHECKLIST.md" "$target/"
  mkdir -p "$target/includes"
  cp "$ROOT"/includes/*.php "$target/includes/"
  (
    cd "$target"
    find . -type f ! -name 'SOURCE-MANIFEST.sha256' -print0 | sort -z | xargs -0 sha256sum > SOURCE-MANIFEST.sha256
  )
  find "$target" -exec touch -t "$EPOCH" {} +
}

make_zip() {
  local stage="$1"
  local output="$2"
  (
    cd "$stage"
    LC_ALL=C find "$TOP" -type f -print | sort | zip -X -q "$output" -@
  )
}

copy_package "$ROOT/build/one/$TOP"
copy_package "$ROOT/build/two/$TOP"
make_zip "$ROOT/build/one" "$DIST/${NAME}-build1.zip"
make_zip "$ROOT/build/two" "$DIST/${NAME}-build2.zip"
cmp "$DIST/${NAME}-build1.zip" "$DIST/${NAME}-build2.zip"
mv "$DIST/${NAME}-build1.zip" "$DIST/${NAME}.zip"
rm "$DIST/${NAME}-build2.zip"
sha256sum "$DIST/${NAME}.zip" > "$DIST/${NAME}.zip.sha256"
unzip -t "$DIST/${NAME}.zip" >/dev/null
unzip -Z1 "$DIST/${NAME}.zip" | grep -Ev '^sabri-platform-foundation/' && {
  echo "Unsafe ZIP entry" >&2
  exit 1
} || true
echo "Deterministic package: $DIST/${NAME}.zip"
cat "$DIST/${NAME}.zip.sha256"

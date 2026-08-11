#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

printf 'Review-2 fixture hashes\n'
sha256sum tests/unit-tests.php qa/wp-purge-smoke.php qa/wp-eleventh-ten-round-smoke.php qa/wp-runtime-smoke.php

printf 'PHP syntax\n'
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -type f -name '*.php' -not -path './build/*' -not -path './dist/*' -print0)

php tests/unit-tests.php
php tests/future-foundation-tests.php
php tests/third-ten-round-review-tests.php
php tests/fourth-ten-round-review-tests.php
php tests/fifth-ten-round-review-tests.php
php tests/sixth-ten-round-review-tests.php
php tests/seventh-ten-round-review-tests.php
php tests/eighth-ten-round-review-tests.php
php tests/fresh-eighty-round-review-tests.php
if [[ -f tests/tenth-fresh-eighty-round-review-tests.php ]]; then php tests/tenth-fresh-eighty-round-review-tests.php; fi
php tests/eleventh-ten-round-review-tests.php
php tests/twelfth-ten-round-review-tests.php
php tests/release-handoff-contract-tests.php
php tests/source-quality-tests.php
php tests/schema-tests.php
php tests/security-tests.php
php tests/contract-tests.php

printf 'Source checksum manifest\n'
sha256sum --check SOURCE-CHECKSUMS.sha256
printf 'Closed-world source inventory\n'
actual_inventory="$(mktemp)"
manifest_inventory="$(mktemp)"
trap 'rm -f "$actual_inventory" "$manifest_inventory"' EXIT
find . -type f -not -path './.git/*' -not -path './build/*' -not -path './dist/*' ! -name 'SOURCE-CHECKSUMS.sha256' -print | LC_ALL=C sort >"$actual_inventory"
awk '{print $2}' SOURCE-CHECKSUMS.sha256 | LC_ALL=C sort >"$manifest_inventory"
diff -u "$manifest_inventory" "$actual_inventory"
if find . -maxdepth 4 -type f \( -name '.fresh-*' -o -name '*.b64' \) -print -quit | grep -q .; then
  echo 'Temporary review/apply artifact found' >&2
  exit 1
fi

printf 'JSON documents\n'
for file in SBOM.cdx.json DEPENDENCY-MANIFEST.json RELEASE-EVIDENCE-TEMPLATE.json; do
  [[ -f "$file" ]] || { echo "Missing $file" >&2; exit 1; }
  php -r '$d=json_decode(file_get_contents($argv[1]),true); if(!is_array($d)||json_last_error()!==JSON_ERROR_NONE){fwrite(STDERR,"Invalid JSON: {$argv[1]}\n");exit(1);}' "$file"
done

printf 'Forbidden artifact checks\n'
if grep -RInE --exclude=run-tests.sh \
  '(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|AKIA[0-9A-Z]{16}|Hostinger credential)' \
  sabri-platform-foundation.php uninstall.php includes qa tools .github; then
  echo "Potential secret/private-data indicator found" >&2; exit 1
fi
if find . -type f -not -path './.git/*' -print0 | xargs -0 grep -Il $'\r' | grep .; then echo "CRLF files found" >&2; exit 1; fi

echo 'All source QA PASS'

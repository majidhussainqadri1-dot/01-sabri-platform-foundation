#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

printf 'PHP syntax\n'
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -type f -name '*.php' -not -path './build/*' -not -path './dist/*' -print0)

php tests/unit-tests.php
php tests/future-foundation-tests.php
php tests/third-ten-round-review-tests.php
php tests/fourth-ten-round-review-tests.php
php tests/fifth-ten-round-review-tests.php
php tests/source-quality-tests.php
php tests/schema-tests.php
php tests/security-tests.php
php tests/contract-tests.php

printf 'Source checksum manifest\n'
sha256sum --check SOURCE-CHECKSUMS.sha256

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

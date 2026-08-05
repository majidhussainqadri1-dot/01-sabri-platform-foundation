#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

printf 'PHP syntax\n'
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find . -type f -name '*.php' -not -path './build/*' -not -path './dist/*' -print0)

printf 'Contract tests\n'
php tests/contract-tests.php
printf 'Security tests\n'
php tests/security-tests.php
printf 'Schema tests\n'
php tests/schema-tests.php

printf 'Forbidden artifact checks\n'
if grep -RInE --exclude-dir=.git --exclude-dir=build --exclude-dir=dist --exclude=run-tests.sh \
  '(BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|AKIA[0-9A-Z]{16}|password\s*[:=]\s*[^[]|Hostinger credential|patient record)' .; then
  echo "Potential secret/private-data indicator found" >&2
  exit 1
fi

if find . -type f -not -path './.git/*' -print0 | xargs -0 grep -Il $'\r' | grep .; then
  echo "CRLF files found; deterministic source requires LF" >&2
  exit 1
fi

echo "All source QA PASS"

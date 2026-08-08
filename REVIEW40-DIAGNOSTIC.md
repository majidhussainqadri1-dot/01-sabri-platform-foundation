# File 01 reviewed-source archive diagnostic

base64_status=0
archive_sha256=27429733f531150cc4d48eae75e892436d736251035e2f8552380f36bb7be5f4
gzip_status=1
tar_list_status=2

## Chunk hashes
ebc738afe9e76527c2e15d4eb1f7bfcfe2293a31ce9b92328b6996a1419bb702  .codex/review40.chunk00
3dc86bd99a48d82630c86c072eb1b4e5a538c9918ac2bb84c6d6a1de0d2d97da  .codex/review40.chunk01
be06a894fe9828668c8d052340e9e59d466daa44e5adac798fe3e05d1912732e  .codex/review40.chunk02
3159797c1c9fd30b147365b83387de4dc43b398f4a7eaac6f0d8285207c40de4  .codex/review40.chunk03
b04cd1df9d0efc85d09b178fdecbf0f6025e629e360646264f84ef57d1830c48  .codex/review40.chunk04
126b57f4c66bbee39b5160d6812435b8fa12afee94073973a9d9305e8e71d461  .codex/review40.chunk05
6f1bfacca39ceed7262a79e05126aafaba48c4dff6868047325b156c21a3cd72  .codex/review40.chunk06
0296fe70366fc5541a98151a3daba34674461091e58cd38a7e12a6adb9b453a3  .codex/review40.chunk07

## base64 stderr

## gzip stderr

gzip: /tmp/file01-review40.tar.gz: invalid compressed data--format violated

## tar stderr
tar: Skipping to next header

gzip: stdin: invalid compressed data--format violated
tar: Child returned status 1
tar: Error is not recoverable: exiting now

## archive listing head
./
./.gitignore
./STAGING-ACCEPTANCE.md
./sabri-platform-foundation.php
./README.md
./SECURITY.md
./CHANGELOG.md
./tools/
./tools/build-package.sh
./TRACEABILITY.md
./SBOM.cdx.json
./ADVERSARIAL-REVIEW-ROUND-4.md
./KNOWN-LIMITATIONS.md
./includes/
./includes/class-spf-installer.php
./includes/class-spf-governance.php

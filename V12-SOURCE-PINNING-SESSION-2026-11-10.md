# File 01 — V12 source/test-file provenance hardening (10 November 2026)

This is a repository-only review handoff for Draft PR #9. The incoming candidate was `4942733478bda7aab800495a77edb49e1d43d9b4`; production, staging and main are unchanged by this change.

## Work performed

- Preserved the canonical **43** requirements: 12 FR, 10 NFR, 3 CEN and 18 FUT, matching the governing Sabri Website Master Plan v12.
- Added **16** missing concrete PHP implementation-symbol pointers and **25** missing existing test-file pointers to `TRACEABILITY.md`; no assertions were invented.
- Strengthened `tools/v12-traceability-audit.php` so source QA fails if fewer than **43/43** implementation symbols or **43/43** test file references have checksum-verifiable on-disk provenance.
- Added explicit missing-ID arrays to the generated evidence report for immediate follow-up if file-level traceability regresses.
- Updated the closed-world SHA-256 inventory; this is a QA/provenance-only change, not a WordPress runtime or data/schema modification.

## Verification semantics

**File-level source and test-file references are NOT proof of behavioral completeness.** All rows retain independent assertion, actual CI, cross-module, staging, live and Founder-approval gates. In particular, the `coded_complete_verified` field remains false. An existing broad test file must never be counted as an individually verified requirement until its actual assertions and exact-head run are reviewed.

## Open acceptance dependencies

1. Individually review 43 requirements against their semantic assertions; collect independent per-requirement CI/commit evidence, rather than extrapolating 4 CI jobs into 43 passes.
2. Verify exact signed/approved File 00, 20, 21, 24, 25 and 26 owner manifests, contracts and their release compatibility with this candidate.
3. Execute the nonproduction chaos observer and observable failure/recovery receipt (F01-FUT-015); path-level test coverage alone is insufficient.
4. Restore authenticated Hostinger staging/live read-only access, freeze deployed File 01 source/package checksums, WordPress/PHP/MySQL, schema/tables/options, migrations, configuration and logs.
5. Rehearse independent staging backup/restore, fresh/upgrade/rollback, accessibility/RTL, load, provider failures and real cross-plugin smoke; get Founder approval before any merge/deployment, then live re-test and parity confirmation.

## Defect/status disclosure

- New verified live defects: **0** (live source inaccessible; this is not a clean-bill-of-health claim).
- Historic three source gaps G-01/G-02/G-03: bounded candidate corrections exist in earlier PR #9 commits, but external acceptance is pending.
- This session addresses **41 file-level provenance omissions** (16 source + 25 test pointers), not 41 independent PHP bugs.
- Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

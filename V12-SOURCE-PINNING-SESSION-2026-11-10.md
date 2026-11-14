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

## Current six-companion GitHub source intake (10 November 2026)

This is a bounded **read-only repository-main snapshot**, NOT authenticated WordPress, staging or deployed-version parity. All six tree listings were untruncated. The software versions below were read from the main plugin PHP headers at the exact cited commits; they are not live installed versions.

| File | Repository | Exact main SHA | Plugin source software version | File 01 declared minimum |
|---|---|---|---|---|
| 00 | `00-sabri-membership-core` | `2fa7c022ee9cd1b65432e900579512f304532442` | 1.2.44 | 1.2.13 |
| 20 | `20-sabri-unified-application-shell` | `8a4dbcaf4fef8e926b9b834ecfde16c21a0f00ca` | 1.4.17 | 1.2.0 |
| 21 | `sabri-complete-home-news-feed` | `626b380328d4400014504e679032291cd023bbdb` | 1.0.5 | 1.0.1 |
| 24 | `24-sabri-platform-security-privacy-compliance-and-resilience-center` | `a5b8d49968a7a5a7d6f3f4655bea541bf38a9acb` | 0.99.0 | 0.25.0 |
| 25 | `25-sabri-public-ui-profile-timeline-visual-experience` | `347a4ff4d4c233c5ea6cd82c7786ee5398ea9d1e` | 0.15.0 | Not a File 01 optional-dependency manifest entry |
| 26 | `26-search-discovery-recommendations-knowledge-graph-classification` | `1b064c6ea9f81195611ed001d5457e8ced65460a` | 1.3.0 | 0.1.0 |

The five declared optional companions satisfy their **minimum source software versions by ordinary numeric precedence**, but that establishes neither exact contract compatibility nor current installed/deployed parity.

### Precisely blocked cross-repository linter intake

At these six exact `main` commits, **0/6** trees contain an allowed `manifest.json` or `architecture-manifest.json` path accepted by File 01 `SPF_Governance_Control_Plane::lint_pinned_repository_sources` (basename and depth constrained in the existing function). Other documents and manifests exist under different filenames/formats; this finding does **not** mean the repositories lack any manifest at all.

Therefore it would be incorrect to supply invented pinned descriptors or report 6/6 source-intake passes. Each canonical owner must first approve and produce a bounded manifest at an accepted path with `module_key`, `canonical_entities`, `writes`, `routes`, correct File 20 global-shell ownership, an exact immutable Git commit and an independently computed file SHA-256. File 01 can then run its existing **read-only, fail-closed** intake and duplicate-owner/route checks. Existing owner modules must not be rewritten or granted authority by this audit.

These **six external source-intake gaps** are integration prerequisites, not six new live defects. No permissions, database tables, plugin activation or production files were modified.

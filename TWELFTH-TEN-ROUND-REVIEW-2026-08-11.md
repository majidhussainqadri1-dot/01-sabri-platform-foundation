# File 01 — Twelfth Fresh Ten-Round Corrective Review — 2026-08-11

## Governing method

This review was performed sequentially against the corrected state produced by the preceding round. A finding was corrected before the next round continued. Repository truth is kept separate from staging/live truth: repository QA cannot create a staging-accepted, live-deployed, or operational claim.

## Round results

| Round | Result | Finding and connected correction |
|---|---|---|
| 1 | DEFECT | Exact PR-head CI was not green because `SOURCE-CHECKSUMS.sha256` no longer matched the checkout. The stale release-integrity baseline was rejected and the source-manifest workflow was re-opened for exact-head correction. |
| 2 | DEFECT | Structured File 00 authorization claims requested `plugin=file-01` and the File 01 contract version but runtime validation did not bind either field; the published authorization-claim schema was also stale. Runtime validation and the published contract were bound to the exact plugin/contract, and regression coverage was added. |
| 3 | DEFECT | Event identity and scalar event facts could be silently normalized/truncated before persistence, allowing distinct facts to collapse. Event identities and payload values now fail closed when noncanonical or outside the bounded envelope. |
| 4 | CLEAN | Registry ownership, route/contract registration, dependency duplication/self-dependency, and optimistic record-version behavior were re-reviewed; no new proven defect was found. |
| 5 | DEFECT | A malformed stored schema version could reach the automatic upgrade entry point and be treated as no-op/current by lower migration logic. The WordPress automatic-upgrade entry point now blocks and records `invalid_schema_version`. |
| 6 | DEFECT | Immutable governance evidence scalar strings were silently shortened to 1000 bytes before hashing/storage. Silent per-field truncation was removed; the existing total evidence-envelope bound remains. |
| 7 | DEFECT | Non-destructive uninstall unscheduled three File 01 jobs but omitted `spf_future_foundation_tick`. The Future Foundation scheduled task is now also unscheduled. |
| 8 | DEFECT | WordPress privacy erasure deleted all actor idempotency records, including potentially in-flight mutation state. Erasure now fails closed while nonterminal idempotency exists and deletes only terminal `completed`/`failed` linkage. |
| 9 | DEFECT | System Check treated `staged` as `staging_accepted`, contradicting the release/status model, and did not surface the new malformed-schema upgrade state consistently. Staging acceptance now requires `approved` or `deployed`, and `invalid_schema_version` is explicitly unhealthy. |
| 10 | DEFECT | After the connected corrections the closed-world source checksum/package gate was necessarily stale. The final step is to regenerate the exact checkout manifest, rerun source/runtime/package QA on the resulting exact HEAD, and accept the repository candidate only if that exact run is green. |

## Defect-bearing rounds

**1, 2, 3, 5, 6, 7, 8, 9, 10**

Round **4** produced no new proven repository defect.

## Truth boundary

This record is repository-scope evidence only. Hostinger staging acceptance, live deployment, live DB/schema/migration state, operational monitoring, and deployed-artifact parity remain separate gates and must be verified from the deployed environments.

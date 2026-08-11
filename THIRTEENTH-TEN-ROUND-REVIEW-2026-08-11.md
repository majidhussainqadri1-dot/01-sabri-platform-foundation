# File 01 — Thirteenth Fresh 10-Round Corrective Review — 2026-08-11

## Governing truth boundary
This record is repository/source and automated-runtime evidence only. It does not assert Hostinger staging acceptance, live deployment, database parity, or operational acceptance. Exact deployed code remains unverified until deployment evidence is collected.

## Sequential review result
| Round | Result | Connected correction |
|---|---|---|
| R1 | DEFECT | Immutable governance identities could silently normalize: package filename, evidence keys/scalars, and amendment stable references. All now fail closed on noncanonical input. |
| R2 | DEFECT | Structured File 00 claim identity fields could be accepted through sanitize-equivalence. Exact canonical claim identities are now required. |
| R3 | DEFECT | Operational acceptance could reuse stale/unbound health and observation evidence. Fresh health-hash binding, expiry, freshness and verifier requirements added. |
| R4 | DEFECT | `SPF_Installer::maybe_upgrade()` itself still returned success for malformed stored schema versions. Direct API now records `invalid_schema_version` and fails closed. |
| R5 | DEFECT | Bounded self-heal inspected a stale `spf_feature_flags` option while canonical flags live in the File 01 table; rollback therefore did not cover the rows actually mutated. Self-heal now snapshots exact flag rows, binds reconciliation to row hash/version, records post hashes, and restores exact rows. Expiry success also requires audit evidence. |
| R6 | DEFECT | Generic external evidence accepted future-dated historical `*_at` claims. Historical timestamps now reject values more than 60 seconds in the future. |
| R7 | DEFECT | Audit-chain verification hard-capped at 50,000 rows while documentation implied a larger explicit ceiling. Complete verification is now paged in 5,000-row batches under a configurable bounded ceiling. |
| R8 | CLEAN | Event identity/payload, idempotency replay/concurrency, privacy erasure and destructive-purge boundaries re-reviewed after corrections; no new defect proved. |
| R9 | CLEAN | Registry ownership, dependency graph, REST authorization, release-train ordering and companion-domain write boundaries re-reviewed; no new defect proved. |
| R10 | DEFECT (QA/EVIDENCE) | The existing regression harness and closed-world checksum inventory did not yet encode the new critical invariants. A Thirteenth source regression suite and WordPress/MySQL runtime smoke were added; Twelfth assertion semantics were updated without weakening the prior invariant; final source manifest was regenerated. |

## Defect-bearing rounds
1, 2, 3, 4, 5, 6, 7, 10.

## Clean rounds
8, 9.

## Lifecycle discipline
Repository-coded/QA-green status remains separate from Staging-Accepted, Live-Deployed and Operational. PR remains Draft/Open/Unmerged until real staging and deployment evidence exists.

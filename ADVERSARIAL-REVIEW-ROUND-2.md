# Fresh Adversarial Review/Fix Round 2

Date: 2026-08-05 (Asia/Karachi)
Review posture: attacker, concurrency, partial failure, stale cache/state, cross-module conflict and recovery.

## Findings and corrections

| Attack/failure case | Defect exposed | Correction |
|---|---|---|
| Reuse one idempotency key across actors/actions | Global key collision/replay risk | Unique actor + action + key `scope_hash`; request-hash conflict returns 409 |
| Concurrent release promotion | Duplicate or skipped lifecycle state | Transaction, row lock, expected sequence, unique `(release_id, sequence_no)` |
| Audit write fails after governance mutation | Un-audited release/amendment state | Release, state, mandatory audit and outbox event share one transaction |
| Dispatcher crashes unexpectedly | Stale global dispatcher lock | `finally`-guaranteed lock release and stale-lock recovery |
| Two dispatchers claim the same event | Duplicate delivery | Conditional `processing` claim and conditional finalization |
| Route points off-site | Open redirect | Same-origin destination validation; legacy redirects are path-only and collision-checked |
| Manifest/contract payload exhaustion | Oversized storage/processing | Explicit manifest, schema and consumer-list size limits |
| Stale administrator form | Lost update | Record versions and expected-version/sequence conflict handling |
| File 00 installed but adapter missing | Permissive fallback | Privileged mutations fail closed; bootstrap fallback only while File 00 is absent |
| Repair clears unrelated caches/data | Cross-module damage | Exact File 01 allowlists; no global cache flush or companion table/meta write |
| Reconciliation plan changes between review/apply | TOCTOU mutation | Canonicalized plan hash excluding volatile timestamp and constant-time comparison |
| Purge replay after tables are removed | Idempotency-table failure | Purge returns external receipt and bypasses post-purge idempotency persistence |
| Nested secret in release/amendment evidence | Data leakage | Recursive key-based evidence redaction and allowlisted release evidence fields |
| Contract deprecation ignored by consumers | Untraceable compatibility break | Consumer acknowledgement ledger, versions, audit and REST command |
| Direct restricted-route browsing | Index/cache/existence leakage | Authenticated capability check, `noindex`, no-cache headers and safe 404/403 behavior |
| Event handler repeatedly fails | Infinite retry | Exponential bounded retry, five-attempt dead-letter and sanitized error |
| Activation fails after table creation | Partial installation | Snapshot of pre-existing tables/options; only newly created File 01 tables removed |
| Historical module manifest overwritten on reactivation | Companion evidence loss | Seed preserves existing non-File-01 manifests |
| Release status overwritten in one row | History loss | Immutable release identity plus append-only state ledger |

## Fresh-review decision

No unresolved static critical/high defect remains in the reviewed repository scope. Real WordPress/MySQL behavior, Hostinger environment, companion runtime contracts, browser/accessibility, backup/restore and rollback remain mandatory staging evidence rather than simulated passes.

# Review/Fix Round 1 — Architecture, Requirements and Corrective Implementation

Date: 2026-08-05 (Asia/Karachi)
Branch: `codex/file-01-complete-foundation-1.0.0`
Governing plan: `SSH-F01-PLAN-2026-v1.0`

## Review scope

The historical `0.1.0` baseline was compared against F01-FR-001–012, F01-NFR-001–010, the Definitive Master Plan v3.0 and the current cross-file ownership model.

## Defects found and corrected

1. **Unsafe Founder assignment:** removed activation-time assignment of the activating administrator as Founder. File 00 remains identity authority.
2. **Duplicate shell and Home/News ownership:** removed legacy public Home/module shortcodes, templates, navigation, post queries, CSS and JavaScript. File 20, File 21 and File 25 remain canonical owners.
3. **Silent page takeover:** replaced page creation/reuse with an explicit owner-bound route registry; collisions and owner transfer fail closed.
4. **Missing constitution and manifest registries:** added amendments, module manifests, contracts, routes and release evidence with stable IDs and versions.
5. **Missing dependency resolution:** added deterministic missing/incompatible/cyclic readiness results.
6. **Unsafe activation:** added owner-token lock, stale-lock recovery, pre-activation snapshot, idempotent schema setup and compensation.
7. **No operational health contract:** added redacted System Check and health snapshots without secrets, paths or personal data.
8. **No controlled legacy cutover:** added dry-run, stable plan hash, typed confirmation, snapshot, File 01-only quarantine and rollback.
9. **No safe repair:** added bounded owner-scoped repair plan, typed confirmation, snapshots and compensation; companion data is never repaired directly.
10. **No release-state truth:** added immutable package evidence and append-only release-state history.
11. **No feature-flag governance:** added owner/environment/reason/expiry/version fields; flags never substitute for authorization.
12. **Destructive uninstall ambiguity:** normal uninstall is non-destructive; purge is separately compiled, capability-checked, backup-evidenced, hash-bound and typed-confirmed.
13. **Insufficient API controls:** added permissions, idempotency, actor/action scoping, rate limiting, validation, stable errors and audit context.
14. **Missing reliable events:** added outbox deduplication, bounded retry, dead-letter state and periodic dispatch.
15. **Missing deterministic release engineering:** added source tests, PHP 8.1/8.3 CI and byte-identical double-build packaging.

## Round-1 decision

All identified architecture and specification defects were corrected. The candidate proceeded to a new adversarial review; no production or staging acceptance was inferred.

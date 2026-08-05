# Changelog

## 1.1.0 — Corrective candidate

- Removed spoofable internal-seed context and placeholder manifests.
- Restricted generic Administrator capabilities to view/manage File 01-owned maintenance.
- Added structured, object-bound, short-lived File 00 claims for sensitive actions.
- Enforced planned→built→verified→staged→approved→deployed release gates with required evidence and optimistic concurrency.
- Added atomic idempotency reservation/replay and bounded rate control.
- Added full shadow-table/option/capability/schedule activation and upgrade compensation.
- Added canonical-owner reconciliation plan/execute/rollback receipts and exact metadata restoration.
- Added WordPress privacy export/erasure coordination and safe retention without rewriting append-only facts.
- Added structured backup/restore evidence, File 24 assurance, table quarantine and verified destructive purge.
- Added real manifest schema, optional dependency readiness and operational feature-flag lifecycle.
- Added actual WordPress/MySQL runtime CI, concurrency tests, migration tests and deterministic packaging.
- Canonical package top-level folder changed to `sabri-platform-foundation-01` under an explicit compatibility amendment; WordPress slug/text domain remains stable.

# Changelog

## 1.2.0 — Final coding candidate

- Aligned File 01 with the latest 00–26 governance, File 20/21/24/25/26 ownership boundaries, and the single-free-tier/donation-only central law.
- Hardened File 00 claims with actor/action/object/purpose binding, short expiry and fail-closed institutional-role separation.
- Hardened runtime locks, mutation idempotency, replay/recovery receipts and serialized rate limiting.
- Suppressed only the expected duplicate-key database log noise used by idempotency reservation/outbox deduplication while preserving duplicate detection and fail-closed handling for all other storage failures.
- Added complete bounded audit-chain verification and recursive sensitive-context redaction.
- Added durable event privacy classification, bounded payloads, stale lease recovery, retries/dead-letter and reconciliation evidence.
- Added exact schema/index verification, dbDelta-compatible index declarations and evidence-gated schema upgrades.
- Added bounded manifests, contracts, redirects and dependency lists; rejected duplicate and required/optional ambiguous dependencies.
- Added fail-closed feature activation requiring dependency readiness and verified migration, health, rollback and gate evidence.
- Bound legacy reconciliation to File 20/21, exact owner/version receipts and exact metadata rollback.
- Hardened destructive purge with File 24/backup evidence, atomic multi-table quarantine, stale-quarantine blocking and terminal idempotent receipt replay.
- Updated software, schema and contract versions to 1.2.0 and expanded runtime/source/schema adversarial tests.

## 1.1.0 — Earlier corrective baseline

- Introduced structured authorization, release lifecycle, idempotency, compensation, privacy, reconciliation, purge governance, runtime CI and deterministic packaging foundations.

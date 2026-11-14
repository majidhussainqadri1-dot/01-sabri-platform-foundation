## 2026-11-14 V12.3 FR-001 authorized amendment-chain fixture (repository candidate only)

- Added 7 synthetic WordPress/MySQL assertions and fixed WP-CLI test-scope error observed in Run #937. Exact-case Run #938 passed 4/4.
- Date-scoped V12.3 grade changed from source-only S to disposable runtime R for FR-001, with no independent semantic/Hostinger/Live acceptance and no change to real Founder governance.
- File 01 runtime source not modified by this evidence-only change.

## 2026-11-14 V12.3 telemetry label provenance hardening (Draft PR only)

- Negative runtime Run #935 proved silent normalized label collisions and loss of the 13th label: 3 invalid label maps, 6 rejection/no-write assertions.
- Reject colliding/noncanonical/oversized label keys and >12 entries before telemetry option writes. Sensitive label-key omission remains in force; no privilege, DB, or deployment action.
- Source-only; operational monitoring, real File 24 assurance and Live acceptance remain pending.

## 2026-11-14 V12.3 telemetry finite-value and canonical-identity rejection (Draft PR only)

- Added five negative telemetry cases and five no-buffer-mutation assertions in disposable WordPress/MySQL testing. Pre-fix Run #933 demonstrated nonfinite/invalid metric acceptance and mutated buffers.
- Restrict File 01 metric names to existing canonical strings <=80 bytes, and reject non-finite numeric values, NaN, Infinity and overflow input before locks/option writes. The fix does not alter WordPress schema, external ownership, or deploy code.
- Operational acceptance and exact deployed Hostinger parity remain independent No-Go gates.

## 2026-11-14 V12.3 requirements-baseline reconciliation (Draft PR only)

- Record the Founder-governing V12.3 plan separately from historic V12 and the old File 01 planning ID. This is source/release-policy traceability only; software/schema/contract constants are unchanged.
- Add explicit release-handoff regression assertions for V12.3 source identity and M11/UX-13/QA-24/CEN-REL-001 acceptance boundaries.
- Add the 43-row evidence ledger with independent semantic, companion, staging and live gates still open. Live read-only access was blocked (disconnected Jetpack and WPVibe HTTP 530), so no production root-cause or resolution is asserted.
- A separate follow-up, after negative regression assertions, tightens **source-level release evidence**: staged requires a freeze receipt and deployed requires explicit true parity, matching the pre-existing evidence template. Disposable WordPress fixtures were updated. There is no DB migration, production deployment or File 24 independent attestation. No Live defect resolution is claimed.

# Changelog

## 2.0.1 — Live-first reconciliation safety patch (2026-08-11)

- Closed the live-discovered Safe Repair bypass in which `spf_page_map` and `spf_founder_user_id` could be removed by owner-scoped repair while the guarded Reconciler correctly remained blocked on missing File 20/File 21 owner acknowledgement.
- Made those legacy options exclusively owned by `SPF_Reconciler`; Safe Repair now reports `legacy_reconciliation_required` warnings and defensively rejects forged/stale legacy-option deletion actions.
- Preserved canonical ownership: File 20 acknowledges shell route/navigation handoff, File 21 acknowledges Home/News content handoff, and File 01 never mutates companion records directly.
- Added real WordPress/MySQL cross-file plan/apply/rollback acceptance with exact File 20/File 21 candidate SHA pinning, 14 reversible owner receipts, quarantine-not-delete behavior and exact snapshot restoration.
- Kept schema at 1.2.0 and File 01 contract/Future Foundation versions at 2.0.0; this is a software patch release only.
- Repository/package evidence remains separate from Staging-Accepted, Live-Deployed and Operational status.

## 2.0.0 — Future Foundation superset and corrective hardening (2026-08-11)

- Added the approved Future Foundation 18-enhancement control-plane/resilience layer.
- Hardened exact table/lock truth, registry normalization, cron evidence, event payloads, generated CI, configuration drift, SLO/rings, event schemas, release evidence and self-heal recovery/compensation.
- Added repeated fresh corrective review suites, exact-head runtime regression checks and deterministic packaging.
- Hardened release lifecycle truth so `staged` is not represented as Staging-Accepted and Operational requires exact deployed release/package plus health/monitoring/support/backup/SLO evidence.
- Bound explicit event dedupe keys to event/version/aggregate/privacy identity while retaining exact legacy replay compatibility.
- Bound migration backup evidence to module/from/to/environment and destructive purge evidence to the exact purge plan/evidence chain.
- Rejected noncanonical/duplicate architecture declarations instead of silently collapsing them.
- Corrected current release/staging handoff documents, WordPress stable tag and CycloneDX SBOM so software 2.0.0, schema 1.2.0 and contract 2.0.0 remain distinct and machine-checked.
- Added Fourteenth-cycle fail-closed identity/evidence hardening, documented contract filters/pagination, and mutation-grade authorization for persisted System Check evidence.
- Historical 1.2.0 and earlier review records are retained as historical evidence; they are not current-head claims.

## 1.2.0 — Earlier corrective baseline

- Aligned File 01 with 00–26 governance, File 20/21/24/25/26 ownership boundaries, and the single-free-tier/donation-only central law.
- Hardened File 00 claims with actor/action/object/purpose binding, short expiry and fail-closed institutional-role separation.
- Hardened runtime locks, mutation idempotency, replay/recovery receipts and serialized rate limiting.
- Added bounded audit-chain verification, event privacy classification, stale-lease recovery, exact schema/index verification, bounded registries/contracts, evidence-gated feature activation, owner/version reconciliation receipts and guarded destructive purge.
- This historical baseline used software/schema/contract version 1.2.0.

## 1.1.0 — Earlier corrective baseline

- Introduced structured authorization, release lifecycle, idempotency, compensation, privacy, reconciliation, purge governance, runtime CI and deterministic packaging foundations.

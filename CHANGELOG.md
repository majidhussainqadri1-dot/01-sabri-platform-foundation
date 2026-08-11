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

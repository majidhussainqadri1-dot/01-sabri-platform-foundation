# File 01 — Sabri Platform Foundation 1.2.0

This repository implements **File 01-B**, the governance/runtime foundation defined by `SSH-F01-PLAN-2026-v1.0`, the Definitive Master Plan v3.0, the recovered directives through 5 August 2026, and the later 6–7 August central-plan harmonization.

## Canonical boundary

File 01 owns the 00–26 governance catalog, module/contract/route registries, dependency readiness, safe activation/upgrade conventions, redacted System Check, release evidence, File 01 privacy lifecycle, legacy reconciliation orchestration, owner-scoped repair, feature-flag governance, event/outbox foundation, and guarded purge governance. It does **not** own membership identity (File 00), global shell/navigation (File 20), Home/News/feed (File 21), Security Center assurance (File 24), public visual/profile experience (File 25), or search/discovery/ranking truth (File 26).

## Release truth

Version **1.2.0** is the final repository coding candidate for the reviewed File 01 scope. It uses software/schema/contract version 1.2.0. The historical encoded 1.1.1 recovery stream was not reproducible and is not used as release evidence; the 1.2.0 source was independently hardened and reviewed.

The current source implements the later central requirements: versioned/machine-readable 00–26 governance and rollback-aware reconciliation; event schema/version/idempotency/replay/dead-letter/reconciliation with durable privacy classification; and fail-closed feature activation requiring dependency readiness plus independently verified migration, health, rollback and gate evidence.

## Security and reliability highlights

- Structured, short-lived File 00 authorization claims bound to actor, action, object, purpose and institutional role.
- No generic Administrator release, Founder or purge authority.
- Atomic idempotency reservation, replay/recovery receipts and serialized mutation throttling.
- Audit-chain integrity verification, bounded redaction and fail-closed tamper handling.
- Bounded outbox payloads, privacy classification, stale-lease recovery, retries and dead-letter handling.
- Shadow-table activation/upgrade compensation plus exact required-index verification.
- Bounded immutable contracts/manifests/routes and deterministic dependency rejection.
- Version-bound File 20/21 reconciliation receipts with exact rollback restoration.
- Independent backup/restore and File 24 assurance before destructive purge; atomic quarantine and terminal replay.
- One free tier / donation-only governance metadata with no donor advantage.

`Staging-Accepted`, `Live-Deployed`, and `Operational` remain separate external statuses. See `TRACEABILITY.md`, `QA-REPORT.md`, `STAGING-ACCEPTANCE.md`, `RELEASE-CHECKLIST.md`, and the two final review records.

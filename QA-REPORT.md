# QA Report — File 01 2.0.0 Repository Candidate

## Automated scope

The exact-head workflow covers PHP 8.1/8.3 lint and source assertions; structured authorization and separation-of-duties tests; manifest/contract/dependency/route bounds; schema plus exact-index tests; disposable WordPress/MySQL activation/reactivation/upgrade/runtime tests; release lifecycle and feature activation evidence; privacy/audit/reconciliation; concurrent idempotency; destructive-purge smoke; Future Foundation runtime checks; release-handoff/current-metadata consistency; and deterministic double-build/package integrity.

The 2.0.0 suite inherits the 1.2.0 security and schema regressions and adds the later Future Foundation and corrective-cycle assertions. Current-facing release documents, WordPress stable tag and CycloneDX component identity are checked against `SPF_VERSION`, `SPF_SCHEMA_VERSION` and `SPF_CONTRACT_VERSION` so stale release metadata fails CI.

## Review discipline

Every corrective change is followed by regression testing. Historical review records remain evidence of their exact historical commits; they are not silently relabeled as current-head evidence. The current fresh ten-round review has its own permanent record and exact-head CI evidence.

## Truth boundary

A green GitHub run proves only the automated environment and exact commit/package that it identifies. Hostinger staging, real companion coexistence, browser/device/accessibility/RTL, production-size load/soak, independent backup/restore/rollback, Founder acceptance, live deployment and sustained monitoring remain separate external gates.

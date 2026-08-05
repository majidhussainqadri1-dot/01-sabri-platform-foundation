# QA Report — File 01 1.1.0 Corrective Candidate

## Automated suites

- PHP syntax on PHP 8.1 and 8.3.
- Pure unit tests for canonical hashing, semantic versions, structured claims, separation of duties, release transitions and evidence validation.
- Source-security tests for removed bypasses, dangerous primitives, ownership boundaries and purge assurance.
- Schema tests for all 14 InnoDB tables, keys, locks, evidence, privacy and migration fields.
- Contract/traceability tests for every F01-FR-001–012 and F01-NFR-001–010.
- Actual WordPress/MySQL fresh activation, reactivation, schema verification, capabilities, manifests, contracts, routes, release lifecycle, flags, privacy, reconciliation and System Check.
- Concurrent identical mutation test proving one callback execution.
- Upgrade/rollback and destructive-purge smoke tests in disposable CI databases.
- Deterministic double-build, source manifest, ZIP integrity and package/source parity.

## Truth boundary

Green CI proves only the automated environment and exact commit. Hostinger staging, real cross-file adapters, browser/accessibility/RTL, production-size load, backup/restore, Founder approval and live monitoring remain mandatory.

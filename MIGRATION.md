# Migration — supported historical software to 2.0.1 (schema 1.2.0)

Software, schema and contract versions are distinct. The current candidate is software `2.0.1`, schema `1.2.0`, contract `2.0.0`; do not describe an upgrade to schema 1.2.0 as a software 1.2.0 release.

1. Freeze the exact staging reality before change: deployed plugin/package/checksum, database/schema version, migration/options state, relevant table shape/counts, runtime logs/configuration and active companion versions.
2. Create and independently verify a complete database/files backup and restore proof before an upgrade.
3. Supply structured `spf_verify_migration_backup_evidence`; a string, checkbox or operator assertion is not sufficient evidence.
4. Upgrade only on isolated staging. File 01 takes shadow snapshots of its existing tables/options/capabilities/schedules and restores them on a failed schema upgrade.
5. Schema 1.2.0 preserves the 1.1.x governance tables and additively requires durable outbox `privacy_class`, exact required-index verification, InnoDB verification, and current release/idempotency/privacy/migration structures.
6. File 01 seeds only its own real manifest/contracts/routes and the 00–26 governance catalog/amendments. Canonical owner modules register their own real manifests.
7. File 01 optional dependency metadata reflects current integration boundaries in `DEPENDENCY-MANIFEST.json`. Missing/incompatible dependencies fail closed for dependent activation paths.
8. Run System Check, schema/index verification, fresh/upgrade/reactivation tests, File 00 claims, File 20/21 reconciliation dry-run, File 24 assurance, current File 26 boundary checks, feature activation evidence, cache/cron/queue checks and rollback.
9. Do not apply legacy reconciliation until File 20/21 return accepted, version-matched, reversible owner receipts.
10. After upgrade, capture exact deployed software/package checksum, schema version and migration state; compare them with the selected GitHub artifact before any Staging-Accepted decision.

Production cutover remains prohibited until `STAGING-ACCEPTANCE.md` is fully evidenced and Founder-approved.

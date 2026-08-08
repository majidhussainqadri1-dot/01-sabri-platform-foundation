# Migration — historical 0.1.0 / 1.1.x to 1.2.0

1. Create and independently verify a complete database/files backup and restore proof before an upgrade.
2. Supply structured `spf_verify_migration_backup_evidence`; a string, checkbox or operator assertion is not sufficient evidence.
3. Upgrade only on isolated staging. File 01 takes shadow snapshots of its existing tables/options/capabilities/schedules and restores them on a failed schema upgrade.
4. Schema 1.2.0 preserves the 1.1.x governance tables and additively requires durable outbox `privacy_class`, exact required-index verification, InnoDB verification, and current release/idempotency/privacy/migration structures.
5. File 01 seeds only its own real manifest/contracts/routes and the 00–26 governance catalog/amendments. Canonical owner modules register their own real manifests.
6. File 01 optional dependency metadata now reflects current integration boundaries: File 00, File 20, File 21, File 24 and File 26. Missing/incompatible dependencies fail closed for dependent activation paths.
7. Run System Check, schema/index verification, fresh/upgrade/reactivation tests, File 00 claims, File 20/21 reconciliation dry-run, File 24 assurance, feature activation evidence, cache/cron/queue checks and rollback.
8. Do not apply legacy reconciliation until File 20/21 return accepted, version-matched, reversible owner receipts.

Production cutover remains prohibited until `STAGING-ACCEPTANCE.md` is fully evidenced and Founder-approved.

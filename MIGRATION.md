# Migration — 1.0.0 to 1.1.0

1. Create and verify a complete database/files backup; perform a restore test in the same staging environment.
2. Supply structured `spf_verify_migration_backup_evidence` from the authorized operations/File 24 assurance adapter. A plain string or checkbox is not evidence.
3. Activate/update on isolated staging. The upgrader creates shadow copies of every pre-existing File 01 table and snapshots File 01 options, Administrator bootstrap capabilities and scheduled jobs.
4. Schema 1.1.0 adds privacy-request, legal-hold and migration ledgers; strengthens release, idempotency and evidence columns; and verifies InnoDB.
5. File 01 seeds only its own real manifest/contracts/routes. Files 00–26 must register their real owner manifests.
6. Run System Check, migration/rollback tests, File 00 authorization claims, File 20/21/24/25 contracts and reconciliation dry-run.
7. Do not apply legacy reconciliation until File 20/21 owner adapters return accepted plans and reversible receipts.

On any failed upgrade, the shadow snapshot is restored. Production cutover remains prohibited until `STAGING-ACCEPTANCE.md` is fully evidenced and approved.

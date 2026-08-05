# Migration and Legacy Reconciliation

1. Back up database, files and configuration; restore the backup in an isolated environment.
2. Install 1.0.0 on Hostinger staging.
3. Run System Check.
4. Review the reconciliation dry run and its SHA-256 plan hash.
5. Apply only with the exact confirmation `APPLY FILE 01 RECONCILIATION`.
6. File 01 removes its unsafe legacy Founder option, removes its obsolete route map, and marks only pages bearing `_spf_managed_page=1` as quarantined. It does not delete pages or touch companion data.
7. Confirm File 20 owns shell/navigation and File 21 owns Home/News before public cutover.
8. Use the stored reconciliation snapshot for rollback if acceptance fails.

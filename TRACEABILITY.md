# File 01 Requirements-to-Code Traceability

| Requirement | Implementation | Automated evidence | External acceptance |
|---|---|---|---|
| F01-FR-001 Master Constitution Registry | `SPF_Installer::seed_governance`, `SPF_Governance::record_amendment` | contract/source/runtime tests | Founder change-control review |
| F01-FR-002 Module Manifest Registry | `SPF_Registry::register_manifest`, real File 01 manifest, canonical catalog | runtime manifest/schema tests | real owner manifests 00–26 |
| F01-FR-003 Dependency Resolver | `SPF_Dependency_Resolver` | unit/runtime readiness tests | companion version matrix |
| F01-FR-004 Foundational Route Registry | `SPF_Registry::map_route` | route collision/same-origin/runtime tests | File 20 placement |
| F01-FR-005 Safe Activation Protocol | `SPF_Installer::activate`, shadow snapshots, lock, compensation | activation/runtime/failure-point tests | Hostinger fresh install |
| F01-FR-006 System Check Contract | `SPF_System_Check` | runtime MySQL/cron/cache/audit tests | provider/SMTP/Hostinger evidence |
| F01-FR-007 Legacy Reconciliation | `SPF_Reconciler` owner plans/receipts/exact rollback | runtime adapter/metadata rollback tests | real File 20/21 adapters and sampled data |
| F01-FR-008 Contract Versioning | contract registry, acknowledgements, deprecation event | contract/runtime tests | consumer acknowledgements |
| F01-FR-009 Safe Repair | `SPF_Repair` lock/dry-run/owner scope/compensation | source/runtime repair tests | staging operator acceptance |
| F01-FR-010 Release Evidence Manifest | `SPF_Governance` state/evidence ledger | unit/runtime lifecycle tests | staging/Founder/production evidence |
| F01-FR-011 Feature-Flag Registry | set/evaluate/expire/reconcile events | runtime flag tests | production kill-switch rehearsal |
| F01-FR-012 Uninstall/Purge Governance | non-destructive uninstall; `SPF_Purge` evidence gates/quarantine | source/runtime purge tests | independent File 24/restore proof |
| F01-NFR-001 Object/Field Authorization | structured File 00 claims, SoD, fail closed | unit/runtime authorization tests | real File 00 contract |
| F01-NFR-002 Privacy Lifecycle | exporter/eraser/holds/retention | runtime privacy tests | policy/legal review |
| F01-NFR-003 Reliability | InnoDB, transactions, locks, idempotency, outbox/dead letter | runtime/concurrency tests | outage/soak tests |
| F01-NFR-004 Performance | bounded lists, indexes, background jobs | query/static checks | production-size p75/p95 load test |
| F01-NFR-005 Accessibility | semantic admin controls/no public UI ownership | source/runtime markup checks | browser/screen-reader/zoom/RTL |
| F01-NFR-006 Observability | redacted health, audit, outbox, reason codes | runtime system check | File 24 dashboards/alerts |
| F01-NFR-007 Migration/Rollback | evidence-gated shadow upgrade and reconciliation rollback | runtime upgrade/rollback tests | full Hostinger drill |
| F01-NFR-008 Operability | System Check, repair, flags, queues, runbooks | runtime/operational tests | operator rehearsal |
| F01-NFR-009 Compatibility | PHP 8.1/8.3 and live WordPress/MySQL CI | matrix CI | actual WordPress 7.0.1/PHP 8.3.30 recheck |
| F01-NFR-010 Localization | translatable strings, no second visual UI, UTC evidence | source tests | Urdu/Arabic/RTL staging acceptance |

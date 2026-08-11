# File 01 Requirements-to-Code Traceability — Software 2.0.0 / Schema 1.2.0 / Contract 2.0.0

| Requirement | Implementation | Automated evidence | External acceptance |
|---|---|---|---|
| F01-FR-001 Master Constitution Registry | `SPF_Installer::seed_governance`, `SPF_Governance::record_amendment` | source/runtime/contract tests | Founder change-control review |
| F01-FR-002 Module Manifest Registry | `SPF_Registry::register_manifest`, current File 01 manifest, canonical 00–26 catalog | manifest/schema/runtime tests | real owner manifests 00–26 |
| F01-FR-003 Dependency Resolver | `SPF_Dependency_Resolver`, bounded/ambiguous dependency rejection | unit/runtime readiness tests | companion version matrix |
| F01-FR-004 Foundational Route Registry | `SPF_Registry::map_route`, bounded redirects, same-origin rules | route/runtime tests | File 20 placement |
| F01-FR-005 Safe Activation Protocol | `SPF_Installer::activate/maybe_upgrade`, locks, shadow snapshots, compensation | activation/upgrade/runtime tests | Hostinger fresh/upgrade drill |
| F01-FR-006 System Check Contract | `SPF_System_Check` | runtime MySQL/cron/cache/audit tests | provider/Hostinger evidence |
| F01-FR-007 Legacy Reconciliation | `SPF_Reconciler`, File 20/21 owner/version receipts, exact rollback | runtime reconciliation tests | real File 20/21 adapters |
| F01-FR-008 Contract Versioning | immutable contract registry, acknowledgements/deprecation | contract/runtime tests | consumer acknowledgements |
| F01-FR-009 Safe Repair | `SPF_Repair` lock/dry-run/owner scope/compensation | source/runtime tests | staging operator acceptance |
| F01-FR-010 Release Evidence Manifest | `SPF_Governance` evidence state machine | unit/runtime lifecycle tests | staging/Founder/production evidence |
| F01-FR-011 Feature-Flag Registry | dependency readiness + migration/health/rollback/gate evidence on enable | source/runtime negative+positive tests | production kill-switch rehearsal |
| F01-FR-012 Uninstall/Purge Governance | non-destructive uninstall; evidence-gated atomic quarantine purge | source/runtime purge tests | File 24/restore proof |
| F01-NFR-001 Authorization | actor/action/object/purpose/institutional-role File 00 claims | unit/runtime authorization tests | real File 00 contract |
| F01-NFR-002 Privacy | exporter/eraser/holds/retention + event privacy class | source/runtime privacy tests | policy/legal review |
| F01-NFR-003 Reliability | InnoDB, exact indexes, transactions, locks, idempotency, outbox/dead letter | schema/runtime/concurrency tests | outage/soak tests |
| F01-NFR-004 Performance | bounded lists/contracts/manifests/routes; indexes/background jobs | source/schema tests | production-size p75/p95 load test |
| F01-NFR-005 Accessibility | semantic admin controls; no public UI ownership | source/runtime checks | browser/screen-reader/zoom/RTL |
| F01-NFR-006 Observability | redacted health, audit, outbox, reason codes | runtime System Check | File 24 dashboards/alerts |
| F01-NFR-007 Migration/Rollback | evidence-gated shadow upgrade and exact reconciliation rollback | runtime upgrade/rollback tests | full Hostinger drill |
| F01-NFR-008 Operability | System Check, repair, flags, queues, runbooks | runtime/operational tests | operator rehearsal |
| F01-NFR-009 Compatibility | PHP 8.1/8.3 + WordPress/MySQL CI | matrix/runtime CI | actual installed-stack recheck |
| F01-NFR-010 Localization | translatable strings/no second visual UI/UTC evidence | source tests | Urdu/Arabic/RTL staging |
| F01-CEN-01 00–26 Governed Registry | current catalog, versioned manifests/contracts/routes, schema/index integrity, migration/reconciliation/rollback | source/schema/runtime tests | real owner registry coexistence |
| F01-CEN-02 Event Backbone | versioned events, idempotent dedupe, bounded payload, privacy class, replay/retry/dead-letter/reconciliation | source/runtime/concurrency tests | real downstream consumer drills |
| F01-CEN-03 Activation Gate | enable-time dependency readiness + verified migration/health/rollback/gate evidence | runtime negative/positive flag tests | staging activation evidence |
| F01-FUT-01..18 Future Foundation | `SPF_Future_Foundation`, governance control plane, platform engineering and resilience lab | Future Foundation unit/runtime/review suites | real environment/provider/operational evidence where applicable |

Every current release trace must bind requirement → implementation → test/result → defect/fix where applicable → exact commit → deterministic package/checksum → staging evidence → Founder approval. Historical documents remain historical evidence only.

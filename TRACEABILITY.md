# File 01 Plan-to-Code Traceability

Governing specification: `SSH-F01-PLAN-2026-v1.0`

| Requirement | Implementation | Automated evidence |
|---|---|---|
| F01-FR-001 Master constitution registry | `SPF_Installer::seed_governance()` and `spf_amendments` | `tests/contract-tests.php` |
| F01-FR-002 Module manifest registry | `SPF_Registry::register_manifest()` and `spf_modules` | contract/schema tests |
| F01-FR-003 Dependency resolution | `SPF_Dependency_Resolver` | dependency static tests |
| F01-FR-004 Foundational route registry | `SPF_Registry::map_route()` and `spf_routes` | collision/owner boundary tests |
| F01-FR-005 Safe activation protocol | `SPF_Installer` lock, snapshot, compensation, audit | security/schema tests |
| F01-FR-006 System Check contract | `SPF_System_Check` and `spf_health` | contract tests |
| F01-FR-007 Legacy reconciliation | `SPF_Reconciler` dry-run/apply/rollback | boundary tests |
| F01-FR-008 Contract versioning | `SPF_Registry::register_contract()/acknowledge_contract()` and `spf_contracts` | semver/schema tests |
| F01-FR-009 Safe repair | `SPF_Repair` dry-run/hash/confirmation | boundary tests |
| F01-FR-010 Release evidence manifest | `SPF_Governance::record_release()/transition_release()`, immutable checksum and append-only `spf_release_states` | release tests |
| F01-FR-011 Feature-flag registry | `SPF_Governance::set_flag()` | availability-not-authorization test |
| F01-FR-012 Uninstall/purge governance | non-destructive `uninstall.php`; guarded `SPF_Purge` | purge guard tests |
| F01-NFR-001 Authorization | `SPF_Authorization`; REST/admin permissions | security tests |
| F01-NFR-002 Privacy lifecycle | DTO allowlists, redacted health/audit, no PII registry | privacy static tests |
| F01-NFR-003 Reliability | outbox, dedupe, retry/dead-letter, idempotency | security/contract tests |
| F01-NFR-004 Performance | bounded lists, cached probes, no public rendering | static tests |
| F01-NFR-005 Accessibility | wp-admin native semantics; no competing public UI | ownership tests; staging manual |
| F01-NFR-006 Observability | audit trace IDs, health snapshots, event outbox | contract tests |
| F01-NFR-007 Migration/rollback | activation and reconciliation snapshots; rollback | schema/boundary tests |
| F01-NFR-008 Operability | System Check, reconciliation, repair, purge plan | contract tests |
| F01-NFR-009 Compatibility | WP 6+/PHP 8.1+ metadata; CI PHP 8.1/8.3 | GitHub Actions |
| F01-NFR-010 Localization | text domain and translatable admin/API messages | static tests |

## External acceptance boundary

Hostinger staging, active-theme coexistence, File 00/20/24/25 real contracts, LiteSpeed behavior, backup/restore, browser/accessibility and Founder acceptance require the real staging environment and cannot be proved by repository-only automation.

# QA Report — File 01 2.0.2 Repository Candidate

## Automated scope

The exact-head workflow covers PHP 8.1/8.3 lint and source assertions; structured authorization and separation-of-duties tests; manifest/contract/dependency/route bounds; schema plus exact-index tests; disposable WordPress/MySQL activation/reactivation/upgrade/runtime tests; release lifecycle and feature activation evidence; privacy/audit/reconciliation; concurrent idempotency; destructive-purge smoke; Future Foundation runtime checks; release-handoff/current-metadata consistency; and deterministic double-build/package integrity.

The 2.0.2 suite inherits the 1.2.0 security and schema regressions and adds the later Future Foundation and corrective-cycle assertions. Current-facing release documents, WordPress stable tag and CycloneDX component identity are checked against `SPF_VERSION`, `SPF_SCHEMA_VERSION` and `SPF_CONTRACT_VERSION` so stale release metadata fails CI.

## Review discipline

Every corrective change is followed by regression testing. Historical review records remain evidence of their exact historical commits; they are not silently relabeled as current-head evidence. The current Fourteenth fresh ten-round review has its own permanent record and exact-head CI evidence; exact identity, evidence-meaningfulness, filtered contract-query and persistent System Check authorization regressions are explicitly gated.

## Truth boundary

A green GitHub run proves only the automated environment and exact commit/package that it identifies. Hostinger staging, real companion coexistence, browser/device/accessibility/RTL, production-size load/soak, independent backup/restore/rollback, Founder acceptance, live deployment and sustained monitoring remain separate external gates.

## 2026-11-26 — authenticated production parity and File00 contract handoff

Production observed through authenticated read-only WPVibe: WP 7.1.5 / PHP 8.3.37 / WPVibe 1.24.3. Six plugins active. File01-B 2.0.1, schema 1.2.0, public contract 2.0.0, 14 InnoDB tables, zero migration and release rows. Active package directory is labeled STAGING-ONLY-NOT-FOR-LIVE. Historical 22 Nov health: 24 pass / 10 warnings / 0 fail, not fresh acceptance. WordPress core 3,338 files verified; Hostinger Reach and Tools official checksum lists report missing files, cause unconfirmed. Four vendor updates offered. Public home renders initial WordPress post. File00/02/06/24 absent, 43 DB tables overall. No live mutation.

### Visible source comparison against exact main adf6dbb9980a85f25f7cf4c2ee679b52703c2e71

WPVibe listed 27 permitted source files (not a complete artifact inventory). 25 compared, 2 HTTP429; no bypass. Normalized text comparison is not byte-exact checksum, masked lines explicitly excluded. 11 visible differences: DEPENDENCY-MANIFEST.json, authorization, dependency-resolver, future-foundation, governance-control-plane, governance, installer, platform-engineering (partial read), registry, resilience-lab, system-check. Four text matches: RELEASE-EVIDENCE-TEMPLATE.json, SBOM.cdx.json, privacy, readme.txt. Ten unmasked text matches: admin, audit, event-bus, idempotency, plugin, purge, reconciler, repair, rest, runtime. Two blocked reads: plugin entry and uninstall. Entry header was previously observed, not compared here. Authorization deployed visible text matched this exact candidate 3ccf4be2a205527c8ff5c713aa1c1fcf14cd36b9, not main; whole-artifact parity remains unverified. Repository 2.0.2 is not live 2.0.1.

### File00 integration

File00 PR66 baseline 2239fb8aad19100da9c59bbb23d5e42832b49c6d: 13/13 green, synthetic File02/06/24 consent and isolated backup/restore; artifact 15076793901. File01 candidate 3ccf4be2a205527c8ff5c713aa1c1fcf14cd36b9 CI 45107324810 green. File00 provider lacked six actual consumer actions, a single fail-closed source contract gap, not six live defects. Draft File00 correction 81e9b60af792f89a08e218daa2fe0d663374ec20 explicitly maps register_manifest, register_contract, acknowledge_contract, map_route, repair_owned_mapping, set_flag to manage_sabri_foundation under the existing eligible canonical Founder-only mutation policy. Ordinary admin diagnostic-only; blocked Founder, member, guest, unknown action remain denied. New provider unit and five-owner disposable exact consumer gate added; await fresh exact-head results. No unattended owner manifests, actual mutation lifecycle or new institutional authority independently accepted. File01 code unchanged in this report-only update.

### Hold

No live backup/restore, staging access, custom package installation or exact artifact checksum available through current connection. No merge/deploy. Complete Hostinger backup, staged restore, approved role policy, all-owner acceptance and fresh independent review remain gates. Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

### Exact-head QA follow-up

File01 report-only head 9e14ecf9fbcf33afe818c44af33a53983fba70a7: WordPress/MySQL QA passed, PHP 8.1/8.3 source suites reached checksum validation then failed at line 22 because this report changed without recalculating its recorded hash. Corrected the single QA-REPORT.md checksum atomically with this report. No product code change or weakened checksum gate.

File00 head 81e9b60af792f89a08e218daa2fe0d663374ec20: 12/13 workflows green, four-owner consent and isolated restore passed; fifth-owner activation rejected because WP-CLI had no authenticated current actor and File01 correctly requires activate_plugins. File00 test harness corrected at f2470bf69a83a9dad7a174c52f21b50a6fe64cc9 to assert and use the disposable Founder --user=1. No File01 guard changed. Fresh CI needed.

Former staging hostname sabrisocialstaging.sabrihomeopathy.com returned HTTP 530 via WPVibe; failing hop/cause unknown. No security bypass. No Hostinger backup or custom ZIP installation tool found in current connector discovery. No site changes.

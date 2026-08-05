=== Sabri Platform Foundation ===
Contributors: majidhussainqadri1-dot
Requires at least: 6.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later

Canonical File 01-B governance, registry, route, privacy, health, reconciliation, repair and release-evidence runtime for the Sabri Social Homeopathy Platform.

== Description ==

This plugin is a governance and compatibility plane. It does not create a second public shell, feed, profile, identity system, Security Center or search backend.

Version 1.1.0 corrects authorization, release lifecycle, idempotency, activation compensation, reconciliation rollback, privacy lifecycle, feature-flag operations, purge assurance and QA depth. Production use still requires the separate staging and Founder-acceptance gates recorded in STAGING-ACCEPTANCE.md.

== Installation ==

1. Verify the package SHA-256 and SOURCE-MANIFEST.sha256.
2. Install only on isolated staging after a verified backup and restore test.
3. Activate as an authorized operator.
4. Run the redacted System Check.
5. Register real owner manifests from companion modules; do not create placeholder manifests.
6. Complete STAGING-ACCEPTANCE.md before any production approval.

== Changelog ==

= 1.1.0 =
* Enforced structured File 00 claims and separation of duties.
* Added atomic idempotency, privacy lifecycle and operational feature flags.
* Added shadow-table activation/upgrade compensation.
* Added evidence-enforced release lifecycle and independent destructive-purge assurance.
* Added WordPress/MySQL runtime, concurrency, migration and deterministic package CI.

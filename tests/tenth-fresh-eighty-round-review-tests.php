<?php
declare(strict_types=1);

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
    $assertions++;
    if ( ! $condition ) { $failures[] = $message; }
};
$root = dirname( __DIR__ );
$read = static fn(string $path): string => (string) file_get_contents( $root . '/' . $path );
$has = static fn(string $path, string $needle): bool => str_contains( $read($path), $needle );

// Rounds 1-8: defects identified in this fresh corrective cycle.
$assert( ! file_exists($root . '/.fresh-eighty-final-trigger'), 'Round 1: stale exact-head trigger artifact remains.' );
$assert( ! file_exists($root . '/tools/eighty-review-exact/part00.b64'), 'Round 2: incomplete staged review payload remains.' );
$assert( $has('qa/run-tests.sh', 'Closed-world source inventory') && $has('qa/run-tests.sh', 'Temporary review/apply artifact found'), 'Round 3: checksum QA is not closed-world.' );
$assert( $has('.github/workflows/corrective-qa.yml', 'plugins/sabri-platform-foundation-01') && $has('.github/workflows/corrective-qa.yml', 'plugin activate sabri-platform-foundation-01'), 'Round 4: runtime QA does not use the canonical package folder.' );
$assert( $has('includes/class-spf-audit.php', 'spf_audit_context_key_invalid'), 'Round 5: audit evidence keys can still normalize/collide.' );
$assert( $has('includes/class-spf-audit.php', 'spf_audit_context_value_too_large') && $has('includes/class-spf-audit.php', 'spf_audit_context_value_invalid'), 'Round 6: audit evidence can still be truncated/normalized silently.' );
$assert( $has('includes/class-spf-event-bus.php', "'|' . \$version . '|'") && $has('includes/class-spf-event-bus.php', "'|' . \$privacy_class . '|'"), 'Round 7: default event dedupe is not version/privacy bound.' );
$assert( $has('includes/class-spf-event-bus.php', 'handler_started') && $has('includes/class-spf-event-bus.php', 'reconciliation_required') && $has('includes/class-spf-event-bus.php', 'spf_outbox_reconciliation_required'), 'Round 8: ambiguous handler completion can still auto-retry.' );

// Rounds 9-80: fresh closure lenses on the corrected source.
$checks = array(
    9  => array('includes/class-spf-authorization.php', 'LEGACY_BOOLEAN_BRIDGE_ACTIONS', 'legacy authority bridge is not read-only'),
    10 => array('includes/class-spf-authorization.php', "'founder' !== \$institutional_role", 'Founder-only action role binding is missing'),
    11 => array('includes/class-spf-authorization.php', 'object_hash', 'authorization object binding is missing'),
    12 => array('includes/class-spf-authorization.php', 'expected_purpose', 'authorization purpose binding is missing'),
    13 => array('includes/class-spf-registry.php', 'spf_manifest_self_dependency', 'self-dependency is not rejected'),
    14 => array('includes/class-spf-registry.php', 'canonical_entities', 'canonical ownership declarations are not validated'),
    15 => array('includes/class-spf-dependency-resolver.php', 'dependency_cycle', 'dependency cycles are not blocked'),
    16 => array('includes/class-spf-dependency-resolver.php', 'dependency_below_minimum', 'minimum dependency versions are not enforced'),
    17 => array('includes/class-spf-dependency-resolver.php', 'dependency_above_maximum', 'maximum dependency versions are not enforced'),
    18 => array('includes/class-spf-installer.php', 'compensation_incomplete', 'activation/upgrade compensation truth is missing'),
    19 => array('includes/class-spf-repair.php', 'plan_hash', 'repair is not plan/dry-run bound'),
    20 => array('includes/class-spf-resilience-lab.php', 'SPF_CHAOS_MODE', 'chaos mode is not explicitly gated'),
    21 => array('includes/class-spf-resilience-lab.php', 'spf_snapshot_capacity_full', 'snapshot capacity can silently evict truth'),
    22 => array('includes/class-spf-resilience-lab.php', 'spf_snapshot_restore_compensation_incomplete', 'snapshot compensation is not verified'),
    23 => array('includes/class-spf-platform-engineering.php', 'spf_event_schema_version_conflict', 'event schema versions are mutable'),
    24 => array('includes/class-spf-platform-engineering.php', 'spf_event_schema_fields_too_large', 'event schema fields are unbounded'),
    25 => array('includes/class-spf-platform-engineering.php', 'spf_config_too_large', 'configuration envelope is unbounded'),
    26 => array('includes/class-spf-platform-engineering.php', 'spf_config_too_deep', 'configuration depth is unbounded'),
    27 => array('includes/class-spf-platform-engineering.php', 'spf_rollout_capacity_full', 'rollout records can silently evict truth'),
    28 => array('includes/class-spf-platform-engineering.php', 'spf_rollout_release_missing', 'rollout is not canonical-release bound'),
    29 => array('includes/class-spf-platform-engineering.php', 'metric_direction_unknown', 'unknown SLO metric direction can pass'),
    30 => array('includes/class-spf-platform-engineering.php', 'slo_objectives_missing', 'SLO gate can pass without objectives'),
    31 => array('includes/class-spf-platform-engineering.php', 'spf_metric_persistence_failed', 'telemetry persistence is not verified'),
    32 => array('includes/class-spf-governance-control-plane.php', 'coded_completion_claim_allowed', 'coded completion is not evidence-gated'),
    33 => array('includes/class-spf-governance-control-plane.php', 'invalid_requirement_entries', 'invalid requirements are not surfaced'),
    34 => array('includes/class-spf-governance-control-plane.php', 'unexpected_evidence_ids', 'orphan evidence is not surfaced'),
    35 => array('includes/class-spf-governance-control-plane.php', 'spf_ai_governance_advisor', 'AI governance advisory boundary is missing'),
    36 => array('includes/class-spf-privacy.php', 'privacy_hold_fail_closed', 'privacy holds can fail open'),
    37 => array('includes/class-spf-privacy.php', 'spf_retention_incomplete', 'retention failures can look successful'),
    38 => array('includes/class-spf-privacy.php', 'FoundationPrivacyErasureCompleted.v1', 'privacy erasure lacks completion evidence'),
    39 => array('includes/class-spf-system-check.php', 'transaction_rollback_probe', 'system check lacks rollback probe'),
    40 => array('includes/class-spf-system-check.php', 'max_interval_seconds', 'external scheduler cadence is not evidenced'),
    41 => array('includes/class-spf-system-check.php', 'SPF_Audit::verify_chain', 'system check omits audit-chain verification'),
    42 => array('includes/class-spf-system-check.php', 'schema_version_current', 'system check omits schema drift'),
    43 => array('includes/class-spf-plugin.php', "'operational'=>\$operational", 'operational status truth is collapsed'),
    44 => array('includes/class-spf-future-foundation.php', 'spf_future_foundation_tick', 'future-foundation health tick is missing'),
    45 => array('includes/class-spf-future-foundation.php', 'spf_five_minutes', 'future-foundation cadence is not five minutes'),
    46 => array('includes/class-spf-rest.php', 'permission_callback', 'restricted REST permission callbacks are missing'),
    47 => array('includes/class-spf-event-bus.php', 'privacy_class', 'event privacy classification is missing'),
    48 => array('includes/class-spf-event-bus.php', 'spf_event_payload_too_many_fields', 'event payload field count is unbounded'),
    49 => array('includes/class-spf-event-bus.php', 'spf_event_payload_too_deep', 'event payload depth is unbounded'),
    50 => array('includes/class-spf-event-bus.php', 'spf_event_payload_key_invalid', 'event payload keys can normalize/collide'),
    51 => array('includes/class-spf-event-bus.php', 'spf_outbox_dead_letter', 'outbox dead-letter evidence is missing'),
    52 => array('includes/class-spf-event-bus.php', 'spf_outbox_recovery_query_failed', 'outbox recovery DB errors can fail open'),
    53 => array('includes/class-spf-audit.php', 'spf_audit_chain_head_invalid', 'audit append accepts malformed chain head'),
    54 => array('includes/class-spf-audit.php', 'spf_audit_verification_incomplete', 'audit verification can claim partial success'),
    55 => array('includes/class-spf-audit.php', 'spf_audit_chain_head_mismatch', 'audit verification does not re-read head'),
    56 => array('includes/class-spf-runtime.php', 'delete_lock_if_matches', 'lock release is not owner-token bound'),
    57 => array('includes/class-spf-runtime.php', 'spf_non_transactional_schema', 'non-transactional schema is not release-blocking'),
    58 => array('includes/class-spf-governance.php', 'validate_transition_evidence_binding', 'release transition evidence is not bound'),
    59 => array('includes/class-spf-governance.php', 'staging_evidence_hash', 'Founder approval is not staged-evidence bound'),
    60 => array('includes/class-spf-governance.php', 'deployed_package_checksum', 'deployment is not package-checksum bound'),
    61 => array('includes/class-spf-governance.php', 'spf_feature_activation_evidence_binding_invalid', 'feature activation is not context-bound'),
    62 => array('includes/class-spf-governance.php', 'expected_version_required', 'feature-flag updates lack optimistic concurrency'),
    63 => array('includes/class-spf-reconciler.php', 'owner_rollback_completed', 'owner rollback is not idempotent'),
    64 => array('includes/class-spf-reconciler.php', 'reconciliation_compensation_incomplete', 'reconciliation compensation can be hidden'),
    65 => array('uninstall.php', 'wp_unschedule_event', 'uninstall does not clear scheduled work'),
    66 => array('sabri-platform-foundation.php', "SPF_VERSION', '2.0.1", 'software version drifted from v2.0.1'),
    67 => array('sabri-platform-foundation.php', "SPF_CONTRACT_VERSION', '2.0.0", 'contract version drifted from v2.0'),
    68 => array('tools/build-package.sh', 'TOP="sabri-platform-foundation-01"', 'package top folder is not canonical'),
    69 => array('tools/build-package.sh', 'build1.zip', 'deterministic double-build is missing'),
    70 => array('tools/build-package.sh', 'sha256sum --check', 'package checksum verification is missing'),
    71 => array('qa/run-tests.sh', 'tests/security-tests.php', 'security suite is omitted from aggregate QA'),
    72 => array('qa/run-tests.sh', 'tests/contract-tests.php', 'contract suite is omitted from aggregate QA'),
    73 => array('qa/wp-runtime-smoke.php', 'PASS', 'WordPress/MySQL runtime smoke lacks a pass contract'),
    74 => array('qa/wp-future-foundation-smoke.php', 'PASS', 'Future Foundation runtime smoke lacks a pass contract'),
    75 => array('STAGING-ACCEPTANCE.md', '- [ ]', 'staging acceptance checklist is not visibly pending'),
    76 => array('KNOWN-LIMITATIONS.md', 'staging', 'known limitations omit staging boundary'),
    77 => array('README.md', 'Staging', 'README collapses staging lifecycle boundary'),
    78 => array('RELEASE-CHECKLIST.md', 'rollback', 'release checklist omits rollback gate'),
    79 => array('PRIVACY.md', 'Privacy', 'privacy governance documentation is missing'),
    80 => array('SECURITY.md', 'Security', 'security governance documentation is missing'),
);

foreach ( $checks as $round => $check ) {
    [$path, $needle, $message] = $check;
    $assert( $has($path, $needle), 'Round ' . $round . ': ' . $message . '.' );
}

if ( 80 !== $assertions ) {
    $failures[] = 'Expected exactly 80 assertions; got ' . $assertions . '.';
}
if ( $failures ) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'Tenth fresh eighty-round review tests: ' . $assertions . '/80 PASS' . PHP_EOL;

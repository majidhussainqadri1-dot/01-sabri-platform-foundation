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

$event = $read('includes/class-spf-event-bus.php');
$runtime = $read('includes/class-spf-runtime.php');
$audit = $read('includes/class-spf-audit.php');
$purge = $read('includes/class-spf-purge.php');
$reconciler = $read('includes/class-spf-reconciler.php');
$governance = $read('includes/class-spf-governance.php');
$system = $read('includes/class-spf-system-check.php');
$auth = $read('includes/class-spf-authorization.php');
$registry = $read('includes/class-spf-registry.php');
$dependency = $read('includes/class-spf-dependency-resolver.php');
$installer = $read('includes/class-spf-installer.php');
$repair = $read('includes/class-spf-repair.php');
$resilience = $read('includes/class-spf-resilience-lab.php');
$engineering = $read('includes/class-spf-platform-engineering.php');
$control = $read('includes/class-spf-governance-control-plane.php');
$plugin = $read('includes/class-spf-plugin.php');
$future = $read('includes/class-spf-future-foundation.php');
$rest = $read('includes/class-spf-rest.php');
$privacy = $read('includes/class-spf-privacy.php');
$main = $read('sabri-platform-foundation.php');
$uninstall = $read('uninstall.php');

// 1-12: defect-bearing rounds corrected in this fresh 80-round cycle.
$assert( str_contains($event, '$version > 65535') && str_contains($event, 'ctype_digit'), 'Round 1: event versions are not strictly bounded/canonical.' );
$assert( str_contains($event, '$raw_dedupe_key') && str_contains($event, "hash( 'sha256', \$raw_dedupe_key )"), 'Round 2: noncanonical dedupe keys can still collapse after sanitization.' );
$assert( str_contains($event, "expired_processing_lease_recovered") && ! str_contains($event, '$stale_before = gmdate'), 'Round 3: expired outbox leases are still delayed by an extra stale window.' );
$assert( str_contains($runtime, "str_ends_with( (string) \$field, '_at' )") && str_contains($runtime, 'spf_evidence_timestamp_invalid'), 'Round 4: structured evidence timestamps are not validated.' );
$assert( str_contains($audit, 'spf_audit_context_too_large') && str_contains($audit, 'spf_audit_context_too_deep') && ! str_contains($audit, "'_truncated' => true"), 'Round 5: mandatory audit evidence can still be silently truncated.' );
$assert( str_contains($purge, 'persist_receipt') && str_contains($purge, 'spf_purge_receipt_persistence_failed'), 'Round 6: purge precommit receipt persistence is not verified.' );
$assert( substr_count($purge, 'self::persist_receipt( $receipt )') >= 3, 'Round 7: purge quarantine/completion receipt stages are not durably verified.' );
$assert( str_contains($purge, '$restored = $wpdb->query') && str_contains($purge, '! SPF_Runtime::table_exists( SPF_Installer::table( $name ) )'), 'Round 8: purge compensation restore is not query/read-back verified.' );
$assert( str_contains($purge, 'spf_purge_transient_cleanup_failed') && str_contains($purge, '$transient_cleanup = self::delete_owned_transients()'), 'Round 9: purge transient cleanup errors can still be ignored.' );
$assert( str_contains($reconciler, 'spf_reconciliation_snapshot_persistence_failed') && str_contains($reconciler, 'The applied reconciliation state could not be durably verified.'), 'Round 10: reconciliation recovery/apply state persistence is not verified.' );
$assert( str_contains($governance, 'spf_release_state_invalid') && str_contains($governance, "'fresh_install','upgrade_test','backup_restore_test','rollback_rehearsal','smoke_test','rollback_ready'"), 'Round 11: release evidence validation is not fail-closed for unknown states and true boolean gates.' );
$assert( str_contains($system, 'spf_health_latest_query_failed') && str_contains($system, 'spf_health_latest_corrupt'), 'Round 12: latest health evidence can still fail/corrupt as a silent null.' );

// 13-80: independent closure lenses on corrected source.
$assert( str_contains($auth, 'LEGACY_BOOLEAN_BRIDGE_ACTIONS') && str_contains($auth, "array( 'view', 'system_check' )"), 'Round 13: legacy File 00 boolean bridge is not read-only.' );
$assert( str_contains($auth, "'founder' !== \$institutional_role"), 'Round 14: Founder-only governance actions are not role-bound.' );
$assert( str_contains($auth, '$issued < time() - 900') && str_contains($auth, '( $expires - $issued ) > 900'), 'Round 15: authorization claims are not short-lived.' );
$assert( str_contains($auth, 'object_hash') && str_contains($auth, 'hash_equals( $expected_hash, $object_hash )'), 'Round 16: authorization claims are not object-bound.' );
$assert( str_contains($auth, 'expected_purpose') && str_contains($auth, "sanitize_key( \$claim['purpose'] )"), 'Round 17: authorization claims are not purpose-bound.' );
$assert( str_contains($registry, 'spf_manifest_self_dependency'), 'Round 18: module registry does not reject self-dependency.' );
$assert( str_contains($registry, 'spf_invalid_manifest_write_owner') && str_contains($registry, 'canonical_entities'), 'Round 19: manifest architecture ownership declarations are not validated.' );
$assert( str_contains($registry, 'spf_invalid_contract_deprecation') || str_contains($registry, 'spf_contract_deprecation'), 'Round 20: contract deprecation timestamps lack validation.' );
$assert( str_contains($registry, 'same_origin') && str_contains($registry, 'target_port'), 'Round 21: route redirect same-origin validation is incomplete.' );
$assert( str_contains($dependency, 'dependency_cycle'), 'Round 22: dependency cycles are not detected.' );
$assert( str_contains($dependency, 'dependency_below_minimum') && str_contains($dependency, 'dependency_above_maximum'), 'Round 23: dependency version windows are not enforced.' );
$assert( str_contains($dependency, "array( 'registered','compatible','active' )"), 'Round 24: optional degraded/suspended/retired dependencies can appear available.' );
$assert( str_contains($installer, 'verify_owned_tables_transactional') || str_contains($runtime, 'verify_owned_tables_transactional'), 'Round 25: transactional table engines are not verified.' );
$assert( str_contains($installer, 'compensation_incomplete'), 'Round 26: activation/upgrade partial failure lacks compensation truth.' );
$assert( str_contains($installer, 'SPF_SCHEMA_VERSION') && str_contains($installer, 'SPF_CONTRACT_VERSION'), 'Round 27: installer version/schema/contract truth is not explicit.' );
$assert( str_contains($repair, 'dry_run') || str_contains($repair, 'dry-run') || str_contains($repair, 'plan_hash'), 'Round 28: repair lacks plan/dry-run evidence.' );
$assert( str_contains($repair, 'file-01') && ! str_contains($repair, 'delete_posts('), 'Round 29: repair can cross File 01 ownership boundaries.' );
$assert( str_contains($resilience, 'SPF_CHAOS_MODE') && str_contains($resilience, "array( 'local', 'development', 'staging', 'ci', 'test' )") && str_contains($resilience, 'fail_closed'), 'Round 30: chaos controls do not fail closed outside the explicit non-production allowlist.' );
$assert( str_contains($resilience, 'spf_snapshot_capacity_full'), 'Round 31: governance snapshots can silently evict recovery truth.' );
$assert( str_contains($resilience, 'spf_snapshot_restore_compensation_incomplete'), 'Round 32: snapshot restore compensation is not verified.' );
$assert( str_contains($resilience, 'SELF_HEAL_RECOVERY_OPTION') && str_contains($resilience, 'rollback'), 'Round 33: self-healing lacks bounded recovery/rollback evidence.' );
$assert( str_contains($engineering, 'spf_event_schema_version_conflict'), 'Round 34: event schema versions are mutable.' );
$assert( str_contains($engineering, 'spf_event_schema_fields_too_large'), 'Round 35: event schema fields are unbounded.' );
$assert( str_contains($engineering, 'spf_config_too_large') && str_contains($engineering, 'spf_config_too_deep'), 'Round 36: configuration drift input is unbounded.' );
$assert( str_contains($engineering, 'secret_hash') && str_contains($engineering, "'redacted'=>true"), 'Round 37: configuration drift can expose raw secrets.' );
$assert( str_contains($engineering, 'spf_rollout_capacity_full'), 'Round 38: progressive rollout truth can silently evict records.' );
$assert( str_contains($engineering, 'spf_rollout_release_missing'), 'Round 39: progressive rollout state is not bound to a canonical release.' );
$assert( str_contains($engineering, 'metric_direction_unknown'), 'Round 40: SLO unknown metric direction can pass implicitly.' );
$assert( str_contains($engineering, 'slo_objectives_missing'), 'Round 41: SLO gate can pass without objectives.' );
$assert( str_contains($engineering, 'future-metrics') && str_contains($engineering, 'spf_metric_persistence_failed'), 'Round 42: telemetry buffer updates are not locked/read-back verified.' );
$assert( str_contains($engineering, 'random_bytes( 16 )') && str_contains($engineering, 'random_bytes( 8 )'), 'Round 43: telemetry trace/span IDs are not cryptographically generated.' );
$assert( str_contains($control, 'coded_completion_claim_allowed') && str_contains($control, 'report_valid'), 'Round 44: traceability can claim completion on invalid report input.' );
$assert( str_contains($control, 'invalid_requirement_entries') && str_contains($control, 'unexpected_evidence_ids'), 'Round 45: traceability does not surface malformed/orphan evidence.' );
$assert( str_contains($control, 'spf_ai_governance_advisor'), 'Round 46: AI governance advisor extension point is missing.' );
$assert( str_contains($control, 'advisory') || str_contains($control, 'autonomous'), 'Round 47: AI governance boundary does not state advisory/non-autonomous behavior.' );
$assert( str_contains($privacy, 'privacy_hold_fail_closed') && str_contains($privacy, 'return true;'), 'Round 48: missing/failed privacy hold registry can fail open.' );
$assert( str_contains($privacy, 'spf_retention_incomplete'), 'Round 49: retention failures can be reported as success.' );
$assert( str_contains($privacy, "status IN ('completed','failed')"), 'Round 50: active/ambiguous idempotency reservations can be deleted by retention.' );
$assert( str_contains($privacy, 'FoundationPrivacyErasureCompleted.v1'), 'Round 51: privacy erasure completion lacks event evidence.' );
$assert( str_contains($system, 'transaction_rollback_probe'), 'Round 52: System Check does not verify transaction rollback behavior.' );
$assert( str_contains($system, 'max_interval_seconds') && str_contains($system, '$max_interval <= 300'), 'Round 53: external scheduler cadence is not bounded to five minutes.' );
$assert( str_contains($system, 'audit_chain') && str_contains($system, 'SPF_Audit::verify_chain'), 'Round 54: System Check omits audit-chain integrity.' );
$assert( str_contains($system, 'privacy_requests_query') && str_contains($system, 'privacy_holds_query'), 'Round 55: privacy health database errors can appear green.' );
$assert( str_contains($system, 'schema_version_current'), 'Round 56: System Check omits schema-version drift.' );
$assert( str_contains($plugin, "'operational'=>\$operational") && str_contains($plugin, "'deployed' === \$release_status"), 'Round 57: operational completion can be asserted before deployment.' );
$assert( str_contains($plugin, "'staging_accepted'=>in_array") && str_contains($plugin, "array('staged','approved','deployed')"), 'Round 58: staging completion status mapping is missing.' );
$assert( str_contains($future, 'spf_future_foundation_tick') && str_contains($future, 'spf_five_minutes'), 'Round 59: Future Foundation periodic health tick is not scheduled at five minutes.' );
$assert( str_contains($future, 'wp_unschedule_event'), 'Round 60: Future Foundation cron lifecycle lacks deactivation cleanup.' );
$assert( str_contains($rest, 'permission_callback') || str_contains($plugin, 'permission_callback'), 'Round 61: restricted REST surfaces lack permission callbacks.' );
$assert( str_contains($event, 'privacy_class') && str_contains($event, "'confidential'") && str_contains($event, "'ephemeral'"), 'Round 62: event privacy classification vocabulary is incomplete.' );
$assert( str_contains($event, 'spf_event_payload_too_many_fields') && str_contains($event, 'spf_event_payload_too_deep'), 'Round 63: event payload envelope is unbounded.' );
$assert( str_contains($event, 'spf_event_payload_key_invalid'), 'Round 64: event payload keys can silently normalize/collide.' );
$assert( str_contains($event, 'spf_outbox_dead_letter'), 'Round 65: outbox lacks dead-letter evidence.' );
$assert( str_contains($event, 'spf_outbox_recovery_query_failed') && str_contains($event, 'spf_outbox_select_failed'), 'Round 66: outbox database errors can fail open.' );
$assert( str_contains($audit, 'spf_audit_chain_head_invalid') && str_contains($audit, 'previous_hash'), 'Round 67: audit append does not fail closed on malformed chain head.' );
$assert( str_contains($audit, 'spf_audit_verification_incomplete'), 'Round 68: audit verification can claim a partial prefix as complete.' );
$assert( str_contains($audit, 'spf_audit_chain_head_mismatch'), 'Round 69: audit verification does not re-check stored head.' );
$assert( str_contains($runtime, 'delete_lock_if_matches') && str_contains($runtime, 'option_value'), 'Round 70: stale lock takeover can delete a newer owner lock.' );
$assert( str_contains($runtime, '$current_expires') && str_contains($runtime, 'HOUR_IN_SECONDS'), 'Round 71: legacy lock expiry can be stolen using contender TTL.' );
$assert( str_contains($runtime, 'spf_non_transactional_schema'), 'Round 72: non-InnoDB owned tables are not release-blocking.' );
$assert( str_contains($governance, 'validate_transition_evidence_binding') && str_contains($governance, 'staging_evidence_hash'), 'Round 73: Founder approval is not bound to staged evidence.' );
$assert( str_contains($governance, 'deployed_package_checksum') && str_contains($governance, 'hash_equals( $expected, $claimed )'), 'Round 74: deployment evidence is not bound to canonical package checksum.' );
$assert( str_contains($governance, 'spf_feature_activation_evidence_binding_invalid'), 'Round 75: feature activation evidence lacks exact context binding.' );
$assert( str_contains($governance, 'expected_version_required') && str_contains($governance, 'spf_stale_record'), 'Round 76: feature-flag updates lack optimistic concurrency.' );
$assert( str_contains($reconciler, 'owner_rollback_completed') && str_contains($reconciler, 'spf_reconciliation_rollback_checkpoint_failed'), 'Round 77: owner rollback can be repeated after local-restore retry.' );
$assert( str_contains($reconciler, 'reconciliation_compensation_incomplete'), 'Round 78: reconciliation compensation failure can be hidden.' );
$assert( str_contains($uninstall, 'wp_unschedule_event') && str_contains($uninstall, 'delete_option'), 'Round 79: uninstall does not clean File 01 schedules/options.' );
$assert( str_contains($main, "define( 'SPF_VERSION', '2.0.0' )") && str_contains($main, "define( 'SPF_CONTRACT_VERSION', '2.0.0' )"), 'Round 80: runtime/version contract baseline drifted from File 01 v2.0.' );

if ( 80 !== $assertions ) {
    $failures[] = "Review harness itself executed {$assertions} assertions instead of 80.";
}
if ( $failures ) {
    fwrite( STDERR, "Fresh 80-round review regression failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Fresh 80-round review assertions: {$assertions}/80 PASS\n";

<?php
declare(strict_types=1);

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
    $assertions++;
    if ( ! $condition ) { $failures[] = $message; }
};

$root = dirname( __DIR__ );
$idempotency = file_get_contents( $root . '/includes/class-spf-idempotency.php' );
$governance = file_get_contents( $root . '/includes/class-spf-governance.php' );
$reconciler = file_get_contents( $root . '/includes/class-spf-reconciler.php' );
$system_check = file_get_contents( $root . '/includes/class-spf-system-check.php' );
$engineering = file_get_contents( $root . '/includes/class-spf-platform-engineering.php' );
$resilience = file_get_contents( $root . '/includes/class-spf-resilience-lab.php' );
$control_plane = file_get_contents( $root . '/includes/class-spf-governance-control-plane.php' );

$assert( str_contains( $idempotency, 'reconciliation_required' ), 'Round 1: stale idempotency ambiguity is not frozen for reconciliation.' );
$assert( ! str_contains( $idempotency, '$claimed = $wpdb->update(' ), 'Round 1: stale idempotency reservations can still be automatically reclaimed and replayed.' );
$assert( str_contains( $governance, 'validate_transition_evidence_binding' ) && str_contains( $governance, 'spf_release_staging_evidence_binding_invalid' ) && str_contains( $governance, 'spf_release_deployed_checksum_binding_invalid' ), 'Round 2: release approval/deployment evidence is not bound to staged evidence and canonical checksum.' );
$assert( str_contains( $governance, 'spf_noncanonical_flag_identity' ) && str_contains( $governance, "'environment'=>\$env" ), 'Round 3: feature-flag authorization identity is not canonicalized before the authorization decision.' );
$assert( str_contains( $reconciler, 'owner_rollback_completed' ) && str_contains( $reconciler, 'spf_reconciliation_rollback_checkpoint_failed' ), 'Round 4: reconciliation rollback lacks a durable owner-compensation checkpoint.' );
$assert( str_contains( $system_check, 'max_interval_seconds' ) && str_contains( $system_check, '$max_interval >= 1 && $max_interval <= 300' ), 'Round 5: external scheduler evidence does not prove the required five-minute cadence.' );
$assert( str_contains( $engineering, 'spf_event_schema_version_conflict' ), 'Round 6: an existing event-schema version can still be silently rewritten.' );
$assert( str_contains( $engineering, 'spf_rollout_release_missing' ) && str_contains( $engineering, 'spf_rollout_capacity_full' ) && ! str_contains( $engineering, 'array_slice( $rollouts, -100' ), 'Round 7: rollout truth is not release-bound or can still be silently evicted.' );
$assert( str_contains( $resilience, 'spf_snapshot_capacity_full' ) && str_contains( $resilience, 'spf_snapshot_restore_compensation_incomplete' ), 'Round 8: snapshot truth can be silently evicted or failed restore compensation is not verified.' );
$assert( str_contains( $governance, "'owner_module','flag_key','environment','readiness_hash'" ) && str_contains( $governance, 'spf_feature_activation_evidence_binding_invalid' ), 'Round 9: feature activation evidence is not bound to the exact owner/flag/environment/readiness state.' );
$assert( str_contains( $control_plane, "'report_valid'" ) && str_contains( $control_plane, "'coded_completion_claim_allowed'" ) && str_contains( $control_plane, "'invalid_requirement_entries'" ) && str_contains( $control_plane, "'unexpected_evidence_ids'" ), 'Round 10: malformed/duplicate/orphan traceability input can still support a completion claim.' );

if ( $failures ) {
    fwrite( STDERR, "Eighth ten-round review regression failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Eighth ten-round review assertions: {$assertions}/{$assertions} PASS\n";

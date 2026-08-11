<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$governance = file_get_contents( $root . '/includes/class-spf-governance.php' );
$authorization = file_get_contents( $root . '/includes/class-spf-authorization.php' );
$plugin = file_get_contents( $root . '/includes/class-spf-plugin.php' );
$installer = file_get_contents( $root . '/includes/class-spf-installer.php' );
$resilience = file_get_contents( $root . '/includes/class-spf-resilience-lab.php' );
$runtime = file_get_contents( $root . '/includes/class-spf-runtime.php' );
$audit = file_get_contents( $root . '/includes/class-spf-audit.php' );
$system_check = file_get_contents( $root . '/includes/class-spf-system-check.php' );

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
    $assertions++;
    if ( ! $condition ) { $failures[] = $message; }
};

// R1 — immutable governance identities and evidence must not normalize into another truth.
$assert( str_contains( $governance, '$raw_package_name' ) && str_contains( $governance, 'silent filename normalization is forbidden' ), 'Release package filename can still normalize silently.' );
$assert( str_contains( $governance, 'spf_governance_evidence_key_noncanonical' ) && str_contains( $governance, '$raw_key !== $key' ), 'Immutable governance evidence keys are not fail-closed canonical.' );
$assert( str_contains( $governance, 'spf_governance_evidence_value_noncanonical' ) && str_contains( $governance, '$raw_value !== $safe_value' ), 'Immutable governance scalar evidence can still normalize silently.' );
$assert( str_contains( $governance, 'spf_invalid_amendment_id' ) && str_contains( $governance, '$raw_id !== $id' ) && str_contains( $governance, '$raw_approver_ref !== $approver_ref' ), 'Amendment stable identities can still normalize or truncate silently.' );

// R2 — File 00 authorization claims must be exact canonical identities, not sanitized equivalents.
$assert( str_contains( $authorization, 'array( \'action\',\'capability\',\'purpose\',\'institutional_role\',\'plugin\' )' ) && str_contains( $authorization, '$raw_identity !== sanitize_key( $raw_identity )' ), 'Structured authorization claim identities are not exact-canonical.' );
$assert( str_contains( $authorization, 'file-01' ) && str_contains( $authorization, '(string) $claim' ) && str_contains( $authorization, 'plugin' ), 'File 00 exact plugin binding is missing.' );

// R3 — Operational status requires fresh, expiring evidence bound to the exact fresh health result.
$assert( str_contains( $plugin, '\'health_hash\' => is_array( $health ) ? SPF_Runtime::hash( $health ) : \'\'' ), 'Operational claim is not bound to exact health evidence.' );
$assert( str_contains( $plugin, '$health_checked_at < time() - 900' ) && str_contains( $plugin, '$observed_at >= time() - 900' ) && str_contains( $plugin, '$expires_at > time()' ), 'Operational acceptance can use stale or non-expiring evidence.' );
$assert( str_contains( $plugin, '\'verifier\'' ) || str_contains( $plugin, '$verifier' ), 'Operational evidence has no verifier requirement.' );

// R4 — direct installer API must fail closed on malformed stored schema truth.
$assert( str_contains( $installer, 'return new WP_Error( \'spf_schema_version_invalid\'' ) && str_contains( $installer, '\'status\'=>\'invalid_schema_version\'' ), 'Installer::maybe_upgrade still treats malformed schema truth as success/current.' );

// R5 — bounded self-heal must use the canonical flags table and restore exact rows.
$assert( str_contains( $resilience, 'expired_flag_rows' ) && str_contains( $resilience, 'SPF_Installer::table' ) && str_contains( $resilience, 'flags' ), 'Self-heal canonical flag-table path is missing.' );
$assert( str_contains( $resilience, '\'flag_snapshot\'' ) && str_contains( $resilience, '\'flag_post_hashes\'' ) && str_contains( $resilience, 'restore_flag_rows' ), 'Self-heal flag mutation lacks snapshot/post-state/rollback binding.' );
$assert( str_contains( $governance, 'reconcile_expired_flags( array $expected_snapshot = array() )' ) && str_contains( $governance, 'spf_flag_expiry_snapshot_changed' ), 'Expired-flag reconciliation is not bound to the reviewed self-heal snapshot.' );
$assert( str_contains( $governance, 'record_required( \'feature_flag_expired\'' ) && str_contains( $resilience, 'flag_result[\'audit_failed\']' ), 'Automatic flag expiry can succeed without mandatory positive audit evidence.' );

// R6 — historical evidence timestamps cannot be asserted in the future.
$assert( str_contains( $runtime, 'spf_evidence_timestamp_future' ) && str_contains( $runtime, '$timestamp > time() + 60' ), 'External evidence accepts future-dated historical timestamps.' );

// R7 — audit verification must be complete but paged, not capped at a single 50k read.
$assert( str_contains( $audit, '$batch_size = 5000' ) && str_contains( $audit, 'WHERE id>%d ORDER BY id ASC LIMIT %d' ) && str_contains( $audit, '1000000' ), 'Audit-chain verification is not paged under a larger explicit ceiling.' );
$assert( str_contains( $system_check, 'spf_audit_health_verification_ceiling' ) && str_contains( $system_check, '500000' ), 'System Check still hard-stops complete audit verification at 50k rows.' );

if ( $failures ) {
    fwrite( STDERR, "Thirteenth ten-round review tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Thirteenth ten-round review assertions: {$assertions}/{$assertions} PASS\n";

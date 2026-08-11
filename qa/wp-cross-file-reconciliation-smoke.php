<?php
/**
 * Real WordPress/MySQL cross-file reconciliation acceptance smoke.
 *
 * Scope: File 01 Reconciler + actual File 20/File 21 owner adapters. File 00's
 * structured claim shape is injected only to authorize this isolated CI
 * mutation; the File 00 bridge has its own independent runtime/live evidence.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$assert( class_exists( 'SPF_Reconciler' ), 'File 01 Reconciler is loaded.' );
$assert( class_exists( 'Sabri\\UnifiedShell\\File01ReconciliationAdapter' ), 'File 20 reconciliation adapter is loaded.' );
$assert( class_exists( 'Sabri\\HomeNewsFeed\\File01ReconciliationAdapter' ), 'File 21 reconciliation adapter is loaded.' );
$assert( has_filter( 'spf_owner_reconciliation_plan' ), 'At least one canonical owner plan adapter is registered.' );
$assert( has_filter( 'spf_execute_owner_reconciliation' ), 'Canonical owner execution adapters are registered.' );
$assert( has_filter( 'spf_rollback_owner_reconciliation' ), 'Canonical owner rollback adapters are registered.' );

// CI-only File 00 structured claim provider for the sensitive reconciliation action.
add_filter( 'spf_file00_authorization_claim', static function ( $claim, $request ) {
	if ( ! is_array( $request ) || empty( $request['user_id'] ) ) { return $claim; }
	$now = time();
	return array(
		'claim_version'      => '1.0.0',
		'allowed'            => true,
		'user_id'            => (int) $request['user_id'],
		'actor_id'           => (int) $request['actor_id'],
		'action'             => (string) $request['action'],
		'capability'         => (string) $request['capability'],
		'issued_at'          => $now,
		'expires_at'         => $now + 60,
		'claim_id'           => 'ci-file01-reconcile-' . substr( hash( 'sha256', wp_json_encode( $request ) . '|' . $now ), 0, 32 ),
		'object_hash'        => (string) $request['object_hash'],
		'purpose'            => (string) $request['purpose'],
		'institutional_role' => 'founder',
		'plugin'             => 'file-01',
		'contract'           => (string) $request['contract'],
		'suspended'          => false,
		'revoked'            => false,
	);
}, 5, 2 );

$keys = array( 'home', 'news', 'founder', 'learn', 'encyclopedia', 'doctors', 'clinic', 'video_wall', 'reels', 'pdf_library', 'radar', 'ai', 'network', 'marketplace' );
$file21_keys = array( 'home', 'news' );
$page_map = array();
$created_pages = array();
foreach ( $keys as $key ) {
	$page_id = wp_insert_post( array(
		'post_title'  => 'File01 legacy CI ' . $key,
		'post_name'   => 'file01-legacy-ci-' . str_replace( '_', '-', $key ),
		'post_type'   => 'page',
		'post_status' => 'draft',
	), true );
	$assert( ! is_wp_error( $page_id ) && $page_id > 0, 'Created legacy page for ' . $key . '.' );
	update_post_meta( $page_id, '_spf_managed_page', '1' );
	$page_map[ $key ] = (int) $page_id;
	$created_pages[] = (int) $page_id;
}

$current_user = get_current_user_id();
$assert( $current_user > 0, 'CI reconciliation runs as an authenticated user.' );
update_option( 'spf_page_map', $page_map, false );
update_option( 'spf_founder_user_id', $current_user, false );

$repair_plan = SPF_Repair::plan();
$repair_actions = is_array( $repair_plan['actions'] ?? null ) ? $repair_plan['actions'] : array();
foreach ( $repair_actions as $repair_action ) {
	$assert( 'remove_legacy_option' !== ( $repair_action['action'] ?? '' ), 'Safe Repair cannot bypass owner-acknowledged reconciliation.' );
}
$warning_targets = array();
foreach ( (array) ( $repair_plan['warnings'] ?? array() ) as $warning ) {
	if ( is_array( $warning ) && 'legacy_reconciliation_required' === ( $warning['code'] ?? '' ) ) {
		$warning_targets[] = (string) ( $warning['target'] ?? '' );
	}
}
$assert( in_array( 'spf_page_map', $warning_targets, true ), 'Safe Repair delegates page-map cutover to Reconciler.' );
$assert( in_array( 'spf_founder_user_id', $warning_targets, true ), 'Safe Repair delegates Founder fallback cutover to Reconciler.' );

$plan = SPF_Reconciler::plan();
$assert( is_array( $plan ), 'Cross-file reconciliation plan is generated.' );
$assert( empty( $plan['blockers'] ), 'All 14 legacy mappings have valid canonical owner acknowledgements.' );
$assert( 15 === (int) ( $plan['counts']['reconcile'] ?? -1 ), 'Plan contains 14 mappings plus Founder fallback.' );
$assert( 14 === (int) ( $plan['counts']['quarantine'] ?? -1 ), 'All 14 legacy File 01-managed pages are quarantined only after owner acknowledgement.' );

$mapping_count = 0;
foreach ( (array) $plan['actions'] as $action ) {
	if ( 'reconcile_legacy_mapping' !== ( $action['action'] ?? '' ) ) { continue; }
	++$mapping_count;
	$key = (string) ( $action['legacy_key'] ?? '' );
	$owner_plan = is_array( $action['owner_plan'] ?? null ) ? $action['owner_plan'] : array();
	$expected_owner = in_array( $key, $file21_keys, true ) ? 'file-21' : 'file-20';
	$assert( true === ( $owner_plan['accepted'] ?? null ), $key . ' owner plan is accepted.' );
	$assert( $expected_owner === ( $owner_plan['owner_module'] ?? '' ), $key . ' is acknowledged by ' . $expected_owner . '.' );
	$assert( '1.0.0' === ( $owner_plan['command_version'] ?? '' ), $key . ' owner plan is versioned.' );
}
$assert( 14 === $mapping_count, 'Exactly 14 mapping handoffs are planned.' );

$plan_hash = SPF_Reconciler::plan_hash( $plan );
$result = SPF_Reconciler::apply( 'APPLY FILE 01 RECONCILIATION', $plan_hash );
$assert( ! is_wp_error( $result ), is_wp_error( $result ) ? 'Apply failed: ' . $result->get_error_message() : 'Cross-file reconciliation apply succeeds.' );
$assert( 'applied' === ( $result['status'] ?? '' ), 'Applied reconciliation records applied state.' );
$assert( 14 === count( (array) ( $result['changed'] ?? array() ) ), 'Apply receives 14 canonical owner receipts.' );
$assert( 14 === count( (array) ( $result['owner_receipts'] ?? array() ) ), 'All mapping handoffs return reversible receipts.' );
$assert( '__missing__' === get_option( 'spf_page_map', '__missing__' ), 'Guarded reconciliation removes the legacy page map.' );
$assert( '__missing__' === get_option( 'spf_founder_user_id', '__missing__' ), 'Guarded reconciliation removes the legacy Founder fallback.' );
foreach ( $created_pages as $page_id ) {
	$assert( '1' === get_post_meta( $page_id, '_spf_legacy_quarantined', true ), 'Acknowledged legacy page is quarantined rather than deleted.' );
}

$rollback = SPF_Reconciler::rollback( 'ROLL BACK FILE 01 RECONCILIATION' );
$assert( ! is_wp_error( $rollback ), is_wp_error( $rollback ) ? 'Rollback failed: ' . $rollback->get_error_message() : 'Cross-file reconciliation rollback succeeds.' );
$assert( 'rolled_back' === ( $rollback['status'] ?? '' ), 'Rollback records rolled_back state.' );
$restored_map = get_option( 'spf_page_map', array() );
$assert( is_array( $restored_map ) && $restored_map === $page_map, 'Rollback restores the exact legacy page map snapshot.' );
$assert( $current_user === (int) get_option( 'spf_founder_user_id', 0 ), 'Rollback restores the exact Founder fallback.' );
foreach ( $created_pages as $page_id ) {
	$assert( '' === (string) get_post_meta( $page_id, '_spf_legacy_quarantined', true ), 'Rollback clears quarantine state back to the snapshot.' );
}

$file20_receipts = get_option( 'sabri_shell_file01_reconciliation_receipts', array() );
$file21_receipts = get_option( 'sabri_hnf_file01_reconciliation_receipts', array() );
$assert( empty( $file20_receipts ), 'File 20 owner receipts are compensated on rollback.' );
$assert( empty( $file21_receipts ), 'File 21 owner receipts are compensated on rollback.' );

foreach ( $created_pages as $page_id ) { wp_delete_post( $page_id, true ); }
delete_option( 'spf_page_map' );
delete_option( 'spf_founder_user_id' );
delete_option( 'spf_reconciliation_snapshot' );
delete_option( 'spf_reconciliation_state' );
delete_option( 'sabri_shell_file01_reconciliation_receipts' );
delete_option( 'sabri_hnf_file01_reconciliation_receipts' );

echo "Cross-file File 01/20/21 reconciliation assertions {$assertions}/{$assertions} PASS\n";

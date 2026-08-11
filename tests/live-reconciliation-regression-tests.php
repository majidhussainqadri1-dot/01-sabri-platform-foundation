<?php
/**
 * Regression tests for the live-discovered File 01 Safe Repair / reconciliation ownership defect.
 */
$root = dirname( __DIR__ );
$repair = file_get_contents( $root . '/includes/class-spf-repair.php' );
$reconciler = file_get_contents( $root . '/includes/class-spf-reconciler.php' );
$admin = file_get_contents( $root . '/includes/class-spf-admin.php' );
$assertions = 0;

$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$assert( false !== strpos( $repair, "RECONCILIATION_OWNED_OPTIONS = array( 'spf_page_map', 'spf_founder_user_id' )" ), 'Repair declares the guarded reconciliation-owned option set.' );
$assert( false !== strpos( $repair, "'legacy_reconciliation_required'" ), 'Repair reports reconciliation-owned legacy state as a warning instead of a mutation.' );
$assert( false !== strpos( $repair, 'spf_repair_reconciliation_owned_option_rejected' ), 'Repair defensively rejects forged/stale legacy-option deletion actions.' );
$assert( false !== strpos( $repair, 'Safe Repair does not remove legacy options.' ), 'Repair contains no generic legacy-option deletion path.' );
$assert( false === strpos( $repair, "delete_option( \$action['target'] )" ), 'Repair cannot directly delete an option named by a repair action.' );
$assert( false === strpos( $repair, "'options' => array()" ), 'Repair snapshots no longer imply ownership of reconciliation options.' );

$assert( false !== strpos( $reconciler, "get_option( 'spf_page_map'" ), 'Reconciler remains the reader/owner of the legacy page map.' );
$assert( false !== strpos( $reconciler, "get_option( 'spf_founder_user_id'" ), 'Reconciler remains the reader/owner of the legacy Founder fallback.' );
$assert( false !== strpos( $reconciler, "'spf_owner_reconciliation_plan'" ), 'Reconciliation still requires owner planning.' );
$assert( false !== strpos( $reconciler, "'owner_reconciliation_adapter_missing_or_invalid'" ), 'Missing/invalid canonical owner acknowledgment remains a blocker.' );
$assert( false !== strpos( $reconciler, "'spf_execute_owner_reconciliation'" ), 'Apply requires canonical owner execution receipts.' );
$assert( false !== strpos( $reconciler, "'spf_rollback_owner_reconciliation'" ), 'Owner receipts remain reversibly roll-backable.' );
$assert( false !== strpos( $reconciler, "delete_option( 'spf_page_map' )" ), 'Only guarded reconciliation removes the legacy page map.' );
$assert( false !== strpos( $reconciler, "delete_option( 'spf_founder_user_id' )" ), 'Only guarded reconciliation removes the Founder fallback.' );
$assert( false !== strpos( $reconciler, "if ( ! empty( \$plan['blockers'] ) )" ), 'Reconciliation refuses to apply while owner blockers exist.' );

$assert( false !== strpos( $admin, 'APPLY FILE 01 RECONCILIATION' ), 'Admin requires exact reconciliation confirmation.' );
$assert( false !== strpos( $admin, 'ROLL BACK FILE 01 RECONCILIATION' ), 'Admin exposes the exact reversible rollback confirmation.' );
$assert( false !== strpos( $admin, 'REPAIR FILE 01 OWNED STATE' ), 'Safe Repair remains a separate explicit action.' );

echo "Live reconciliation ownership regression assertions {$assertions}/{$assertions} PASS\n";

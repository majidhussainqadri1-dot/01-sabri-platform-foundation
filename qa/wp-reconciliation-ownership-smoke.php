<?php
/**
 * Real WordPress/MySQL regression for the live-discovered Safe Repair ownership defect.
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

update_option( 'spf_page_map', array( 'home' => 162, 'news' => 163, 'founder' => 164 ), false );
update_option( 'spf_founder_user_id', 1, false );

$plan = SPF_Repair::plan();
$assert( is_array( $plan ), 'Safe Repair returns a plan.' );
$assert( empty( $plan['blockers'] ), 'Legacy reconciliation state does not become a schema-repair blocker.' );
$warnings = isset( $plan['warnings'] ) && is_array( $plan['warnings'] ) ? $plan['warnings'] : array();
$warning_targets = array_values( array_filter( array_map( static function ( $item ) {
	return is_array( $item ) && 'legacy_reconciliation_required' === ( $item['code'] ?? '' ) ? ( $item['target'] ?? '' ) : '';
}, $warnings ) ) );
$assert( in_array( 'spf_page_map', $warning_targets, true ), 'Safe Repair reports the page map as reconciliation-owned.' );
$assert( in_array( 'spf_founder_user_id', $warning_targets, true ), 'Safe Repair reports Founder fallback as reconciliation-owned.' );

foreach ( (array) ( $plan['actions'] ?? array() ) as $action ) {
	$assert( 'remove_legacy_option' !== ( $action['action'] ?? '' ), 'Safe Repair proposes no legacy-option deletion action.' );
}
$assert( false !== get_option( 'spf_page_map', false ), 'Planning Safe Repair preserves spf_page_map.' );
$assert( false !== get_option( 'spf_founder_user_id', false ), 'Planning Safe Repair preserves spf_founder_user_id.' );

$reconcile = SPF_Reconciler::plan();
$assert( is_array( $reconcile ), 'Reconciler still owns and plans legacy cutover.' );
$reconcile_actions = (array) ( $reconcile['actions'] ?? array() );
$assert( count( $reconcile_actions ) >= 4, 'Reconciler sees legacy mappings plus Founder fallback.' );
$assert( ! empty( $reconcile['blockers'] ), 'Without canonical owner adapters the reconciliation remains fail-closed.' );
$assert( false !== get_option( 'spf_page_map', false ), 'Dry-run reconciliation does not mutate the page map.' );
$assert( false !== get_option( 'spf_founder_user_id', false ), 'Dry-run reconciliation does not mutate Founder fallback.' );

delete_option( 'spf_page_map' );
delete_option( 'spf_founder_user_id' );

echo "WordPress reconciliation ownership assertions {$assertions}/{$assertions} PASS\n";

<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;
$failures = array();
$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};

// R9: a view-only principal must not persist health rows through System Check.
$admin_id = get_current_user_id();
$health_table = SPF_Installer::table( 'health' );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$health_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
$login = 'spf-r14-viewer-' . wp_generate_password( 8, false, false );
$viewer_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $login . '@example.test' );
if ( is_wp_error( $viewer_id ) ) {
	$failures[] = 'Could not create disposable view-only authorization principal.';
} else {
	$viewer = new WP_User( $viewer_id );
	$viewer->set_role( 'subscriber' );
	$viewer->add_cap( SPF_Authorization::CAP_VIEW );
	$viewer->remove_cap( SPF_Authorization::CAP_MANAGE );
	wp_set_current_user( $viewer_id );
	$blocked = SPF_System_Check::run( true, array( 'purpose'=>'fourteenth_runtime_auth_probe' ) );
	$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$health_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
	$assert( is_wp_error( $blocked ) && 'spf_forbidden' === $blocked->get_error_code(), 'View-only principal persisted a System Check.' );
	$assert( $before === $after, 'Unauthorized System Check changed persisted health evidence.' );
	wp_set_current_user( $admin_id );
	wp_delete_user( $viewer_id );
}

// R8: owner/status filters and offset pagination must be real DB query semantics.
$current = SPF_Registry::list_contracts( array( 'owner_module'=>'file-01', 'status'=>'current', 'limit'=>200, 'offset'=>0 ) );
$assert( is_array( $current ) && ! empty( $current ), 'Filtered File 01 current contract query returned no seeded contracts.' );
if ( is_array( $current ) ) {
	$all_match = true;
	foreach ( $current as $row ) {
		if ( 'file-01' !== ( $row['owner_module'] ?? '' ) || 'current' !== ( $row['status'] ?? '' ) ) { $all_match = false; break; }
	}
	$assert( $all_match, 'Contract owner/status filter leaked a nonmatching row.' );
}
$bad_filter = SPF_Registry::list_contracts( array( 'owner_module'=>'FILE-01', 'limit'=>10 ) );
$assert( is_wp_error( $bad_filter ) && 'spf_contract_filter_invalid' === $bad_filter->get_error_code(), 'Noncanonical contract filter was silently normalized.' );
$page1 = SPF_Registry::list_contracts( array( 'owner_module'=>'file-01', 'limit'=>1, 'offset'=>0 ) );
$page2 = SPF_Registry::list_contracts( array( 'owner_module'=>'file-01', 'limit'=>1, 'offset'=>1 ) );
if ( count( $current ) >= 2 ) {
	$id1 = ($page1[0]['contract_key'] ?? '') . '@' . ($page1[0]['contract_version'] ?? '');
	$id2 = ($page2[0]['contract_key'] ?? '') . '@' . ($page2[0]['contract_version'] ?? '');
	$assert( '' !== $id1 && '' !== $id2 && $id1 !== $id2, 'Bounded contract offset pagination did not advance to the next row.' );
} else {
	$assert( is_array( $page1 ) && count( $page1 ) <= 1 && is_array( $page2 ), 'Bounded contract pagination shape is invalid.' );
}

// R6/R7: empty arrays cannot satisfy mandatory immutable evidence fields.
$release_evidence = array(
	'source_commit_verified'=>true,'package_checksum_verified'=>true,'reproducible_build'=>true,
	'source_manifest'=>array(),'sbom'=>'runtime-sbom-ref',
);
$evidence_result = SPF_Governance::validate_evidence_for_state( 'built', $release_evidence );
$assert( is_wp_error( $evidence_result ) && 'spf_release_evidence_incomplete' === $evidence_result->get_error_code(), 'Empty structured release evidence passed the runtime evidence gate.' );
$bad_lock = SPF_Runtime::acquire_lock( 'Audit Chain', 30, $admin_id );
$assert( is_wp_error( $bad_lock ) && 'spf_lock_name_invalid' === $bad_lock->get_error_code(), 'Noncanonical runtime lock identity was accepted.' );

if ( $failures ) {
	fwrite( STDERR, "Fourteenth WordPress/MySQL runtime smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Fourteenth WordPress/MySQL runtime assertions: {$assertions}/{$assertions} PASS\n";

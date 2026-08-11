<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Non-destructive uninstall: canonical governance records, audit, manifests,
// routes, release evidence and reconciliation snapshots remain intact. A
// destructive purge is a separate Founder-authorized, backup/restore-proven,
// File-24-assured operation and is intentionally unavailable from uninstall.
foreach ( array( 'spf_dispatch_outbox', 'spf_privacy_retention', 'spf_reconcile_expired_flags', 'spf_future_foundation_tick' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

foreach ( array( 'spf_activation_lock', 'spf_audit_chain_lock', 'spf_outbox_dispatch_lock' ) as $option ) {
	delete_option( $option );
}
delete_transient( 'spf_activation_notice' );

$role = get_role( 'administrator' );
if ( $role ) {
	// Only bootstrap capabilities granted by File 01 are removed. Founder,
	// release and purge authority are managed by File 00 and are not modified.
	$role->remove_cap( 'view_sabri_foundation' );
	$role->remove_cap( 'manage_sabri_foundation' );
}

<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// File 01 follows a non-destructive uninstall law.
// Governance history, manifests, routes, release evidence, audit and reconciliation
// snapshots are intentionally preserved. Destructive purge is a separate,
// explicitly authorized, backup-proven and audited operational procedure.
$timestamp = wp_next_scheduled( 'spf_dispatch_outbox' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'spf_dispatch_outbox' );
}
delete_option( 'spf_activation_lock' );
delete_option( 'spf_audit_chain_lock' );
delete_option( 'spf_outbox_dispatch_lock' );
delete_option( 'spf_activation_state' );
delete_transient( 'spf_activation_notice' );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_sabri_foundation' );
	$role->remove_cap( 'release_sabri_foundation' );
	$role->remove_cap( 'purge_sabri_foundation' );
}

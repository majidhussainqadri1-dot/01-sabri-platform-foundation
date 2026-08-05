<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Purge {
	public static function plan() {
		global $wpdb;
		$tables = array();
		foreach ( array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' ) as $name ) {
			$table = SPF_Installer::table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$tables[] = array(
				'name'   => $name,
				'exists' => $exists,
				'rows'   => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
			);
		}
		return array(
			'generated_at' => current_time( 'mysql', true ),
			'tables'       => $tables,
			'options'      => self::owned_options(),
			'warning'      => 'This permanently removes only File 01-owned governance/runtime data. Companion data and legacy pages are not deleted.',
			'requirements' => array(
				'SPF_ALLOW_DESTRUCTIVE_PURGE constant must be true',
				'current actor must have purge_sabri_foundation',
				'non-empty backup reference',
				'exact typed confirmation',
				'fresh matching plan hash',
			),
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return hash( 'sha256', wp_json_encode( $plan ) );
	}

	public static function apply( $confirmation, $backup_reference, $expected_hash ) {
		global $wpdb;
		$prior = get_option( 'spf_external_purge_receipt', array() );
		if ( is_array( $prior ) && isset( $prior['status'], $prior['plan_hash'] ) && 'completed' === $prior['status'] && hash_equals( (string) $prior['plan_hash'], (string) $expected_hash ) ) {
			return $prior;
		}
		if ( ! defined( 'SPF_ALLOW_DESTRUCTIVE_PURGE' ) || true !== SPF_ALLOW_DESTRUCTIVE_PURGE ) {
			return new WP_Error( 'spf_purge_disabled', __( 'Destructive purge is disabled by configuration.', 'sabri-platform-foundation' ), array( 'status' => 403 ) );
		}
		$allowed = SPF_Authorization::require_action( 'purge' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( 'PURGE FILE 01 GOVERNANCE DATA' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact purge confirmation was not supplied.', 'sabri-platform-foundation' ) );
		}
		$backup_reference = substr( sanitize_text_field( $backup_reference ), 0, 191 );
		if ( strlen( $backup_reference ) < 8 ) {
			return new WP_Error( 'spf_backup_proof_required', __( 'A verifiable backup/restore evidence reference is required.', 'sabri-platform-foundation' ) );
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_purge_plan_changed', __( 'The purge plan changed. Generate and approve a fresh plan.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}

		$receipt = array(
			'purge_id'         => wp_generate_uuid4(),
			'plan_hash'        => $hash,
			'backup_reference' => $backup_reference,
			'actor_id'         => get_current_user_id(),
			'started_at'       => current_time( 'mysql', true ),
			'table_summary'    => $plan['tables'],
		);
		$precommit = SPF_Audit::record_required( 'purge_precommit', 'foundation_purge', $receipt['purge_id'], 'authorized', array( 'purpose' => 'destructive_purge', 'backup_reference' => $backup_reference ) );
		if ( is_wp_error( $precommit ) ) {
			return $precommit;
		}
		update_option( 'spf_external_purge_receipt', $receipt, false );

		foreach ( array_reverse( array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' ) ) as $name ) {
			$table = SPF_Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
		}
		foreach ( self::owned_options() as $option ) {
			if ( 'spf_external_purge_receipt' !== $option ) {
				delete_option( $option );
			}
		}
		$receipt['completed_at'] = current_time( 'mysql', true );
		$receipt['status'] = 'completed';
		update_option( 'spf_external_purge_receipt', $receipt, false );
		return $receipt;
	}

	private static function owned_options() {
		return array(
			'spf_activation_lock',
			'spf_activation_snapshot',
			'spf_activation_state',
			'spf_version',
			'spf_schema_version',
			'spf_contract_version',
			'spf_builtin_contracts_registered',
			'spf_reconciliation_snapshot',
			'spf_reconciliation_state',
			'spf_external_purge_receipt',
			'spf_audit_chain_lock',
			'spf_outbox_dispatch_lock',
		);
	}
}

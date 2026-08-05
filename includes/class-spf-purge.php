<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Purge {
	public static function plan() {
		global $wpdb;
		$tables = array();
		foreach ( SPF_Installer::table_names() as $name ) {
			$table = SPF_Installer::table( $name );
			$exists = SPF_Runtime::table_exists( $table );
			$tables[] = array(
				'name' => $name,
				'exists' => $exists,
				'rows' => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
				'engine' => $exists ? SPF_Runtime::table_engine( $table ) : '',
			);
		}
		$holds = SPF_Runtime::table_exists( SPF_Installer::table( 'privacy_holds' ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SPF_Installer::table( 'privacy_holds' ) . " WHERE active=1" ) : 0; // phpcs:ignore
		return array(
			'generated_at' => SPF_Runtime::now_mysql(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'tables' => $tables,
			'options' => SPF_Installer::owned_options(),
			'active_legal_holds' => $holds,
			'audit_chain_head' => self::audit_chain_head(),
			'warning' => 'This permanently removes only File 01-owned governance/runtime data after a reversible quarantine rename. Companion data and legacy pages are not deleted.',
			'requirements' => array(
				'SPF_ALLOW_DESTRUCTIVE_PURGE constant must be true',
				'current actor must hold a valid short-lived File 00 Founder claim',
				'independent backup and restore evidence must be verified structurally',
				'File 24 or approved assurance adapter must accept the evidence envelope',
				'no active legal/privacy hold may exist',
				'exact typed confirmation and fresh matching plan hash',
			),
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return SPF_Runtime::hash( $plan );
	}

	/**
	 * Destructive purge is deliberately not exposed as a REST mutation.
	 * Operators must invoke it through an authenticated administrative/CLI
	 * procedure after independent evidence adapters are installed.
	 */
	public static function apply( $confirmation, array $backup_evidence, $expected_hash ) {
		global $wpdb;
		if ( ! defined( 'SPF_ALLOW_DESTRUCTIVE_PURGE' ) || true !== SPF_ALLOW_DESTRUCTIVE_PURGE ) {
			return new WP_Error( 'spf_purge_disabled', __( 'Destructive purge is disabled by configuration.', 'sabri-platform-foundation' ), array( 'status' => 403 ) );
		}
		$allowed = SPF_Authorization::require_action( 'purge', array( 'object_id' => 'file-01-governance-data' ), array( 'purpose' => 'destructive_purge' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( 'PURGE FILE 01 GOVERNANCE DATA' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact purge confirmation was not supplied.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_purge_plan_changed', __( 'The purge plan changed. Generate and approve a fresh plan.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $plan['active_legal_holds'] ) ) {
			return new WP_Error( 'spf_purge_legal_hold', __( 'Destructive purge is blocked by an active legal/privacy hold.', 'sabri-platform-foundation' ), array( 'status' => 423 ) );
		}
		$verified = SPF_Runtime::verify_evidence(
			'spf_verify_backup_restore_evidence',
			array( 'operation'=>'file01_purge','plan_hash'=>$hash,'submitted_evidence'=>$backup_evidence,'table_summary'=>$plan['tables'] ),
			array( 'backup_id','backup_checksum','restore_tested_at','restore_environment','verifier','expires_at' )
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$assurance = SPF_Runtime::verify_evidence(
			'spf_verify_file24_purge_assurance',
			array( 'operation'=>'file01_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'audit_chain_head'=>$plan['audit_chain_head'] ),
			array( 'assurance_id','reviewed_at','verifier','expires_at' )
		);
		if ( is_wp_error( $assurance ) ) {
			return $assurance;
		}
		$lock = SPF_Runtime::acquire_lock( 'destructive_purge', 3600, get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$purge_id = wp_generate_uuid4();
		$receipt = array(
			'purge_id' => $purge_id,
			'plan_hash' => $hash,
			'backup_evidence_hash' => $verified['evidence_hash'],
			'assurance_evidence_hash' => $assurance['evidence_hash'],
			'audit_chain_head' => $plan['audit_chain_head'],
			'actor_id' => get_current_user_id(),
			'started_at' => SPF_Runtime::now_mysql(),
			'status' => 'preparing',
			'table_summary' => $plan['tables'],
			'quarantine_tables' => array(),
		);
		$prior = get_option( 'spf_external_purge_receipt', array() );
		if ( is_array( $prior ) && 'completed' === ( $prior['status'] ?? '' ) && hash_equals( (string) ( $prior['plan_hash'] ?? '' ), $hash ) ) {
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $prior;
		}
		$precommit = SPF_Audit::record_required( 'purge_precommit', 'foundation_purge', $purge_id, 'authorized', array( 'purpose'=>'destructive_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'assurance_evidence_hash'=>$assurance['evidence_hash'] ) );
		if ( is_wp_error( $precommit ) ) {
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $precommit;
		}
		$receipt['audit_chain_head'] = self::audit_chain_head();
		update_option( 'spf_external_purge_receipt', $receipt, false );
		do_action( 'spf_purge_external_receipt', $receipt, 'precommit' );

		$renamed = array();
		try {
			// First quarantine all tables by renaming. Until every rename succeeds,
			// the operation remains reversible without relying on DDL rollback.
			foreach ( SPF_Installer::table_names() as $name ) {
				$table = SPF_Installer::table( $name );
				if ( ! SPF_Runtime::table_exists( $table ) ) {
					continue;
				}
				$quarantine = substr( $table, 0, 48 ) . '_purge_' . substr( str_replace( '-', '', $purge_id ), 0, 8 );
				$wpdb->query( "DROP TABLE IF EXISTS {$quarantine}" ); // phpcs:ignore
				if ( false === $wpdb->query( "RENAME TABLE {$table} TO {$quarantine}" ) ) { // phpcs:ignore
					throw new RuntimeException( 'A File 01 table could not be quarantined: ' . $name );
				}
				$renamed[ $name ] = $quarantine;
			}
			$receipt['status'] = 'quarantined';
			$receipt['quarantine_tables'] = $renamed;
			update_option( 'spf_external_purge_receipt', $receipt, false );
			foreach ( $renamed as $name => $quarantine ) {
				if ( false === $wpdb->query( "DROP TABLE {$quarantine}" ) ) { // phpcs:ignore
					throw new RuntimeException( 'A quarantined File 01 table could not be dropped: ' . $name );
				}
				if ( SPF_Runtime::table_exists( $quarantine ) ) {
					throw new RuntimeException( 'A quarantined File 01 table remains after DROP: ' . $name );
				}
			}
			foreach ( SPF_Installer::owned_options() as $option ) {
				if ( ! in_array( $option, array( 'spf_external_purge_receipt', SPF_Installer::LOCK_OPTION ), true ) ) {
					delete_option( $option );
				}
			}
			$receipt['completed_at'] = SPF_Runtime::now_mysql();
			$receipt['status'] = 'completed';
			$receipt['verification'] = array( 'tables_remaining' => self::owned_tables_remaining(), 'options_checked' => true );
			if ( ! empty( $receipt['verification']['tables_remaining'] ) ) {
				throw new RuntimeException( 'One or more File 01 tables remain after purge.' );
			}
			update_option( 'spf_external_purge_receipt', $receipt, false );
			do_action( 'spf_purge_external_receipt', $receipt, 'completed' );
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $receipt;
		} catch ( Throwable $error ) {
			// Restore only while quarantined tables still exist. Once a table was
			// dropped, DDL cannot be rolled back; the external receipt truthfully
			// records a partial failure for restore-from-backup operations.
			foreach ( array_reverse( $renamed, true ) as $name => $quarantine ) {
				$table = SPF_Installer::table( $name );
				if ( SPF_Runtime::table_exists( $quarantine ) && ! SPF_Runtime::table_exists( $table ) ) {
					$wpdb->query( "RENAME TABLE {$quarantine} TO {$table}" ); // phpcs:ignore
				}
			}
			$receipt['failed_at'] = SPF_Runtime::now_mysql();
			$receipt['status'] = empty( self::owned_tables_remaining() ) ? 'failed_after_drop' : 'failed_compensated_or_partial';
			$receipt['error_code'] = 'purge_incomplete';
			$receipt['tables_remaining'] = self::owned_tables_remaining();
			update_option( 'spf_external_purge_receipt', $receipt, false );
			do_action( 'spf_purge_external_receipt', $receipt, 'failed' );
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return new WP_Error( 'spf_purge_incomplete', $error->getMessage(), array( 'status'=>500,'receipt'=>$receipt ) );
		}
	}

	private static function audit_chain_head() {
		global $wpdb;
		$table = SPF_Installer::table( 'audit' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return str_repeat( '0', 64 );
		}
		$head = (string) $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
		return preg_match( '/^[a-f0-9]{64}$/', $head ) ? $head : str_repeat( '0', 64 );
	}

	private static function owned_tables_remaining() {
		$remaining = array();
		foreach ( SPF_Installer::table_names() as $name ) {
			if ( SPF_Runtime::table_exists( SPF_Installer::table( $name ) ) ) {
				$remaining[] = $name;
			}
		}
		return $remaining;
	}
}

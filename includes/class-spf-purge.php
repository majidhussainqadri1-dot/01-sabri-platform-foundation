<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Purge {
	public static function plan() {
		global $wpdb;
		$tables = array();
		$query_failures = array();
		foreach ( SPF_Installer::table_names() as $name ) {
			$table = SPF_Installer::table( $name );
			$exists = SPF_Runtime::table_exists( $table );
			$rows = 0;
			if ( $exists ) {
				$rows_raw = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
				if ( ! empty( $wpdb->last_error ) || null === $rows_raw ) {
					$query_failures[] = 'count:' . $name;
					$rows = null;
				} else {
					$rows = (int) $rows_raw;
				}
			}
			$tables[] = array(
				'name' => $name,
				'exists' => $exists,
				'rows' => $rows,
				'engine' => $exists ? SPF_Runtime::table_engine( $table ) : '',
			);
		}
		$holds = 0;
		$holds_table = SPF_Installer::table( 'privacy_holds' );
		if ( SPF_Runtime::table_exists( $holds_table ) ) {
			$holds_raw = $wpdb->get_var( "SELECT COUNT(*) FROM {$holds_table} WHERE active=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
			if ( ! empty( $wpdb->last_error ) || null === $holds_raw ) {
				$query_failures[] = 'count:privacy_holds_active';
				$holds = null;
			} else {
				$holds = (int) $holds_raw;
			}
		} else {
			$query_failures[] = 'missing:privacy_holds';
			$holds = null;
		}
		return array(
			'generated_at' => SPF_Runtime::now_mysql(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'tables' => $tables,
			'options' => SPF_Installer::owned_options(),
			'active_legal_holds' => $holds,
			'query_failures' => $query_failures,
			'audit_chain_head' => self::audit_chain_head(),
			'stale_quarantine_tables' => self::stale_quarantine_tables(),
			'warning' => 'This permanently removes only File 01-owned governance/runtime data after a reversible quarantine rename. Companion data and legacy pages are not deleted.',
			'requirements' => array(
				'SPF_ALLOW_DESTRUCTIVE_PURGE constant must be true',
				'current actor must hold a valid short-lived File 00 Founder claim',
				'independent backup and restore evidence must be verified structurally',
				'File 24 or approved assurance adapter must accept the evidence envelope',
				'no active legal/privacy hold or stale purge quarantine may exist',
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
		$expected_hash = strtolower( trim( (string) $expected_hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return new WP_Error( 'spf_purge_plan_hash_invalid', __( 'A valid purge-plan SHA-256 is required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}

		// A completed receipt is an idempotent terminal result. It is safe to
		// replay after authorization/confirmation without rebuilding a now-empty
		// post-purge plan that would necessarily hash differently.
		$prior = get_option( 'spf_external_purge_receipt', array() );
		if ( is_array( $prior ) && 'completed' === ( $prior['status'] ?? '' ) && ! empty( $prior['plan_hash'] ) && hash_equals( (string) $prior['plan_hash'], $expected_hash ) ) {
			$prior['idempotent_replay'] = true;
			return $prior;
		}

		$plan = self::plan();
		if ( ! empty( $plan['query_failures'] ) ) {
			return new WP_Error( 'spf_purge_plan_query_failed', __( 'Destructive purge is blocked because its table/hold inventory could not be verified.', 'sabri-platform-foundation' ), array( 'status'=>503, 'failures'=>$plan['query_failures'] ) );
		}
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, $expected_hash ) ) {
			return new WP_Error( 'spf_purge_plan_changed', __( 'The purge plan changed. Generate and approve a fresh plan.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $plan['active_legal_holds'] ) ) {
			return new WP_Error( 'spf_purge_legal_hold', __( 'Destructive purge is blocked by an active legal/privacy hold.', 'sabri-platform-foundation' ), array( 'status' => 423 ) );
		}
		if ( ! empty( $plan['stale_quarantine_tables'] ) ) {
			return new WP_Error( 'spf_purge_stale_quarantine', __( 'Destructive purge is blocked because a previous purge quarantine still exists.', 'sabri-platform-foundation' ), array( 'status' => 423, 'tables' => $plan['stale_quarantine_tables'] ) );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $plan['audit_chain_head'] ) ) {
			return new WP_Error( 'spf_purge_audit_head_invalid', __( 'Destructive purge is blocked because the audit-chain head is invalid.', 'sabri-platform-foundation' ), array( 'status' => 412 ) );
		}

		$backup_context = array( 'operation'=>'file01_purge','plan_hash'=>$hash,'submitted_evidence'=>$backup_evidence,'table_summary'=>$plan['tables'] );
		$verified = SPF_Runtime::verify_evidence(
			'spf_verify_backup_restore_evidence',
			$backup_context,
			array( 'backup_id','backup_checksum','restore_tested_at','restore_environment','verifier','expires_at','operation','plan_hash' )
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! hash_equals( 'file01_purge', (string) $verified['operation'] ) || ! hash_equals( $hash, strtolower( (string) $verified['plan_hash'] ) ) ) {
			return new WP_Error( 'spf_purge_backup_evidence_binding_invalid', __( 'Backup/restore evidence is not bound to this exact purge plan.', 'sabri-platform-foundation' ), array( 'status'=>412 ) );
		}
		$assurance_context = array( 'operation'=>'file01_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'audit_chain_head'=>$plan['audit_chain_head'] );
		$assurance = SPF_Runtime::verify_evidence(
			'spf_verify_file24_purge_assurance',
			$assurance_context,
			array( 'assurance_id','reviewed_at','verifier','expires_at','operation','plan_hash','backup_evidence_hash','audit_chain_head' )
		);
		if ( is_wp_error( $assurance ) ) {
			return $assurance;
		}
		foreach ( array( 'operation','plan_hash','backup_evidence_hash','audit_chain_head' ) as $binding_field ) {
			if ( ! hash_equals( (string) $assurance_context[ $binding_field ], (string) $assurance[ $binding_field ] ) ) {
				return new WP_Error( 'spf_purge_assurance_binding_invalid', __( 'File 24 purge assurance is not bound to this exact destructive-operation evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>412, 'field'=>$binding_field ) );
			}
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
		$precommit = SPF_Audit::record_required( 'purge_precommit', 'foundation_purge', $purge_id, 'authorized', array( 'purpose'=>'destructive_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'assurance_evidence_hash'=>$assurance['evidence_hash'] ) );
		if ( is_wp_error( $precommit ) ) {
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $precommit;
		}
		$receipt['audit_chain_head'] = self::audit_chain_head();
		$persisted = self::persist_receipt( $receipt );
		if ( is_wp_error( $persisted ) ) {
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $persisted;
		}
		do_action( 'spf_purge_external_receipt', $receipt, 'precommit' );

		$renamed = array();
		try {
			// Build one multi-table RENAME statement. MySQL executes a multi-table
			// RENAME atomically, so File 01 never enters a half-quarantined state.
			$rename_parts = array();
			foreach ( SPF_Installer::table_names() as $name ) {
				$table = SPF_Installer::table( $name );
				if ( ! SPF_Runtime::table_exists( $table ) ) {
					continue;
				}
				$quarantine = substr( $table, 0, 48 ) . '_purge_' . substr( str_replace( '-', '', $purge_id ), 0, 8 );
				if ( SPF_Runtime::table_exists( $quarantine ) ) {
					throw new RuntimeException( 'A purge quarantine collision already exists: ' . $name );
				}
				$renamed[ $name ] = $quarantine;
				$rename_parts[] = "{$table} TO {$quarantine}";
			}
			if ( $rename_parts && false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $rename_parts ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are generated only from allowlisted File 01 tables.
				throw new RuntimeException( 'File 01 tables could not be atomically quarantined.' );
			}
			foreach ( $renamed as $name => $quarantine ) {
				if ( ! SPF_Runtime::table_exists( $quarantine ) || SPF_Runtime::table_exists( SPF_Installer::table( $name ) ) ) {
					throw new RuntimeException( 'Atomic purge quarantine verification failed: ' . $name );
				}
			}
			$receipt['status'] = 'quarantined';
			$receipt['quarantine_tables'] = $renamed;
			$persisted = self::persist_receipt( $receipt );
			if ( is_wp_error( $persisted ) ) {
				throw new RuntimeException( 'The quarantine receipt could not be durably verified.' );
			}

			foreach ( $renamed as $name => $quarantine ) {
				if ( false === $wpdb->query( "DROP TABLE {$quarantine}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated allowlisted quarantine identifier.
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
			$transient_cleanup = self::delete_owned_transients();
			if ( is_wp_error( $transient_cleanup ) ) {
				throw new RuntimeException( $transient_cleanup->get_error_message() );
			}
			$receipt['completed_at'] = SPF_Runtime::now_mysql();
			$receipt['status'] = 'completed';
			$receipt['verification'] = array( 'tables_remaining' => self::owned_tables_remaining(), 'stale_quarantines' => self::stale_quarantine_tables(), 'options_checked' => true );
			if ( ! empty( $receipt['verification']['tables_remaining'] ) || ! empty( $receipt['verification']['stale_quarantines'] ) ) {
				throw new RuntimeException( 'One or more File 01 tables or purge quarantines remain after purge.' );
			}
			$persisted = self::persist_receipt( $receipt );
			if ( is_wp_error( $persisted ) ) {
				throw new RuntimeException( 'The completed purge receipt could not be durably verified.' );
			}
			do_action( 'spf_purge_external_receipt', $receipt, 'completed' );
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return $receipt;
		} catch ( Throwable $error ) {
			// A multi-table rename can be reversed atomically while every quarantine
			// still exists. Once any DROP succeeds, external restore evidence is the
			// only truthful recovery source.
			$can_restore = ! empty( $renamed );
			$restore_parts = array();
			foreach ( $renamed as $name => $quarantine ) {
				$table = SPF_Installer::table( $name );
				if ( ! SPF_Runtime::table_exists( $quarantine ) || SPF_Runtime::table_exists( $table ) ) {
					$can_restore = false;
					break;
				}
				$restore_parts[] = "{$quarantine} TO {$table}";
			}
			if ( $can_restore && $restore_parts ) {
				$restored = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $restore_parts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- generated allowlisted identifiers.
				if ( false === $restored ) {
					$can_restore = false;
				} else {
					foreach ( $renamed as $name => $quarantine ) {
						if ( ! SPF_Runtime::table_exists( SPF_Installer::table( $name ) ) || SPF_Runtime::table_exists( $quarantine ) ) {
							$can_restore = false;
							break;
						}
					}
				}
			}
			$receipt['failed_at'] = SPF_Runtime::now_mysql();
			$receipt['status'] = empty( self::owned_tables_remaining() ) ? 'failed_after_drop' : ( $can_restore ? 'failed_compensated' : 'failed_partial' );
			$receipt['error_code'] = 'purge_incomplete';
			$receipt['tables_remaining'] = self::owned_tables_remaining();
			$receipt['stale_quarantine_tables'] = self::stale_quarantine_tables();
			$failed_receipt_persist = self::persist_receipt( $receipt );
			if ( is_wp_error( $failed_receipt_persist ) ) {
				$receipt['receipt_persistence_failed'] = true;
			}
			do_action( 'spf_purge_external_receipt', $receipt, 'failed' );
			SPF_Runtime::release_lock( 'destructive_purge', $lock );
			return new WP_Error( 'spf_purge_incomplete', sanitize_text_field( $error->getMessage() ), array( 'status'=>500,'receipt'=>$receipt ) );
		}
	}

	private static function audit_chain_head() {
		global $wpdb;
		$table = SPF_Installer::table( 'audit' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return str_repeat( '0', 64 );
		}
		$head = (string) $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
		if ( '' === $head ) {
			return str_repeat( '0', 64 );
		}
		return preg_match( '/^[a-f0-9]{64}$/', $head ) ? $head : 'invalid';
	}

	private static function stale_quarantine_tables() {
		global $wpdb;
		$prefix = $wpdb->esc_like( $wpdb->prefix . 'spf_' ) . '%_purge_%';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix ) );
		$result = array();
		foreach ( (array) $rows as $table ) {
			$table = (string) $table;
			if ( preg_match( '/^' . preg_quote( $wpdb->prefix . 'spf_', '/' ) . '[a-z0-9_]+_purge_[a-f0-9]{8}$/', $table ) ) {
				$result[] = $table;
			}
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	private static function delete_owned_transients() {
		global $wpdb;
		$patterns = array( '_transient_spf_rl_%', '_transient_timeout_spf_rl_%' );
		foreach ( $patterns as $pattern ) {
			$like = $wpdb->esc_like( str_replace( '%', '', $pattern ) ) . '%';
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			if ( false === $deleted ) {
				return new WP_Error( 'spf_purge_transient_cleanup_failed', __( 'File 01 rate-limit transients could not be removed safely.', 'sabri-platform-foundation' ) );
			}
		}
		return true;
	}

	private static function persist_receipt( array $receipt ) {
		update_option( 'spf_external_purge_receipt', $receipt, false );
		$stored = get_option( 'spf_external_purge_receipt', array() );
		if ( ! is_array( $stored ) || SPF_Runtime::hash( $stored ) !== SPF_Runtime::hash( $receipt ) ) {
			return new WP_Error( 'spf_purge_receipt_persistence_failed', __( 'The destructive-purge receipt could not be durably verified.', 'sabri-platform-foundation' ), array( 'status'=>500 ) );
		}
		return true;
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

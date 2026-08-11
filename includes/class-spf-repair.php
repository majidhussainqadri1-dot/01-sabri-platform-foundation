<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reversible repair of File 01-owned state only.
 *
 * Legacy cutover options are deliberately excluded. spf_page_map and
 * spf_founder_user_id are reconciliation-owned state and may only be removed
 * by SPF_Reconciler after canonical File 20/21 owner acknowledgement and
 * reversible receipts have been verified.
 */
final class SPF_Repair {
	private const LOCK = 'repair';
	private const RECONCILIATION_OWNED_OPTIONS = array( 'spf_page_map', 'spf_founder_user_id' );

	public static function plan() {
		$actions  = array();
		$blockers = array();
		$warnings = array();
		foreach ( SPF_Installer::table_names() as $name ) {
			$table = SPF_Installer::table( $name );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				$actions[] = array( 'action' => 'recreate_missing_owned_schema', 'target' => $name );
			}
		}
		$schema = SPF_Installer::verify_schema();
		if ( is_wp_error( $schema ) ) {
			$data = $schema->get_error_data();
			if ( is_array( $data ) && ! empty( $data['defects'] ) ) {
				foreach ( $data['defects'] as $defect_key => $defect_code ) {
					if ( 'missing_table' !== (string) $defect_code ) {
						$blockers[] = array( 'code'=>'schema_upgrade_required', 'defect'=>sanitize_text_field((string)$defect_key), 'defect_code'=>sanitize_key((string)$defect_code) );
					}
			}
		}
		foreach ( self::RECONCILIATION_OWNED_OPTIONS as $option ) {
			if ( self::option_exists( $option ) ) {
				$warnings[] = array(
					'code'   => 'legacy_reconciliation_required',
					'target' => $option,
					'owner'  => 'SPF_Reconciler',
				);
			}
		}
		if ( SPF_Runtime::table_exists( SPF_Installer::table( 'routes' ) ) ) {
			foreach ( SPF_Registry::list_routes() as $route ) {
				if ( 'file-01' === $route['owner_module'] && $route['page_id'] && ! get_post( $route['page_id'] ) ) {
					$actions[] = array( 'action' => 'clear_missing_owned_page_reference', 'target' => $route['route_key'], 'record_version' => $route['record_version'] );
				}
		}
		}
		return array(
			'generated_at' => SPF_Runtime::now_mysql(),
			'actions'      => $actions,
			'blockers'     => $blockers,
			'warnings'     => $warnings,
			'law'          => 'Only File 01-owned schema and File 01 route references may be repaired here. File 01 legacy cutover options belong exclusively to owner-acknowledged SPF_Reconciler; companion records are never repaired directly.',
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return SPF_Runtime::hash( $plan );
	}

	public static function apply( $confirmation, $expected_hash ) {
		if ( 'REPAIR FILE 01 OWNED STATE' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact repair confirmation was not supplied.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key' => 'file-01' ), array( 'purpose' => 'safe_repair' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_repair_plan_changed', __( 'The repair plan changed. Generate a new dry run.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $plan['blockers'] ) ) {
			return new WP_Error( 'spf_repair_blocked', __( 'Repair is blocked because a versioned schema upgrade is required.', 'sabri-platform-foundation' ), array( 'status' => 409, 'blockers' => $plan['blockers'] ) );
		}
		$token = SPF_Runtime::acquire_lock( self::LOCK, 900, get_current_user_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$snapshot = self::snapshot( $plan );
		$changed  = array();
		try {
			$pre = SPF_Audit::record_required( 'repair_precommit', 'foundation_repair', $hash, 'authorized', array( 'purpose' => 'safe_repair' ) );
			if ( is_wp_error( $pre ) ) {
				throw new RuntimeException( $pre->get_error_message() );
			}
			$missing = array_values( array_filter( $plan['actions'], static function ( $action ) { return 'recreate_missing_owned_schema' === $action['action']; } ) );
			if ( $missing ) {
				SPF_Installer::install_schema();
				foreach ( $missing as $action ) {
					if ( ! SPF_Runtime::table_exists( SPF_Installer::table( $action['target'] ) ) ) {
						throw new RuntimeException( 'Missing File 01 table could not be recreated: ' . $action['target'] );
					}
					$changed[] = $action;
				}
			}
			foreach ( $plan['actions'] as $action ) {
				if ( 'recreate_missing_owned_schema' === $action['action'] ) {
					continue;
				}
				$result = self::apply_action( $action );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
				$changed[] = $action;
			}
			$schema = SPF_Installer::verify_schema();
			if ( is_wp_error( $schema ) ) {
				throw new RuntimeException( 'Post-repair schema verification failed.' );
			}
			$event = SPF_Event_Bus::publish( 'FoundationRepairCompleted.v1', 'foundation_repair', $hash, array( 'changed_count' => count( $changed ) ), 1, 'repair-' . $hash );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$trace = SPF_Audit::record_required( 'repair_owned_mapping', 'foundation_repair', $hash, 'success', array( 'purpose' => 'safe_repair', 'changed_count' => count( $changed ) ) );
			if ( is_wp_error( $trace ) ) {
				throw new RuntimeException( $trace->get_error_message() );
			}
			return array( 'trace_id' => $trace, 'plan_hash' => $hash, 'changed' => $changed, 'warnings' => $plan['warnings'] ?? array(), 'status' => 'applied' );
		} catch ( Throwable $error ) {
			$restored = self::restore_snapshot( $snapshot );
			$compensated = ! is_wp_error( $restored );
			SPF_Audit::record( 'repair_compensated', 'foundation_repair', $hash, $compensated?'failed':'compensation_incomplete', array( 'purpose'=>'safe_repair', 'error'=>$error->getMessage() ) );
			return new WP_Error( $compensated?'spf_repair_failed':'spf_repair_compensation_incomplete', $compensated ? $error->getMessage() : __( 'Repair failed and its compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>$compensated?409:500 ) );
		} finally {
			SPF_Runtime::release_lock( self::LOCK, $token );
		}
	}

	private static function apply_action( array $action ) {
		global $wpdb;
		if ( 'remove_legacy_option' === ( $action['action'] ?? '' ) ) {
			$target = sanitize_key( (string) ( $action['target'] ?? '' ) );
			if ( in_array( $target, self::RECONCILIATION_OWNED_OPTIONS, true ) ) {
				return new WP_Error( 'spf_repair_reconciliation_owned_option_rejected', __( 'This legacy option is owned by the guarded reconciliation workflow and cannot be removed by Safe Repair.', 'sabri-platform-foundation' ), array( 'status' => 409, 'target' => $target ) );
			}
			return new WP_Error( 'spf_repair_legacy_option_action_rejected', __( 'Safe Repair does not remove legacy options.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( 'clear_missing_owned_page_reference' === ( $action['action'] ?? '' ) ) {
			$updated = $wpdb->update(
				SPF_Installer::table( 'routes' ),
				array( 'page_id' => null, 'status' => 'degraded', 'record_version' => (int) $action['record_version'] + 1, 'updated_at' => SPF_Runtime::now_mysql() ),
				array( 'route_key' => $action['target'], 'owner_module' => 'file-01', 'record_version' => (int) $action['record_version'] ),
				array( null, '%s', '%d', '%s' ),
				array( '%s', '%s', '%d' )
			);
			return 1 === $updated ? true : new WP_Error( 'spf_repair_stale_route', __( 'The route changed before repair and was not modified.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		return new WP_Error( 'spf_unknown_repair_action', __( 'An unknown File 01 repair action was rejected.', 'sabri-platform-foundation' ) );
	}

	private static function snapshot( array $plan ) {
		global $wpdb;
		$snapshot = array( 'routes' => array(), 'created_tables' => array() );
		foreach ( $plan['actions'] as $action ) {
			if ( 'clear_missing_owned_page_reference' === $action['action'] ) {
				$snapshot['routes'][ $action['target'] ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'routes' ) . ' WHERE route_key=%s AND owner_module=%s', $action['target'], 'file-01' ), ARRAY_A );
			}
			if ( 'recreate_missing_owned_schema' === $action['action'] ) {
				$snapshot['created_tables'][] = $action['target'];
			}
		}
		return $snapshot;
	}

	private static function restore_snapshot( array $snapshot ) {
		global $wpdb;
		$failures = array();
		foreach ( $snapshot['routes'] as $route ) {
			if ( is_array( $route ) && ! empty( $route['id'] ) && SPF_Runtime::table_exists( SPF_Installer::table( 'routes' ) ) ) {
				$result = $wpdb->replace( SPF_Installer::table( 'routes' ), $route );
				if ( false === $result ) { $failures[] = 'route:'.($route['route_key']??'unknown'); }
			}
		}
		foreach ( $snapshot['created_tables'] as $name ) {
			$table = SPF_Installer::table( $name );
			if ( SPF_Runtime::table_exists( $table ) ) { $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); }
			if ( SPF_Runtime::table_exists( $table ) ) { $failures[] = 'table:'.$name; }
		}
		return empty($failures) ? true : new WP_Error( 'spf_repair_restore_incomplete', __( 'Repair compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'failures'=>$failures ) );
	}

	private static function option_exists( $option ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $option ) );
	}
}

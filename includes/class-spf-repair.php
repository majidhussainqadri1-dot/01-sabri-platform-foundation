<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reversible repair of File 01-owned state only.
 */
final class SPF_Repair {
	private const LOCK = 'repair';

	public static function plan() {
		global $wpdb;
		$actions  = array();
		$blockers = array();
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
				foreach ( $data['defects'] as $defect ) {
					if ( false === strpos( (string) $defect, ':missing_table' ) ) {
						$blockers[] = array( 'code' => 'schema_upgrade_required', 'defect' => sanitize_text_field( $defect ) );
					}
				}
			}
		}
		foreach ( array( 'spf_page_map', 'spf_founder_user_id' ) as $option ) {
			if ( self::option_exists( $option ) ) {
				$actions[] = array( 'action' => 'remove_legacy_option', 'target' => $option );
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
			'law'          => 'Only File 01-owned schema, legacy options and File 01 route references may be changed. Companion records are never repaired directly.',
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return SPF_Runtime::hash( $plan );
	}

	public static function apply( $confirmation, $expected_hash ) {
		global $wpdb;
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
			return array( 'trace_id' => $trace, 'plan_hash' => $hash, 'changed' => $changed, 'status' => 'applied' );
		} catch ( Throwable $error ) {
			self::restore_snapshot( $snapshot );
			SPF_Audit::record( 'repair_compensated', 'foundation_repair', $hash, 'failed', array( 'purpose' => 'safe_repair', 'error' => $error->getMessage() ) );
			return new WP_Error( 'spf_repair_failed', $error->getMessage(), array( 'status' => 409 ) );
		} finally {
			SPF_Runtime::release_lock( self::LOCK, $token );
		}
	}

	private static function apply_action( array $action ) {
		global $wpdb;
		if ( 'remove_legacy_option' === $action['action'] ) {
			delete_option( $action['target'] );
			return self::option_exists( $action['target'] ) ? new WP_Error( 'spf_repair_option_failed', __( 'A legacy File 01 option could not be removed.', 'sabri-platform-foundation' ) ) : true;
		}
		if ( 'clear_missing_owned_page_reference' === $action['action'] ) {
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
		$snapshot = array( 'options' => array(), 'routes' => array(), 'created_tables' => array() );
		foreach ( array( 'spf_page_map', 'spf_founder_user_id' ) as $option ) {
			$snapshot['options'][ $option ] = array( 'exists' => self::option_exists( $option ), 'value' => get_option( $option ) );
		}
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
		foreach ( $snapshot['options'] as $option => $state ) {
			$state['exists'] ? update_option( $option, $state['value'], false ) : delete_option( $option );
		}
		foreach ( $snapshot['routes'] as $route ) {
			if ( is_array( $route ) && ! empty( $route['id'] ) && SPF_Runtime::table_exists( SPF_Installer::table( 'routes' ) ) ) {
				$wpdb->replace( SPF_Installer::table( 'routes' ), $route );
			}
		}
		foreach ( $snapshot['created_tables'] as $name ) {
			$table = SPF_Installer::table( $name );
			if ( SPF_Runtime::table_exists( $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
			}
		}
	}

	private static function option_exists( $option ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $option ) );
	}
}

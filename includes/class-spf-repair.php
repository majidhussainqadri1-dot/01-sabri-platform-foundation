<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Repair {
	public static function plan() {
		global $wpdb;
		$actions = array();
		foreach ( array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' ) as $name ) {
			$table = SPF_Installer::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$actions[] = array( 'action' => 'recreate_owned_schema', 'target' => $name );
			}
		}
		if ( false !== get_option( 'spf_page_map', false ) ) {
			$actions[] = array( 'action' => 'remove_legacy_mapping_option', 'target' => 'spf_page_map' );
		}
		if ( false !== get_option( 'spf_founder_user_id', false ) ) {
			$actions[] = array( 'action' => 'remove_unsafe_founder_option', 'target' => 'spf_founder_user_id' );
		}
		foreach ( SPF_Registry::list_routes() as $route ) {
			if ( $route['page_id'] && ! get_post( $route['page_id'] ) ) {
				$actions[] = array( 'action' => 'clear_missing_owned_page_reference', 'target' => $route['route_key'], 'record_version' => $route['record_version'] );
			}
		}
		return array(
			'generated_at' => current_time( 'mysql', true ),
			'actions'      => $actions,
			'law'          => 'Only File 01-owned schema, mappings, flags and caches may be changed.',
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return hash( 'sha256', wp_json_encode( $plan ) );
	}

	public static function apply( $confirmation, $expected_hash ) {
		global $wpdb;
		if ( 'REPAIR FILE 01 OWNED STATE' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact repair confirmation was not supplied.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_repair_plan_changed', __( 'The repair plan changed. Generate a new dry run.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$precommit = SPF_Audit::record_required( 'repair_precommit', 'foundation_repair', $hash, 'authorized', array( 'purpose' => 'safe_repair' ) );
		if ( is_wp_error( $precommit ) ) {
			return $precommit;
		}

		$snapshot = array(
			'spf_page_map'        => get_option( 'spf_page_map', null ),
			'spf_founder_user_id' => get_option( 'spf_founder_user_id', null ),
			'routes'              => array(),
			'created_tables'      => array(),
		);
		foreach ( $plan['actions'] as $action ) {
			if ( 'clear_missing_owned_page_reference' === $action['action'] ) {
				$snapshot['routes'][ $action['target'] ] = $wpdb->get_row(
					$wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'routes' ) . ' WHERE route_key=%s', $action['target'] ),
					ARRAY_A
				);
			}
			if ( 'recreate_owned_schema' === $action['action'] ) {
				$snapshot['created_tables'][] = $action['target'];
			}
		}

		$changed = array();
		foreach ( $plan['actions'] as $action ) {
			$result = self::apply_action( $action );
			if ( is_wp_error( $result ) ) {
				self::restore_snapshot( $snapshot );
				SPF_Audit::record( 'repair_compensated', 'foundation_repair', $hash, 'failed', array( 'purpose' => 'safe_repair', 'error_code' => $result->get_error_code() ) );
				return $result;
			}
			$changed[] = $action;
		}

		wp_cache_delete( 'module_list', 'sabri_platform_foundation' );
		wp_cache_delete( 'route_list', 'sabri_platform_foundation' );
		wp_cache_delete( 'contract_list', 'sabri_platform_foundation' );
		$trace = SPF_Audit::record_required( 'repair_owned_mapping', 'foundation_repair', $hash, 'success', array( 'purpose' => 'safe_repair', 'changed_count' => count( $changed ) ) );
		if ( is_wp_error( $trace ) ) {
			self::restore_snapshot( $snapshot );
			return $trace;
		}
		return array( 'trace_id' => $trace, 'plan_hash' => $hash, 'changed' => $changed, 'status' => 'applied' );
	}

	private static function apply_action( array $action ) {
		global $wpdb;
		switch ( $action['action'] ) {
			case 'recreate_owned_schema':
				SPF_Installer::install_schema();
				$table = SPF_Installer::table( $action['target'] );
				return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
					? true
					: new WP_Error( 'spf_repair_schema_failed', __( 'A File 01 table could not be recreated.', 'sabri-platform-foundation' ) );
			case 'remove_legacy_mapping_option':
				delete_option( 'spf_page_map' );
				return '__missing__' === get_option( 'spf_page_map', '__missing__' )
					? true
					: new WP_Error( 'spf_repair_option_failed', __( 'The legacy route-map option could not be removed.', 'sabri-platform-foundation' ) );
			case 'remove_unsafe_founder_option':
				delete_option( 'spf_founder_user_id' );
				return '__missing__' === get_option( 'spf_founder_user_id', '__missing__' )
					? true
					: new WP_Error( 'spf_repair_option_failed', __( 'The unsafe legacy Founder option could not be removed.', 'sabri-platform-foundation' ) );
			case 'clear_missing_owned_page_reference':
				$updated = $wpdb->update(
					SPF_Installer::table( 'routes' ),
					array( 'page_id' => null, 'status' => 'degraded', 'record_version' => (int) $action['record_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ),
					array( 'route_key' => $action['target'], 'record_version' => (int) $action['record_version'] )
				);
				return 1 === $updated
					? true
					: new WP_Error( 'spf_repair_stale_route', __( 'The route changed before repair and was not modified.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		return new WP_Error( 'spf_unknown_repair_action', __( 'An unknown File 01 repair action was rejected.', 'sabri-platform-foundation' ) );
	}

	private static function restore_snapshot( array $snapshot ) {
		global $wpdb;
		null === $snapshot['spf_page_map'] ? delete_option( 'spf_page_map' ) : update_option( 'spf_page_map', $snapshot['spf_page_map'], false );
		null === $snapshot['spf_founder_user_id'] ? delete_option( 'spf_founder_user_id' ) : update_option( 'spf_founder_user_id', $snapshot['spf_founder_user_id'], false );
		foreach ( $snapshot['routes'] as $route ) {
			if ( ! is_array( $route ) || empty( $route['id'] ) ) {
				continue;
			}
			$wpdb->update(
				SPF_Installer::table( 'routes' ),
				array(
					'page_id'        => $route['page_id'],
					'status'         => $route['status'],
					'record_version' => $route['record_version'],
					'updated_at'     => $route['updated_at'],
				),
				array( 'id' => (int) $route['id'] )
			);
		}
		foreach ( $snapshot['created_tables'] as $name ) {
			$table = SPF_Installer::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
		}
	}
}

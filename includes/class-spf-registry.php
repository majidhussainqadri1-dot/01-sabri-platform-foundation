<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Registry {
	private const MODULE_STATES = array( 'unregistered', 'registered', 'compatible', 'active', 'degraded', 'suspended', 'retired' );
	private const CONTRACT_STATES = array( 'draft', 'approved', 'current', 'deprecated', 'retired' );
	private const ROUTE_STATES = array( 'registered', 'active', 'degraded', 'redirect', 'retired' );

	public static function register_manifest( array $manifest, array $context = array() ) {
		global $wpdb;
		$manifest = self::normalize_manifest( $manifest );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( ! SPF_Installer::is_internal_seed() ) {
			$allowed = SPF_Authorization::require_action(
				'register_manifest',
				array( 'module_key' => $manifest['module_key'], 'owner_file' => $manifest['owner_file'] ),
				array( 'purpose' => $context['purpose'] ?? 'manifest_registration' )
			);
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$table = SPF_Installer::table( 'modules' );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE module_key=%s", $manifest['module_key'] ), ARRAY_A );
		if ( $old && ! self::transition_allowed( 'module', $old['state'], $manifest['state'] ) ) {
			return new WP_Error( 'spf_invalid_module_transition', __( 'Invalid module transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && $old['owner_file'] !== $manifest['owner_file'] ) {
			return new WP_Error( 'spf_module_owner_conflict', __( 'A module owner cannot be silently changed.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && SPF_Installer::is_internal_seed() ) {
			$context['expected_version'] = (int) $old['record_version'];
		}
		if ( $old && ! isset( $context['expected_version'] ) ) {
			return new WP_Error( 'spf_expected_version_required', __( 'Updating a manifest requires its expected record version.', 'sabri-platform-foundation' ), array( 'status' => 428 ) );
		}
		if ( $old && (int) $context['expected_version'] !== (int) $old['record_version'] ) {
			return new WP_Error( 'spf_stale_record', __( 'The module changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$pre = SPF_Audit::record_required( 'register_manifest_precommit', 'foundation_module', $manifest['module_key'], 'authorized', array( 'purpose' => $context['purpose'] ?? 'manifest_registration' ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$now = SPF_Runtime::now_mysql();
			$data = array(
				'owner_file'       => $manifest['owner_file'],
				'owner_name'       => $manifest['owner_name'],
				'slug'             => $manifest['slug'],
				'namespace_prefix' => $manifest['namespace_prefix'],
				'software_version' => $manifest['software_version'],
				'contract_version' => $manifest['contract_version'],
				'state'            => $manifest['state'],
				'manifest_json'    => wp_json_encode( $manifest ),
				'updated_at'       => $now,
			);
			if ( strlen( $data['manifest_json'] ) > 262144 ) {
				throw new RuntimeException( 'Manifest too large.' );
			}
			if ( $old ) {
				$data['record_version'] = (int) $old['record_version'] + 1;
				$ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'], 'record_version' => (int) $old['record_version'] ) );
				if ( 1 !== $ok ) {
					throw new RuntimeException( 'Manifest update conflict.' );
				}
			} else {
				$data += array( 'module_key' => $manifest['module_key'], 'record_version' => 1, 'created_at' => $now );
				if ( false === $wpdb->insert( $table, $data ) ) {
					throw new RuntimeException( 'Manifest insert failed.' );
				}
			}
			$event_name = 'FoundationModuleRegistered.v1';
			if ( $old && 'active' !== $old['state'] && 'active' === $manifest['state'] ) {
				$event_name = 'ModuleActivated.v1';
			} elseif ( $old && 'active' === $old['state'] && in_array( $manifest['state'], array( 'degraded','suspended','retired' ), true ) ) {
				$event_name = 'ModuleDeactivated.v1';
			}
			$event = SPF_Event_Bus::publish( $event_name, 'foundation_module', $manifest['module_key'], array( 'state' => $manifest['state'], 'software_version' => $manifest['software_version'] ), 1, 'manifest-' . $manifest['module_key'] . '-' . $manifest['software_version'] . '-' . $manifest['state'] );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$audit = SPF_Audit::record_required( 'register_manifest', 'foundation_module', $manifest['module_key'], 'success', array( 'purpose' => $context['purpose'] ?? 'manifest_registration', 'state' => $manifest['state'] ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'module_key' => $manifest['module_key'], 'record_version' => $old ? (int) $old['record_version'] + 1 : 1, 'state' => $manifest['state'] );
		} catch ( Throwable $error ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_manifest_write_failed', $error->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function register_contract( array $contract, array $context = array() ) {
		global $wpdb;
		$contract = self::normalize_contract( $contract );
		if ( is_wp_error( $contract ) ) {
			return $contract;
		}
		if ( ! self::get_module( $contract['owner_module'] ) ) {
			return new WP_Error( 'spf_contract_owner_missing', __( 'Contract owner is not registered.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! SPF_Installer::is_internal_seed() ) {
			$allowed = SPF_Authorization::require_action( 'register_contract', array( 'owner_module' => $contract['owner_module'], 'contract_key' => $contract['contract_key'] ), array( 'purpose' => $context['purpose'] ?? 'contract_registration' ) );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$table = SPF_Installer::table( 'contracts' );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE contract_key=%s AND contract_version=%s", $contract['contract_key'], $contract['contract_version'] ), ARRAY_A );
		if ( $old && $old['owner_module'] !== $contract['owner_module'] ) {
			return new WP_Error( 'spf_contract_owner_conflict', __( 'Contract owner cannot be silently changed.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && ! self::transition_allowed( 'contract', $old['status'], $contract['status'] ) ) {
			return new WP_Error( 'spf_invalid_contract_transition', __( 'Invalid contract transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && SPF_Installer::is_internal_seed() ) {
			$context['expected_version'] = (int) $old['record_version'];
		}
		if ( $old && ! isset( $context['expected_version'] ) ) {
			return new WP_Error( 'spf_expected_version_required', __( 'Updating a contract requires its expected record version.', 'sabri-platform-foundation' ), array( 'status' => 428 ) );
		}
		if ( $old && (int) $context['expected_version'] !== (int) $old['record_version'] ) {
			return new WP_Error( 'spf_stale_record', __( 'The contract changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$pre = SPF_Audit::record_required( 'register_contract_precommit', 'foundation_contract', $contract['contract_key'] . '@' . $contract['contract_version'], 'authorized', array( 'purpose' => $context['purpose'] ?? 'contract_registration' ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$acks = $old ? json_decode( $old['acknowledgements_json'], true ) : array();
			if ( ! is_array( $acks ) ) {
				$acks = array();
			}
			$now = SPF_Runtime::now_mysql();
			$data = array(
				'contract_key'         => $contract['contract_key'],
				'contract_version'     => $contract['contract_version'],
				'owner_module'         => $contract['owner_module'],
				'status'               => $contract['status'],
				'schema_json'          => wp_json_encode( $contract['schema'] ),
				'consumers_json'       => wp_json_encode( $contract['consumers'] ),
				'acknowledgements_json'=> wp_json_encode( $acks ),
				'deprecation_at'       => $contract['deprecation_at'],
				'updated_at'           => $now,
			);
			if ( $old ) {
				$data['record_version'] = (int) $old['record_version'] + 1;
				$ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'], 'record_version' => (int) $old['record_version'] ) );
				if ( 1 !== $ok ) {
					throw new RuntimeException( 'Contract update conflict.' );
				}
			} else {
				$data += array( 'record_version' => 1, 'created_at' => $now );
				if ( false === $wpdb->insert( $table, $data ) ) {
					throw new RuntimeException( 'Contract insert failed.' );
				}
			}
			$event_name = ( $old && 'deprecated' !== $old['status'] && 'deprecated' === $contract['status'] ) ? 'FoundationContractDeprecated.v1' : 'FoundationContractRegistered.v1';
			$event = SPF_Event_Bus::publish( $event_name, 'foundation_contract', $contract['contract_key'], array( 'version' => $contract['contract_version'], 'status' => $contract['status'], 'owner_module' => $contract['owner_module'] ), 1, 'contract-' . $contract['contract_key'] . '-' . $contract['contract_version'] . '-' . $contract['status'] );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$audit = SPF_Audit::record_required( 'register_contract', 'foundation_contract', $contract['contract_key'] . '@' . $contract['contract_version'], 'success', array( 'purpose' => $context['purpose'] ?? 'contract_registration', 'status' => $contract['status'] ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'contract_key' => $contract['contract_key'], 'contract_version' => $contract['contract_version'], 'record_version' => $old ? (int) $old['record_version'] + 1 : 1 );
		} catch ( Throwable $error ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_contract_write_failed', $error->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function acknowledge_contract( $contract_key, $contract_version, $consumer_module, array $context = array() ) {
		global $wpdb;
		$key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $contract_key );
		$version = sanitize_text_field( $contract_version );
		$consumer = sanitize_key( $consumer_module );
		$allowed = SPF_Authorization::require_action( 'acknowledge_contract', array( 'owner_module' => $consumer, 'contract_key' => $key ), array( 'purpose' => $context['purpose'] ?? 'contract_acknowledgement' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$table = SPF_Installer::table( 'contracts' );
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE contract_key=%s AND contract_version=%s FOR UPDATE", $key, $version ), ARRAY_A );
			if ( ! $row ) {
				throw new RuntimeException( 'Contract not found.' );
			}
			$consumers = json_decode( $row['consumers_json'], true );
			if ( ! is_array( $consumers ) || ! in_array( $consumer, $consumers, true ) ) {
				throw new RuntimeException( 'Consumer is not declared.' );
			}
			$acks = json_decode( $row['acknowledgements_json'], true );
			if ( ! is_array( $acks ) ) {
				$acks = array();
			}
			$acks[ $consumer ] = array( 'acknowledged_at' => SPF_Runtime::now_mysql(), 'actor_id' => get_current_user_id(), 'contract_hash' => hash( 'sha256', $row['schema_json'] ) );
			$updated = $wpdb->update( $table, array( 'acknowledgements_json' => wp_json_encode( $acks ), 'record_version' => (int) $row['record_version'] + 1, 'updated_at' => SPF_Runtime::now_mysql() ), array( 'id' => (int) $row['id'], 'record_version' => (int) $row['record_version'] ) );
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Contract acknowledgement conflict.' );
			}
			$audit = SPF_Audit::record_required( 'acknowledge_contract', 'foundation_contract', $key . '@' . $version, 'success', array( 'purpose' => $context['purpose'] ?? 'contract_acknowledgement', 'consumer' => $consumer ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return true;
		} catch ( Throwable $error ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_contract_acknowledgement_failed', $error->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function map_route( array $route, array $context = array() ) {
		global $wpdb;
		$route = self::normalize_route( $route );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		if ( ! self::get_module( $route['owner_module'] ) ) {
			return new WP_Error( 'spf_route_owner_missing', __( 'Route owner is not registered.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! SPF_Installer::is_internal_seed() ) {
			$allowed = SPF_Authorization::require_action( 'map_route', array( 'owner_module' => $route['owner_module'], 'route_key' => $route['route_key'] ), array( 'purpose' => $context['purpose'] ?? 'route_mapping' ) );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$table = SPF_Installer::table( 'routes' );
		$collision = $wpdb->get_row( $wpdb->prepare( "SELECT route_key,owner_module FROM {$table} WHERE route_path=%s AND route_key<>%s", $route['route_path'], $route['route_key'] ), ARRAY_A );
		if ( $collision ) {
			return new WP_Error( 'spf_route_collision', __( 'Canonical route collision.', 'sabri-platform-foundation' ), array( 'status' => 409, 'owner_module' => $collision['owner_module'] ) );
		}
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE route_key=%s", $route['route_key'] ), ARRAY_A );
		if ( $old && $old['owner_module'] !== $route['owner_module'] ) {
			return new WP_Error( 'spf_route_owner_conflict', __( 'Route owner cannot be silently changed.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && ! self::transition_allowed( 'route', $old['status'], $route['status'] ) ) {
			return new WP_Error( 'spf_invalid_route_transition', __( 'Invalid route transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && SPF_Installer::is_internal_seed() ) {
			$context['expected_version'] = (int) $old['record_version'];
		}
		if ( $old && ! isset( $context['expected_version'] ) ) {
			return new WP_Error( 'spf_expected_version_required', __( 'Updating a route requires its expected record version.', 'sabri-platform-foundation' ), array( 'status' => 428 ) );
		}
		if ( $old && (int) $context['expected_version'] !== (int) $old['record_version'] ) {
			return new WP_Error( 'spf_stale_record', __( 'The route changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$pre = SPF_Audit::record_required( 'map_route_precommit', 'foundation_route', $route['route_key'], 'authorized', array( 'purpose' => $context['purpose'] ?? 'route_mapping' ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$now = SPF_Runtime::now_mysql();
			$data = array(
				'route_path'     => $route['route_path'],
				'owner_module'   => $route['owner_module'],
				'page_id'        => $route['page_id'],
				'layout_context' => $route['layout_context'],
				'status'         => $route['status'],
				'destination'    => $route['destination'],
				'redirects_json' => wp_json_encode( $route['redirects'] ),
				'updated_at'     => $now,
			);
			if ( $old ) {
				$data['record_version'] = (int) $old['record_version'] + 1;
				$ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'], 'record_version' => (int) $old['record_version'] ) );
				if ( 1 !== $ok ) {
					throw new RuntimeException( 'Route update conflict.' );
				}
			} else {
				$data += array( 'route_key' => $route['route_key'], 'record_version' => 1, 'created_at' => $now );
				if ( false === $wpdb->insert( $table, $data ) ) {
					throw new RuntimeException( 'Route insert failed.' );
				}
			}
			$event = SPF_Event_Bus::publish( 'FoundationRouteMapped.v1', 'foundation_route', $route['route_key'], array( 'path' => $route['route_path'], 'owner_module' => $route['owner_module'], 'status' => $route['status'] ), 1, 'route-' . $route['route_key'] . '-' . md5( $route['route_path'] . $route['status'] ) );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$audit = SPF_Audit::record_required( 'map_route', 'foundation_route', $route['route_key'], 'success', array( 'purpose' => $context['purpose'] ?? 'route_mapping', 'owner_module' => $route['owner_module'] ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'route_key' => $route['route_key'], 'record_version' => $old ? (int) $old['record_version'] + 1 : 1 );
		} catch ( Throwable $error ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_route_write_failed', $error->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function get_module( $module_key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'modules' ) . ' WHERE module_key=%s', sanitize_key( $module_key ) ), ARRAY_A );
		return $row ? self::module_dto( $row ) : null;
	}

	public static function list_modules( array $filters = array() ) {
		global $wpdb;
		$limit = max( 1, min( 200, absint( $filters['limit'] ?? 100 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'modules' ) . ' ORDER BY owner_file,module_key LIMIT %d', $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'module_dto' ), $rows );
	}

	public static function list_contracts( array $filters = array() ) {
		global $wpdb;
		$limit = max( 1, min( 200, absint( $filters['limit'] ?? 100 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'contracts' ) . ' ORDER BY contract_key,contract_version LIMIT %d', $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'contract_dto' ), $rows );
	}

	public static function list_routes() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . SPF_Installer::table( 'routes' ) . ' ORDER BY route_path', ARRAY_A );
		return array_map( array( __CLASS__, 'route_dto' ), $rows );
	}

	public static function valid_semver( $version ) {
		return is_string( $version ) && 1 === preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version );
	}

	private static function normalize_manifest( array $manifest ) {
		$required_fields = array( 'module_key','owner_file','owner_name','slug','namespace_prefix','software_version','contract_version','state','required','optional','capabilities','commands','queries','events','routes','data_classes','health' );
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $manifest ) ) {
				return new WP_Error( 'spf_invalid_manifest', 'Missing ' . $field );
			}
		}
		$module_key = sanitize_key( $manifest['module_key'] );
		$owner_file = preg_replace( '/[^0-9A-Z-]/', '', strtoupper( (string) $manifest['owner_file'] ) );
		if ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $module_key ) || ! preg_match( '/^(?:0[0-9]|1[0-9]|2[0-6])$/', $owner_file ) || $module_key !== 'file-' . strtolower( $owner_file ) ) {
			return new WP_Error( 'spf_invalid_manifest_owner', __( 'Manifest module key and owner file do not match the canonical numbered-file registry.', 'sabri-platform-foundation' ) );
		}
		$state = sanitize_key( $manifest['state'] );
		if ( ! in_array( $state, self::MODULE_STATES, true ) || ! self::valid_semver( (string) $manifest['software_version'] ) || ! self::valid_semver( (string) $manifest['contract_version'] ) ) {
			return new WP_Error( 'spf_invalid_manifest', __( 'Manifest state or version is invalid.', 'sabri-platform-foundation' ) );
		}
		foreach ( array( 'required','optional','capabilities','commands','queries','events','routes','data_classes' ) as $field ) {
			if ( ! is_array( $manifest[ $field ] ) ) {
				return new WP_Error( 'spf_invalid_manifest', 'Manifest field must be an array: ' . $field );
			}
		}
		if ( ! is_array( $manifest['health'] ) ) {
			return new WP_Error( 'spf_invalid_manifest', 'Manifest health declaration must be structured.' );
		}
		$manifest['module_key'] = $module_key;
		$manifest['owner_file'] = $owner_file;
		$manifest['owner_name'] = substr( sanitize_text_field( $manifest['owner_name'] ), 0, 191 );
		$manifest['slug'] = sanitize_title( $manifest['slug'] );
		$manifest['namespace_prefix'] = substr( preg_replace( '/[^A-Za-z0-9_\\\\]/', '', (string) $manifest['namespace_prefix'] ), 0, 64 );
		$manifest['software_version'] = sanitize_text_field( $manifest['software_version'] );
		$manifest['contract_version'] = sanitize_text_field( $manifest['contract_version'] );
		$manifest['state'] = $state;
		$manifest['required'] = self::normalize_dependencies( $manifest['required'] );
		if ( is_wp_error( $manifest['required'] ) ) {
			return $manifest['required'];
		}
		$manifest['optional'] = self::normalize_dependencies( $manifest['optional'] );
		if ( is_wp_error( $manifest['optional'] ) ) {
			return $manifest['optional'];
		}
		foreach ( array( 'capabilities','commands','queries','events','routes','data_classes' ) as $field ) {
			$manifest[ $field ] = array_values( array_unique( array_filter( array_map( static function ( $v ) { return substr( sanitize_text_field( (string) $v ), 0, 191 ); }, $manifest[ $field ] ) ) ) );
		}
		$manifest['health'] = SPF_Runtime::canonicalize( $manifest['health'] );
		return $manifest;
	}

	private static function normalize_dependencies( array $dependencies ) {
		$result = array();
		foreach ( $dependencies as $dependency ) {
			if ( ! is_array( $dependency ) || empty( $dependency['module_key'] ) || empty( $dependency['minimum_version'] ) ) {
				return new WP_Error( 'spf_invalid_dependency', __( 'Dependency declarations require module_key and minimum_version.', 'sabri-platform-foundation' ) );
			}
			$key = sanitize_key( $dependency['module_key'] );
			$min = sanitize_text_field( $dependency['minimum_version'] );
			$max = sanitize_text_field( $dependency['maximum_version'] ?? '' );
			if ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $key ) || ! self::valid_semver( $min ) || ( $max && ! self::valid_semver( $max ) ) || ( $max && version_compare( $min, $max, '>' ) ) ) {
				return new WP_Error( 'spf_invalid_dependency', __( 'Dependency version range is invalid.', 'sabri-platform-foundation' ) );
			}
			$result[] = array( 'module_key' => $key, 'minimum_version' => $min, 'maximum_version' => $max, 'purpose' => substr( sanitize_text_field( $dependency['purpose'] ?? '' ), 0, 191 ) );
		}
		usort( $result, static function ( $a, $b ) { return strcmp( $a['module_key'], $b['module_key'] ); } );
		return $result;
	}

	private static function normalize_contract( array $contract ) {
		foreach ( array( 'contract_key','contract_version','owner_module','status','schema','consumers' ) as $field ) {
			if ( ! array_key_exists( $field, $contract ) ) {
				return new WP_Error( 'spf_invalid_contract', 'Missing ' . $field );
			}
		}
		$key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $contract['contract_key'] );
		$version = sanitize_text_field( $contract['contract_version'] );
		$owner = sanitize_key( $contract['owner_module'] );
		$status = sanitize_key( $contract['status'] );
		if ( ! $key || ! self::valid_semver( $version ) || ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $owner ) || ! in_array( $status, self::CONTRACT_STATES, true ) || ! is_array( $contract['schema'] ) || ! is_array( $contract['consumers'] ) ) {
			return new WP_Error( 'spf_invalid_contract', __( 'Invalid contract.', 'sabri-platform-foundation' ) );
		}
		$consumers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $contract['consumers'] ) ) ) );
		foreach ( $consumers as $consumer ) {
			if ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $consumer ) ) {
				return new WP_Error( 'spf_invalid_contract_consumer', __( 'Contract consumer is not a canonical module key.', 'sabri-platform-foundation' ) );
			}
		}
		return array(
			'contract_key' => $key,
			'contract_version' => $version,
			'owner_module' => $owner,
			'status' => $status,
			'schema' => SPF_Runtime::canonicalize( $contract['schema'] ),
			'consumers' => $consumers,
			'deprecation_at' => empty( $contract['deprecation_at'] ) ? null : gmdate( 'Y-m-d H:i:s', strtotime( $contract['deprecation_at'] ) ),
		);
	}

	private static function normalize_route( array $route ) {
		foreach ( array( 'route_key','route_path','owner_module' ) as $field ) {
			if ( empty( $route[ $field ] ) ) {
				return new WP_Error( 'spf_invalid_route', 'Missing ' . $field );
			}
		}
		$key = sanitize_key( $route['route_key'] );
		$path = '/' . trim( (string) $route['route_path'], '/' ) . '/';
		$path = preg_replace( '#/+#', '/', $path );
		$owner = sanitize_key( $route['owner_module'] );
		$status = sanitize_key( $route['status'] ?? 'registered' );
		if ( ! $key || ! preg_match( '#^/[A-Za-z0-9/_-]+/$#', $path ) || ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $owner ) || ! in_array( $status, self::ROUTE_STATES, true ) ) {
			return new WP_Error( 'spf_invalid_route', __( 'Invalid route declaration.', 'sabri-platform-foundation' ) );
		}
		$destination = isset( $route['destination'] ) ? esc_url_raw( $route['destination'] ) : '';
		if ( $destination && ! self::same_origin( $destination ) ) {
			return new WP_Error( 'spf_unsafe_route_destination', __( 'Route destination must be same-origin.', 'sabri-platform-foundation' ) );
		}
		$redirects = array();
		foreach ( (array) ( $route['redirects'] ?? array() ) as $redirect ) {
			$redirect = '/' . trim( sanitize_text_field( $redirect ), '/' ) . '/';
			if ( preg_match( '#^/[A-Za-z0-9/_-]+/$#', $redirect ) && $redirect !== $path ) {
				$redirects[] = $redirect;
			}
		}
		return array(
			'route_key' => $key,
			'route_path' => $path,
			'owner_module' => $owner,
			'page_id' => empty( $route['page_id'] ) ? null : absint( $route['page_id'] ),
			'layout_context' => sanitize_key( $route['layout_context'] ?? 'minimal' ),
			'status' => $status,
			'destination' => $destination,
			'redirects' => array_values( array_unique( $redirects ) ),
		);
	}

	private static function module_dto( array $row ) {
		$manifest = json_decode( $row['manifest_json'], true );
		if ( ! is_array( $manifest ) ) {
			$manifest = array();
		}
		return array(
			'module_key' => $row['module_key'], 'owner_file' => $row['owner_file'], 'owner_name' => $row['owner_name'], 'slug' => $row['slug'],
			'namespace_prefix' => $row['namespace_prefix'], 'software_version' => $row['software_version'], 'contract_version' => $row['contract_version'],
			'state' => $row['state'], 'required' => $manifest['required'] ?? array(), 'optional' => $manifest['optional'] ?? array(),
			'capabilities' => $manifest['capabilities'] ?? array(), 'commands' => $manifest['commands'] ?? array(), 'queries' => $manifest['queries'] ?? array(),
			'events' => $manifest['events'] ?? array(), 'routes' => $manifest['routes'] ?? array(), 'data_classes' => $manifest['data_classes'] ?? array(),
			'health' => $manifest['health'] ?? array(), 'record_version' => (int) $row['record_version'], 'updated_at' => $row['updated_at'],
		);
	}

	private static function contract_dto( array $row ) {
		return array(
			'contract_key' => $row['contract_key'], 'contract_version' => $row['contract_version'], 'owner_module' => $row['owner_module'], 'status' => $row['status'],
			'schema' => json_decode( $row['schema_json'], true ), 'consumers' => json_decode( $row['consumers_json'], true ), 'acknowledgements' => json_decode( $row['acknowledgements_json'], true ),
			'deprecation_at' => $row['deprecation_at'], 'record_version' => (int) $row['record_version'], 'updated_at' => $row['updated_at'],
		);
	}

	private static function route_dto( array $row ) {
		return array(
			'route_key' => $row['route_key'], 'route_path' => $row['route_path'], 'owner_module' => $row['owner_module'], 'page_id' => $row['page_id'] ? (int) $row['page_id'] : null,
			'layout_context' => $row['layout_context'], 'status' => $row['status'], 'destination' => $row['destination'], 'redirects' => json_decode( $row['redirects_json'], true ) ?: array(),
			'record_version' => (int) $row['record_version'], 'updated_at' => $row['updated_at'],
		);
	}

	private static function transition_allowed( $type, $from, $to ) {
		if ( $from === $to ) {
			return true;
		}
		$maps = array(
			'module' => array(
				'unregistered'=>array('registered'),'registered'=>array('compatible','degraded','retired'),'compatible'=>array('active','degraded','retired'),'active'=>array('degraded','suspended','retired'),'degraded'=>array('compatible','active','suspended','retired'),'suspended'=>array('compatible','active','retired'),'retired'=>array(),
			),
			'contract' => array(
				'draft'=>array('approved','retired'),'approved'=>array('current','deprecated','retired'),'current'=>array('deprecated','retired'),'deprecated'=>array('retired'),'retired'=>array(),
			),
			'route' => array(
				'registered'=>array('active','degraded','redirect','retired'),'active'=>array('degraded','redirect','retired'),'degraded'=>array('active','redirect','retired'),'redirect'=>array('active','degraded','retired'),'retired'=>array(),
			),
		);
		return isset( $maps[ $type ][ $from ] ) && in_array( $to, $maps[ $type ][ $from ], true );
	}

	private static function same_origin( $url ) {
		$target = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) || empty( $target['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		$target_scheme = strtolower( $target['scheme'] ?? 'https' );
		$home_scheme = strtolower( $home['scheme'] ?? 'https' );
		$target_port = isset( $target['port'] ) ? (int) $target['port'] : ( 'https' === $target_scheme ? 443 : 80 );
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home_scheme ? 443 : 80 );
		return strtolower( $target['host'] ) === strtolower( $home['host'] ) && $target_scheme === $home_scheme && $target_port === $home_port;
	}
}

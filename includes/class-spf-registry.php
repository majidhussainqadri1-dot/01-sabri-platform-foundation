<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Registry {
	private const MODULE_STATES = array( 'unregistered', 'registered', 'compatible', 'active', 'degraded', 'suspended', 'retired' );
	private const CONTRACT_STATES = array( 'draft', 'approved', 'current', 'deprecated', 'retired' );
	private const ROUTE_STATES = array( 'registered', 'active', 'degraded', 'redirect', 'retired' );

	public static function register_manifest( array $manifest, array $context = array() ) {
		global $wpdb;
		$manifest = self::normalize_manifest( $manifest );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		if ( empty( $context['system_seed'] ) ) {
			$allowed = SPF_Authorization::require_action( 'register_manifest', $manifest['module_key'] );
			if ( is_wp_error( $allowed ) ) { return $allowed; }
		}
		$pre = SPF_Audit::record_required( 'register_manifest_precommit', 'foundation_module', $manifest['module_key'], 'authorized', array( 'purpose' => $context['purpose'] ?? 'manifest_registration' ) );
		if ( is_wp_error( $pre ) ) { return $pre; }
		$table = SPF_Installer::table( 'modules' );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE module_key=%s", $manifest['module_key'] ), ARRAY_A );
		if ( $old && ! self::transition_allowed( 'module', $old['state'], $manifest['state'] ) ) {
			return new WP_Error( 'spf_invalid_module_transition', __( 'Invalid module transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( $old && isset( $context['expected_version'] ) && (int) $context['expected_version'] !== (int) $old['record_version'] ) {
			return new WP_Error( 'spf_stale_record', __( 'The module changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$data = array(
			'owner_file' => $manifest['owner_file'], 'owner_name' => $manifest['owner_name'], 'slug' => $manifest['slug'],
			'namespace_prefix' => $manifest['namespace_prefix'], 'software_version' => $manifest['software_version'],
			'contract_version' => $manifest['contract_version'], 'state' => $manifest['state'],
			'manifest_json' => wp_json_encode( $manifest ), 'updated_at' => $now,
		);
		if ( strlen( $data['manifest_json'] ) > 65535 ) { return new WP_Error( 'spf_manifest_too_large', __( 'Manifest too large.', 'sabri-platform-foundation' ) ); }
		if ( $old ) {
			$data['record_version'] = (int) $old['record_version'] + 1;
			$ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) );
		} else {
			$data += array( 'module_key' => $manifest['module_key'], 'record_version' => 1, 'created_at' => $now );
			$ok = $wpdb->insert( $table, $data );
		}
		if ( false === $ok ) { return new WP_Error( 'spf_manifest_write_failed', __( 'Manifest could not be stored.', 'sabri-platform-foundation' ) ); }
		$trace = SPF_Audit::record( 'register_manifest', 'foundation_module', $manifest['module_key'], 'success', $context );
		SPF_Event_Bus::publish( 'FoundationModuleRegistered.v1', 'foundation_module', $manifest['module_key'], array( 'state' => $manifest['state'], 'trace_id' => $trace ), 1, 'manifest-' . $manifest['module_key'] . '-' . $manifest['software_version'] );
		return true;
	}

	public static function register_contract( array $contract, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'contract_key','contract_version','owner_module','status','schema','consumers' ) as $field ) {
			if ( ! array_key_exists( $field, $contract ) ) { return new WP_Error( 'spf_invalid_contract', 'Missing ' . $field ); }
		}
		$key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $contract['contract_key'] );
		$version = sanitize_text_field( $contract['contract_version'] );
		$owner = sanitize_key( $contract['owner_module'] );
		$status = sanitize_key( $contract['status'] );
		if ( ! $key || ! self::valid_semver( $version ) || ! in_array( $status, self::CONTRACT_STATES, true ) || ! is_array( $contract['schema'] ) || ! is_array( $contract['consumers'] ) ) {
			return new WP_Error( 'spf_invalid_contract', __( 'Invalid contract.', 'sabri-platform-foundation' ) );
		}
		if ( ! self::get_module( $owner ) ) { return new WP_Error( 'spf_contract_owner_missing', __( 'Contract owner missing.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		$allowed = SPF_Authorization::require_action( 'register_contract', $owner );
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$pre = SPF_Audit::record_required( 'register_contract_precommit', 'foundation_contract', $key . '@' . $version, 'authorized', array( 'purpose' => $context['purpose'] ?? 'contract_registration' ) );
		if ( is_wp_error( $pre ) ) { return $pre; }
		$table = SPF_Installer::table( 'contracts' );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE contract_key=%s AND contract_version=%s", $key, $version ), ARRAY_A );
		if ( $old && ! self::transition_allowed( 'contract', $old['status'], $status ) ) { return new WP_Error( 'spf_invalid_contract_transition', __( 'Invalid contract transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		$acks = $old ? json_decode( $old['acknowledgements_json'], true ) : array();
		if ( ! is_array( $acks ) ) { $acks = array(); }
		$now = current_time( 'mysql', true );
		$data = array(
			'contract_key' => $key, 'contract_version' => $version, 'owner_module' => $owner, 'status' => $status,
			'schema_json' => wp_json_encode( $contract['schema'] ),
			'consumers_json' => wp_json_encode( array_values( array_unique( array_map( 'sanitize_key', $contract['consumers'] ) ) ) ),
			'acknowledgements_json' => wp_json_encode( $acks ),
			'deprecation_at' => empty( $contract['deprecation_at'] ) ? null : gmdate( 'Y-m-d H:i:s', strtotime( $contract['deprecation_at'] ) ),
			'updated_at' => $now,
		);
		if ( $old ) { $data['record_version'] = (int) $old['record_version'] + 1; $ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) ); }
		else { $data += array( 'record_version' => 1, 'created_at' => $now ); $ok = $wpdb->insert( $table, $data ); }
		if ( false === $ok ) { return new WP_Error( 'spf_contract_write_failed', __( 'Contract could not be stored.', 'sabri-platform-foundation' ) ); }
		$trace = SPF_Audit::record( 'register_contract', 'foundation_contract', $key . '@' . $version, 'success', $context );
		SPF_Event_Bus::publish( 'FoundationContractRegistered.v1', 'foundation_contract', $key, array( 'version' => $version, 'status' => $status, 'trace_id' => $trace ), 1, 'contract-' . $key . '-' . $version );
		return true;
	}

	public static function acknowledge_contract( $contract_key, $contract_version, $consumer_module, array $context = array() ) {
		global $wpdb;
		$key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $contract_key );
		$version = sanitize_text_field( $contract_version );
		$consumer = sanitize_key( $consumer_module );
		$allowed = SPF_Authorization::require_action( 'acknowledge_contract', $consumer );
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$table = SPF_Installer::table( 'contracts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE contract_key=%s AND contract_version=%s", $key, $version ), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'spf_contract_not_found', __( 'Contract not found.', 'sabri-platform-foundation' ), array( 'status' => 404 ) ); }
		$consumers = json_decode( $row['consumers_json'], true );
		if ( ! is_array( $consumers ) || ! in_array( $consumer, $consumers, true ) ) { return new WP_Error( 'spf_contract_consumer_not_declared', __( 'Consumer is not declared.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		$acks = json_decode( $row['acknowledgements_json'], true ); if ( ! is_array( $acks ) ) { $acks = array(); }
		$acks[ $consumer ] = array( 'acknowledged_at' => current_time( 'mysql', true ), 'actor_id' => get_current_user_id() );
		$updated = $wpdb->update( $table, array( 'acknowledgements_json' => wp_json_encode( $acks ), 'record_version' => (int) $row['record_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'], 'record_version' => (int) $row['record_version'] ) );
		if ( 1 !== $updated ) { return new WP_Error( 'spf_stale_record', __( 'Contract changed before acknowledgement.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		SPF_Audit::record( 'acknowledge_contract', 'foundation_contract', $key . '@' . $version, 'success', $context );
		return true;
	}

	public static function map_route( array $route, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'route_key','route_path','owner_module' ) as $field ) { if ( empty( $route[ $field ] ) ) { return new WP_Error( 'spf_invalid_route', 'Missing ' . $field ); } }
		$key = sanitize_key( $route['route_key'] ); $path = '/' . trim( sanitize_text_field( $route['route_path'] ), '/' ) . '/'; $owner = sanitize_key( $route['owner_module'] );
		$status = sanitize_key( $route['status'] ?? 'registered' ); if ( ! in_array( $status, self::ROUTE_STATES, true ) ) { return new WP_Error( 'spf_invalid_route_state', __( 'Invalid route state.', 'sabri-platform-foundation' ) ); }
		if ( ! self::get_module( $owner ) ) { return new WP_Error( 'spf_route_owner_missing', __( 'Route owner missing.', 'sabri-platform-foundation' ) ); }
		if ( empty( $context['system_seed'] ) ) { $allowed = SPF_Authorization::require_action( 'map_route', $owner ); if ( is_wp_error( $allowed ) ) { return $allowed; } }
		$destination = isset( $route['destination'] ) ? esc_url_raw( $route['destination'] ) : '';
		if ( $destination && ! self::same_origin( $destination ) ) { return new WP_Error( 'spf_unsafe_route_destination', __( 'Route destination must be same-origin.', 'sabri-platform-foundation' ) ); }
		$table = SPF_Installer::table( 'routes' );
		$collision = $wpdb->get_row( $wpdb->prepare( "SELECT route_key,owner_module FROM {$table} WHERE route_path=%s AND route_key<>%s", $path, $key ), ARRAY_A );
		if ( $collision ) { return new WP_Error( 'spf_route_collision', __( 'Canonical route collision.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE route_key=%s", $key ), ARRAY_A );
		if ( $old && $old['owner_module'] !== $owner ) { return new WP_Error( 'spf_route_owner_conflict', __( 'Route owner cannot be silently changed.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		if ( $old && ! self::transition_allowed( 'route', $old['status'], $status ) ) { return new WP_Error( 'spf_invalid_route_transition', __( 'Invalid route transition.', 'sabri-platform-foundation' ), array( 'status' => 409 ) ); }
		$pre = SPF_Audit::record_required( 'map_route_precommit', 'foundation_route', $key, 'authorized', array( 'purpose' => $context['purpose'] ?? 'route_mapping' ) ); if ( is_wp_error( $pre ) ) { return $pre; }
		$now = current_time( 'mysql', true );
		$data = array( 'route_path' => $path, 'owner_module' => $owner, 'page_id' => empty( $route['page_id'] ) ? null : absint( $route['page_id'] ), 'layout_context' => sanitize_key( $route['layout_context'] ?? 'minimal' ), 'status' => $status, 'destination' => $destination, 'redirects_json' => wp_json_encode( $route['redirects'] ?? array() ), 'updated_at' => $now );
		if ( $old ) { $data['record_version'] = (int) $old['record_version'] + 1; $ok = $wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) ); }
		else { $data += array( 'route_key' => $key, 'record_version' => 1, 'created_at' => $now ); $ok = $wpdb->insert( $table, $data ); }
		if ( false === $ok ) { return new WP_Error( 'spf_route_write_failed', __( 'Route could not be stored.', 'sabri-platform-foundation' ) ); }
		$trace = SPF_Audit::record( 'map_route', 'foundation_route', $key, 'success', $context );
		SPF_Event_Bus::publish( 'FoundationRouteMapped.v1', 'foundation_route', $key, array( 'path' => $path, 'owner_module' => $owner, 'trace_id' => $trace ), 1, 'route-' . $key . '-' . md5( $path ) );
		return true;
	}

	public static function get_module( $module_key ) {
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'modules' ) . ' WHERE module_key=%s', sanitize_key( $module_key ) ), ARRAY_A ); return $row ? self::module_dto( $row ) : null;
	}
	public static function list_modules( array $filters = array() ) {
		global $wpdb; $limit = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'modules' ) . ' ORDER BY owner_file,module_key LIMIT %d', $limit ), ARRAY_A ); return array_map( array( __CLASS__, 'module_dto' ), $rows );
	}
	public static function list_contracts( array $filters = array() ) {
		global $wpdb; $limit = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'contracts' ) . ' ORDER BY contract_key,contract_version DESC LIMIT %d', $limit ), ARRAY_A );
		return array_map( static fn( $r ) => array( 'contract_key'=>$r['contract_key'],'contract_version'=>$r['contract_version'],'owner_module'=>$r['owner_module'],'status'=>$r['status'],'schema'=>json_decode($r['schema_json'],true),'consumers'=>json_decode($r['consumers_json'],true),'acknowledgements'=>json_decode($r['acknowledgements_json'],true),'deprecation_at'=>$r['deprecation_at'],'record_version'=>(int)$r['record_version'] ), $rows );
	}
	public static function list_routes() {
		global $wpdb; $rows = $wpdb->get_results( 'SELECT * FROM ' . SPF_Installer::table( 'routes' ) . ' ORDER BY route_path', ARRAY_A ); return array_map( static fn( $r ) => array( 'route_key'=>$r['route_key'],'route_path'=>$r['route_path'],'owner_module'=>$r['owner_module'],'page_id'=>$r['page_id']?(int)$r['page_id']:null,'layout_context'=>$r['layout_context'],'status'=>$r['status'],'destination'=>$r['destination'],'redirects'=>json_decode($r['redirects_json'],true),'record_version'=>(int)$r['record_version'] ), $rows );
	}
	public static function valid_semver( $version ) { return (bool) preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', (string) $version ); }

	private static function normalize_manifest( array $m ) {
		foreach ( array( 'module_key','owner_file','owner_name','slug','software_version','contract_version','state' ) as $f ) { if ( ! isset( $m[$f] ) || '' === (string)$m[$f] ) { return new WP_Error( 'spf_invalid_manifest', 'Missing ' . $f ); } }
		if ( ! self::valid_semver( $m['software_version'] ) || ! self::valid_semver( $m['contract_version'] ) ) { return new WP_Error( 'spf_invalid_manifest_version', __( 'Versions must be semantic.', 'sabri-platform-foundation' ) ); }
		$key = sanitize_key( $m['module_key'] ); $owner = substr( sanitize_text_field( $m['owner_file'] ), 0, 16 );
		if ( preg_match( '/^file-(\d{2})$/', $key, $match ) && $match[1] !== $owner ) { return new WP_Error( 'spf_manifest_owner_mismatch', __( 'Numbered owner mismatch.', 'sabri-platform-foundation' ) ); }
		if ( ! in_array( $m['state'], self::MODULE_STATES, true ) ) { return new WP_Error( 'spf_invalid_module_state', __( 'Invalid module state.', 'sabri-platform-foundation' ) ); }
		$normalize_deps = static function( $deps ) { $out=array(); foreach((array)$deps as $d){ if(is_string($d)){$out[]=array('module_key'=>sanitize_key($d),'minimum_version'=>'0.0.0','maximum_version'=>'');} elseif(is_array($d)&&!empty($d['module_key'])){$out[]=array('module_key'=>sanitize_key($d['module_key']),'minimum_version'=>SPF_Registry::valid_semver($d['minimum_version']??'')?$d['minimum_version']:'0.0.0','maximum_version'=>SPF_Registry::valid_semver($d['maximum_version']??'')?$d['maximum_version']:'');}} return $out; };
		return array( 'module_key'=>$key,'owner_file'=>$owner,'owner_name'=>substr(sanitize_text_field($m['owner_name']),0,191),'slug'=>sanitize_title($m['slug']),'namespace_prefix'=>sanitize_key($m['namespace_prefix']??''),'software_version'=>sanitize_text_field($m['software_version']),'contract_version'=>sanitize_text_field($m['contract_version']),'state'=>$m['state'],'required'=>$normalize_deps($m['required']??array()),'optional'=>$normalize_deps($m['optional']??array()),'capabilities'=>array_values(array_unique(array_map('sanitize_key',(array)($m['capabilities']??array())))),'api_events'=>(array)($m['api_events']??array()),'source'=>substr(sanitize_text_field($m['source']??'runtime'),0,191) );
	}
	private static function module_dto( array $r ) { $m=json_decode($r['manifest_json'],true); return array('module_key'=>$r['module_key'],'owner_file'=>$r['owner_file'],'owner_name'=>$r['owner_name'],'slug'=>$r['slug'],'namespace_prefix'=>$r['namespace_prefix'],'software_version'=>$r['software_version'],'contract_version'=>$r['contract_version'],'state'=>$r['state'],'required'=>$m['required']??array(),'optional'=>$m['optional']??array(),'capabilities'=>$m['capabilities']??array(),'record_version'=>(int)$r['record_version']); }
	private static function same_origin( $url ) { $t=wp_parse_url($url); $h=wp_parse_url(home_url('/')); return empty($t['host']) || (isset($h['host']) && strtolower($t['host'])===strtolower($h['host']) && (empty($t['scheme'])||empty($h['scheme'])||strtolower($t['scheme'])===strtolower($h['scheme']))); }
	private static function transition_allowed( $type, $from, $to ) {
		if ( $from === $to ) { return true; }
		$maps=array('module'=>array('unregistered'=>array('registered','retired'),'registered'=>array('compatible','degraded','retired'),'compatible'=>array('active','degraded','suspended','retired'),'active'=>array('degraded','suspended','retired'),'degraded'=>array('compatible','active','suspended','retired'),'suspended'=>array('compatible','active','retired'),'retired'=>array()),'contract'=>array('draft'=>array('approved','retired'),'approved'=>array('current','deprecated','retired'),'current'=>array('deprecated','retired'),'deprecated'=>array('retired'),'retired'=>array()),'route'=>array('registered'=>array('active','degraded','redirect','retired'),'active'=>array('degraded','redirect','retired'),'degraded'=>array('active','redirect','retired'),'redirect'=>array('active','degraded','retired'),'retired'=>array()));
		return isset($maps[$type][$from]) && in_array($to,$maps[$type][$from],true);
	}
}

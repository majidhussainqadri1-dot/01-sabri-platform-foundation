<?php
defined( 'ABSPATH' ) || exit;

/**
 * File 01 platform-engineering control plane.
 *
 * Implements service catalog, golden-path scaffolding, contract/event labs,
 * configuration drift, release-train planning, progressive delivery, SLO/error
 * budget release gates and privacy-safe telemetry context propagation.
 */
final class SPF_Platform_Engineering {
	const EVENT_SCHEMA_OPTION = 'spf_future_event_schema_registry';
	const CONFIG_BASELINE_OPTION = 'spf_future_config_baselines';
	const ROLLOUT_OPTION = 'spf_future_progressive_rollouts';
	const METRIC_OPTION = 'spf_future_platform_metrics';

	public static function service_catalog() {
		$modules = SPF_Registry::list_modules( array( 'limit' => 200 ) );
		$contracts = SPF_Registry::list_contracts( array( 'limit' => 200 ) );
		$routes = SPF_Registry::list_routes();
		$readiness = SPF_Dependency_Resolver::all_readiness();
		$readiness_by_key = array();
		foreach ( $readiness as $item ) {
			if ( is_array( $item ) && ! empty( $item['module_key'] ) ) { $readiness_by_key[ sanitize_key( $item['module_key'] ) ] = $item; }
		}
		$catalog = array();
		foreach ( $modules as $module ) {
			if ( ! is_array( $module ) ) { continue; }
			$key = sanitize_key( $module['module_key'] ?? '' );
			$catalog[] = array(
				'module_key'=>$key, 'owner_file'=>sanitize_text_field($module['owner_file']??''), 'owner_name'=>sanitize_text_field($module['owner_name']??''),
				'software_version'=>sanitize_text_field($module['software_version']??''), 'contract_version'=>sanitize_text_field($module['contract_version']??''), 'state'=>sanitize_key($module['state']??''),
				'capabilities'=>array_values((array)($module['capabilities']??array())), 'required'=>array_values((array)($module['required']??array())), 'optional'=>array_values((array)($module['optional']??array())),
				'canonical_entities'=>array_values((array)($module['canonical_entities']??array())), 'readiness'=>$readiness_by_key[$key]??array('ready'=>false,'code'=>'not_evaluated'),
			);
		}
		$contract_catalog = array();
		foreach ( $contracts as $contract ) {
			if ( ! is_array( $contract ) ) { continue; }
			$contract_catalog[] = array('contract_key'=>sanitize_text_field($contract['contract_key']??''),'contract_version'=>sanitize_text_field($contract['contract_version']??''),'owner_module'=>sanitize_key($contract['owner_module']??''),'status'=>sanitize_key($contract['status']??''),'consumers'=>array_values((array)($contract['consumers']??array())),'deprecation_at'=>sanitize_text_field($contract['deprecation_at']??''));
		}
		$route_catalog = array();
		foreach ( $routes as $route ) {
			if ( ! is_array( $route ) ) { continue; }
			$route_catalog[] = array('route_key'=>sanitize_key($route['route_key']??''),'route_path'=>sanitize_text_field($route['route_path']??''),'owner_module'=>sanitize_key($route['owner_module']??''),'layout_context'=>sanitize_key($route['layout_context']??''),'status'=>sanitize_key($route['status']??''));
		}
		return array('generated_at'=>SPF_Runtime::now_mysql(),'modules'=>$catalog,'contracts'=>$contract_catalog,'routes'=>$route_catalog,'contract_count'=>count($contract_catalog),'route_count'=>count($route_catalog),'health'=>SPF_System_Check::latest(),'ownership_note'=>'Catalog only. Canonical domain ownership remains with each numbered file.');
	}

	public static function scaffold_module( array $spec ) {
		$module_key = sanitize_key( $spec['module_key'] ?? '' );
		$owner_file = sanitize_text_field( $spec['owner_file'] ?? '' );
		$slug = sanitize_title( $spec['slug'] ?? $module_key );
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['prefix'] ?? '' ) ) );
		if ( '' === $module_key || '' === $owner_file || '' === $slug || '' === $prefix || strlen( $prefix ) > 16
			|| ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $module_key )
			|| ! preg_match( '/^(?:0[0-9]|1[0-9]|2[0-6])$/', $owner_file )
			|| $module_key !== 'file-' . $owner_file ) {
			return new WP_Error( 'spf_scaffold_invalid', __( 'A currently approved canonical module key, matching owner file, slug and bounded namespace prefix are required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$required = self::normalize_scaffold_dependencies( (array) ( $spec['required'] ?? array( 'file-01', 'file-00' ) ) );
		$optional = self::normalize_scaffold_dependencies( (array) ( $spec['optional'] ?? array() ) );
		if ( is_wp_error( $required ) || is_wp_error( $optional ) ) {
			return is_wp_error( $required ) ? $required : $optional;
		}
		$manifest = array(
			'module_key'       => $module_key,
			'owner_file'       => $owner_file,
			'owner_name'       => sanitize_text_field( $spec['owner_name'] ?? $owner_file ),
			'slug'             => $slug,
			'namespace_prefix' => $prefix,
			'software_version' => '0.1.0',
			'contract_version' => '1.0.0',
			'state'            => 'registered',
			'required'         => $required,
			'optional'         => $optional,
			'capabilities'     => array(),
			'commands'         => array(),
			'queries'          => array(),
			'events'           => array(),
			'routes'           => array(),
			'data_classes'     => array( 'internal' ),
			'health'           => array( 'callback' => '', 'contract' => '' ),
			'canonical_entities'=> array(),
			'writes'           => array(),
			'global_shell_owner'=> false,
			'application_shell_owner'=> false,
		);
		$plugin_header = "<?php\n/**\n * Plugin Name: " . ( $manifest['owner_name'] ?: $module_key ) . "\n * Version: 0.1.0\n * Requires PHP: 8.1\n */\n\ndefined( 'ABSPATH' ) || exit;\n";
		$test = "<?php\ndeclare(strict_types=1);\n\$manifest = json_decode( file_get_contents( dirname(__DIR__) . '/manifest.json' ), true );\nif ( ! is_array( \$manifest ) || empty( \$manifest['module_key'] ) || empty( \$manifest['owner_file'] ) ) { fwrite( STDERR, 'Invalid generated manifest.' . PHP_EOL ); exit(1); }\necho 'Generated module smoke PASS' . PHP_EOL;\n";
		$ci = "name: Module QA\non: [push, pull_request]\njobs:\n  qa:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2\n      - name: PHP syntax\n        run: find . -name '*.php' -print0 | xargs -0 -n1 php -l\n      - name: Golden-path smoke\n        run: php tests/smoke.php\n";
		return array(
			'scaffold_version' => '1.1.0',
			'manifest'         => $manifest,
			'files'            => array(
				$slug . '.php'             => $plugin_header,
				'manifest.json'             => wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'tests/smoke.php'           => $test,
				'.github/workflows/qa.yml'  => $ci,
				'ROLLBACK.md'                => "# Rollback\n\nDocument reversible migration and disable/restore steps before release.\n",
				'PRIVACY.md'                 => "# Privacy\n\nDeclare purpose, data classes, retention, export/erasure and vendor boundaries.\n",
			),
			'generated_only' => true,
			'write_performed'=> false,
		);
	}

	public static function contract_compatibility( array $old, array $new ) {
		$issues = array();
		$old_schema = (array) ( $old['schema'] ?? array() );
		$new_schema = (array) ( $new['schema'] ?? array() );
		foreach ( $old_schema as $field => $definition ) {
			$definition = (array) $definition;
			if ( ! array_key_exists( $field, $new_schema ) ) {
				$issues[] = array( 'severity'=>'breaking', 'code'=>'field_removed', 'field'=>$field );
				continue;
			}
			$new_definition = (array) $new_schema[ $field ];
			if ( ( $definition['type'] ?? '' ) !== ( $new_definition['type'] ?? '' ) ) {
				$issues[] = array( 'severity'=>'breaking', 'code'=>'type_changed', 'field'=>$field );
			}
			if ( empty( $definition['required'] ) && ! empty( $new_definition['required'] ) ) {
				$issues[] = array( 'severity'=>'breaking', 'code'=>'optional_became_required', 'field'=>$field );
			}
		}
		foreach ( $new_schema as $field => $definition ) {
			if ( ! array_key_exists( $field, $old_schema ) && ! empty( $definition['required'] ) ) {
				$issues[] = array( 'severity'=>'breaking', 'code'=>'new_required_field', 'field'=>$field );
			}
		}
		$old_version = sanitize_text_field( $old['contract_version'] ?? '0.0.0' );
		$new_version = sanitize_text_field( $new['contract_version'] ?? '0.0.0' );
		$breaking = (bool) array_filter( $issues, static function ( $issue ) { return 'breaking' === $issue['severity']; } );
		$version_valid = SPF_Registry::valid_semver( $old_version ) && SPF_Registry::valid_semver( $new_version );
		$major_bumped = $version_valid && (int) strtok( $new_version, '.' ) > (int) strtok( $old_version, '.' );
		return array(
			'compatible'      => ! $breaking,
			'breaking_change' => $breaking,
			'major_bump_ok'   => ! $breaking || $major_bumped,
			'issues'          => $issues,
			'old_hash'        => SPF_Runtime::hash( $old_schema ),
			'new_hash'        => SPF_Runtime::hash( $new_schema ),
		);
	}

	public static function register_event_schema( array $schema ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'event-schema-registry' ), array( 'purpose'=>'event_schema_registry' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$normalized = self::normalize_event_schema( $schema );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$lock_name = 'future-event-schema-registry';
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$registry = get_option( self::EVENT_SCHEMA_OPTION, array() );
			$registry = is_array( $registry ) ? $registry : array();
			$key = $normalized['event_name'] . '@' . $normalized['version'];
			$registry[ $key ] = $normalized;
			ksort( $registry, SORT_STRING );
			$expected = array_slice( $registry, 0, 500, true );
			update_option( self::EVENT_SCHEMA_OPTION, $expected, false );
			if ( SPF_Runtime::hash( get_option( self::EVENT_SCHEMA_OPTION, array() ) ) !== SPF_Runtime::hash( $expected ) ) {
				return new WP_Error( 'spf_event_schema_persistence_failed', __( 'The event schema registry could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			return $normalized;
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}

	public static function list_event_schemas() {
		$registry = get_option( self::EVENT_SCHEMA_OPTION, array() );
		return is_array( $registry ) ? $registry : array();
	}

	public static function validate_event_fixture( array $event, array $schema ) {
		$normalized = self::normalize_event_schema( $schema );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$errors = array();
		foreach ( $normalized['fields'] as $field => $definition ) {
			if ( ! empty( $definition['required'] ) && ! array_key_exists( $field, $event ) ) {
				$errors[] = 'missing:' . $field;
			}
			if ( array_key_exists( $field, $event ) && ! self::value_matches_type( $event[ $field ], $definition['type'] ) ) {
				$errors[] = 'type:' . $field;
			}
		}
		if ( empty( $normalized['allow_additional'] ) ) {
			foreach ( array_keys( $event ) as $field ) {
				$field_key = sanitize_key( $field );
				if ( '' === $field_key || ! array_key_exists( $field_key, $normalized['fields'] ) ) {
					$errors[] = 'unknown:' . $field_key;
				}
			}
		}
		return array(
			'valid'       => empty( $errors ),
			'errors'      => $errors,
			'fixture_hash'=> SPF_Runtime::hash( $event ),
			'replay_safe' => ! empty( $event['event_id'] ) && ! empty( $event['occurred_at'] ),
			'dispatched'  => false,
		);
	}

	public static function replay_event_fixture( array $event, array $schema, $dispatch = false ) {
		$validation = self::validate_event_fixture( $event, $schema );
		if ( is_wp_error( $validation ) || empty( $validation['valid'] ) ) {
			return $validation;
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production' );
		$allowed_dispatch = $dispatch && defined( 'SPF_EVENT_REPLAY_DISPATCH' ) && SPF_EVENT_REPLAY_DISPATCH && 'production' !== $environment;
		if ( $allowed_dispatch ) {
			do_action( 'spf_event_replay_fixture', SPF_Runtime::canonicalize( $event ), SPF_Runtime::canonicalize( $schema ) );
			$validation['dispatched'] = true;
		}
		$validation['simulation_only'] = ! $validation['dispatched'];
		return $validation;
	}

	public static function set_config_baseline( $environment, array $config ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'config-baseline' ), array( 'purpose'=>'config_as_code' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$environment = sanitize_key( $environment );
		if ( ! in_array( $environment, array( 'development','ci','staging','production' ), true ) ) {
			return new WP_Error( 'spf_config_environment_invalid', __( 'A supported environment is required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$sanitized = self::sanitize_config( $config );
		$lock_name = 'future-config-baselines';
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$baseline = get_option( self::CONFIG_BASELINE_OPTION, array() );
			$baseline = is_array( $baseline ) ? $baseline : array();
			$baseline[ $environment ] = $sanitized;
			update_option( self::CONFIG_BASELINE_OPTION, $baseline, false );
			if ( SPF_Runtime::hash( get_option( self::CONFIG_BASELINE_OPTION, array() ) ) !== SPF_Runtime::hash( $baseline ) ) {
				return new WP_Error( 'spf_config_baseline_persistence_failed', __( 'The configuration baseline could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			return array( 'environment'=>$environment, 'config_hash'=>SPF_Runtime::hash( $sanitized ) );
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}

	public static function detect_config_drift( $environment, array $current ) {
		$environment = sanitize_key( $environment );
		$baselines = get_option( self::CONFIG_BASELINE_OPTION, array() );
		$baseline = is_array( $baselines ) ? (array) ( $baselines[ $environment ] ?? array() ) : array();
		$current = self::sanitize_config( $current );
		$keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $current ) ) ) );
		$changes = array();
		foreach ( $keys as $key ) {
			$before = $baseline[ $key ] ?? null;
			$after = $current[ $key ] ?? null;
			if ( SPF_Runtime::hash( $before ) !== SPF_Runtime::hash( $after ) ) {
				$changes[] = array( 'key'=>$key, 'baseline_hash'=>SPF_Runtime::hash( $before ), 'current_hash'=>SPF_Runtime::hash( $after ) );
			}
		}
		return array(
			'environment'   => $environment,
			'baseline_found'=> ! empty( $baseline ),
			'drifted'       => ! empty( $changes ),
			'changes'       => $changes,
			'baseline_hash' => SPF_Runtime::hash( $baseline ),
			'current_hash'  => SPF_Runtime::hash( $current ),
		);
	}

	public static function plan_release_train( array $manifests ) {
		$versions = array();
		$dependencies = array();
		$manifest_errors = array();
		$seen = array();
		foreach ( $manifests as $index => $manifest ) {
			if ( ! is_array( $manifest ) ) {
				$manifest_errors[] = array( 'index'=>$index, 'code'=>'manifest_not_object' );
				continue;
			}
			$key = sanitize_key( $manifest['module_key'] ?? '' );
			if ( ! $key ) {
				$manifest_errors[] = array( 'index'=>$index, 'code'=>'module_key_missing' );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$manifest_errors[] = array( 'module_key'=>$key, 'code'=>'duplicate_module_key' );
				continue;
			}
			$seen[ $key ] = true;
			$version = sanitize_text_field( $manifest['software_version'] ?? '' );
			if ( ! SPF_Registry::valid_semver( $version ) ) {
				$manifest_errors[] = array( 'module_key'=>$key, 'code'=>'software_version_invalid', 'value'=>$version );
			}
			$versions[ $key ] = $version;
			$dependencies[ $key ] = array();
			foreach ( (array) ( $manifest['required'] ?? array() ) as $dependency ) {
				if ( is_array( $dependency ) ) {
					$dep_key = sanitize_key( $dependency['module_key'] ?? '' );
					$minimum = sanitize_text_field( $dependency['minimum_version'] ?? '0.0.0' );
				} else {
					$dep_key = sanitize_key( $dependency );
					$minimum = '0.0.0';
				}
				if ( ! $dep_key ) {
					$manifest_errors[] = array( 'module_key'=>$key, 'code'=>'dependency_key_invalid' );
					continue;
				}
				if ( $dep_key === $key ) {
					$manifest_errors[] = array( 'module_key'=>$key, 'dependency'=>$dep_key, 'code'=>'self_dependency' );
				}
				if ( ! SPF_Registry::valid_semver( $minimum ) ) {
					$manifest_errors[] = array( 'module_key'=>$key, 'dependency'=>$dep_key, 'code'=>'minimum_version_invalid', 'value'=>$minimum );
					continue;
				}
				if ( isset( $dependencies[ $key ][ $dep_key ] ) && $dependencies[ $key ][ $dep_key ] !== $minimum ) {
					$manifest_errors[] = array( 'module_key'=>$key, 'dependency'=>$dep_key, 'code'=>'dependency_version_conflict' );
					continue;
				}
				$dependencies[ $key ][ $dep_key ] = $minimum;
			}
		}
		$in_degree = array_fill_keys( array_keys( $dependencies ), 0 );
		$edges = array_fill_keys( array_keys( $dependencies ), array() );
		$missing = array();
		$incompatible = array();
		foreach ( $dependencies as $module => $deps ) {
			foreach ( $deps as $dep => $minimum ) {
				if ( ! isset( $dependencies[ $dep ] ) ) {
					$missing[ $module ][] = $dep;
					continue;
				}
				if ( SPF_Registry::valid_semver( $versions[ $dep ] ?? '' ) && '0.0.0' !== $minimum && version_compare( $versions[ $dep ], $minimum, '<' ) ) {
					$incompatible[ $module ][] = array( 'module_key'=>$dep, 'minimum_version'=>$minimum, 'actual_version'=>$versions[ $dep ] );
				}
				$edges[ $dep ][] = $module;
				$in_degree[ $module ]++;
			}
		}
		$queue = array_keys( array_filter( $in_degree, static function ( $degree ) { return 0 === $degree; } ) );
		sort( $queue, SORT_STRING );
		$order = array();
		while ( $queue ) {
			$node = array_shift( $queue );
			$order[] = $node;
			foreach ( $edges[ $node ] as $consumer ) {
				$in_degree[ $consumer ]--;
				if ( 0 === $in_degree[ $consumer ] ) {
					$queue[] = $consumer;
					sort( $queue, SORT_STRING );
				}
			}
		}
		$cycles = array_keys( array_filter( $in_degree, static function ( $degree ) { return $degree > 0; } ) );
		return array(
			'valid'            => empty( $manifest_errors ) && empty( $missing ) && empty( $incompatible ) && empty( $cycles ),
			'order'            => $order,
			'manifest_errors'  => $manifest_errors,
			'missing'          => $missing,
			'incompatible'     => $incompatible,
			'cycle_candidates' => $cycles,
			'plan_hash'        => SPF_Runtime::hash( array( 'versions'=>$versions, 'dependencies'=>$dependencies, 'order'=>$order, 'errors'=>$manifest_errors ) ),
			'execution_mode'   => 'plan-only-until-approved-deployment-adapter',
		);
	}

	public static function create_rollout( $release_id, array $rings, array $slo = array() ) {
		$allowed = SPF_Authorization::require_action( 'transition_release', array( 'object_id'=>sanitize_text_field( $release_id ) ), array( 'purpose'=>'progressive_delivery' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
		$rings = array_values( array_unique( array_filter( array_map( 'sanitize_key', $rings ) ) ) );
		$slo = self::sanitize_numeric_map( $slo );
		if ( '' === $release_id || empty( $rings ) || count( $rings ) > 20 || empty( $slo ) ) {
			return new WP_Error( 'spf_rollout_invalid', __( 'A release id, bounded rollout rings and at least one SLO objective are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$lock_name = 'future-rollout-' . substr( SPF_Runtime::hash( $release_id ), 0, 24 );
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$rollouts = get_option( self::ROLLOUT_OPTION, array() );
			$rollouts = is_array( $rollouts ) ? $rollouts : array();
			$requested_hash = SPF_Runtime::hash( array( 'rings'=>$rings, 'slo'=>$slo ) );
			if ( ! empty( $rollouts[ $release_id ] ) ) {
				$existing = (array) $rollouts[ $release_id ];
				$existing_hash = SPF_Runtime::hash( array( 'rings'=>(array)($existing['rings'] ?? array()), 'slo'=>(array)($existing['slo'] ?? array()) ) );
				if ( hash_equals( $existing_hash, $requested_hash ) ) {
					return $existing;
				}
				return new WP_Error( 'spf_rollout_conflict', __( 'A rollout already exists for this release with different rings or SLO objectives.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			$rollouts[ $release_id ] = array(
				'release_id'   => $release_id,
				'rings'        => $rings,
				'index'        => 0,
				'current_ring' => $rings[0],
				'status'       => 'planned',
				'slo'          => $slo,
				'revision'     => 1,
				'created_at'   => SPF_Runtime::now_mysql(),
				'updated_at'   => SPF_Runtime::now_mysql(),
			);
			$expected = array_slice( $rollouts, -100, null, true );
			update_option( self::ROLLOUT_OPTION, $expected, false );
			$persisted = get_option( self::ROLLOUT_OPTION, array() );
			if ( empty( $persisted[ $release_id ] ) || SPF_Runtime::hash( $persisted[ $release_id ] ) !== SPF_Runtime::hash( $expected[ $release_id ] ) ) {
				return new WP_Error( 'spf_rollout_persistence_failed', __( 'The progressive rollout could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			return $expected[ $release_id ];
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}

	public static function advance_rollout( $release_id, array $metrics ) {
		$allowed = SPF_Authorization::require_action( 'transition_release', array( 'object_id'=>sanitize_text_field( $release_id ) ), array( 'purpose'=>'progressive_delivery' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
		$lock_name = 'future-rollout-' . substr( SPF_Runtime::hash( $release_id ), 0, 24 );
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$rollouts = get_option( self::ROLLOUT_OPTION, array() );
			if ( ! is_array( $rollouts ) || empty( $rollouts[ $release_id ] ) ) {
				return new WP_Error( 'spf_rollout_missing', __( 'The progressive rollout was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
			}
			$rollout = (array) $rollouts[ $release_id ];
			$status = sanitize_key( $rollout['status'] ?? '' );
			if ( in_array( $status, array( 'rollback_required','rolled_back' ), true ) ) {
				return new WP_Error( 'spf_rollout_rollback_pending', __( 'This rollout is rollback-gated and cannot advance.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			if ( 'full' === $status ) {
				return $rollout;
			}
			$gate = self::evaluate_slo_gate( $metrics, (array) ( $rollout['slo'] ?? array() ) );
			if ( empty( $gate['allow'] ) ) {
				$rollout['status'] = 'rollback_required';
				$rollout['rollback_reason'] = $gate['reason'];
				$rollout['rollback_requested_at'] = SPF_Runtime::now_mysql();
				do_action( 'spf_progressive_delivery_rollback_requested', $release_id, $rollout, $gate );
			} else {
				$current_index = max( 0, min( (int) ( $rollout['index'] ?? 0 ), count( (array) $rollout['rings'] ) - 1 ) );
				$next_index = min( $current_index + 1, count( $rollout['rings'] ) - 1 );
				$next_ring = sanitize_key( $rollout['rings'][ $next_index ] ?? '' );
				if ( in_array( $next_ring, array( 'production','full','all','100' ), true ) ) {
					$founder_gate = SPF_Authorization::require_action( 'deploy_release', array( 'object_id'=>$release_id, 'next_ring'=>$next_ring ), array( 'purpose'=>'progressive_delivery_final' ) );
					if ( is_wp_error( $founder_gate ) ) {
						return $founder_gate;
					}
				}
				if ( $next_index === $current_index ) {
					$rollout['status'] = 'full';
				} else {
					$execution = apply_filters( 'spf_progressive_delivery_execute_ring', null, array(
						'release_id'   => $release_id,
						'current_ring' => sanitize_key( $rollout['rings'][ $current_index ] ?? '' ),
						'next_ring'    => $next_ring,
						'gate'         => $gate,
					) );
					$executed_at = is_array( $execution ) ? strtotime( (string) ( $execution['executed_at'] ?? '' ) ) : false;
					$execution_valid = is_array( $execution ) && ! empty( $execution['verified'] )
						&& sanitize_key( $execution['release_id'] ?? '' ) === sanitize_key( $release_id )
						&& sanitize_key( $execution['ring'] ?? '' ) === $next_ring
						&& ! empty( $execution['evidence_id'] ) && strlen( (string) $execution['evidence_id'] ) <= 191
						&& $executed_at && abs( time() - $executed_at ) <= 900;
					if ( $execution_valid ) {
						$rollout['index'] = $next_index;
						$rollout['current_ring'] = $next_ring;
						$rollout['status'] = ( $next_index >= count( $rollout['rings'] ) - 1 ) ? 'full' : 'progressing';
						$rollout['execution_evidence_hash'] = SPF_Runtime::hash( $execution );
					} else {
						$rollout['status'] = 'advance_pending_adapter';
						$rollout['next_ring'] = $next_ring;
						$rollout['advance_requested_at'] = SPF_Runtime::now_mysql();
						do_action( 'spf_progressive_delivery_action_requested', $release_id, $next_ring, $rollout, $gate );
					}
				}
			}
			$rollout['revision'] = max( 1, (int) ( $rollout['revision'] ?? 1 ) + 1 );
			$rollout['updated_at'] = SPF_Runtime::now_mysql();
			$rollout['last_gate'] = $gate;
			$rollouts[ $release_id ] = $rollout;
			update_option( self::ROLLOUT_OPTION, $rollouts, false );
			$persisted = get_option( self::ROLLOUT_OPTION, array() );
			if ( empty( $persisted[ $release_id ] ) || SPF_Runtime::hash( $persisted[ $release_id ] ) !== SPF_Runtime::hash( $rollout ) ) {
				return new WP_Error( 'spf_rollout_persistence_failed', __( 'The progressive rollout state could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			return $rollout;
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}

	public static function evaluate_slo_gate( array $metrics, array $objectives ) {
		$metrics = self::sanitize_numeric_map( $metrics );
		$objectives = self::sanitize_numeric_map( $objectives );
		if ( empty( $objectives ) ) {
			return array( 'allow'=>false, 'reason'=>'slo_objectives_missing', 'violations'=>array( array( 'code'=>'no_objectives' ) ), 'checked_at'=>function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ) );
		}
		$violations = array();
		foreach ( $objectives as $name => $threshold ) {
			if ( ! array_key_exists( $name, $metrics ) ) {
				$violations[] = array( 'metric'=>$name, 'code'=>'missing_metric', 'threshold'=>$threshold );
				continue;
			}
			$value = $metrics[ $name ];
			$lower_is_better = self::metric_lower_is_better( $name );
			$ok = $lower_is_better ? $value <= $threshold : $value >= $threshold;
			if ( ! $ok ) {
				$violations[] = array( 'metric'=>$name, 'value'=>$value, 'threshold'=>$threshold, 'direction'=>$lower_is_better ? 'max' : 'min' );
			}
		}
		$error_budget = isset( $metrics['error_budget_remaining'] ) ? (float) $metrics['error_budget_remaining'] : null;
		if ( null !== $error_budget && $error_budget < 0 ) {
			$violations[] = array( 'metric'=>'error_budget_remaining', 'value'=>$error_budget, 'threshold'=>0, 'direction'=>'min' );
		}
		return array(
			'allow'      => empty( $violations ),
			'reason'     => empty( $violations ) ? 'slo_gate_pass' : 'slo_or_error_budget_violation',
			'violations' => $violations,
			'checked_at' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function new_telemetry_context( array $parent = array() ) {
		$trace_id = self::valid_hex_id( $parent['trace_id'] ?? '', 32 ) ? strtolower( $parent['trace_id'] ) : bin2hex( random_bytes( 16 ) );
		$span_id  = bin2hex( random_bytes( 8 ) );
		return array(
			'trace_id'       => $trace_id,
			'span_id'        => $span_id,
			'parent_span_id' => self::valid_hex_id( $parent['span_id'] ?? '', 16 ) ? strtolower( $parent['span_id'] ) : '',
			'request_id'     => wp_generate_uuid4(),
			'event_id'       => ! empty( $parent['event_id'] ) ? substr( sanitize_text_field( $parent['event_id'] ), 0, 191 ) : '',
			'sampled'        => array_key_exists( 'sampled', $parent ) ? (bool) $parent['sampled'] : true,
		);
	}

	public static function record_metric( $name, $value, array $labels = array() ) {
		$name = sanitize_key( $name );
		if ( '' === $name || ! is_numeric( $value ) ) {
			return false;
		}
		$safe_labels = array();
		foreach ( array_slice( $labels, 0, 12, true ) as $key => $label ) {
			$key = sanitize_key( $key );
			if ( '' === $key || preg_match( '/(email|phone|token|secret|patient|message|content|document|address|name)/i', $key ) ) {
				continue;
			}
			if ( is_scalar( $label ) ) {
				$safe_labels[ $key ] = substr( sanitize_text_field( (string) $label ), 0, 80 );
			}
		}
		$metrics = get_option( self::METRIC_OPTION, array() );
		$metrics = is_array( $metrics ) ? $metrics : array();
		$metrics[] = array( 'name'=>$name, 'value'=>(float)$value, 'labels'=>$safe_labels, 'time'=>SPF_Runtime::now_mysql() );
		$metrics = array_slice( $metrics, -500 );
		update_option( self::METRIC_OPTION, $metrics, false );
		do_action( 'spf_telemetry_metric', end( $metrics ) );
		return true;
	}

	private static function normalize_event_schema( array $schema ) {
		$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) ( $schema['event_name'] ?? '' ) );
		$version = sanitize_text_field( $schema['version'] ?? '1.0.0' );
		$owner = sanitize_key( $schema['owner_module'] ?? '' );
		$fields = array();
		foreach ( array_slice( (array) ( $schema['fields'] ?? array() ), 0, 100, true ) as $field => $definition ) {
			$field = sanitize_key( $field );
			$definition = (array) $definition;
			$type = sanitize_key( $definition['type'] ?? 'string' );
			if ( $field && in_array( $type, array( 'string','integer','number','boolean','array','object','timestamp' ), true ) ) {
				$fields[ $field ] = array( 'type'=>$type, 'required'=>! empty( $definition['required'] ) );
			}
		}
		if ( '' === $name || ! SPF_Registry::valid_semver( $version ) || '' === $owner || empty( $fields ) ) {
			return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, owner and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		return array(
			'event_name'    => substr( $name, 0, 160 ),
			'version'       => $version,
			'owner_module'  => $owner,
			'privacy_class'   => sanitize_key( $schema['privacy_class'] ?? 'internal' ),
			'allow_additional' => ! empty( $schema['allow_additional'] ),
			'fields'          => $fields,
			'deprecated_at' => substr( sanitize_text_field( $schema['deprecated_at'] ?? '' ), 0, 40 ),
		);
	}

	private static function value_matches_type( $value, $type ) {
		switch ( $type ) {
			case 'integer': return is_int( $value ) || ctype_digit( (string) $value );
			case 'number': return is_numeric( $value );
			case 'boolean': return is_bool( $value );
			case 'array': return is_array( $value ) && SPF_Runtime::is_list( $value );
			case 'object': return is_array( $value ) && ! SPF_Runtime::is_list( $value );
			case 'timestamp': return is_numeric( $value ) || false !== strtotime( (string) $value );
			default: return is_scalar( $value );
		}
	}

	private static function sanitize_config( array $config ) {
		return self::sanitize_config_level( $config, 0 );
	}

	private static function sanitize_config_level( array $config, $depth ) {
		if ( $depth > 4 ) {
			return array( '_truncated'=>true );
		}
		$out = array();
		foreach ( array_slice( $config, 0, 200, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			if ( preg_match( '/(secret|password|token|key|credential)/i', $key ) ) {
				$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::sanitize_config_level( $value, $depth + 1 );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}


	private static function normalize_scaffold_dependencies( array $dependencies ) {
		$out = array();
		$seen = array();
		foreach ( array_slice( $dependencies, 0, 100 ) as $dependency ) {
			if ( is_array( $dependency ) ) {
				$key = sanitize_key( $dependency['module_key'] ?? '' );
				$minimum = sanitize_text_field( $dependency['minimum_version'] ?? '0.0.0' );
				$maximum = sanitize_text_field( $dependency['maximum_version'] ?? '' );
			} else {
				$key = sanitize_key( $dependency );
				$minimum = '0.0.0';
				$maximum = '';
			}
			if ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $key ) || ! SPF_Registry::valid_semver( $minimum )
				|| ( $maximum && ! SPF_Registry::valid_semver( $maximum ) ) || ( $maximum && version_compare( $minimum, $maximum, '>' ) ) ) {
				return new WP_Error( 'spf_scaffold_dependency_invalid', __( 'Generated module dependencies require a canonical module key and valid semantic version range.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$out[] = array( 'module_key'=>$key, 'minimum_version'=>$minimum, 'maximum_version'=>$maximum );
		}
		return $out;
	}

	private static function sanitize_numeric_map( array $values ) {
		$out = array();
		foreach ( array_slice( $values, 0, 100, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( $key && is_numeric( $value ) ) {
				$out[ $key ] = (float) $value;
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function metric_lower_is_better( $name ) {
		$name = sanitize_key( $name );
		if ( str_contains( $name, 'budget_remaining' ) || str_contains( $name, 'availability' ) || str_contains( $name, 'success' ) || str_contains( $name, 'throughput' ) || str_contains( $name, 'coverage' ) ) {
			return false;
		}
		return str_contains( $name, 'latency' ) || str_contains( $name, 'error' ) || str_contains( $name, 'lag' ) || str_contains( $name, 'failure' );
	}

	private static function valid_hex_id( $value, $length ) {
		return is_string( $value ) && strlen( $value ) === $length && (bool) preg_match( '/^[a-f0-9]+$/i', $value );
	}
}

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
		if ( array_intersect( array_column( $required, 'module_key' ), array_column( $optional, 'module_key' ) ) ) {
			return new WP_Error( 'spf_scaffold_dependency_ambiguity', __( 'Golden-path scaffolding cannot declare the same module as both required and optional.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$dependency_keys = array();
		foreach ( array_merge( $required, $optional ) as $dependency ) {
			$dependency_key = is_array( $dependency ) ? sanitize_key( $dependency['module_key'] ?? '' ) : sanitize_key( $dependency );
			if ( '' !== $dependency_key ) {
				$dependency_keys[] = $dependency_key;
			}
		}
		if ( in_array( $module_key, $dependency_keys, true ) ) {
			return new WP_Error( 'spf_scaffold_self_dependency', __( 'Golden-path scaffolding cannot generate a module that depends on itself.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
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
		$ci = "name: Module QA\non: [push, pull_request]\njobs:\n  qa:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\n      - name: Set up PHP\n        uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc\n        with:\n          php-version: '8.1'\n          coverage: none\n      - name: PHP syntax\n        run: find . -name '*.php' -print0 | xargs -0 -n1 php -l\n      - name: Golden-path smoke\n        run: php tests/smoke.php\n";
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
		$issues=array();$old_schema=(array)($old['schema']??array());$new_schema=(array)($new['schema']??array());foreach($old_schema as $field=>$definition){$definition=(array)$definition;if(!array_key_exists($field,$new_schema)){$issues[]=array('severity'=>'breaking','code'=>'field_removed','field'=>$field);continue;}$new_definition=(array)$new_schema[$field];if(($definition['type']??'')!==($new_definition['type']??'')){$issues[]=array('severity'=>'breaking','code'=>'type_changed','field'=>$field);}if(empty($definition['required'])&&!empty($new_definition['required'])){$issues[]=array('severity'=>'breaking','code'=>'optional_became_required','field'=>$field);}}foreach($new_schema as $field=>$definition){if(!array_key_exists($field,$old_schema)&&!empty($definition['required'])){$issues[]=array('severity'=>'breaking','code'=>'new_required_field','field'=>$field);}}
		$old_version=sanitize_text_field($old['contract_version']??'0.0.0');$new_version=sanitize_text_field($new['contract_version']??'0.0.0');$version_valid=SPF_Registry::valid_semver($old_version)&&SPF_Registry::valid_semver($new_version);if(!$version_valid){$issues[]=array('severity'=>'breaking','code'=>'contract_version_invalid');}elseif(version_compare($new_version,$old_version,'<')){$issues[]=array('severity'=>'breaking','code'=>'contract_version_regressed','old_version'=>$old_version,'new_version'=>$new_version);}$breaking=(bool)array_filter($issues,static function($issue){return 'breaking'===($issue['severity']??'');});$major_bumped=$version_valid&&(int)strtok($new_version,'.')>(int)strtok($old_version,'.');
		return array('compatible'=>!$breaking,'breaking_change'=>$breaking,'major_bump_ok'=>!$breaking||$major_bumped,'version_valid'=>$version_valid,'version_monotonic'=>$version_valid&&version_compare($new_version,$old_version,'>='),'issues'=>$issues,'old_hash'=>SPF_Runtime::hash($old_schema),'new_hash'=>SPF_Runtime::hash($new_schema));
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
		if ( ! SPF_Registry::get_module( $normalized['owner_module'] ) ) {
			return new WP_Error( 'spf_event_schema_owner_unregistered', __( 'The event-schema owner must be a registered canonical module.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
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
			if ( isset( $registry[ $key ] ) ) {
				$existing = is_array( $registry[ $key ] ) ? SPF_Runtime::canonicalize( $registry[ $key ] ) : array();
				if ( $existing && hash_equals( SPF_Runtime::hash( $existing ), SPF_Runtime::hash( $normalized ) ) ) {
					return $normalized;
				}
				return new WP_Error( 'spf_event_schema_version_conflict', __( 'A published event-schema version is immutable; change the semantic version before changing its contract.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			if ( count( $registry ) >= 500 ) {
				return new WP_Error( 'spf_event_schema_registry_full', __( 'The bounded event-schema registry is full; retire or migrate an existing schema before adding another.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
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
				$field_key = sanitize_key( (string) $field );
				if ( '' === $field_key || (string) $field !== $field_key || ! array_key_exists( (string) $field, $normalized['fields'] ) ) {
					$errors[] = 'unknown:' . ( '' === $field_key ? '[invalid]' : $field_key );
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
		if ( ! is_bool( $dispatch ) ) { return new WP_Error( 'spf_event_replay_dispatch_invalid', __( 'Replay dispatch must be a boolean.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$validation = self::validate_event_fixture( $event, $schema );
		if ( is_wp_error( $validation ) || empty( $validation['valid'] ) ) {
			return $validation;
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production' );
		$allowed_dispatch = true === $dispatch && defined( 'SPF_EVENT_REPLAY_DISPATCH' ) && true === SPF_EVENT_REPLAY_DISPATCH && 'production' !== $environment;
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
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
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
		if ( is_wp_error( $current ) ) {
			return $current;
		}
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
		$versions=array(); $dependencies=array(); $ranges=array(); $manifest_errors=array(); $seen=array();
		foreach($manifests as $index=>$manifest){
			if(!is_array($manifest)){$manifest_errors[]=array('index'=>$index,'code'=>'manifest_not_object');continue;}
			$key=sanitize_key($manifest['module_key']??'');
			if(!preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$key)){$manifest_errors[]=array('index'=>$index,'code'=>'module_key_invalid','value'=>$key);continue;}
			if(isset($seen[$key])){$manifest_errors[]=array('module_key'=>$key,'code'=>'duplicate_module_key');continue;} $seen[$key]=true;
			$version=sanitize_text_field($manifest['software_version']??''); if(!SPF_Registry::valid_semver($version)){$manifest_errors[]=array('module_key'=>$key,'code'=>'software_version_invalid','value'=>$version);} $versions[$key]=$version; $dependencies[$key]=array(); $ranges[$key]=array();
			foreach((array)($manifest['required']??array()) as $dependency){
				if(is_array($dependency)){$dep_key=sanitize_key($dependency['module_key']??'');$minimum=sanitize_text_field($dependency['minimum_version']??'0.0.0');$maximum=sanitize_text_field($dependency['maximum_version']??'');}else{$dep_key=sanitize_key($dependency);$minimum='0.0.0';$maximum='';}
				if(!preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$dep_key)){$manifest_errors[]=array('module_key'=>$key,'code'=>'dependency_key_invalid','value'=>$dep_key);continue;}
				if($dep_key===$key){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'self_dependency');}
				if(!SPF_Registry::valid_semver($minimum)||($maximum&&!SPF_Registry::valid_semver($maximum))||($maximum&&version_compare($minimum,$maximum,'>'))){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'dependency_version_range_invalid','minimum'=>$minimum,'maximum'=>$maximum);continue;}
				$new_range=array('minimum'=>$minimum,'maximum'=>$maximum); if(isset($ranges[$key][$dep_key])&&$ranges[$key][$dep_key]!==$new_range){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'dependency_version_conflict');continue;}
				$dependencies[$key][$dep_key]=$minimum; $ranges[$key][$dep_key]=$new_range;
			}
		}
		$in_degree=array_fill_keys(array_keys($dependencies),0);$edges=array_fill_keys(array_keys($dependencies),array());$missing=array();$incompatible=array();
		foreach($dependencies as $module=>$deps){foreach($deps as $dep=>$minimum){if(!isset($dependencies[$dep])){$missing[$module][]=$dep;continue;}$maximum=$ranges[$module][$dep]['maximum']??'';$actual=$versions[$dep]??'';if(SPF_Registry::valid_semver($actual)&&(('0.0.0'!==$minimum&&version_compare($actual,$minimum,'<'))||($maximum&&version_compare($actual,$maximum,'>')))){$incompatible[$module][]=array('module_key'=>$dep,'minimum_version'=>$minimum,'maximum_version'=>$maximum,'actual_version'=>$actual);}$edges[$dep][]=$module;$in_degree[$module]++;}}
		$queue=array_keys(array_filter($in_degree,static function($degree){return 0===$degree;}));sort($queue,SORT_STRING);$order=array();while($queue){$node=array_shift($queue);$order[]=$node;foreach($edges[$node] as $consumer){$in_degree[$consumer]--;if(0===$in_degree[$consumer]){$queue[]=$consumer;sort($queue,SORT_STRING);}}}$cycles=array_keys(array_filter($in_degree,static function($degree){return $degree>0;}));
		return array('valid'=>empty($manifest_errors)&&empty($missing)&&empty($incompatible)&&empty($cycles),'order'=>$order,'manifest_errors'=>$manifest_errors,'missing'=>$missing,'incompatible'=>$incompatible,'cycle_candidates'=>$cycles,'plan_hash'=>SPF_Runtime::hash(array('versions'=>$versions,'dependencies'=>$ranges,'order'=>$order,'errors'=>$manifest_errors,'missing'=>$missing,'incompatible'=>$incompatible)),'execution_mode'=>'plan-only-until-approved-deployment-adapter');
	}

	public static function create_rollout( $release_id, array $rings, array $slo = array() ) {
		$release_id = trim( sanitize_text_field( (string) $release_id ) );
		$allowed = SPF_Authorization::require_action( 'transition_release', array( 'object_id'=>$release_id ), array( 'purpose'=>'progressive_delivery' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! wp_is_uuid( $release_id ) ) {
			return new WP_Error( 'spf_rollout_release_invalid', __( 'Progressive delivery must bind a canonical File 01 release UUID.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$release = SPF_Governance::get_release( $release_id );
		if ( ! is_array( $release ) || empty( $release['release_id'] ) ) {
			return new WP_Error( 'spf_rollout_release_missing', __( 'Progressive delivery cannot be created for an unregistered release.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
		}
		if ( in_array( sanitize_key( $release['status'] ?? '' ), array( 'deployed','rolled_back','superseded' ), true ) ) {
			return new WP_Error( 'spf_rollout_release_terminal', __( 'A terminal release cannot start a new progressive rollout.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
		}
		if ( count( $rings ) > 20 ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings exceed the bounded rollout envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$normalized_rings = array();
		$seen_rings = array();
		foreach ( $rings as $raw_ring ) {
			if ( ! is_string( $raw_ring ) ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$ring = sanitize_key( $raw_ring );
			if ( '' === $ring || $raw_ring !== $ring || isset( $seen_rings[ $ring ] ) ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be unique canonical values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$seen_rings[ $ring ] = true;
			$normalized_rings[] = $ring;
		}
		$rings = $normalized_rings;
		$slo = self::sanitize_numeric_map( $slo );
		if ( is_wp_error( $slo ) ) { return $slo; }
		$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );
		$terminal_rings = array( 'production','full' );
		$unknown_rings = array_values( array_diff( $rings, $allowed_rings ) );
		$terminal_count = count( array_intersect( $rings, $terminal_rings ) );
		$last_ring = $rings ? $rings[ count( $rings ) - 1 ] : '';
		if ( '' === $release_id || count( $rings ) < 2 || count( $rings ) > 20 || $unknown_rings || 1 !== $terminal_count || ! in_array( $last_ring, $terminal_rings, true ) || empty( $slo ) ) {
			return new WP_Error( 'spf_rollout_invalid', __( 'A release id, two or more canonical rollout rings ending in exactly one production/full ring, and at least one SLO objective are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
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
			if ( empty( $rollouts[ $release_id ] ) && count( $rollouts ) >= 100 ) {
				return new WP_Error( 'spf_rollout_capacity_full', __( 'Progressive rollout capacity is full; explicitly retire completed rollout evidence before creating another rollout.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
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
			$expected = $rollouts;
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
					return new WP_Error( 'spf_rollout_terminal_state_invalid', __( 'A non-final rollout state cannot be promoted to full without a new verified deployment-adapter transition.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
				} else {
					$execution = apply_filters( 'spf_progressive_delivery_execute_ring', null, array(
						'release_id'   => $release_id,
						'current_ring' => sanitize_key( $rollout['rings'][ $current_index ] ?? '' ),
						'next_ring'    => $next_ring,
						'gate'         => $gate,
					) );
					$executed_at = is_array( $execution ) ? strtotime( (string) ( $execution['executed_at'] ?? '' ) ) : false;
					$execution_release_id = is_array( $execution ) ? substr( sanitize_text_field( (string) ( $execution['release_id'] ?? '' ) ), 0, 191 ) : '';
					$execution_valid = is_array( $execution ) && true === ( $execution['verified'] ?? false )
						&& '' !== $execution_release_id && hash_equals( $release_id, $execution_release_id )
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
		if ( is_wp_error( $metrics ) || is_wp_error( $objectives ) ) {
			$error = is_wp_error( $objectives ) ? $objectives : $metrics;
			return array( 'allow'=>false, 'reason'=>'invalid_slo_input', 'violations'=>array( array( 'code'=>$error->get_error_code() ) ), 'checked_at'=>function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ) );
		}
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
			if ( null === $lower_is_better ) {
				$violations[] = array( 'metric'=>$name, 'value'=>$value, 'threshold'=>$threshold, 'code'=>'metric_direction_unknown' );
				continue;
			}
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
			'sampled'        => array_key_exists( 'sampled', $parent ) ? true === $parent['sampled'] : true,
		);
	}

	public static function record_metric( $name, $value, array $labels = array() ) {
		$name=sanitize_key($name);if(''===$name||!is_numeric($value)){return false;}$safe_labels=array();foreach(array_slice($labels,0,12,true) as $key=>$label){$key=sanitize_key($key);if(''===$key||preg_match('/(email|phone|token|secret|patient|message|content|document|address|name)/i',$key)){continue;}if(is_scalar($label)){$safe_labels[$key]=substr(sanitize_text_field((string)$label),0,80);}}
		$lock_name='future-metrics';$lock=SPF_Runtime::acquire_lock($lock_name,60);if(is_wp_error($lock)){return $lock;}
		try{$metrics=get_option(self::METRIC_OPTION,array());$metrics=is_array($metrics)?$metrics:array();$metric=array('name'=>$name,'value'=>(float)$value,'labels'=>$safe_labels,'time'=>SPF_Runtime::now_mysql());$metrics[]=$metric;$expected=array_slice($metrics,-500);update_option(self::METRIC_OPTION,$expected,false);if(SPF_Runtime::hash(get_option(self::METRIC_OPTION,array()))!==SPF_Runtime::hash($expected)){return new WP_Error('spf_metric_persistence_failed',__('The telemetry metric buffer could not be verified after persistence.','sabri-platform-foundation'),array('status'=>409));}do_action('spf_telemetry_metric',$metric);return true;}finally{SPF_Runtime::release_lock($lock_name,$lock);}
	}

	private static function normalize_event_schema( array $schema ) {
		$raw_name = (string) ( $schema['event_name'] ?? '' );
		$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', $raw_name );
		$version = sanitize_text_field( $schema['version']??'1.0.0' );
		$owner = sanitize_key( $schema['owner_module']??'' );
		$privacy_class = sanitize_key( $schema['privacy_class']??'internal' );
		$allowed_privacy = array( 'public','internal','restricted','confidential','ephemeral' );
		if ( array_key_exists( 'allow_additional', $schema ) && ! is_bool( $schema['allow_additional'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$raw_fields = (array) ( $schema['fields'] ?? array() );
		if ( count( $raw_fields ) > 100 ) {
			return new WP_Error( 'spf_event_schema_fields_too_large', __( 'Event schema fields exceed the bounded 100-field envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$fields = array();
		foreach ( $raw_fields as $field => $definition ) {
			$raw_field = (string) $field;
			$field = sanitize_key( $raw_field );
			if ( '' === $field || $raw_field !== $field || ! is_array( $definition ) ) {
				return new WP_Error( 'spf_event_schema_field_invalid', __( 'Event schema field names must already be canonical and every field definition must be structured.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			$type = sanitize_key( $definition['type']??'string' );
			if ( array_key_exists( 'required', $definition ) && ! is_bool( $definition['required'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			if ( ! in_array( $type, array('string','integer','number','boolean','array','object','timestamp'), true ) ) { return new WP_Error( 'spf_event_schema_type_invalid', __( 'Event-schema field type is not supported.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$fields[$field] = array( 'type'=>$type, 'required'=>true === ($definition['required']??false) );
		}
		if ( ''===$name || $raw_name !== $name || strlen( $name ) > 160 || !SPF_Registry::valid_semver($version) || !preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner) || !in_array($privacy_class,$allowed_privacy,true) || empty($fields) ) { return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$deprecated_at = '';
		if ( '' !== trim( (string) ( $schema['deprecated_at'] ?? '' ) ) ) {
			$deprecated_ts = strtotime( (string) $schema['deprecated_at'] );
			if ( false === $deprecated_ts ) { return new WP_Error( 'spf_event_schema_deprecation_invalid', __( 'Event-schema deprecation timestamp is invalid.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$deprecated_at = gmdate( 'Y-m-d H:i:s', $deprecated_ts );
		}
		return array( 'event_name'=>$name, 'version'=>$version, 'owner_module'=>$owner, 'privacy_class'=>$privacy_class, 'allow_additional'=>true===($schema['allow_additional']??false), 'fields'=>$fields, 'deprecated_at'=>$deprecated_at );
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
			return new WP_Error( 'spf_config_too_deep', __( 'Configuration nesting exceeds the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		if ( count( $config ) > 200 ) {
			return new WP_Error( 'spf_config_too_large', __( 'Configuration keys exceed the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$out = array();
		foreach ( $config as $raw_key => $value ) {
			if ( ! is_string( $raw_key ) ) {
				return new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			$key = sanitize_key( $raw_key );
			if ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) ) {
				return new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must already be unique canonical keys.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			if ( preg_match( '/(^|_)(secret|password|token|private_key|api_key|encryption_key|credential)($|_)/i', $key ) ) {
				$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
				continue;
			}
			if ( is_array( $value ) ) {
				$nested = self::sanitize_config_level( $value, $depth + 1 );
				if ( is_wp_error( $nested ) ) { return $nested; }
				$out[ $key ] = $nested;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
			} else {
				return new WP_Error( 'spf_config_value_invalid', __( 'Configuration drift input contains an unsupported value type.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}


	private static function normalize_scaffold_dependencies( array $dependencies ) {
		if ( count( $dependencies ) > 64 ) {
			return new WP_Error( 'spf_scaffold_dependency_list_too_large', __( 'Golden-path dependency lists must remain within the canonical 64-dependency registry bound.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$out = array();
		$seen = array();
		foreach ( $dependencies as $dependency ) {
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
			if ( isset( $seen[ $key ] ) ) {
				return new WP_Error( 'spf_scaffold_duplicate_dependency', __( 'Golden-path scaffolding rejects duplicate dependencies instead of silently collapsing them.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			$seen[ $key ] = true;
			$out[] = array( 'module_key'=>$key, 'minimum_version'=>$minimum, 'maximum_version'=>$maximum );
		}
		return $out;
	}

	private static function sanitize_numeric_map( array $values ) {
		if ( count( $values ) > 100 ) { return new WP_Error( 'spf_numeric_map_too_large', __( 'Numeric metric/objective maps exceed the bounded envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$out = array();
		foreach ( $values as $raw_key => $value ) {
			if ( ! is_string( $raw_key ) ) { return new WP_Error( 'spf_numeric_map_key_invalid', __( 'Metric/objective keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$key = sanitize_key( $raw_key );
			if ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) || ! is_numeric( $value ) ) { return new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective entries must use unique canonical keys and numeric values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$number = (float) $value;
			if ( ! is_finite( $number ) ) { return new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective values must be finite numbers.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
			$out[ $key ] = $number;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function metric_lower_is_better( $name ) {
		$name = sanitize_key( $name );
		if ( str_contains( $name, 'budget_remaining' ) || str_contains( $name, 'availability' ) || str_contains( $name, 'success' ) || str_contains( $name, 'throughput' ) || str_contains( $name, 'coverage' ) ) { return false; }
		if ( str_contains( $name, 'latency' ) || str_contains( $name, 'error' ) || str_contains( $name, 'lag' ) || str_contains( $name, 'failure' ) || str_contains( $name, 'utilization' ) || str_contains( $name, 'saturation' ) || str_contains( $name, 'queue' ) || str_contains( $name, 'depth' ) || str_contains( $name, 'duration' ) ) { return true; }
		return null;
	}

	private static function valid_hex_id( $value, $length ) {
		return is_string( $value ) && strlen( $value ) === $length && (bool) preg_match( '/^[a-f0-9]+$/i', $value );
	}
}

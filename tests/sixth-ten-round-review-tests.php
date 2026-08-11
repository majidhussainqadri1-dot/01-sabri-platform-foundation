<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';

$root = dirname( __DIR__ );
$pass = 0;
$fail = static function ( string $message ): void { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); };
$expect = static function ( bool $condition, string $message ) use ( &$pass, $fail ): void { if ( ! $condition ) { $fail( $message ); } $pass++; };
$src = static function ( string $file ) use ( $root ): string { return (string) file_get_contents( $root . '/' . $file ); };

$installer = $src( 'includes/class-spf-installer.php' );
$system = $src( 'includes/class-spf-system-check.php' );
$expect( str_contains( $installer, "'spf_future_foundation_tick'  => 'spf_five_minutes'" ) && str_contains( $system, 'wp_get_schedule( $hook )' ), 'Round 1 exact cron recurrence restore/health verification missing' );
$expect( str_contains( $installer, 'persisted_snapshot' ) && str_contains( $installer, 'option_restore_failed' ) && str_contains( $installer, 'capability_restore_failed' ) && str_contains( $installer, 'schedule_unschedule_failed' ), 'Round 2 snapshot/compensation read-back verification missing' );

$manifest_method = new ReflectionMethod( SPF_Registry::class, 'normalize_manifest' );
$manifest_method->setAccessible( true );
$base_manifest = array(
	'module_key'=>'file-01','owner_file'=>'01','owner_name'=>'File 01','slug'=>'file-01','namespace_prefix'=>'SPF_',
	'software_version'=>'2.0.0','contract_version'=>'2.0.0','state'=>'active','required'=>array(),'optional'=>array(),
	'capabilities'=>array(),'commands'=>array(),'queries'=>array(),'events'=>array(),'routes'=>array(),'data_classes'=>array('internal'),
	'health'=>array('callback'=>'','contract'=>''),'canonical_entities'=>array(),'writes'=>array(),'global_shell_owner'=>false,'application_shell_owner'=>false,
);
$bad_identity = $base_manifest; $bad_identity['owner_name'] = '<b></b>';
$r = $manifest_method->invoke( null, $bad_identity );
$expect( is_wp_error( $r ) && 'spf_invalid_manifest_identity' === $r->get_error_code(), 'Round 3 post-normalization manifest identity guard missing' );
$self_dependency = $base_manifest; $self_dependency['required'] = array( array('module_key'=>'file-01','minimum_version'=>'2.0.0') );
$r = $manifest_method->invoke( null, $self_dependency );
$expect( is_wp_error( $r ) && 'spf_manifest_self_dependency' === $r->get_error_code(), 'Round 4 authoritative manifest self-dependency guard missing' );
$too_many_entities = $base_manifest; $too_many_entities['canonical_entities'] = array_fill( 0, 129, 'entity' );
$r = $manifest_method->invoke( null, $too_many_entities );
$control = $src( 'includes/class-spf-governance-control-plane.php' );
$expect( is_wp_error( $r ) && 'spf_manifest_architecture_too_large' === $r->get_error_code() && str_contains( $control, 'spf_policy_catalog_full' ), 'Round 5 bounded registry/policy fail-closed guards missing' );

$contract_method = new ReflectionMethod( SPF_Registry::class, 'normalize_contract' );
$contract_method->setAccessible( true );
$r = $contract_method->invoke( null, array( 'contract_key'=>'Test.v1','contract_version'=>'1.0.0','owner_module'=>'file-01','status'=>'current','schema'=>array('x'=>'string'),'consumers'=>array(),'deprecation_at'=>'not-a-real-date' ) );
$expect( is_wp_error( $r ) && 'spf_invalid_contract_deprecation' === $r->get_error_code(), 'Round 6 invalid contract deprecation timestamp accepted' );

$runtime = $src( 'includes/class-spf-runtime.php' );
$expect( str_contains( $runtime, "'expires' => \$created + \$ttl" ) && str_contains( $runtime, 'current_expires' ) && str_contains( $runtime, 'HOUR_IN_SECONDS' ), 'Round 7 lock-owned expiry/stale takeover protection missing' );
$authorization = $src( 'includes/class-spf-authorization.php' );
$expect( str_contains( $authorization, 'LEGACY_BOOLEAN_BRIDGE_ACTIONS' ) && str_contains( $authorization, "array( 'view' )" ) && str_contains( $authorization, 'run_system_check' ), 'Round 8 legacy boolean bridge is not read-only' );
$privacy = $src( 'includes/class-spf-privacy.php' );
$purge = $src( 'includes/class-spf-purge.php' );
$expect( str_contains( $privacy, 'privacy_hold_registry_missing' ) && str_contains( $purge, 'spf_purge_plan_query_failed' ) && str_contains( $purge, "'query_failures' => \$query_failures" ), 'Round 9 privacy/purge fail-closed inventory guards missing' );

$engineering = $src( 'includes/class-spf-platform-engineering.php' );
$expect( str_contains( $engineering, 'spf_event_schema_fields_too_large' ) && str_contains( $engineering, 'spf_scaffold_dependency_list_too_large' ) && str_contains( $engineering, 'spf_scaffold_duplicate_dependency' ) && str_contains( $engineering, 'spf_scaffold_dependency_ambiguity' ) && str_contains( $engineering, "true === \$parent['sampled']" ), 'Round 10 event/telemetry/Golden-Path strictness guards missing' );

$duplicate = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>array('file-01','file-01'),'optional'=>array() ) );
$expect( is_wp_error( $duplicate ) && 'spf_scaffold_duplicate_dependency' === $duplicate->get_error_code(), 'Round 10 duplicate Golden-Path dependency was not rejected at runtime' );
$ambiguous = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>array('file-01'),'optional'=>array('file-01') ) );
$expect( is_wp_error( $ambiguous ) && 'spf_scaffold_dependency_ambiguity' === $ambiguous->get_error_code(), 'Round 10 required/optional Golden-Path ambiguity was not rejected' );
$too_many = array_fill( 0, 65, 'file-01' );
$r = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>$too_many,'optional'=>array() ) );
$expect( is_wp_error( $r ) && 'spf_scaffold_dependency_list_too_large' === $r->get_error_code(), 'Round 10 oversized Golden-Path dependency list was not rejected' );
$trace = SPF_Platform_Engineering::new_telemetry_context( array( 'sampled'=>'false' ) );
$expect( false === $trace['sampled'], 'Round 10 telemetry sampling coerced a non-boolean truthy value' );

$schema_method = new ReflectionMethod( SPF_Platform_Engineering::class, 'normalize_event_schema' );
$schema_method->setAccessible( true );
$fields = array(); for ( $i = 0; $i < 101; $i++ ) { $fields['f'.$i] = array('type'=>'string','required'=>false); }
$r = $schema_method->invoke( null, array('event_name'=>'TestEvent.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>$fields) );
$expect( is_wp_error( $r ) && 'spf_event_schema_fields_too_large' === $r->get_error_code(), 'Round 10 oversized event schema was silently truncated' );
$fixture_schema = array('event_name'=>'Fixture.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>array('event_id'=>array('type'=>'string','required'=>true),'occurred_at'=>array('type'=>'timestamp','required'=>true),'note'=>array('type'=>'string','required'=>false)));
$r = SPF_Platform_Engineering::validate_event_fixture( array('event_id'=>'e1','occurred_at'=>'2026-08-08T00:00:00Z','note!'=>'aliased'), $fixture_schema );
$expect( is_array( $r ) && empty( $r['valid'] ), 'Round 10 non-canonical event fixture key aliased a declared field' );

printf( "Sixth ten-round review assertions: %d/%d PASS\n", $pass, $pass );

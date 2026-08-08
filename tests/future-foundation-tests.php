<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';

$assertions = 0;
$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};

$features = SPF_Future_Foundation::feature_catalog();
$assert( count( $features ) === 18, 'Future Foundation catalog does not contain all 18 enhancements.' );
$assert( isset( $features['F01-FUT-001'], $features['F01-FUT-018'] ), 'Future Foundation stable IDs are incomplete.' );
$assert( count( array_filter( $features, static fn($f) => ($f['status'] ?? '') === 'coded' ) ) === 18, 'Not all enhancements are marked coded.' );

$policies = SPF_Governance_Control_Plane::default_policies();
$decision = SPF_Governance_Control_Plane::evaluate_policy( 'claim_global_shell', [], $policies );
$assert( $decision['decision'] === 'deny', 'Policy-as-Code did not deny a duplicate shell ownership claim.' );
$decision = SPF_Governance_Control_Plane::evaluate_policy( 'view', [], $policies );
$assert( $decision['decision'] === 'abstain', 'Policy-as-Code improperly granted or denied an unrelated action.' );

$inventory = [
	'modules' => [
		[ 'module_key'=>'file-01', 'manifest'=>[ 'required'=>[] ] ],
		[ 'module_key'=>'file-20', 'manifest'=>[ 'required'=>['file-01'] ] ],
		[ 'module_key'=>'file-21', 'manifest'=>[ 'required'=>['file-01','file-20'] ] ],
	],
	'routes' => [],
	'global_shell_owners'=>['file-20'],
];
$impact = SPF_Governance_Control_Plane::simulate_amendment([
	'decision_id'=>'D-200','affected_files'=>['file-01'],'contracts'=>['FoundationRegistry.v1'],'migration_required'=>true,'security_privacy_impact'=>true
], $inventory );
$assert( $impact['requires_migration'] === true, 'Amendment simulator lost migration requirement.' );
$assert( isset( $impact['dependent_modules']['file-20'] ), 'Amendment simulator did not find dependent modules.' );
$assert( $impact['risk_band'] === 'high', 'High-impact amendment was not classified high risk.' );
$assert( in_array( 'rollback', $impact['required_test_gates'], true ), 'Amendment simulator omitted rollback gate.' );

$lint = SPF_Governance_Control_Plane::lint_architecture([
	'modules'=>[
		['module_key'=>'file-a','canonical_entities'=>['profile']],
		['module_key'=>'file-b','canonical_entities'=>['profile'],'writes'=>[['owner_module'=>'file-a']]],
	],
	'routes'=>[
		['route_path'=>'/same','owner_module'=>'file-a'],
		['route_path'=>'/same','owner_module'=>'file-b'],
	],
	'global_shell_owners'=>['file-20'],
]);
$assert( $lint['pass'] === false, 'Architecture linter failed to detect conflicts.' );
$codes = array_column( $lint['findings'], 'code' );
$assert( in_array( 'duplicate_canonical_owner', $codes, true ), 'Duplicate canonical owner not detected.' );
$assert( in_array( 'foreign_direct_write', $codes, true ), 'Foreign direct write not detected.' );
$assert( in_array( 'duplicate_route_owner', $codes, true ), 'Duplicate route owner not detected.' );

$trace = SPF_Governance_Control_Plane::build_traceability_report(
	[['id'=>'REQ-1'],['id'=>'REQ-2']],
	[
		'REQ-1'=>['design'=>1,'code'=>1,'test'=>1,'package'=>1,'staging'=>1,'approval'=>1],
		'REQ-2'=>['design'=>1,'code'=>1,'test'=>0],
	]
);
$assert( $trace['total'] === 2, 'Traceability total is wrong.' );
$assert( $trace['coded_complete'] === 1, 'Traceability coded count is wrong.' );
$assert( $trace['coded_percentage'] === 50.0, 'Traceability coded percentage is wrong.' );
$assert( $trace['production_complete'] === 1, 'Traceability production count is wrong.' );

$scaffold = SPF_Platform_Engineering::scaffold_module(['module_key'=>'file-27','owner_file'=>'27','owner_name'=>'Example Module','slug'=>'example-module','prefix'=>'EXM']);
$assert( ! is_wp_error( $scaffold ), 'Golden-path scaffolder rejected a valid module.' );
$assert( $scaffold['generated_only'] === true && $scaffold['write_performed'] === false, 'Scaffolder should be generation-only by default.' );
$assert( isset( $scaffold['files']['example-module.php'], $scaffold['files']['.github/workflows/qa.yml'] ), 'Scaffolder omitted golden-path files.' );
$assert( str_contains( $scaffold['files']['.github/workflows/qa.yml'], 'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683' ), 'Scaffolder did not pin the checkout action.' );

$compat = SPF_Platform_Engineering::contract_compatibility(
	['contract_version'=>'1.2.0','schema'=>['id'=>['type'=>'string','required'=>true],'note'=>['type'=>'string','required'=>false]]],
	['contract_version'=>'2.0.0','schema'=>['id'=>['type'=>'integer','required'=>true],'new'=>['type'=>'string','required'=>true]]]
);
$assert( $compat['breaking_change'] === true, 'Contract lab failed to detect breaking changes.' );
$assert( $compat['major_bump_ok'] === true, 'Contract lab failed to accept an explicit major bump for breaking changes.' );
$assert( count( $compat['issues'] ) >= 2, 'Contract lab emitted too few issues.' );

$event_schema = ['event_name'=>'ExampleChanged.v1','version'=>'1.0.0','owner_module'=>'file-01','fields'=>['event_id'=>['type'=>'string','required'=>true],'occurred_at'=>['type'=>'timestamp','required'=>true],'count'=>['type'=>'integer','required'=>true]]];
$event = ['event_id'=>'evt-1','occurred_at'=>'2026-08-08T05:00:00Z','count'=>2];
$event_check = SPF_Platform_Engineering::validate_event_fixture( $event, $event_schema );
$assert( ! is_wp_error( $event_check ) && $event_check['valid'] === true, 'Event Schema Registry rejected a valid fixture.' );
$replay = SPF_Platform_Engineering::replay_event_fixture( $event, $event_schema, true );
$assert( $replay['simulation_only'] === true && $replay['dispatched'] === false, 'Event replay should be simulation-only without an explicit safe dispatch gate.' );
$platform_source = file_get_contents( dirname(__DIR__) . '/includes/class-spf-platform-engineering.php' );
$assert( str_contains( $platform_source, "wp_get_environment_type()" ) && str_contains( $platform_source, "'production' !== \$environment" ), 'Event replay dispatch gate is not fail-closed for production.' );

$GLOBALS['spf_test_options'][SPF_Platform_Engineering::CONFIG_BASELINE_OPTION] = ['staging'=>['mode'=>'safe','api_token'=>['secret_hash'=>hash('sha256','x'),'redacted'=>true]]];
$drift = SPF_Platform_Engineering::detect_config_drift( 'staging', ['mode'=>'unsafe','api_token'=>'x'] );
$assert( $drift['baseline_found'] === true, 'Config drift detector did not find baseline.' );
$assert( $drift['drifted'] === true, 'Config drift detector failed to detect a changed value.' );
$assert( count( $drift['changes'] ) >= 1, 'Config drift detector returned no changes.' );
$sanitize_config = new ReflectionMethod( SPF_Platform_Engineering::class, 'sanitize_config' );
$sanitize_config->setAccessible( true );
$nested_config = $sanitize_config->invoke( null, ['provider'=>['api_token'=>'nested-secret','mode'=>'safe']] );
$assert( isset($nested_config['provider']['api_token']['redacted']) && $nested_config['provider']['api_token']['redacted'] === true, 'Nested configuration secret was not redacted.' );

$train = SPF_Platform_Engineering::plan_release_train([
	['module_key'=>'file-01','software_version'=>'2.0.0','required'=>[]],
	['module_key'=>'file-00','software_version'=>'1.2.3','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0']]],
	['module_key'=>'file-20','software_version'=>'1.2.0','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0'],['module_key'=>'file-00','minimum_version'=>'1.2.0']]],
	['module_key'=>'file-21','software_version'=>'1.0.1','required'=>[['module_key'=>'file-20','minimum_version'=>'1.2.0']]],
]);
$assert( $train['valid'] === true, 'Release train rejected a valid dependency graph.' );
$assert( array_search('file-01',$train['order'],true) < array_search('file-20',$train['order'],true), 'Release train order violates dependencies.' );
$assert( empty( $train['incompatible'] ), 'Release train reported compatible versions as incompatible.' );
$incompatible_train = SPF_Platform_Engineering::plan_release_train([
	['module_key'=>'file-01','software_version'=>'1.2.0','required'=>[]],
	['module_key'=>'file-20','software_version'=>'1.2.0','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0']]],
]);
$assert( $incompatible_train['valid'] === false && ! empty( $incompatible_train['incompatible']['file-20'] ), 'Release train failed to block an incompatible minimum version.' );
$bad_train = SPF_Platform_Engineering::plan_release_train([
	['module_key'=>'a','required'=>['b']],['module_key'=>'b','required'=>['a']],
]);
$assert( $bad_train['valid'] === false && ! empty( $bad_train['cycle_candidates'] ), 'Release train failed to detect a cycle.' );

$slo = SPF_Platform_Engineering::evaluate_slo_gate(
	['availability'=>99.95,'latency_p95'=>300,'error_rate'=>0.5,'error_budget_remaining'=>10],
	['availability'=>99.9,'latency_p95'=>500,'error_rate'=>1.0]
);
$assert( $slo['allow'] === true, 'SLO gate blocked healthy metrics.' );
$slo_bad = SPF_Platform_Engineering::evaluate_slo_gate(
	['availability'=>99.0,'latency_p95'=>800,'error_rate'=>2.0,'error_budget_remaining'=>-1],
	['availability'=>99.9,'latency_p95'=>500,'error_rate'=>1.0]
);
$assert( $slo_bad['allow'] === false && count($slo_bad['violations']) >= 3, 'SLO gate failed to block unhealthy metrics.' );
$slo_missing = SPF_Platform_Engineering::evaluate_slo_gate( ['availability'=>100], [] );
$assert( $slo_missing['allow'] === false && $slo_missing['reason'] === 'slo_objectives_missing', 'SLO gate must fail closed when no objectives are defined.' );
$slo_budget = SPF_Platform_Engineering::evaluate_slo_gate( ['error_budget_remaining'=>50], ['error_budget_remaining'=>10] );
$assert( $slo_budget['allow'] === true, 'Error-budget remaining metric direction is incorrect.' );

$context = SPF_Platform_Engineering::new_telemetry_context();
$assert( strlen($context['trace_id']) === 32 && strlen($context['span_id']) === 16, 'Telemetry context IDs have invalid sizes.' );
$assert( $context['request_id'] === '123e4567-e89b-42d3-a456-426614174000', 'Telemetry context did not create request id.' );
SPF_Platform_Engineering::record_metric( 'privacy_test', 1, ['module'=>'file-01','patient_name'=>'sensitive'] );
$metric_rows = $GLOBALS['spf_test_options'][SPF_Platform_Engineering::METRIC_OPTION] ?? [];
$last_metric = end( $metric_rows );
$assert( isset($last_metric['labels']['module']) && ! isset($last_metric['labels']['patient_name']), 'Telemetry accepted a sensitive label key.' );

$twin = SPF_Resilience_Lab::digital_twin([
	'modules'=>[
		['module_key'=>'file-01','required'=>[]],
		['module_key'=>'file-20','required'=>['file-01']],
		['module_key'=>'file-21','required'=>['file-20']],
	]
], ['failed_modules'=>['file-20']]);
$assert( $twin['release_safe'] === false, 'Digital twin failed to mark dependency failure unsafe.' );
$assert( $twin['module_states']['file-21']['status'] === 'blocked', 'Digital twin failed to propagate dependency blockage.' );
$twin_transitive = SPF_Resilience_Lab::digital_twin([
	'modules'=>[
		['module_key'=>'file-01','required'=>[]],
		['module_key'=>'file-20','required'=>[['module_key'=>'file-01']]],
		['module_key'=>'file-21','required'=>[['module_key'=>'file-20']]],
	]
], ['failed_modules'=>['file-01']]);
$assert( $twin_transitive['module_states']['file-20']['status'] === 'blocked' && $twin_transitive['module_states']['file-21']['status'] === 'blocked', 'Digital twin did not propagate a transitive dependency failure.' );

$GLOBALS['spf_test_options']['spf_future_platform_metrics'] = array_fill(0, 501, ['x'=>1]);
$heal = SPF_Resilience_Lab::self_heal_plan();
$assert( $heal['owner_scope'] === 'file-01-only', 'Self-heal plan lost File 01 ownership boundary.' );
$assert( in_array('trim_metric_buffer', array_column($heal['actions'],'action'), true), 'Self-heal plan failed to detect oversized File 01 metric buffer.' );

$chaos = SPF_Resilience_Lab::chaos_scenarios();
$assert( count($chaos) >= 7, 'Chaos harness does not define the expected failure scenarios.' );
$assert( isset($chaos['database_interrupt'], $chaos['duplicate_event']), 'Chaos harness core scenarios missing.' );

$GLOBALS['spf_test_options'][SPF_Resilience_Lab::SNAPSHOT_OPTION] = [
	'snap-1'=>[
		'id'=>'snap-1','label'=>'before','created_at'=>'2026-08-08 05:00:00','data_hash'=>'abc',
		'data'=>['policy_catalog'=>['a'=>1],'event_schemas'=>[],'config_baselines'=>[],'feature_flags'=>[],'activation_state'=>[],'upgrade_state'=>[]]
	]
];
$diff = SPF_Resilience_Lab::diff_snapshot('snap-1', ['policy_catalog'=>['a'=>2],'event_schemas'=>[],'config_baselines'=>[],'feature_flags'=>[],'activation_state'=>[],'upgrade_state'=>[]]);
$assert( ! is_wp_error($diff) && $diff['changed'] === true, 'Time-travel snapshot diff failed to detect change.' );
$assert( $diff['changes'][0]['section'] === 'policy_catalog', 'Time-travel snapshot diff identified wrong section.' );
$restore_plan = SPF_Resilience_Lab::restore_snapshot_plan('snap-1');
$assert( ! is_wp_error($restore_plan) && $restore_plan['owner_scope'] === 'file-01-governance-config-only', 'Snapshot restore plan lost its File 01-only scope.' );
$assert( in_array('activation_state', $restore_plan['excluded_sections'], true) && in_array('upgrade_state', $restore_plan['excluded_sections'], true), 'Snapshot restore plan must exclude runtime activation/upgrade truth.' );

$advice = SPF_Governance_Control_Plane::advisory_copilot([
	'inventory'=>[
		'modules'=>[
			['module_key'=>'x','canonical_entities'=>['thing']],
			['module_key'=>'y','canonical_entities'=>['thing']],
		],
		'routes'=>[],
		'global_shell_owners'=>['file-20'],
	],
]);
$assert( $advice['advisory_only'] === true, 'AI governance copilot is not advisory-only.' );
$assert( $advice['autonomous_changes'] === false && $advice['autonomous_approval'] === false, 'AI governance copilot must never autonomously change or approve.' );
$assert( ! empty($advice['items']), 'AI governance copilot emitted no advice for an architectural conflict.' );

if ( $failures ) {
	fwrite( STDERR, "Future Foundation tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Future Foundation assertions: {$assertions}/{$assertions} PASS\n";

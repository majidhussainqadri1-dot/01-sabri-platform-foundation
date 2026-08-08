<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$failures = array();
$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};

$status = SPF_Future_Foundation::status();
$assert( 18 === (int) $status['feature_count'], 'Future Foundation feature count must be 18.' );
$assert( 18 === (int) $status['coded_count'], 'All Future Foundation features must be coded.' );
$assert( false === $status['ai_autonomous_changes'], 'AI governance advisor must be non-autonomous.' );

$policy = SPF_Governance_Control_Plane::evaluate_policy( 'claim_global_shell', array() );
$assert( 'deny' === $policy['decision'], 'Policy-as-Code must deny a global shell ownership claim.' );
$saved_policy = SPF_Governance_Control_Plane::save_policy( array( 'id'=>'F01-POL-SMOKE-001','title'=>'Smoke deny rule','effect'=>'deny','actions'=>array('smoke_forbidden_action'),'owner'=>'file-01','decision_id'=>'F01-FUT-001','priority'=>50 ) );
$assert( ! is_wp_error( $saved_policy ) && 'F01-POL-SMOKE-001' === $saved_policy['id'], 'Policy-as-Code write path failed.' );

$scaffold = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-27','owner_file'=>'27','owner_name'=>'Runtime Test','slug'=>'runtime-test','prefix'=>'RTT' ) );
$assert( ! is_wp_error( $scaffold ) && empty( $scaffold['write_performed'] ), 'Golden-path scaffold must be generated without foreign writes.' );

$event_schema = array(
	'event_name'=>'FutureFoundationSmoke.v1','version'=>'1.0.0','owner_module'=>'file-01',
	'privacy_class'=>'internal','fields'=>array(
		'event_id'=>array('type'=>'string','required'=>true),
		'occurred_at'=>array('type'=>'timestamp','required'=>true),
	)
);
$registered = SPF_Platform_Engineering::register_event_schema( $event_schema );
$assert( ! is_wp_error( $registered ), 'Authorized File 01 event schema registration failed.' );
$fixture = SPF_Platform_Engineering::replay_event_fixture( array( 'event_id'=>'smoke-1','occurred_at'=>gmdate('c') ), $event_schema, false );
$assert( ! is_wp_error( $fixture ) && ! empty( $fixture['valid'] ) && empty( $fixture['dispatched'] ), 'Event replay fixture validation failed or dispatched unexpectedly.' );

$baseline = SPF_Platform_Engineering::set_config_baseline( 'staging', array( 'mode'=>'safe', 'api_token'=>'never-store-plaintext' ) );
$assert( ! is_wp_error( $baseline ), 'Config-as-Code baseline write failed.' );
$drift = SPF_Platform_Engineering::detect_config_drift( 'staging', array( 'mode'=>'changed', 'api_token'=>'never-store-plaintext' ) );
$assert( ! empty( $drift['drifted'] ), 'Environment drift was not detected.' );
$stored = get_option( SPF_Platform_Engineering::CONFIG_BASELINE_OPTION, array() );
$assert( isset( $stored['staging']['api_token']['redacted'] ) && true === $stored['staging']['api_token']['redacted'], 'Sensitive configuration baseline was not redacted.' );

$train = SPF_Platform_Engineering::plan_release_train( array(
	array( 'module_key'=>'file-01','software_version'=>'2.0.0','required'=>array() ),
	array( 'module_key'=>'file-20','software_version'=>'1.2.0','required'=>array( array( 'module_key'=>'file-01','minimum_version'=>'2.0.0' ) ) ),
) );
$assert( ! empty( $train['valid'] ) && empty( $train['incompatible'] ), 'Release train rejected compatible versions.' );
$bad_train = SPF_Platform_Engineering::plan_release_train( array(
	array( 'module_key'=>'file-01','software_version'=>'1.2.0','required'=>array() ),
	array( 'module_key'=>'file-20','software_version'=>'1.2.0','required'=>array( array( 'module_key'=>'file-01','minimum_version'=>'2.0.0' ) ) ),
) );
$assert( empty( $bad_train['valid'] ) && ! empty( $bad_train['incompatible']['file-20'] ), 'Release train failed to block incompatible versions.' );

$trace = SPF_Platform_Engineering::new_telemetry_context();
$assert( 32 === strlen( $trace['trace_id'] ) && 16 === strlen( $trace['span_id'] ), 'Telemetry context IDs are invalid.' );
$assert( SPF_Platform_Engineering::record_metric( 'runtime_future_foundation_smoke', 1, array( 'module'=>'file-01' ) ), 'Telemetry metric record failed.' );
SPF_Platform_Engineering::record_metric( 'runtime_privacy_label_smoke', 1, array( 'module'=>'file-01', 'patient_name'=>'must-not-persist' ) );
$metric_log = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
$last_metric = end( $metric_log );
$assert( isset( $last_metric['labels']['module'] ) && ! isset( $last_metric['labels']['patient_name'] ), 'Telemetry stored a sensitive label key.' );

$snapshot = SPF_Resilience_Lab::capture_snapshot( 'runtime-smoke' );
$assert( ! is_wp_error( $snapshot ) && ! empty( $snapshot['id'] ), 'Time-travel snapshot capture failed.' );
$diff = SPF_Resilience_Lab::diff_snapshot( $snapshot['id'] );
$assert( ! is_wp_error( $diff ) && empty( $diff['changed'] ), 'Fresh snapshot should match current File 01 governance state.' );
$policies_before_restore = get_option( SPF_Governance_Control_Plane::POLICY_OPTION, array() );
update_option( SPF_Governance_Control_Plane::POLICY_OPTION, array(), false );
$restore_plan = SPF_Resilience_Lab::restore_snapshot_plan( $snapshot['id'] );
$assert( ! is_wp_error( $restore_plan ) && ! empty( $restore_plan['changes'] ) && 'file-01-governance-config-only' === $restore_plan['owner_scope'], 'Snapshot restore dry run failed.' );
$restored = SPF_Resilience_Lab::restore_snapshot( $snapshot['id'], 'RESTORE FILE 01 GOVERNANCE SNAPSHOT', $restore_plan['plan_hash'] );
$assert( ! is_wp_error( $restored ) && ! empty( $restored['restored'] ) && empty( $restored['companion_data_modified'] ), 'Snapshot restore failed or crossed the File 01 boundary.' );
$assert( SPF_Runtime::hash( get_option( SPF_Governance_Control_Plane::POLICY_OPTION, array() ) ) === SPF_Runtime::hash( $policies_before_restore ), 'Snapshot restore did not restore the File 01 policy catalog.' );

$heal = SPF_Resilience_Lab::self_heal_plan();
$assert( 'file-01-only' === $heal['owner_scope'], 'Self-heal plan must remain File 01 scoped.' );

$chaos = SPF_Resilience_Lab::run_chaos( 'dependency_timeout', array( 'module'=>'file-20' ) );
$assert( ! is_wp_error( $chaos ) && empty( $chaos['injected'] ), 'Chaos must remain simulation-only without SPF_CHAOS_MODE.' );

$twin = SPF_Resilience_Lab::digital_twin( array( 'modules'=>array(
	array('module_key'=>'file-01','required'=>array()),
	array('module_key'=>'file-20','required'=>array('file-01')),
) ), array( 'failed_modules'=>array('file-01') ) );
$assert( empty( $twin['release_safe'] ) && 'blocked' === $twin['module_states']['file-20']['status'], 'Digital twin failed to propagate dependency failure.' );

$rollout = SPF_Platform_Engineering::create_rollout( 'runtime-sensitive-test', array('staging','canary','full'), array('availability'=>99.9) );
$assert( is_wp_error( $rollout ) && 'spf_forbidden' === $rollout->get_error_code(), 'Progressive delivery must fail closed without File 00 release authority.' );

$advice = SPF_Governance_Control_Plane::advisory_copilot( array( 'inventory'=>array(
	'modules'=>array(
		array('module_key'=>'a','canonical_entities'=>array('shared')),
		array('module_key'=>'b','canonical_entities'=>array('shared')),
	),
	'routes'=>array(),'global_shell_owners'=>array('file-20'),
) ) );
$assert( ! empty( $advice['advisory_only'] ) && empty( $advice['autonomous_changes'] ) && ! empty( $advice['items'] ), 'AI governance advisor boundary/evidence failed.' );

if ( $failures ) {
	fwrite( STDERR, "Future Foundation runtime smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Future Foundation runtime assertions: {$assertions}/{$assertions} PASS\n";

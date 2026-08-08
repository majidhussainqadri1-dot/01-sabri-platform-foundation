<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';
$assertions=0; $failures=[];
$assert=static function(bool $c,string $m)use(&$assertions,&$failures):void{$assertions++;if(!$c)$failures[]=$m;};

$features=SPF_Future_Foundation::feature_catalog();
$assert(count($features)===18,'18 enhancements missing.');
$assert(isset($features['F01-FUT-001'],$features['F01-FUT-018']),'Stable future IDs incomplete.');
$assert(count(array_filter($features,static fn($f)=>($f['status']??'')==='coded'))===18,'Not all future enhancements coded.');

$policies=SPF_Governance_Control_Plane::default_policies();
$assert(SPF_Governance_Control_Plane::evaluate_policy('claim_global_shell',[],$policies)['decision']==='deny','Duplicate shell not denied.');
$assert(SPF_Governance_Control_Plane::evaluate_policy('view',[],$policies)['decision']==='abstain','Policy engine granted unrelated authority.');

$inventory=['modules'=>[
 ['module_key'=>'file-01','manifest'=>['required'=>[]]],
 ['module_key'=>'file-20','manifest'=>['required'=>['file-01']]],
 ['module_key'=>'file-21','manifest'=>['required'=>['file-20']]],
],'routes'=>[],'global_shell_owners'=>['file-20']];
$impact=SPF_Governance_Control_Plane::simulate_amendment(['decision_id'=>'D-200','affected_files'=>['file-01'],'contracts'=>['FoundationRegistry.v1'],'migration_required'=>true,'security_privacy_impact'=>true],$inventory);
$assert($impact['requires_migration']===true,'Amendment migration requirement lost.');
$assert(isset($impact['dependent_modules']['file-20'],$impact['dependent_modules']['file-21']),'Transitive amendment impact incomplete.');
$assert($impact['risk_band']==='high','High amendment impact not high risk.');
$assert(in_array('rollback',$impact['required_test_gates'],true),'Rollback gate omitted.');

$lint=SPF_Governance_Control_Plane::lint_architecture(['modules'=>[
 ['module_key'=>'file-a','canonical_entities'=>['profile']],
 ['module_key'=>'file-b','canonical_entities'=>['profile'],'writes'=>[['owner_module'=>'file-a']]],
],'routes'=>[['route_path'=>'/same','owner_module'=>'file-a'],['route_path'=>'/same','owner_module'=>'file-b']],'global_shell_owners'=>['file-20','file-x']]);
$codes=array_column($lint['findings'],'code');
$assert($lint['pass']===false,'Architecture linter missed conflicts.');
foreach(['duplicate_canonical_owner','foreign_direct_write','duplicate_route_owner','shell_owner_violation'] as $code){$assert(in_array($code,$codes,true),'Missing linter code '.$code);}

$normalize_manifest=new ReflectionMethod(SPF_Registry::class,'normalize_manifest');$normalize_manifest->setAccessible(true);
$architecture_manifest=$normalize_manifest->invoke(null,[
 'module_key'=>'file-20','owner_file'=>'20','owner_name'=>'Shell','slug'=>'shell','namespace_prefix'=>'SHELL_','software_version'=>'1.0.0','contract_version'=>'1.0.0','state'=>'active',
 'required'=>[],'optional'=>[],'capabilities'=>[],'commands'=>[],'queries'=>[],'events'=>[],'routes'=>[],'data_classes'=>[],'health'=>[],
 'canonical_entities'=>['global-shell'],'writes'=>[['owner_module'=>'file-20','operation'=>'write']],'global_shell_owner'=>true,
]);
$assert(!is_wp_error($architecture_manifest)&&($architecture_manifest['canonical_entities'][0]??'')==='global-shell','Manifest architecture declarations were not preserved.');
$assert(($architecture_manifest['global_shell_owner']??false)===true,'Shell-owner declaration was not preserved.');

$trace=SPF_Governance_Control_Plane::build_traceability_report([['id'=>'REQ-1'],['id'=>'REQ-1'],['id'=>'REQ-2']],[
 'REQ-1'=>['design'=>1,'code'=>1,'test'=>1,'package'=>1,'staging'=>1,'approval'=>1],
 'REQ-2'=>['design'=>1,'code'=>1,'test'=>1,'package'=>1,'staging'=>1,'approval'=>1,'live'=>1,'operational'=>1],
]);
$assert($trace['total']===2,'Duplicate requirement IDs inflated trace total.');
$assert($trace['coded_complete']===2,'Trace coded count wrong.');
$assert($trace['release_ready']===2,'Trace release-ready count wrong.');
$assert($trace['live_deployed']===1,'Trace live count wrong.');
$assert($trace['production_complete']===1,'Trace operational completion truth wrong.');
$assert($trace['duplicate_requirement_ids']===['REQ-1'],'Duplicate requirement IDs not reported.');

$scaffold=SPF_Platform_Engineering::scaffold_module(['module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Example Module','slug'=>'example-module','prefix'=>'EXM','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0']]]);
$assert(!is_wp_error($scaffold),'Structured golden-path dependency rejected.');
$assert($scaffold['generated_only']===true && $scaffold['write_performed']===false,'Scaffolder must remain generation-only.');
$assert(($scaffold['manifest']['required'][0]['minimum_version']??'')==='2.0.0','Scaffolder lost minimum dependency version.');
$assert(isset($scaffold['manifest']['capabilities'],$scaffold['manifest']['routes'],$scaffold['manifest']['data_classes'],$scaffold['manifest']['health']),'Generated manifest is missing registry-required fields.');
$generated_manifest_check=$normalize_manifest->invoke(null,$scaffold['manifest']);
$assert(!is_wp_error($generated_manifest_check),'Golden-path scaffold is not accepted by the File 01 manifest contract.');

$assert(str_contains($scaffold['files']['.github/workflows/qa.yml'],'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683'),'Scaffold checkout action not pinned.');
$assert(str_contains($scaffold['files']['.github/workflows/qa.yml'],'php tests/smoke.php'),'Generated CI does not execute smoke test.');

$compat=SPF_Platform_Engineering::contract_compatibility(['contract_version'=>'1.2.0','schema'=>['id'=>['type'=>'string','required'=>true],'note'=>['type'=>'string','required'=>false]]],['contract_version'=>'2.0.0','schema'=>['id'=>['type'=>'integer','required'=>true],'new'=>['type'=>'string','required'=>true]]]);
$assert($compat['breaking_change']===true,'Contract breaking changes missed.');
$assert($compat['major_bump_ok']===true,'Major bump rejected for breaking change.');
$regressed=SPF_Platform_Engineering::contract_compatibility(['contract_version'=>'2.0.0','schema'=>['id'=>['type'=>'string','required'=>true]]],['contract_version'=>'1.9.9','schema'=>['id'=>['type'=>'string','required'=>true]]]);
$assert($regressed['compatible']===false && $regressed['version_monotonic']===false,'Contract version regression was accepted as compatible.');
$normalize_dependencies=new ReflectionMethod(SPF_Registry::class,'normalize_dependencies');$normalize_dependencies->setAccessible(true);
$dep=$normalize_dependencies->invoke(null,[['module_key'=>'file-20','minimum_version'=>'1.2.0','fail_mode'=>'No duplicate shell']]);
$assert(($dep[0]['fail_mode']??'')==='No duplicate shell','Dependency fail-mode metadata was discarded.');


$schema=['event_name'=>'ExampleChanged.v1','version'=>'1.0.0','owner_module'=>'file-01','fields'=>['event_id'=>['type'=>'string','required'=>true],'occurred_at'=>['type'=>'timestamp','required'=>true],'count'=>['type'=>'integer','required'=>true]]];
$event=['event_id'=>'evt-1','occurred_at'=>'2026-08-08T05:00:00Z','count'=>2];
$event_check=SPF_Platform_Engineering::validate_event_fixture($event,$schema);
$assert(!is_wp_error($event_check)&&$event_check['valid']===true,'Valid event fixture rejected.');
$invalid_owner_schema=$schema;$invalid_owner_schema['owner_module']='rogue';
$assert(is_wp_error(SPF_Platform_Engineering::validate_event_fixture($event,$invalid_owner_schema)),'Non-canonical event-schema owner was accepted.');
$invalid_privacy_schema=$schema;$invalid_privacy_schema['privacy_class']='mystery';
$assert(is_wp_error(SPF_Platform_Engineering::validate_event_fixture($event,$invalid_privacy_schema)),'Unknown event privacy class was accepted.');
$event_extra=SPF_Platform_Engineering::validate_event_fixture($event+['secret'=>'x'],$schema);
$assert($event_extra['valid']===false && in_array('unknown:secret',$event_extra['errors'],true),'Unknown event field not rejected.');
$replay=SPF_Platform_Engineering::replay_event_fixture($event,$schema,true);
$assert($replay['simulation_only']===true && $replay['dispatched']===false,'Replay dispatched without explicit safe gate.');

$GLOBALS['spf_test_options'][SPF_Platform_Engineering::CONFIG_BASELINE_OPTION]=['staging'=>['mode'=>'safe','api_token'=>['secret_hash'=>hash('sha256','x'),'redacted'=>true]]];
$drift=SPF_Platform_Engineering::detect_config_drift('staging',['mode'=>'unsafe','api_token'=>'x']);
$assert($drift['baseline_found']===true && $drift['drifted']===true,'Config drift not detected.');
$sanitize_config=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_config');$sanitize_config->setAccessible(true);
$nested=$sanitize_config->invoke(null,['provider'=>['api_token'=>'nested-secret','mode'=>'safe']]);
$assert(($nested['provider']['api_token']['redacted']??false)===true,'Nested configuration secret not redacted.');

$train=SPF_Platform_Engineering::plan_release_train([
 ['module_key'=>'file-01','software_version'=>'2.0.0','required'=>[]],
 ['module_key'=>'file-00','software_version'=>'1.2.3','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0']]],
 ['module_key'=>'file-20','software_version'=>'1.2.0','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0'],['module_key'=>'file-00','minimum_version'=>'1.2.0']]],
 ['module_key'=>'file-21','software_version'=>'1.0.1','required'=>[['module_key'=>'file-20','minimum_version'=>'1.2.0']]],
]);
$assert($train['valid']===true,'Valid release train rejected.');
$assert(array_search('file-01',$train['order'],true)<array_search('file-20',$train['order'],true),'Release train dependency order wrong.');
$bad_version=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-02','software_version'=>'banana','required'=>[]]]);
$assert($bad_version['valid']===false && !empty($bad_version['manifest_errors']),'Invalid semantic version not blocked.');
$duplicate=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-02','software_version'=>'1.0.0'],['module_key'=>'file-02','software_version'=>'2.0.0']]);
$assert($duplicate['valid']===false,'Duplicate release-train module silently overwritten.');
$bad_train=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-01','software_version'=>'1.0.0','required'=>['file-20']],['module_key'=>'file-20','software_version'=>'1.0.0','required'=>['file-01']]]);
$assert($bad_train['valid']===false && !empty($bad_train['cycle_candidates']),'Release-train cycle missed.');
$too_new=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-01','software_version'=>'2.1.0','required'=>[]],['module_key'=>'file-20','software_version'=>'1.0.0','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0','maximum_version'=>'2.0.9']]]]);
$assert($too_new['valid']===false && !empty($too_new['incompatible']['file-20']),'Maximum dependency version was not enforced.');
$noncanonical=SPF_Platform_Engineering::plan_release_train([['module_key'=>'rogue','software_version'=>'1.0.0','required'=>[]]]);
$assert($noncanonical['valid']===false,'Non-canonical release-train module was accepted.');


$slo=SPF_Platform_Engineering::evaluate_slo_gate(['availability'=>99.95,'latency_p95'=>300,'error_rate'=>0.5,'error_budget_remaining'=>10],['availability'=>99.9,'latency_p95'=>500,'error_rate'=>1.0]);
$assert($slo['allow']===true,'Healthy SLO blocked.');
$slo_bad=SPF_Platform_Engineering::evaluate_slo_gate(['availability'=>99.0,'latency_p95'=>800,'error_rate'=>2.0,'error_budget_remaining'=>-1],['availability'=>99.9,'latency_p95'=>500,'error_rate'=>1.0]);
$assert($slo_bad['allow']===false && count($slo_bad['violations'])>=3,'Unhealthy SLO passed.');
$assert(SPF_Platform_Engineering::evaluate_slo_gate(['availability'=>100],[])['reason']==='slo_objectives_missing','Missing SLO objectives did not fail closed.');
$unknown_slo=SPF_Platform_Engineering::evaluate_slo_gate(['mystery_metric'=>50],['mystery_metric'=>40]);
$assert($unknown_slo['allow']===false && ($unknown_slo['violations'][0]['code']??'')==='metric_direction_unknown','Unknown SLO metric direction was guessed instead of failing closed.');


$context=SPF_Platform_Engineering::new_telemetry_context();
$assert(strlen($context['trace_id'])===32 && strlen($context['span_id'])===16,'Telemetry IDs invalid.');
SPF_Platform_Engineering::record_metric('privacy_test',1,['module'=>'file-01','patient_name'=>'sensitive']);
$metric_result=SPF_Platform_Engineering::record_metric('second_metric',2,['module'=>'file-01']);
$assert($metric_result===true,'Locked telemetry metric persistence failed.');
$rows=$GLOBALS['spf_test_options'][SPF_Platform_Engineering::METRIC_OPTION]??[];$last=end($rows);
$assert(isset($last['labels']['module'])&&!isset($last['labels']['patient_name']),'Telemetry persisted sensitive label.');

$twin=SPF_Resilience_Lab::digital_twin(['modules'=>[['module_key'=>'file-01','required'=>[]],['module_key'=>'file-20','required'=>[['module_key'=>'file-01']]],['module_key'=>'file-21','required'=>[['module_key'=>'file-20']]]]],['failed_modules'=>['file-01']]);
$assert($twin['release_safe']===false && $twin['module_states']['file-21']['status']==='blocked','Digital twin transitive failure propagation failed.');
$suspended=SPF_Resilience_Lab::digital_twin(['modules'=>[['module_key'=>'x','state'=>'suspended','required'=>[]]]],[]);
$assert($suspended['release_safe']===false,'Suspended module incorrectly considered release-safe.');
$GLOBALS['spf_test_options'][SPF_Platform_Engineering::METRIC_OPTION]=array_fill(0,501,['x'=>1]);
$heal=SPF_Resilience_Lab::self_heal_plan();
$assert($heal['owner_scope']==='file-01-only' && in_array('trim_metric_buffer',array_column($heal['actions'],'action'),true),'Bounded self-heal plan wrong.');
$chaos=SPF_Resilience_Lab::chaos_scenarios();
$assert(count($chaos)>=7 && isset($chaos['database_interrupt'],$chaos['duplicate_event']),'Chaos scenarios incomplete.');

$GLOBALS['spf_test_options'][SPF_Resilience_Lab::SNAPSHOT_OPTION]=['snap-1'=>['id'=>'snap-1','label'=>'before','created_at'=>'2026-08-08 05:00:00','data_hash'=>'abc','data'=>['policy_catalog'=>['a'=>1],'event_schemas'=>[],'config_baselines'=>[],'feature_flags'=>[],'activation_state'=>[],'upgrade_state'=>[]]]];
$diff=SPF_Resilience_Lab::diff_snapshot('snap-1',['policy_catalog'=>['a'=>2],'event_schemas'=>[],'config_baselines'=>[],'feature_flags'=>[],'activation_state'=>[],'upgrade_state'=>[]]);
$assert(!is_wp_error($diff)&&$diff['changed']===true,'Snapshot diff missed change.');
$plan=SPF_Resilience_Lab::restore_snapshot_plan('snap-1');
$assert(!is_wp_error($plan)&&$plan['owner_scope']==='file-01-governance-config-only','Snapshot restore scope wrong.');
$assert(in_array('activation_state',$plan['excluded_sections'],true)&&in_array('upgrade_state',$plan['excluded_sections'],true),'Runtime truth not excluded from time-travel restore.');

$advice=SPF_Governance_Control_Plane::advisory_copilot(['inventory'=>['modules'=>[['module_key'=>'x','canonical_entities'=>['thing']],['module_key'=>'y','canonical_entities'=>['thing']]],'routes'=>[],'global_shell_owners'=>['file-20']],'secret_token'=>'must-not-leak']);
$assert($advice['advisory_only']===true && $advice['autonomous_changes']===false && $advice['autonomous_approval']===false,'AI governance copilot autonomy boundary broken.');
$assert(!empty($advice['items']),'AI governance copilot emitted no architectural advice.');

$gov_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-governance-control-plane.php');
$eng_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-platform-engineering.php');
$res_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-resilience-lab.php');
$assert(str_contains($gov_source,"'approve_amendment'") && str_contains($gov_source,'sanitize_advisory_input'),'Governance mutation/advisor hardening absent.');
$assert(str_contains($gov_source,"'future-policy-catalog'"),'Policy catalog concurrency lock absent.');
$assert(str_contains($eng_source,"'future-event-schema-registry'") && str_contains($eng_source,"'future-config-baselines'"),'Future registries lack concurrency locks.');
$assert(str_contains($eng_source,"'contracts'=>\$contract_catalog") && str_contains($eng_source,"'routes'=>\$route_catalog"),'Developer service catalog omits contract/route summaries.');
$dependency_manifest=json_decode(file_get_contents(dirname(__DIR__).'/DEPENDENCY-MANIFEST.json'),true);
$assert(($dependency_manifest['software_version']??'')==='2.0.0'&&($dependency_manifest['contract_version']??'')==='2.0.0','Dependency manifest version identity drift remains.');
$installer_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-installer.php');
$assert(str_contains($installer_source,"'policy_as_code'")&&str_contains($installer_source,"'ai_governance_advisory'"),'File 01 v2 manifest omits Future Foundation capabilities.');
$assert(str_contains($installer_source,"'25'=>'Sabri Unified Global Visual Experience and Design System'")&&str_contains($installer_source,"'26'=>'Search, Discovery and Ranking'"),'Canonical module catalog is stale against the latest central plan.');

$assert(str_contains($eng_source,"'rollback_required','rolled_back'") && str_contains($eng_source,"'future-rollout-'"),'Progressive rollout stale/rollback guard absent.');
$assert(str_contains($eng_source,"'future-metrics'")&&str_contains($eng_source,'spf_metric_persistence_failed'),'Telemetry metric lost-update/persistence guard absent.');
$assert(str_contains($res_source,"'spf_self_heal_recovery_stale'") && str_contains($res_source,"'self_heal_precommit'"),'Self-heal stale recovery/audit guard absent.');
$assert(str_contains($res_source,'$safe_environments') && str_contains($res_source,"'run_reconciliation'") && str_contains($res_source,'sanitize_chaos_context'),'Chaos fail-closed authorization/privacy guard absent.');
$assert(str_contains($res_source,"'spf_snapshot_integrity_failed'") && str_contains($res_source,"'future-snapshot-restore'"),'Snapshot integrity/concurrency guard absent.');

if($failures){fwrite(STDERR,"Future Foundation tests failed:\n- ".implode("\n- ",$failures)."\n");exit(1);} echo "Future Foundation assertions: {$assertions}/{$assertions} PASS\n";

<?php
/** Actual WordPress/MySQL runtime smoke and integration tests. */

$assertions = 0;
$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin ? $admin->ID : 1 );

$assert( defined( 'SPF_VERSION' ) && '1.1.0' === SPF_VERSION, 'Plugin version mismatch.' );
$assert( '1.1.0' === get_option( SPF_Installer::SCHEMA_OPTION ), 'Schema version mismatch after activation.' );
$schema = SPF_Installer::verify_schema();
$assert( ! is_wp_error( $schema ), 'Schema verification failed: ' . ( is_wp_error( $schema ) ? $schema->get_error_message() : '' ) );
$assert( true === SPF_Runtime::verify_owned_tables_transactional(), 'One or more File 01 tables are not InnoDB.' );
foreach ( SPF_Installer::table_names() as $name ) {
	$assert( SPF_Runtime::table_exists( SPF_Installer::table( $name ) ), "Missing runtime table {$name}." );
}

$role = get_role( 'administrator' );
$assert( $role && $role->has_cap( SPF_Authorization::CAP_VIEW ), 'Administrator missing view bootstrap capability.' );
$assert( $role && $role->has_cap( SPF_Authorization::CAP_MANAGE ), 'Administrator missing manage bootstrap capability.' );
$assert( ! $role->has_cap( SPF_Authorization::CAP_RELEASE ), 'Administrator improperly has release capability.' );
$assert( ! $role->has_cap( SPF_Authorization::CAP_FOUNDER ), 'Administrator improperly has Founder capability.' );
$assert( ! $role->has_cap( SPF_Authorization::CAP_PURGE ), 'Administrator improperly has purge capability.' );

$modules = SPF_Registry::list_modules( [ 'limit'=>100 ] );
$assert( 1 === count( $modules ) && 'file-01' === $modules[0]['module_key'], 'File 01 seeded placeholder module manifests.' );
$assert( 27 === count( SPF_Installer::canonical_module_catalog() ), 'Canonical file catalog is incomplete.' );
$assert( 4 === count( SPF_Registry::list_contracts( [ 'limit'=>100 ] ) ), 'Built-in contract count mismatch.' );
$assert( 2 === count( SPF_Registry::list_routes() ), 'Foundation route count mismatch.' );
$assert( false === get_option( 'spf_founder_user_id', false ), 'Unsafe legacy Founder option remains.' );
$assert( false === get_option( 'spf_page_map', false ), 'Legacy route map exists on fresh activation.' );

$unauthorized = SPF_Governance::record_release( [
	'software_version'=>'1.1.0','schema_version'=>'1.1.0','commit_sha'=>str_repeat('a',40),'package_name'=>'test.zip','checksum_sha256'=>str_repeat('b',64),
	'status'=>'planned','evidence'=>[ 'scope_reference'=>'runtime-test','owner'=>'file-01' ],
] );
$assert( is_wp_error( $unauthorized ) && 'spf_forbidden' === $unauthorized->get_error_code(), 'Sensitive release action did not fail closed without File 00 claim.' );

add_filter( 'spf_file00_authorization_claim', static function ( $claim, array $request ) {
	$founder_actions = [ 'approve_release','deploy_release','approve_amendment','purge','production_cutover' ];
	$release_actions = [ 'record_release','transition_release','run_reconciliation','run_schema_upgrade' ];
	$role = in_array( $request['action'], $founder_actions, true ) ? 'founder' : ( in_array( $request['action'], $release_actions, true ) ? 'release_operator' : 'administrator' );
	return [
		'claim_version'=>'1.1.0','allowed'=>true,'user_id'=>$request['user_id'],'action'=>$request['action'],'capability'=>$request['capability'],
		'issued_at'=>time()-5,'expires_at'=>time()+300,'claim_id'=>wp_generate_uuid4(),'object_hash'=>$request['object_hash'],'purpose'=>$request['purpose'],
		'institutional_role'=>$role,'suspended'=>false,'revoked'=>false,
	];
}, 10, 2 );

$invalid_initial = SPF_Governance::record_release( [
	'software_version'=>'1.1.1','schema_version'=>'1.1.0','commit_sha'=>str_repeat('c',40),'package_name'=>'bad.zip','checksum_sha256'=>str_repeat('d',64),
	'status'=>'deployed','evidence'=>[ 'production_change_id'=>'x' ],
] );
$assert( is_wp_error( $invalid_initial ) && 'spf_release_initial_state_invalid' === $invalid_initial->get_error_code(), 'Direct deployed release creation was accepted.' );

$release = SPF_Governance::record_release( [
	'software_version'=>'1.1.0','schema_version'=>'1.1.0','commit_sha'=>str_repeat('e',40),'package_name'=>'runtime-candidate.zip','checksum_sha256'=>str_repeat('f',64),
	'status'=>'planned','evidence'=>[ 'scope_reference'=>'SSH-F01-PLAN-2026-v1.0','owner'=>'file-01' ],
], [ 'purpose'=>'release_evidence' ] );
$assert( is_array( $release ) && 'planned' === $release['status'], 'Planned release could not be recorded.' );
$rid = $release['release_id'] ?? '';

$built = SPF_Governance::transition_release( $rid, 'built', [
	'source_commit_verified'=>true,'package_checksum_verified'=>true,'reproducible_build'=>true,'source_manifest'=>'SOURCE-MANIFEST.sha256','sbom'=>'SBOM.cdx.json',
], [ 'purpose'=>'release_transition','expected_sequence'=>1,'expected_record_version'=>1 ] );
$assert( is_array( $built ) && 2 === $built['sequence_no'], 'planned→built failed.' );
$verified = SPF_Governance::transition_release( $rid, 'verified', [
	'ci_run'=>'runtime-ci','test_summary'=>'all automated suites pass','zero_unresolved_critical_high'=>true,'security_review'=>'review-round-4',
], [ 'purpose'=>'release_transition','expected_sequence'=>2,'expected_record_version'=>2 ] );
$assert( is_array( $verified ) && 3 === $verified['sequence_no'], 'built→verified failed.' );
$staged = SPF_Governance::transition_release( $rid, 'staged', [
	'staging_environment'=>'disposable-ci','fresh_install'=>true,'upgrade_test'=>true,'cross_file_contracts'=>'stubbed owner contracts','backup_restore_test'=>true,
	'rollback_rehearsal'=>true,'browser_accessibility_rtl'=>'CI does not substitute Hostinger acceptance','founder_acceptance_pending'=>'yes',
], [ 'purpose'=>'release_transition','expected_sequence'=>3,'expected_record_version'=>3 ] );
$assert( is_array( $staged ) && 4 === $staged['sequence_no'], 'verified→staged failed.' );
$approved = SPF_Governance::transition_release( $rid, 'approved', [
	'founder_approval_id'=>'ci-founder-claim-test','approved_scope'=>'disposable CI only','staging_evidence_hash'=>str_repeat('a',64),'rollback_window'=>'30 minutes',
], [ 'purpose'=>'release_transition','expected_sequence'=>4,'expected_record_version'=>4 ] );
$assert( is_array( $approved ) && 'approved' === $approved['status'], 'Founder-gated staged→approved failed.' );
$record = SPF_Governance::get_release( $rid );
$assert( is_array( $record ) && 5 === count( $record['states'] ), 'Release state history is incomplete.' );

$flag = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>true,'reason'=>'runtime test' ], [ 'purpose'=>'feature_flag' ] );
$assert( is_array( $flag ) && spf_is_feature_enabled( 'file-01', 'runtime_probe', 'staging' ), 'Feature flag create/evaluate failed.' );
$flag2 = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>false,'reason'=>'runtime test complete' ], [ 'purpose'=>'feature_flag','expected_version'=>$flag['record_version'] ] );
$assert( is_array( $flag2 ) && ! spf_is_feature_enabled( 'file-01', 'runtime_probe', 'staging' ), 'Feature flag optimistic update failed.' );

$request = new WP_REST_Request( 'POST', '/sabri-foundation/v1/runtime-probe' );
$request->set_header( 'X-Idempotency-Key', 'runtime-probe-key-0001' );
$request->set_body_params( [ 'value'=>1 ] );
delete_option( 'spf_runtime_callback_count' );
$callback = static function () { $count=(int)get_option('spf_runtime_callback_count',0); update_option('spf_runtime_callback_count',$count+1,false); return [ 'count'=>$count+1 ]; };
$first = SPF_Idempotency::execute( $request, 'runtime_probe', $callback );
$second = SPF_Idempotency::execute( $request, 'runtime_probe', $callback );
$assert( ! is_wp_error( $first ) && ! is_wp_error( $second ), 'Idempotency execution/replay failed.' );
$assert( 1 === (int) get_option( 'spf_runtime_callback_count' ), 'Idempotent replay executed callback more than once.' );

$page_id = wp_insert_post( [ 'post_title'=>'Legacy File 01 Test','post_status'=>'draft','post_type'=>'page' ], true );
$assert( ! is_wp_error( $page_id ), 'Could not create disposable legacy test page.' );
add_post_meta( $page_id, '_spf_managed_page', '1' );
add_post_meta( $page_id, '_spf_legacy_quarantined', '1' );
add_post_meta( $page_id, '_spf_legacy_quarantined', 'preexisting-second' );
update_option( 'spf_page_map', [ 'home'=>(int)$page_id ], false );
update_option( 'spf_founder_user_id', 999, false );
add_filter( 'spf_owner_reconciliation_plan', static fn() => [ 'accepted'=>true,'owner_module'=>'file-20','command_version'=>'1.0.0','target'=>'canonical-shell-route' ] );
add_filter( 'spf_execute_owner_reconciliation', static fn() => [ 'success'=>true,'receipt_id'=>'owner-receipt-1','owner_module'=>'file-20','rollback_command'=>'RollbackOwnerRoute.v1' ] );
add_filter( 'spf_rollback_owner_reconciliation', static fn() => [ 'success'=>true ] );
$plan = SPF_Reconciler::plan();
$assert( empty( $plan['blockers'] ), 'Accepted owner reconciliation plan remains blocked.' );
$applied = SPF_Reconciler::apply( 'APPLY FILE 01 RECONCILIATION', SPF_Reconciler::plan_hash( $plan ) );
$assert( is_array( $applied ) && false === get_option( 'spf_page_map', false ), 'Reconciliation apply failed.' );
$rolled = SPF_Reconciler::rollback( 'ROLL BACK FILE 01 RECONCILIATION' );
$values = get_post_meta( $page_id, '_spf_legacy_quarantined', false );
$assert( is_array( $rolled ) && [ '1','preexisting-second' ] === array_values( $values ), 'Reconciliation rollback did not restore exact metadata values.' );
$assert( 999 === (int) get_option( 'spf_founder_user_id' ), 'Reconciliation rollback did not restore exact option value.' );
wp_delete_post( $page_id, true );
delete_option( 'spf_page_map' );
delete_option( 'spf_founder_user_id' );

$privacy = SPF_Privacy::create_request( get_current_user_id(), 'export', 'runtime test', 'platform governance' );
$assert( is_array( $privacy ) && ! empty( $privacy['request_id'] ), 'Privacy request could not be recorded.' );
$export = SPF_Privacy::export_personal_data( $admin->user_email, 1 );
$assert( is_array( $export ) && ! empty( $export['data'] ), 'Privacy export returned no File 01 records.' );
$erasure = SPF_Privacy::erase_personal_data( $admin->user_email, 1 );
$assert( ! empty( $erasure['items_retained'] ), 'Privacy erasure did not truthfully report retained immutable facts.' );
$audit_verify = SPF_Audit::verify_chain();
$assert( is_array( $audit_verify ) && ! empty( $audit_verify['verified'] ), 'Audit chain failed after privacy erasure.' );

update_option( SPF_Installer::SCHEMA_OPTION, '1.0.0', false );
add_filter( 'spf_verify_migration_backup_evidence', static fn() => [
	'verified'=>true,'backup_id'=>'ci-backup-1','restore_tested_at'=>gmdate('c'),'environment'=>'staging','verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600),
] );
$upgrade = SPF_Installer::maybe_upgrade();
$assert( true === $upgrade && '1.1.0' === get_option( SPF_Installer::SCHEMA_OPTION ), 'Evidence-gated schema upgrade failed.' );

SPF_Installer::activate();
$assert( 1 === count( SPF_Registry::list_modules( [ 'limit'=>100 ] ) ), 'Reactivation created duplicate/placeholder manifests.' );
$assert( 4 === count( SPF_Registry::list_contracts( [ 'limit'=>100 ] ) ), 'Reactivation duplicated contracts.' );

$health = SPF_System_Check::run( true );
$assert( is_array( $health ) && 'fail' !== $health['overall_status'], 'System Check has a blocking failure in disposable CI.' );
$assert( is_array( SPF_System_Check::latest() ), 'Latest health snapshot unavailable.' );

if ( $failures ) {
	fwrite( STDERR, "WordPress runtime tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "WordPress/MySQL runtime assertions: {$assertions}/{$assertions} PASS\n";

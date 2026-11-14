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

$assert( defined( 'SPF_VERSION' ) && '2.0.1' === SPF_VERSION, 'Plugin version mismatch.' );
$assert( '1.2.0' === get_option( SPF_Installer::SCHEMA_OPTION ), 'Schema version mismatch after activation.' );
$assert( '2.0.0' === get_option( SPF_Installer::CONTRACT_OPTION ), 'Contract version mismatch after activation.' );
$schema = SPF_Installer::verify_schema();
$assert( ! is_wp_error( $schema ), 'Schema verification failed: ' . ( is_wp_error( $schema ) ? $schema->get_error_message() : '' ) );
$assert( true === SPF_Installer::verify_required_indexes(), 'Required File 01 index integrity failed.' );
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
$assert( 5 === count( SPF_Registry::list_contracts( [ 'limit'=>100 ] ) ), 'Built-in contract count mismatch.' );
$assert( 2 === count( SPF_Registry::list_routes() ), 'Foundation route count mismatch.' );
$assert( false === get_option( 'spf_founder_user_id', false ), 'Unsafe legacy Founder option remains.' );
$assert( false === get_option( 'spf_page_map', false ), 'Legacy route map exists on fresh activation.' );

$future = SPF_Future_Foundation::status();
$assert( 18 === (int) ( $future['feature_count'] ?? 0 ) && 18 === (int) ( $future['implementation_present_count'] ?? 0 ), 'Future Foundation 18 implementation entrypoints are incomplete.' );
$assert( 0 === (int) ( $future['coded_count'] ?? -1 ) && false === ( $future['coded_completion_verified'] ?? true ), 'An unverified capability catalogue falsely certifies coding completion.' );
$assert( empty( $future['ai_autonomous_changes'] ) && empty( $future['ai_autonomous_approval'] ), 'AI governance boundary is not advisory-only.' );

$unauthorized = SPF_Governance::record_release( [
	'software_version'=>'2.0.0','schema_version'=>'1.2.0','commit_sha'=>str_repeat('a',40),'package_name'=>'test.zip','checksum_sha256'=>str_repeat('b',64),
	'status'=>'planned','evidence'=>[ 'scope_reference'=>'runtime-test','owner'=>'file-01' ],
] );
$assert( is_wp_error( $unauthorized ) && 'spf_forbidden' === $unauthorized->get_error_code(), 'Sensitive release action did not fail closed without File 00 claim.' );

// F01-FR-001 V12.3: an untrusted caller cannot authorize a Foundation amendment.
$amendment_probe_id = 'f01-v123-' . bin2hex( random_bytes( 6 ) );
$unauthorized_amendment = SPF_Governance::record_amendment( [
    'amendment_id'=>$amendment_probe_id,
    'effective_at'=>gmdate( 'Y-m-d H:i:s' ),
    'decision'=>[ 'scope'=>'synthetic-fixture', 'rule'=>'no-automatic-approval' ],
    'approver_ref'=>'synthetic-founder-fixture',
] );
$assert( is_wp_error( $unauthorized_amendment ) && 'spf_forbidden' === $unauthorized_amendment->get_error_code(),
    'V12.3 FR-001: unauthorized amendment was approved before File 00 claim.' );

add_filter( 'spf_file00_authorization_claim', static function ( $claim, array $request ) {
	$founder_actions = [ 'approve_release','deploy_release','approve_amendment','purge','production_cutover' ];
	$release_actions = [ 'record_release','transition_release','run_reconciliation','run_schema_upgrade' ];
	$role = in_array( $request['action'], $founder_actions, true ) ? 'founder' : ( in_array( $request['action'], $release_actions, true ) ? 'release_operator' : 'administrator' );
	return [
		'claim_version'=>'1.2.0','allowed'=>true,'user_id'=>$request['user_id'],'actor_id'=>$request['user_id'],'action'=>$request['action'],'capability'=>$request['capability'],
		'issued_at'=>time()-5,'expires_at'=>time()+300,'claim_id'=>wp_generate_uuid4(),'object_hash'=>$request['object_hash'],'purpose'=>$request['purpose'],
		'institutional_role'=>$role,'plugin'=>'file-01','contract'=>SPF_CONTRACT_VERSION,'suspended'=>false,'revoked'=>false,
	];
}, 10, 2 );

// F01-FR-001: authorized, disposable amendment registration and supersession chain.
global $wpdb; // WP-CLI eval-file executes in a method-local scope; use WordPress DB explicitly.
$amendment_test_table = SPF_Installer::table( 'amendments' );
$amendment_base_id = 'f01-v123-base-' . bin2hex( random_bytes( 6 ) );
$amendment_next_id = 'f01-v123-next-' . bin2hex( random_bytes( 6 ) );
$amendment_payload = [
    'amendment_id'=>$amendment_base_id,
    'effective_at'=>gmdate( 'Y-m-d H:i:s' ),
    'decision'=>[ 'scope'=>'synthetic-ci-only', 'requirement'=>'F01-FR-001' ],
    'approver_ref'=>'ci-founder-claim-fixture',
];
$malformed_amendment = $amendment_payload;
$malformed_amendment['decision'] = 'invalid-unstructured-decision';
$malformed_result = SPF_Governance::record_amendment( $malformed_amendment );
$assert( is_wp_error( $malformed_result ) && 'spf_invalid_amendment' === $malformed_result->get_error_code(),
    'V12.3 FR-001: unstructured Founder amendment decision was accepted.' );
$amendment_recorded = SPF_Governance::record_amendment( $amendment_payload );
$assert( true === $amendment_recorded, 'V12.3 FR-001: authorized bounded amendment was not stored.' );
$second_amendment = $amendment_payload;
$second_amendment['amendment_id'] = $amendment_next_id;
$second_amendment['supersedes'] = $amendment_base_id;
$second_amendment['decision'] = [ 'scope'=>'synthetic-ci-only', 'supersession'=>'approved-reference-fixture' ];
$next_recorded = SPF_Governance::record_amendment( $second_amendment );
$assert( true === $next_recorded, 'V12.3 FR-001: a subsequent, authorized synthetic amendment was rejected.' );
$stored_amendment = $wpdb->get_row( $wpdb->prepare( "SELECT amendment_id, supersedes, status FROM {$amendment_test_table} WHERE amendment_id=%s", $amendment_next_id ), ARRAY_A );
$assert( is_array( $stored_amendment ) && $amendment_next_id === ( $stored_amendment['amendment_id'] ?? '' )
    && $amendment_base_id === ( $stored_amendment['supersedes'] ?? '' )
    && 'approved' === ( $stored_amendment['status'] ?? '' ),
    'V12.3 FR-001: persisted synthetic amendment chain lost identity, supersedes reference, or status.' );
$duplicate_amendment = SPF_Governance::record_amendment( $amendment_payload );
$assert( is_wp_error( $duplicate_amendment ) && 'spf_amendment_write_failed' === $duplicate_amendment->get_error_code(),
    'V12.3 FR-001: duplicate amendment ID was permitted.' );
$wpdb->delete( $amendment_test_table, [ 'amendment_id'=>$amendment_base_id ], [ '%s' ] );
$wpdb->delete( $amendment_test_table, [ 'amendment_id'=>$amendment_next_id ], [ '%s' ] );
$remaining_amendment = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$amendment_test_table} WHERE amendment_id IN (%s,%s)", $amendment_base_id, $amendment_next_id ) );
$assert( 0 === $remaining_amendment, 'V12.3 FR-001: synthetic amendment rows were not cleaned up.' );

$invalid_initial = SPF_Governance::record_release( [
	'software_version'=>'2.0.1','schema_version'=>'1.2.0','commit_sha'=>str_repeat('c',40),'package_name'=>'bad.zip','checksum_sha256'=>str_repeat('d',64),
	'status'=>'deployed','evidence'=>[ 'production_change_id'=>'x' ],
] );
$assert( is_wp_error( $invalid_initial ) && 'spf_release_initial_state_invalid' === $invalid_initial->get_error_code(), 'Direct deployed release creation was accepted.' );

$release = SPF_Governance::record_release( [
	'software_version'=>'2.0.0','schema_version'=>'1.2.0','commit_sha'=>str_repeat('e',40),'package_name'=>'runtime-candidate.zip','checksum_sha256'=>str_repeat('f',64),
	'status'=>'planned','evidence'=>[ 'scope_reference'=>'SSH-F01-PLAN-2026-v1.0','owner'=>'file-01' ],
], [ 'purpose'=>'release_evidence' ] );
$assert( is_array( $release ) && 'planned' === $release['status'], 'Planned release could not be recorded.' );
$rid = $release['release_id'] ?? '';

$built = SPF_Governance::transition_release( $rid, 'built', [
	'source_commit_verified'=>true,'package_checksum_verified'=>true,'reproducible_build'=>true,'source_manifest'=>'SOURCE-MANIFEST.sha256','sbom'=>'SBOM.cdx.json',
], [ 'purpose'=>'release_transition','expected_sequence'=>1,'expected_record_version'=>1 ] );
$assert( is_array( $built ) && 2 === $built['sequence_no'], 'planned→built failed.' );
$verified = SPF_Governance::transition_release( $rid, 'verified', [
	'ci_run'=>'runtime-ci','test_summary'=>'all automated suites pass','zero_unresolved_critical_high'=>true,'security_review'=>'final-adversarial-review',
], [ 'purpose'=>'release_transition','expected_sequence'=>2,'expected_record_version'=>2 ] );
$assert( is_array( $verified ) && 3 === $verified['sequence_no'], 'built→verified failed.' );
$staged = SPF_Governance::transition_release( $rid, 'staged', [
	'staging_environment'=>'disposable-ci','staging_reality_freeze'=>'synthetic CI snapshot, not Hostinger','fresh_install'=>true,'upgrade_test'=>true,'cross_file_contracts'=>'stubbed owner contracts','backup_restore_test'=>true,
	'rollback_rehearsal'=>true,'browser_accessibility_rtl'=>'CI does not substitute Hostinger acceptance','founder_acceptance_pending'=>'yes',
], [ 'purpose'=>'release_transition','expected_sequence'=>3,'expected_record_version'=>3 ] );
$assert( is_array( $staged ) && 4 === $staged['sequence_no'], 'verified→staged failed.' );
$approved = SPF_Governance::transition_release( $rid, 'approved', [
	'founder_approval_id'=>'ci-founder-claim-test','approved_scope'=>'disposable CI only','staging_evidence_hash'=>$staged['evidence_hash'],'rollback_window'=>'30 minutes',
], [ 'purpose'=>'release_transition','expected_sequence'=>4,'expected_record_version'=>4 ] );
$assert( is_array( $approved ) && 'approved' === $approved['status'], 'Founder-gated staged→approved failed.' );
$record = SPF_Governance::get_release( $rid );
$assert( is_array( $record ) && 5 === count( $record['states'] ), 'Release state history is incomplete.' );

// V12.3 F01-FUT-011: disposable WordPress/MySQL progressive-delivery fail-closed scenarios.
// No external adapter is installed; no production rollout or File 24 approval is implied.
$rollout_initial_value = get_option( SPF_Platform_Engineering::ROLLOUT_OPTION, null );
$invalid_rollout = SPF_Platform_Engineering::create_rollout( $rid, [ 'staging','staging','full' ], [ 'availability'=>99.9 ] );
$assert( is_wp_error( $invalid_rollout ) && 'spf_rollout_invalid' === $invalid_rollout->get_error_code(),
    'V12.3: duplicate progressive-delivery ring was accepted.' );
$rollout_plan = SPF_Platform_Engineering::create_rollout( $rid, [ 'staging','canary','full' ], [ 'availability'=>99.9 ] );
$assert( is_array( $rollout_plan ) && 'planned' === ( $rollout_plan['status'] ?? '' ) && 0 === (int) ( $rollout_plan['index'] ?? -1 ),
    'V12.3: authorized bounded rollout plan could not be created.' );
$without_adapter = SPF_Platform_Engineering::advance_rollout( $rid, [ 'availability'=>99.95 ] );
$assert( is_array( $without_adapter ) && 'advance_pending_adapter' === ( $without_adapter['status'] ?? '' )
    && 'canary' === ( $without_adapter['next_ring'] ?? '' ) && 0 === (int) ( $without_adapter['index'] ?? -1 ),
    'V12.3: SLO success falsely advanced the rollout with no independent deployment adapter.' );
$failed_gate = SPF_Platform_Engineering::advance_rollout( $rid, [ 'availability'=>90.0 ] );
$assert( is_array( $failed_gate ) && 'rollback_required' === ( $failed_gate['status'] ?? '' )
    && 0 === (int) ( $failed_gate['index'] ?? -1 ),
    'V12.3: a failed SLO did not stop rollout and demand rollback.' );
$blocked_again = SPF_Platform_Engineering::advance_rollout( $rid, [ 'availability'=>99.95 ] );
$assert( is_wp_error( $blocked_again ) && 'spf_rollout_rollback_pending' === $blocked_again->get_error_code(),
    'V12.3: rollback-required rollout advanced again without recovery.' );
if ( null === $rollout_initial_value ) { delete_option( SPF_Platform_Engineering::ROLLOUT_OPTION ); }
else { update_option( SPF_Platform_Engineering::ROLLOUT_OPTION, $rollout_initial_value, false ); }

$ungated_flag = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>true,'reason'=>'runtime test' ], [ 'purpose'=>'feature_flag' ] );
$assert( is_wp_error( $ungated_flag ) && 'spf_evidence_unverified' === $ungated_flag->get_error_code(), 'Feature activation did not fail closed without readiness evidence.' );
add_filter( 'spf_verify_feature_activation_evidence', static function ( $claim, array $context ) {
	return [
		'verified'=>true,
		'owner_module'=>sanitize_key( $context['owner_module'] ?? '' ),
		'flag_key'=>sanitize_key( $context['flag_key'] ?? '' ),
		'environment'=>sanitize_key( $context['environment'] ?? '' ),
		'readiness_hash'=>(string) ( $context['readiness_hash'] ?? '' ),
		'migration_status'=>'ready','health_status'=>'pass',
		'rollback_evidence'=>'ci-rollback-ready','gate_evidence'=>'ci-gate-proof',
		'verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600),
	];
}, 10, 2 );
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
add_filter( 'spf_owner_reconciliation_plan', static fn() => [ 'accepted'=>true,'owner_module'=>'file-20','command_version'=>'2.0.0','target'=>'canonical-shell-route' ] );
add_filter( 'spf_execute_owner_reconciliation', static fn() => [ 'success'=>true,'receipt_id'=>'owner-receipt-1','owner_module'=>'file-20','command_version'=>'2.0.0','rollback_command'=>'rollback_owner_route_v1','state_hash'=>str_repeat('a',64) ] );
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
$assert( is_array( $audit_verify ) && ! empty( $audit_verify['verified'] ) && ! empty( $audit_verify['complete'] ), 'Audit chain failed after privacy erasure.' );

update_option( SPF_Installer::SCHEMA_OPTION, '1.0.0', false );
add_filter( 'spf_verify_migration_backup_evidence', static function ( $claim, array $context ) {
	return [
		'verified'=>true,'backup_id'=>'ci-backup-1','restore_tested_at'=>gmdate('c'),'environment'=>(string)($context['environment']??''),'verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600),
		'module'=>(string)($context['module']??''),'from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),
	];
}, 10, 2 );
$upgrade = SPF_Installer::maybe_upgrade();
$assert( true === $upgrade && '1.2.0' === get_option( SPF_Installer::SCHEMA_OPTION ), 'Evidence-gated schema upgrade failed.' );

SPF_Installer::activate();
$assert( 1 === count( SPF_Registry::list_modules( [ 'limit'=>100 ] ) ), 'Reactivation created duplicate/placeholder manifests.' );
$assert( 5 === count( SPF_Registry::list_contracts( [ 'limit'=>100 ] ) ), 'Reactivation duplicated contracts.' );

$health = SPF_System_Check::run( true );
$assert( is_array( $health ) && 'fail' !== $health['overall_status'], 'System Check has a blocking failure in disposable CI.' );
$assert( is_array( SPF_System_Check::latest() ), 'Latest health snapshot unavailable.' );

// Eleventh ten-round integrated runtime regressions.
global $wpdb;
$outbox = SPF_Installer::table( 'outbox' );
$wpdb->query( "DELETE FROM {$outbox} WHERE event_name LIKE 'EleventhReview.%'" );
$e1 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'a', [ 'value'=>1 ], 1, 'same-caller-key', 'internal' );
$e2 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'b', [ 'value'=>1 ], 1, 'same-caller-key', 'internal' );
$e3 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'a', [ 'value'=>999 ], 1, 'same-caller-key', 'internal' );
$e_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$outbox} WHERE event_name='EleventhReview.One.v1'" );
$assert( true === $e1 && true === $e2 && true === $e3 && 2 === $e_count, 'Explicit event dedupe key is not safely identity-scoped/idempotent.' );

update_option( SPF_Installer::SCHEMA_OPTION, '1.0.0', false );
add_filter( 'spf_verify_migration_backup_evidence', static function ( $claim, array $context ) {
    return [ 'verified'=>true,'backup_id'=>'wrong-context-backup','restore_tested_at'=>gmdate('c'),'verifier'=>'CI negative probe','module'=>'file-99','from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),'environment'=>(string)($context['environment']??''),'expires_at'=>gmdate('c',time()+3600) ];
}, 50, 2 );
$bad_upgrade = SPF_Installer::maybe_upgrade();
$assert( is_wp_error( $bad_upgrade ) && 'spf_migration_backup_evidence_binding_invalid' === $bad_upgrade->get_error_code(), 'Wrong-context migration backup evidence was accepted.' );
update_option( SPF_Installer::SCHEMA_OPTION, SPF_SCHEMA_VERSION, false );

add_filter( 'spf_verify_backup_restore_evidence', static function ( $claim, array $context ) {
    return [ 'verified'=>true,'backup_id'=>'negative-probe','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI negative probe','expires_at'=>gmdate('c',time()+3600),'operation'=>'file01_purge','plan_hash'=>str_repeat('0',64) ];
}, 50, 2 );
$purge_plan = SPF_Purge::plan();
$bad_purge = SPF_Purge::apply( 'PURGE FILE 01 GOVERNANCE DATA', [ 'backup_id'=>'submitted' ], SPF_Purge::plan_hash( $purge_plan ) );
$assert( is_wp_error( $bad_purge ) && 'spf_purge_backup_evidence_binding_invalid' === $bad_purge->get_error_code(), 'Wrong-plan purge backup evidence was accepted.' );

 // V12 migration truth: a wp_options version string cannot override actual schema.
$original_schema_option = get_option( SPF_Installer::SCHEMA_OPTION );
$original_upgrade_state = get_option( 'spf_upgrade_state', array( 'status'=>'not-required' ) );
$migrations_table = SPF_Installer::table( 'migrations' );
$migration_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$migrations_table}" );
update_option( SPF_Installer::SCHEMA_OPTION, '9.0.0', false );
$downgrade_result = SPF_Installer::maybe_upgrade();
$assert( is_wp_error( $downgrade_result ) && 'spf_schema_downgrade_blocked' === $downgrade_result->get_error_code(), 'Newer stored schema was incorrectly accepted by an older File 01 runtime.' );
$assert( 'downgrade_blocked' === ( get_option( 'spf_upgrade_state' )['status'] ?? '' ), 'Future-schema downgrade blocker was not reported.' );
$assert( '9.0.0' === get_option( SPF_Installer::SCHEMA_OPTION ), 'Downgrade probe silently mutated the schema-version option.' );
update_option( SPF_Installer::SCHEMA_OPTION, $original_schema_option, false );

// Operate only in disposable WordPress CI: remove an owned nonunique index,
// prove "already current" is rejected, and restore the exact index immediately.
$flags_table = SPF_Installer::table( 'flags' );
$index_dropped = $wpdb->query( "ALTER TABLE {$flags_table} DROP INDEX expires_at" );
if ( false === $index_dropped ) {
	$assert( false, 'CI could not establish the intentionally missing-index fixture.' );
} else {
	try {
		$stale_result = SPF_Installer::maybe_upgrade();
		$assert( is_wp_error( $stale_result ) && 'spf_schema_integrity_required' === $stale_result->get_error_code(), 'Matching schema-version option bypassed missing-index verification.' );
		$assert( 'schema_integrity_required' === ( get_option( 'spf_upgrade_state' )['status'] ?? '' ), 'Incomplete same-version schema was not recorded as a blocker.' );
		$assert( $original_schema_option === get_option( SPF_Installer::SCHEMA_OPTION ), 'Stale schema probe changed the version option.' );
	} finally {
		$index_restored = $wpdb->query( "ALTER TABLE {$flags_table} ADD INDEX expires_at (expires_at)" );
		$assert( false !== $index_restored && true === SPF_Installer::verify_schema(), 'Disposable missing-index fixture could not be completely restored.' );
	}
}
$assert( $migration_count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$migrations_table}" ), 'Schema-parity dry runs created a migration record.' );
update_option( SPF_Installer::SCHEMA_OPTION, $original_schema_option, false );
update_option( 'spf_upgrade_state', $original_upgrade_state, false );
$assert( true === SPF_Installer::maybe_upgrade(), 'Restored current schema was not accepted after truth checks.' );

// V12 fail-closed regression: on disposable WordPress/MySQL CI only.
// Every temporary registry corruption is restored before continuing.
$modules_table = SPF_Installer::table( 'modules' );
$original_module_row = $wpdb->get_row( $wpdb->prepare( "SELECT manifest_json,software_version FROM {$modules_table} WHERE module_key=%s", 'file-01' ), ARRAY_A );
$assert( is_array( $original_module_row ), 'Cannot capture a canonical module snapshot before integrity testing.' );
if ( is_array( $original_module_row ) ) {
	try {
		$wpdb->update( $modules_table, array( 'manifest_json'=>'{unparseable' ), array( 'module_key'=>'file-01' ) );
		$broken_manifest = SPF_Dependency_Resolver::readiness( 'file-01' );
		$assert( false === $broken_manifest['ready'] && 'module_manifest_invalid' === $broken_manifest['code'], 'Corrupt manifest JSON was misread as a dependency-free ready module.' );

		$malformed_manifest = json_decode( $original_module_row['manifest_json'], true );
		$malformed_manifest['required'] = 'not-an-array';
		$wpdb->update( $modules_table, array( 'manifest_json'=>wp_json_encode( $malformed_manifest ) ), array( 'module_key'=>'file-01' ) );
		$wrong_dependency_type = SPF_Dependency_Resolver::readiness( 'file-01' );
		$assert( false === $wrong_dependency_type['ready'] && 'module_manifest_invalid' === $wrong_dependency_type['code'], 'Malformed required-dependency payload was treated as ready.' );

		$malformed_manifest = json_decode( $original_module_row['manifest_json'], true );
		$malformed_manifest['software_version'] = '9.0.0';
		$wpdb->update( $modules_table, array( 'manifest_json'=>wp_json_encode( $malformed_manifest ) ), array( 'module_key'=>'file-01' ) );
		$mismatched_manifest = SPF_Dependency_Resolver::readiness( 'file-01' );
		$assert( false === $mismatched_manifest['ready'] && 'module_manifest_invalid' === $mismatched_manifest['code'], 'Manifest/version row identity drift was misread as ready.' );

		$wpdb->update( $modules_table, array( 'manifest_json'=>$original_module_row['manifest_json'], 'software_version'=>'not-semver' ), array( 'module_key'=>'file-01' ) );
		$invalid_runtime_version = SPF_Dependency_Resolver::readiness( 'file-01' );
		$assert( false === $invalid_runtime_version['ready'] && 'module_version_invalid' === $invalid_runtime_version['code'], 'Malformed persisted runtime SemVer was misread as ready.' );
	} finally {
		$restored_module = $wpdb->update( $modules_table, array( 'manifest_json'=>$original_module_row['manifest_json'], 'software_version'=>$original_module_row['software_version'] ), array( 'module_key'=>'file-01' ) );
		$assert( false !== $restored_module, 'Disposable corrupt-registry fixture could not be restored.' );
	}
	$healthy_module = SPF_Dependency_Resolver::readiness( 'file-01' );
	$assert( true === $healthy_module['ready'], 'Exact restored canonical registry did not recover readiness.' );
}

$bad_manifest = SPF_Registry::get_module( 'file-01' );
$bad_manifest['canonical_entities'] = [ 'Not Canonical' ];
$bad_manifest['writes'] = [];
$bad_manifest_result = SPF_Installer::with_internal_seed( static fn() => SPF_Registry::register_manifest( $bad_manifest ) );
$assert( is_wp_error( $bad_manifest_result ) && 'spf_invalid_manifest_entity' === $bad_manifest_result->get_error_code(), 'Noncanonical manifest entity was silently normalized.' );

// V12.3 F01-FUT-014: fully disposable, File-01-only self-heal apply/rollback drill.
// This is a synthetic WordPress/MySQL candidate test, not Hostinger recovery evidence.
$heal_option = 'spf_future_policy_catalog'; // belongs to SPF_Resilience_Lab::OWNED_OPTIONS.
$heal_original = get_option( $heal_option, null );
$heal_recoveries_original = get_option( SPF_Resilience_Lab::SELF_HEAL_RECOVERY_OPTION, null );
$heal_sentinel = 'synthetic-malformed-configuration-v123';
try {
    update_option( $heal_option, $heal_sentinel, false );
    $heal_plan = SPF_Resilience_Lab::self_heal_plan();
    $heal_targets = array_values( array_filter( (array) ( $heal_plan['actions'] ?? array() ),
        static fn( $a ) => 'quarantine_malformed_owned_option' === ( $a['action'] ?? '' )
            && $heal_option === ( $a['option'] ?? '' ) ) );
    $assert( 'file-01-only' === ( $heal_plan['owner_scope'] ?? '' )
        && true === ( $heal_plan['dry_run'] ?? false ) && count( $heal_targets ) === 1,
        'V12.3 FUT-014: dry-run failed to isolate malformed File 01-owned state.' );
    $heal_wrong_confirmation = SPF_Resilience_Lab::apply_self_heal( 'NOT CONFIRMED', (string) ( $heal_plan['plan_hash'] ?? '' ) );
    $assert( is_wp_error( $heal_wrong_confirmation )
        && 'spf_self_heal_confirmation_required' === $heal_wrong_confirmation->get_error_code()
        && $heal_sentinel === get_option( $heal_option ),
        'V12.3 FUT-014: apply bypassed typed confirmation or mutated the guarded option.' );
    $heal_wrong_hash = SPF_Resilience_Lab::apply_self_heal( 'APPLY FILE 01 SELF HEAL', str_repeat( '0', 64 ) );
    $assert( is_wp_error( $heal_wrong_hash )
        && 'spf_self_heal_plan_changed' === $heal_wrong_hash->get_error_code()
        && $heal_sentinel === get_option( $heal_option ),
        'V12.3 FUT-014: stale plan hash was accepted or mutated File 01 state.' );
    $heal_apply = SPF_Resilience_Lab::apply_self_heal( 'APPLY FILE 01 SELF HEAL', (string) $heal_plan['plan_hash'] );
    $heal_id = is_array( $heal_apply ) ? (string) ( $heal_apply['recovery_id'] ?? '' ) : '';
    $assert( is_array( $heal_apply ) && '' !== $heal_id
        && 'file-01-only' === ( $heal_apply['owner_scope'] ?? '' )
        && false === ( $heal_apply['companion_data_modified'] ?? true )
        && array() === get_option( $heal_option ),
        'V12.3 FUT-014: authorized File 01 self-heal did not apply with a recovery receipt.' );
    if ( '' !== $heal_id ) {
        $heal_without_confirmation = SPF_Resilience_Lab::rollback_self_heal( $heal_id, 'NOT CONFIRMED' );
        $assert( is_wp_error( $heal_without_confirmation )
            && 'spf_self_heal_rollback_confirmation_required' === $heal_without_confirmation->get_error_code()
            && array() === get_option( $heal_option ),
            'V12.3 FUT-014: rollback did not enforce independent typed confirmation.' );
        update_option( $heal_option, array( 'changed_after_heal'=>true ), false );
        $heal_stale_rollback = SPF_Resilience_Lab::rollback_self_heal( $heal_id, 'ROLL BACK FILE 01 SELF HEAL' );
        $assert( is_wp_error( $heal_stale_rollback )
            && 'spf_self_heal_recovery_stale' === $heal_stale_rollback->get_error_code()
            && array( 'changed_after_heal'=>true ) === get_option( $heal_option ),
            'V12.3 FUT-014: stale rollback overwrote newer owner state.' );
        update_option( $heal_option, array(), false ); // restore exact immediate post-heal state.
        $heal_rollback = SPF_Resilience_Lab::rollback_self_heal( $heal_id, 'ROLL BACK FILE 01 SELF HEAL' );
        $assert( is_array( $heal_rollback ) && true === ( $heal_rollback['rolled_back'] ?? false )
            && false === ( $heal_rollback['companion_data_modified'] ?? true )
            && $heal_sentinel === get_option( $heal_option ),
            'V12.3 FUT-014: valid rollback failed to restore the original File 01 option.' );
        $heal_idempotent = SPF_Resilience_Lab::rollback_self_heal( $heal_id, 'ROLL BACK FILE 01 SELF HEAL' );
        $assert( is_array( $heal_idempotent ) && true === ( $heal_idempotent['idempotent_replay'] ?? false )
            && $heal_sentinel === get_option( $heal_option ),
            'V12.3 FUT-014: rollback replay was not idempotent.' );
    }
} finally {
    if ( null === $heal_original ) { delete_option( $heal_option ); }
    else { update_option( $heal_option, $heal_original, false ); }
    if ( null === $heal_recoveries_original ) { delete_option( SPF_Resilience_Lab::SELF_HEAL_RECOVERY_OPTION ); }
    else { update_option( SPF_Resilience_Lab::SELF_HEAL_RECOVERY_OPTION, $heal_recoveries_original, false ); }
}
$assert( $heal_original === get_option( $heal_option, null )
    && $heal_recoveries_original === get_option( SPF_Resilience_Lab::SELF_HEAL_RECOVERY_OPTION, null ),
    'V12.3 FUT-014: disposable self-heal fixture did not restore its prior WordPress option state.' );

if ( $failures ) {
	fwrite( STDERR, "WordPress runtime tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "WordPress/MySQL runtime assertions: {$assertions}/{$assertions} PASS\n";
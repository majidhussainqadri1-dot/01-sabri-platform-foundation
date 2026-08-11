<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;
$failures = array();
$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$failures, &$assertions ) {
    $assertions++;
    if ( ! $condition ) { $failures[] = $message; }
};

// R1: invalid package identity and noncanonical immutable evidence must fail before authorization/write.
$base_release = array(
    'software_version'=>SPF_VERSION,
    'commit_sha'=>str_repeat( 'a', 40 ),
    'package_name'=>'file01.zip',
    'checksum_sha256'=>str_repeat( 'b', 64 ),
    'schema_version'=>SPF_SCHEMA_VERSION,
    'status'=>'planned',
    'evidence'=>array( 'scope_reference'=>'runtime-smoke', 'owner'=>'file-01' ),
);
$bad_package = $base_release; $bad_package['package_name'] = '../file01.zip';
$result = SPF_Governance::record_release( $bad_package );
$assert( is_wp_error( $result ) && 'spf_release_package_name_invalid' === $result->get_error_code(), 'Noncanonical release package filename was accepted.' );
$bad_evidence = $base_release; $bad_evidence['evidence'] = array( 'bad key'=>'value' );
$result = SPF_Governance::record_release( $bad_evidence );
$assert( is_wp_error( $result ) && 'spf_governance_evidence_key_noncanonical' === $result->get_error_code(), 'Noncanonical immutable evidence key was accepted.' );

// R2: a sanitized-equivalent File 00 claim must not become authorization truth.
$user_id = get_current_user_id();
$action = 'view';
$capability = SPF_Authorization::required_capability( $action );
$object = null;
$object_hash = SPF_Runtime::hash( array( 'object_id'=>'' ) );
$claim = array(
    'claim_version'=>'1.0.0','allowed'=>true,'user_id'=>(int)$user_id,'action'=>$action,'capability'=>$capability,
    'issued_at'=>time(),'expires_at'=>time()+300,'claim_id'=>'runtime-claim-1','object_hash'=>$object_hash,
    'purpose'=>'thirteenth_runtime','institutional_role'=>'member','plugin'=>'file-01','contract'=>SPF_CONTRACT_VERSION,
);
$assert( true === SPF_Authorization::validate_claim( $claim, $user_id, $action, $capability, $object, array( 'purpose'=>'thirteenth_runtime' ) ), 'Canonical structured authorization claim was unexpectedly rejected.' );
$claim['plugin'] = 'FILE-01';
$assert( false === SPF_Authorization::validate_claim( $claim, $user_id, $action, $capability, $object, array( 'purpose'=>'thirteenth_runtime' ) ), 'Noncanonical File 00 claim identity was accepted after sanitization.' );

// R3: operational acceptance must be fresh and bound to the exact health record.
$method = new ReflectionMethod( SPF_Plugin::class, 'validate_operational_claim' );
$method->setAccessible( true );
$health = array( 'overall_status'=>'pass', 'checked_at'=>gmdate( 'Y-m-d H:i:s' ) );
$context = array(
    'release_status'=>'deployed', 'release_id'=>'123e4567-e89b-42d3-a456-426614174000',
    'deployed_package_checksum'=>str_repeat( 'c', 64 ), 'health'=>$health, 'health_hash'=>SPF_Runtime::hash( $health ),
);
$operational_claim = array(
    'verified'=>true, 'release_id'=>$context['release_id'], 'deployed_package_checksum'=>$context['deployed_package_checksum'],
    'health_hash'=>$context['health_hash'], 'monitoring_status'=>'pass', 'support_status'=>'ready',
    'backup_restore_status'=>'pass', 'slo_status'=>'pass', 'observed_at'=>gmdate( 'c' ), 'expires_at'=>gmdate( 'c', time()+300 ), 'verifier'=>'ci-runtime',
);
$assert( true === $method->invoke( null, $operational_claim, $context ), 'Fresh exact-bound operational evidence was rejected.' );
$stale_claim = $operational_claim; $stale_claim['observed_at'] = gmdate( 'c', time()-3600 ); $stale_claim['expires_at'] = gmdate( 'c', time()+300 );
$assert( false === $method->invoke( null, $stale_claim, $context ), 'Stale operational evidence was accepted.' );
$stale_context = $context; $stale_context['health']['checked_at'] = gmdate( 'Y-m-d H:i:s', time()-3600 ); $stale_context['health_hash'] = SPF_Runtime::hash( $stale_context['health'] );
$stale_health_claim = $operational_claim; $stale_health_claim['health_hash'] = $stale_context['health_hash'];
$assert( false === $method->invoke( null, $stale_health_claim, $stale_context ), 'Stale health evidence was accepted as Operational.' );

// R4: malformed schema truth must fail closed in Installer::maybe_upgrade itself.
$schema_before = get_option( SPF_Installer::SCHEMA_OPTION, SPF_SCHEMA_VERSION );
update_option( SPF_Installer::SCHEMA_OPTION, 'not-a-semver', false );
$upgrade = SPF_Installer::maybe_upgrade();
$assert( is_wp_error( $upgrade ) && 'spf_schema_version_invalid' === $upgrade->get_error_code(), 'Direct installer API accepted malformed schema version.' );
update_option( SPF_Installer::SCHEMA_OPTION, $schema_before, false );

// R5: self-heal must snapshot the canonical flag row, disable it, and restore it exactly.
$flag_table = SPF_Installer::table( 'flags' );
$flag_key = 'thirteenth-self-heal-' . wp_generate_password( 8, false, false );
$now = SPF_Runtime::now_mysql();
$expired_at = gmdate( 'Y-m-d H:i:s', time()-120 );
$wpdb->insert( $flag_table, array(
    'owner_module'=>'file-01','flag_key'=>$flag_key,'environment'=>'staging','enabled'=>1,'expires_at'=>$expired_at,
    'reason'=>'thirteenth-runtime','record_version'=>1,'created_at'=>$now,'updated_at'=>$now,
) );
$flag_id = (int) $wpdb->insert_id;
$original_flag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flag_table} WHERE id=%d", $flag_id ), ARRAY_A );
$plan = SPF_Resilience_Lab::self_heal_plan();
$flag_action = null;
foreach ( (array) $plan['actions'] as $action_row ) { if ( 'reconcile_expired_flags' === ( $action_row['action'] ?? '' ) ) { $flag_action = $action_row; break; } }
$flag_ids = $flag_action ? array_map( static function( $row ){ return (int)($row['id']??0); }, (array)$flag_action['flag_snapshot'] ) : array();
$assert( is_array( $flag_action ) && in_array( $flag_id, $flag_ids, true ), 'Self-heal dry run did not bind the canonical expired flag row.' );
$heal_result = SPF_Resilience_Lab::apply_self_heal( 'APPLY FILE 01 SELF HEAL', $plan['plan_hash'] );
$after_heal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flag_table} WHERE id=%d", $flag_id ), ARRAY_A );
$assert( ! is_wp_error( $heal_result ) && 0 === (int)($after_heal['enabled']??-1), 'Self-heal did not disable the exact bound expired flag.' );
$recovery_id = is_array( $heal_result ) ? (string)($heal_result['recovery_id']??'') : '';
$rollback = $recovery_id ? SPF_Resilience_Lab::rollback_self_heal( $recovery_id, 'ROLL BACK FILE 01 SELF HEAL' ) : null;
$after_rollback = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flag_table} WHERE id=%d", $flag_id ), ARRAY_A );
$assert( ! is_wp_error( $rollback ) && SPF_Runtime::hash( $original_flag ) === SPF_Runtime::hash( $after_rollback ), 'Self-heal rollback did not restore the exact canonical flag row.' );
$audit_table = SPF_Installer::table( 'audit' );
$positive_audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action_name=%s AND object_id=%s", 'feature_flag_expired', 'file-01:'.$flag_key.':staging' ) );
$assert( $positive_audit >= 1, 'Automatic flag expiry completed without positive mandatory audit evidence.' );
$wpdb->delete( $flag_table, array( 'id'=>$flag_id ), array( '%d' ) );

// R6: historical external evidence cannot claim a future observation time.
$future_filter = static function () { return array( 'verified'=>true, 'restore_tested_at'=>gmdate( 'c', time()+3600 ) ); };
add_filter( 'spf_thirteenth_future_evidence', $future_filter, 10, 2 );
$future_evidence = SPF_Runtime::verify_evidence( 'spf_thirteenth_future_evidence', array(), array( 'restore_tested_at' ) );
remove_filter( 'spf_thirteenth_future_evidence', $future_filter, 10 );
$assert( is_wp_error( $future_evidence ) && 'spf_evidence_timestamp_future' === $future_evidence->get_error_code(), 'Future-dated historical evidence was accepted.' );

// R9 review surface: noncanonical amendment IDs must fail before authorization/write.
$bad_amendment = SPF_Governance::record_amendment( array(
    'amendment_id'=>'bad id','effective_at'=>gmdate('c'),'decision'=>array('rule'=>'test'),'approver_ref'=>'founder-runtime'
) );
$assert( is_wp_error( $bad_amendment ) && 'spf_invalid_amendment_id' === $bad_amendment->get_error_code(), 'Noncanonical amendment identifier was accepted.' );

// R7: complete audit verification remains paged and returns explicit bounded evidence.
$audit_check = SPF_Audit::verify_chain( 500000 );
$assert( ! is_wp_error( $audit_check ) && ! empty( $audit_check['complete'] ) && 5000 === (int)$audit_check['batch_size'] && 500000 === (int)$audit_check['ceiling'], 'Paged complete audit-chain verification failed.' );

if ( $failures ) {
    fwrite( STDERR, "Thirteenth WordPress/MySQL runtime smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Thirteenth WordPress/MySQL runtime assertions: {$assertions}/{$assertions} PASS\n";

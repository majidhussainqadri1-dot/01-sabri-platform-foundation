<?php
wp_set_current_user( 1 );
add_filter( 'spf_file00_authorization_claim', static function ( $claim, array $request ) {
	return [
		'claim_version'=>'1.1.0','allowed'=>true,'user_id'=>$request['user_id'],'action'=>$request['action'],'capability'=>$request['capability'],
		'issued_at'=>time()-5,'expires_at'=>time()+300,'claim_id'=>wp_generate_uuid4(),'object_hash'=>$request['object_hash'],'purpose'=>$request['purpose'],
		'institutional_role'=>'founder','plugin'=>'file-01','contract'=>SPF_CONTRACT_VERSION,'suspended'=>false,'revoked'=>false,
	];
}, 10, 2 );
add_filter( 'spf_verify_backup_restore_evidence', static function ( $claim, array $context ) {
	return [
		'verified'=>true,'backup_id'=>'disposable-ci-backup','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI','expires_at'=>gmdate('c',time()+3600),
		'operation'=>(string)($context['operation']??''),'plan_hash'=>(string)($context['plan_hash']??''),
	];
}, 10, 2 );
add_filter( 'spf_verify_file24_purge_assurance', static function ( $claim, array $context ) {
	return [
		'verified'=>true,'assurance_id'=>'file24-ci-assurance','reviewed_at'=>gmdate('c'),'verifier'=>'CI File24 adapter','expires_at'=>gmdate('c',time()+3600),
		'operation'=>(string)($context['operation']??''),'plan_hash'=>(string)($context['plan_hash']??''),'backup_evidence_hash'=>(string)($context['backup_evidence_hash']??''),'audit_chain_head'=>(string)($context['audit_chain_head']??''),
	];
}, 10, 2 );
$plan=SPF_Purge::plan();
$result=SPF_Purge::apply('PURGE FILE 01 GOVERNANCE DATA',[ 'backup_id'=>'submitted' ],SPF_Purge::plan_hash($plan));
if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().': '.$result->get_error_message()."\n");exit(1);}
if('completed'!==($result['status']??'')||!empty($result['verification']['tables_remaining'])){fwrite(STDERR,"Purge did not complete truthfully.\n");exit(1);}
echo "Disposable purge smoke PASS\n";

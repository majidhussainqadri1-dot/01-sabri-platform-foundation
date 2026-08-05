<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Governance {
	private const RELEASE_STATES = array( 'planned','built','verified','staged','approved','deployed','rolled_back','superseded' );

	public static function record_release( array $release, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'software_version','commit_sha','package_name','checksum_sha256','schema_version','evidence' ) as $field ) {
			if ( ! isset( $release[ $field ] ) ) { return new WP_Error( 'spf_invalid_release', 'Missing ' . $field ); }
		}
		if ( ! SPF_Registry::valid_semver( $release['software_version'] ) || ! SPF_Registry::valid_semver( $release['schema_version'] ) || ! preg_match( '/^[a-f0-9]{40,64}$/i', $release['commit_sha'] ) || ! preg_match( '/^[a-f0-9]{64}$/i', $release['checksum_sha256'] ) || ! is_array( $release['evidence'] ) ) {
			return new WP_Error( 'spf_invalid_release', __( 'Release evidence is invalid.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'record_release' ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		$release_id = isset( $release['release_id'] ) && wp_is_uuid( $release['release_id'] ) ? $release['release_id'] : wp_generate_uuid4();
		$status = sanitize_key( $release['status'] ?? 'built' ); if ( ! in_array( $status, self::RELEASE_STATES, true ) ) { return new WP_Error( 'spf_invalid_release_state', __( 'Invalid release state.', 'sabri-platform-foundation' ) ); }
		$pre = SPF_Audit::record_required( 'record_release_precommit', 'foundation_release', $release_id, 'authorized', array( 'purpose' => $context['purpose'] ?? 'release_evidence' ) ); if ( is_wp_error( $pre ) ) { return $pre; }
		$wpdb->query( 'START TRANSACTION' );
		try {
			$insert = $wpdb->insert( SPF_Installer::table( 'releases' ), array(
				'release_id'=>$release_id,'software_version'=>sanitize_text_field($release['software_version']),'commit_sha'=>strtolower($release['commit_sha']),
				'package_name'=>substr(sanitize_file_name($release['package_name']),0,191),'checksum_sha256'=>strtolower($release['checksum_sha256']),
				'schema_version'=>sanitize_text_field($release['schema_version']),'evidence_json'=>wp_json_encode(self::sanitize_evidence($release['evidence'])),'status'=>$status,'created_at'=>current_time('mysql',true)
			) );
			if ( false === $insert ) { throw new RuntimeException( 'Release record could not be inserted.' ); }
			$state = $wpdb->insert( SPF_Installer::table( 'release_states' ), array( 'release_id'=>$release_id,'sequence_no'=>1,'status'=>$status,'evidence_json'=>wp_json_encode(self::sanitize_evidence($release['evidence'])),'actor_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true) ) );
			if ( false === $state ) { throw new RuntimeException( 'Release state could not be inserted.' ); }
			$audit = SPF_Audit::record_required( 'record_release', 'foundation_release', $release_id, 'success', array( 'purpose'=>$context['purpose']??'release_evidence','status'=>$status ) );
			if ( is_wp_error( $audit ) ) { throw new RuntimeException( $audit->get_error_message() ); }
			$event = SPF_Event_Bus::publish( 'FoundationReleaseRecorded.v1', 'foundation_release', $release_id, array( 'status'=>$status,'version'=>$release['software_version'] ), 1, 'release-'.$release_id );
			if ( is_wp_error( $event ) ) { throw new RuntimeException( $event->get_error_message() ); }
			$wpdb->query( 'COMMIT' );
			return array( 'release_id'=>$release_id,'status'=>$status );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); return new WP_Error( 'spf_release_write_failed', $e->getMessage() );
		}
	}

	public static function transition_release( $release_id, $next_status, array $evidence = array(), array $context = array() ) {
		global $wpdb;
		$release_id = sanitize_text_field( $release_id ); $next_status = sanitize_key( $next_status );
		if ( ! wp_is_uuid( $release_id ) || ! in_array( $next_status, self::RELEASE_STATES, true ) ) { return new WP_Error( 'spf_invalid_release_transition', __( 'Invalid release transition.', 'sabri-platform-foundation' ) ); }
		$allowed = SPF_Authorization::require_action( 'record_release', $release_id ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		$wpdb->query( 'START TRANSACTION' );
		try {
			$release = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'releases' ) . ' WHERE release_id=%s FOR UPDATE', $release_id ), ARRAY_A );
			if ( ! $release ) { throw new RuntimeException( 'Release not found.' ); }
			$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'release_states' ) . ' WHERE release_id=%s ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE', $release_id ), ARRAY_A );
			$expected_sequence = isset( $context['expected_sequence'] ) ? absint( $context['expected_sequence'] ) : null;
			if ( null !== $expected_sequence && (int) $last['sequence_no'] !== $expected_sequence ) { throw new RuntimeException( 'The release state changed before this transition.' ); }
			if ( ! self::release_transition_allowed( $last['status'], $next_status ) ) { throw new RuntimeException( 'The release transition is not allowed.' ); }
			$sequence = (int) $last['sequence_no'] + 1;
			$state = $wpdb->insert( SPF_Installer::table( 'release_states' ), array( 'release_id'=>$release_id,'sequence_no'=>$sequence,'status'=>$next_status,'evidence_json'=>wp_json_encode(self::sanitize_evidence($evidence)),'actor_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true) ) );
			if ( false === $state ) { throw new RuntimeException( 'Release state could not be stored.' ); }
			$updated = $wpdb->update( SPF_Installer::table( 'releases' ), array( 'status'=>$next_status ), array( 'release_id'=>$release_id,'status'=>$last['status'] ) );
			if ( 1 !== $updated ) { throw new RuntimeException( 'Release state conflict.' ); }
			$audit = SPF_Audit::record_required( 'transition_release', 'foundation_release', $release_id, 'success', array( 'purpose'=>$context['purpose']??'release_transition','from'=>$last['status'],'to'=>$next_status,'sequence'=>$sequence ) );
			if ( is_wp_error( $audit ) ) { throw new RuntimeException( $audit->get_error_message() ); }
			$event = SPF_Event_Bus::publish( 'FoundationReleaseStateChanged.v1', 'foundation_release', $release_id, array( 'from'=>$last['status'],'to'=>$next_status,'sequence'=>$sequence ), 1, 'release-state-'.$release_id.'-'.$sequence );
			if ( is_wp_error( $event ) ) { throw new RuntimeException( $event->get_error_message() ); }
			$wpdb->query( 'COMMIT' ); return array( 'release_id'=>$release_id,'status'=>$next_status,'sequence_no'=>$sequence );
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'spf_release_transition_failed', $e->getMessage(), array( 'status'=>409 ) ); }
	}

	public static function record_amendment( array $amendment, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'amendment_id','effective_at','decision','approver_ref' ) as $field ) { if ( empty( $amendment[$field] ) ) { return new WP_Error( 'spf_invalid_amendment', 'Missing '.$field ); } }
		if ( ! is_array( $amendment['decision'] ) ) { return new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) ); }
		$allowed = SPF_Authorization::require_action( 'approve_amendment' ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );
		$pre = SPF_Audit::record_required( 'record_amendment_precommit', 'foundation_amendment', $id, 'authorized', array( 'purpose'=>$context['purpose']??'change_control' ) ); if ( is_wp_error( $pre ) ) { return $pre; }
		$ok = $wpdb->insert( SPF_Installer::table( 'amendments' ), array( 'amendment_id'=>$id,'effective_at'=>gmdate('Y-m-d H:i:s',strtotime($amendment['effective_at'])),'supersedes'=>substr(sanitize_text_field($amendment['supersedes']??''),0,191),'decision_json'=>wp_json_encode(self::sanitize_evidence($amendment['decision'])),'approver_ref'=>substr(sanitize_text_field($amendment['approver_ref']),0,191),'status'=>'approved','created_at'=>current_time('mysql',true) ) );
		if ( false === $ok ) { return new WP_Error( 'spf_amendment_write_failed', __( 'Amendment could not be stored.', 'sabri-platform-foundation' ) ); }
		SPF_Audit::record( 'record_amendment', 'foundation_amendment', $id, 'success', $context );
		SPF_Event_Bus::publish( 'FoundationAmendmentApproved.v1', 'foundation_amendment', $id, array( 'effective_at'=>$amendment['effective_at'] ), 1, 'amendment-'.$id );
		return true;
	}

	public static function set_flag( array $flag, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'flag_key','owner_module','environment','enabled','reason' ) as $field ) { if ( ! array_key_exists( $field, $flag ) ) { return new WP_Error( 'spf_invalid_flag', 'Missing '.$field ); } }
		$owner = sanitize_key( $flag['owner_module'] ); if ( ! SPF_Registry::get_module( $owner ) ) { return new WP_Error( 'spf_flag_owner_missing', __( 'Flag owner missing.', 'sabri-platform-foundation' ) ); }
		$allowed = SPF_Authorization::require_action( 'set_flag', $owner ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		$table=SPF_Installer::table('flags'); $key=sanitize_key($flag['flag_key']); $env=sanitize_key($flag['environment']);
		$old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE owner_module=%s AND flag_key=%s AND environment=%s",$owner,$key,$env),ARRAY_A);
		$data=array('owner_module'=>$owner,'flag_key'=>$key,'environment'=>$env,'enabled'=>empty($flag['enabled'])?0:1,'expires_at'=>empty($flag['expires_at'])?null:gmdate('Y-m-d H:i:s',strtotime($flag['expires_at'])),'reason'=>substr(sanitize_text_field($flag['reason']),0,500),'updated_at'=>current_time('mysql',true));
		if($old){$data['record_version']=(int)$old['record_version']+1;$ok=$wpdb->update($table,$data,array('id'=>(int)$old['id']));}else{$data+=array('record_version'=>1,'created_at'=>current_time('mysql',true));$ok=$wpdb->insert($table,$data);}
		if(false===$ok){return new WP_Error('spf_flag_write_failed',__('Flag could not be stored.','sabri-platform-foundation'));}
		SPF_Audit::record('set_flag','foundation_flag',$owner.':'.$key,'success',$context); return true;
	}

	public static function get_release( $release_id ) {
		global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.SPF_Installer::table('releases').' WHERE release_id=%s',$release_id),ARRAY_A); if(!$row){return null;}
		$row['evidence']=json_decode($row['evidence_json'],true); unset($row['evidence_json']);
		$row['states']=$wpdb->get_results($wpdb->prepare('SELECT sequence_no,status,evidence_json,actor_id,created_at FROM '.SPF_Installer::table('release_states').' WHERE release_id=%s ORDER BY sequence_no',$release_id),ARRAY_A); return $row;
	}
	public static function list_releases( $limit=50 ) { global $wpdb; $limit=max(1,min(100,absint($limit))); return $wpdb->get_results($wpdb->prepare('SELECT release_id,software_version,commit_sha,package_name,checksum_sha256,schema_version,status,created_at FROM '.SPF_Installer::table('releases').' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A); }
	public static function list_amendments( $limit=100 ) { global $wpdb; $limit=max(1,min(200,absint($limit))); $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.SPF_Installer::table('amendments').' ORDER BY effective_at DESC LIMIT %d',$limit),ARRAY_A); foreach($rows as &$row){$row['decision']=json_decode($row['decision_json'],true);unset($row['decision_json']);} return $rows; }

	private static function release_transition_allowed( $from, $to ) {
		if($from===$to){return true;} $map=array('planned'=>array('built','superseded'),'built'=>array('verified','superseded'),'verified'=>array('staged','superseded'),'staged'=>array('approved','rolled_back','superseded'),'approved'=>array('deployed','rolled_back','superseded'),'deployed'=>array('rolled_back','superseded'),'rolled_back'=>array('superseded'),'superseded'=>array()); return isset($map[$from])&&in_array($to,$map[$from],true);
	}
	private static function sanitize_evidence( array $input ) {
		$out=array(); foreach($input as $k=>$v){if(preg_match('/password|token|secret|authorization|cookie|nonce|patient|message_body|payment|identity_document/i',(string)$k)){$out[$k]='[redacted]';}elseif(is_array($v)){$out[$k]=self::sanitize_evidence($v);}elseif(is_scalar($v)||null===$v){$out[$k]=is_string($v)?substr(sanitize_text_field($v),0,1000):$v;}else{$out[$k]='[unsupported]';}} return $out;
	}
}

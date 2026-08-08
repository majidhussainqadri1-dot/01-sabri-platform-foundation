<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Governance {
	private const RELEASE_STATES = array( 'planned','built','verified','staged','approved','deployed','rolled_back','superseded' );

	public static function record_release( array $release, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'software_version','commit_sha','package_name','checksum_sha256','schema_version','evidence' ) as $field ) {
			if ( ! isset( $release[ $field ] ) ) {
				return new WP_Error( 'spf_invalid_release', 'Missing ' . $field );
			}
		}
		$status = sanitize_key( $release['status'] ?? 'planned' );
		if ( 'planned' !== $status ) {
			return new WP_Error( 'spf_release_initial_state_invalid', __( 'A release must be created as planned.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		if ( ! SPF_Registry::valid_semver( (string) $release['software_version'] ) || ! SPF_Registry::valid_semver( (string) $release['schema_version'] ) || ! preg_match( '/^[a-f0-9]{40,64}$/i', (string) $release['commit_sha'] ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $release['checksum_sha256'] ) || ! is_array( $release['evidence'] ) ) {
			return new WP_Error( 'spf_invalid_release', __( 'Release evidence is invalid.', 'sabri-platform-foundation' ) );
		}
		$evidence = self::sanitize_evidence( $release['evidence'] );
		$evidence_error = self::validate_evidence_for_state( $status, $evidence );
		if ( is_wp_error( $evidence_error ) ) {
			return $evidence_error;
		}
		$allowed = SPF_Authorization::require_action( 'record_release', array( 'object_id' => $release['software_version'], 'state' => $status ), array( 'purpose' => $context['purpose'] ?? 'release_evidence' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$release_id = isset( $release['release_id'] ) && wp_is_uuid( $release['release_id'] ) ? $release['release_id'] : wp_generate_uuid4();
		$pre = SPF_Audit::record_required( 'record_release_precommit', 'foundation_release', $release_id, 'authorized', array( 'purpose' => $context['purpose'] ?? 'release_evidence', 'status' => $status ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$now = SPF_Runtime::now_mysql();
			$evidence_hash = SPF_Runtime::hash( $evidence );
			$insert = $wpdb->insert(
				SPF_Installer::table( 'releases' ),
				array(
					'release_id' => $release_id,
					'software_version' => sanitize_text_field( $release['software_version'] ),
					'commit_sha' => strtolower( $release['commit_sha'] ),
					'package_name' => substr( sanitize_file_name( $release['package_name'] ), 0, 191 ),
					'checksum_sha256' => strtolower( $release['checksum_sha256'] ),
					'schema_version' => sanitize_text_field( $release['schema_version'] ),
					'evidence_json' => wp_json_encode( $evidence ),
					'evidence_hash' => $evidence_hash,
					'status' => $status,
					'approved_by' => 0,
					'record_version' => 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
			if ( false === $insert ) {
				throw new RuntimeException( 'Release record could not be inserted.' );
			}
			$state = $wpdb->insert(
				SPF_Installer::table( 'release_states' ),
				array( 'release_id'=>$release_id,'sequence_no'=>1,'status'=>$status,'evidence_json'=>wp_json_encode($evidence),'evidence_hash'=>$evidence_hash,'actor_id'=>get_current_user_id(),'created_at'=>$now )
			);
			if ( false === $state ) {
				throw new RuntimeException( 'Release state could not be inserted.' );
			}
			$audit = SPF_Audit::record_required( 'record_release', 'foundation_release', $release_id, 'success', array( 'purpose'=>$context['purpose']??'release_evidence','status'=>$status,'evidence_hash'=>$evidence_hash ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationReleaseRecorded.v1', 'foundation_release', $release_id, array( 'status'=>$status,'version'=>$release['software_version'],'evidence_hash'=>$evidence_hash ), 1, 'release-'.$release_id );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'release_id'=>$release_id,'status'=>$status,'sequence_no'=>1,'record_version'=>1,'evidence_hash'=>$evidence_hash );
		} catch ( Throwable $e ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_release_write_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function transition_release( $release_id, $next_status, array $evidence = array(), array $context = array() ) {
		global $wpdb;
		$release_id = sanitize_text_field( $release_id );
		$next_status = sanitize_key( $next_status );
		if ( ! wp_is_uuid( $release_id ) || ! in_array( $next_status, self::RELEASE_STATES, true ) ) {
			return new WP_Error( 'spf_invalid_release_transition', __( 'Invalid release transition.', 'sabri-platform-foundation' ) );
		}
		$evidence = self::sanitize_evidence( $evidence );
		$evidence_error = self::validate_evidence_for_state( $next_status, $evidence );
		if ( is_wp_error( $evidence_error ) ) {
			return $evidence_error;
		}
		$action = 'transition_release';
		if ( 'approved' === $next_status ) {
			$action = 'approve_release';
		} elseif ( 'deployed' === $next_status ) {
			$action = 'deploy_release';
		}
		$allowed = SPF_Authorization::require_action( $action, array( 'object_id' => $release_id, 'next_status' => $next_status ), array( 'purpose' => $context['purpose'] ?? 'release_transition' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$pre = SPF_Audit::record_required( 'transition_release_precommit', 'foundation_release', $release_id, 'authorized', array( 'purpose' => $context['purpose'] ?? 'release_transition', 'next_status' => $next_status ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$release = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'releases' ) . ' WHERE release_id=%s FOR UPDATE', $release_id ), ARRAY_A );
			if ( ! $release ) {
				throw new RuntimeException( 'Release not found.' );
			}
			$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SPF_Installer::table( 'release_states' ) . ' WHERE release_id=%s ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE', $release_id ), ARRAY_A );
			if ( ! $last ) {
				throw new RuntimeException( 'Release state history is missing.' );
			}
			if ( ! isset( $context['expected_sequence'], $context['expected_record_version'] ) ) {
				throw new RuntimeException( 'Expected sequence and record version are required.' );
			}
			if ( (int) $last['sequence_no'] !== (int) $context['expected_sequence'] || (int) $release['record_version'] !== (int) $context['expected_record_version'] ) {
				throw new RuntimeException( 'The release changed before this transition.' );
			}
			if ( ! self::release_transition_allowed( $last['status'], $next_status ) ) {
				throw new RuntimeException( 'The release transition is not allowed.' );
			}
			if ( in_array( $next_status, array( 'approved','deployed' ), true ) && ! self::prior_evidence_complete( $release_id, $next_status ) ) {
				throw new RuntimeException( 'Required prior release gates are incomplete.' );
			}
			$sequence = (int) $last['sequence_no'] + 1;
			$evidence_hash = SPF_Runtime::hash( $evidence );
			$now = SPF_Runtime::now_mysql();
			$state = $wpdb->insert( SPF_Installer::table( 'release_states' ), array( 'release_id'=>$release_id,'sequence_no'=>$sequence,'status'=>$next_status,'evidence_json'=>wp_json_encode($evidence),'evidence_hash'=>$evidence_hash,'actor_id'=>get_current_user_id(),'created_at'=>$now ) );
			if ( false === $state ) {
				throw new RuntimeException( 'Release state could not be stored.' );
			}
			$update = array( 'status'=>$next_status,'record_version'=>(int)$release['record_version']+1,'updated_at'=>$now );
			if ( 'approved' === $next_status ) {
				$update['approved_by'] = get_current_user_id();
				$update['approved_at'] = $now;
			}
			if ( 'deployed' === $next_status ) {
				$update['deployed_at'] = $now;
			}
			$updated = $wpdb->update( SPF_Installer::table( 'releases' ), $update, array( 'release_id'=>$release_id,'status'=>$last['status'],'record_version'=>(int)$release['record_version'] ) );
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Release state conflict.' );
			}
			$audit = SPF_Audit::record_required( 'transition_release', 'foundation_release', $release_id, 'success', array( 'purpose'=>$context['purpose']??'release_transition','from'=>$last['status'],'to'=>$next_status,'sequence'=>$sequence,'evidence_hash'=>$evidence_hash ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationReleaseStateChanged.v1', 'foundation_release', $release_id, array( 'from'=>$last['status'],'to'=>$next_status,'sequence'=>$sequence,'evidence_hash'=>$evidence_hash ), 1, 'release-state-'.$release_id.'-'.$sequence );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			if ( 'approved' === $next_status ) {
				$approved_event = SPF_Event_Bus::publish( 'ReleaseApproved.v1', 'foundation_release', $release_id, array( 'sequence'=>$sequence,'approved_by'=>get_current_user_id(),'evidence_hash'=>$evidence_hash ), 1, 'release-approved-'.$release_id );
				if ( is_wp_error( $approved_event ) ) {
					throw new RuntimeException( $approved_event->get_error_message() );
				}
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'release_id'=>$release_id,'status'=>$next_status,'sequence_no'=>$sequence,'record_version'=>(int)$release['record_version']+1,'evidence_hash'=>$evidence_hash );
		} catch ( Throwable $e ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_release_transition_failed', $e->getMessage(), array( 'status'=>409 ) );
		}
	}

	public static function record_amendment( array $amendment, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'amendment_id','effective_at','decision','approver_ref' ) as $field ) {
			if ( empty( $amendment[ $field ] ) ) {
				return new WP_Error( 'spf_invalid_amendment', 'Missing '.$field );
			}
		}
		if ( ! is_array( $amendment['decision'] ) ) {
			return new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'approve_amendment', array( 'object_id' => $amendment['amendment_id'] ), array( 'purpose' => $context['purpose'] ?? 'change_control' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );
		$pre = SPF_Audit::record_required( 'record_amendment_precommit', 'foundation_amendment', $id, 'authorized', array( 'purpose'=>$context['purpose']??'change_control' ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$ok = $wpdb->insert( SPF_Installer::table( 'amendments' ), array( 'amendment_id'=>$id,'effective_at'=>gmdate('Y-m-d H:i:s',strtotime($amendment['effective_at'])),'supersedes'=>substr(sanitize_text_field($amendment['supersedes']??''),0,191),'decision_json'=>wp_json_encode(self::sanitize_evidence($amendment['decision'])),'approver_ref'=>substr(sanitize_text_field($amendment['approver_ref']),0,191),'status'=>'approved','record_version'=>1,'created_at'=>SPF_Runtime::now_mysql() ) );
			if ( false === $ok ) {
				throw new RuntimeException( 'Amendment could not be stored.' );
			}
			$audit = SPF_Audit::record_required( 'record_amendment', 'foundation_amendment', $id, 'success', array( 'purpose'=>$context['purpose']??'change_control' ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationAmendmentApproved.v1', 'foundation_amendment', $id, array( 'effective_at'=>$amendment['effective_at'] ), 1, 'amendment-'.$id );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return true;
		} catch ( Throwable $e ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_amendment_write_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function set_flag( array $flag, array $context = array() ) {
		global $wpdb;
		foreach ( array( 'flag_key','owner_module','environment','enabled','reason' ) as $field ) {
			if ( ! array_key_exists( $field, $flag ) ) {
				return new WP_Error( 'spf_invalid_flag', 'Missing '.$field );
			}
		}
		if ( ! is_bool( $flag['enabled'] ) ) {
			return new WP_Error( 'spf_invalid_flag_enabled', __( 'Feature flag enabled must be a boolean.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$expires_at = null;
		if ( array_key_exists( 'expires_at', $flag ) && null !== $flag['expires_at'] && '' !== trim( (string) $flag['expires_at'] ) ) {
			$expires_ts = strtotime( (string) $flag['expires_at'] );
			if ( false === $expires_ts || $expires_ts <= time() ) {
				return new WP_Error( 'spf_invalid_flag_expiry', __( 'Feature flag expiry must be a valid future time.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
			}
			$expires_at = gmdate( 'Y-m-d H:i:s', $expires_ts );
		}
		$owner = sanitize_key( $flag['owner_module'] );
		if ( ! SPF_Registry::get_module( $owner ) ) {
			return new WP_Error( 'spf_flag_owner_missing', __( 'Flag owner missing.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'set_flag', array( 'owner_module'=>$owner,'flag_key'=>$flag['flag_key'] ), array( 'purpose'=>$context['purpose']??'feature_flag' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$table = SPF_Installer::table( 'flags' );
		$key = sanitize_key( $flag['flag_key'] );
		$env = sanitize_key( $flag['environment'] );
		if ( ! in_array( $env, array( 'all','local','development','staging','production' ), true ) || ! $key ) {
			return new WP_Error( 'spf_invalid_flag', __( 'Flag environment or key is invalid.', 'sabri-platform-foundation' ) );
		}
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_module=%s AND flag_key=%s AND environment=%s", $owner, $key, $env ), ARRAY_A );
		if ( $old && ! isset( $context['expected_version'] ) ) {
			return new WP_Error( 'spf_expected_version_required', __( 'Updating a flag requires its expected record version.', 'sabri-platform-foundation' ), array( 'status' => 428 ) );
		}
		if ( $old && (int) $context['expected_version'] !== (int) $old['record_version'] ) {
			return new WP_Error( 'spf_stale_record', __( 'The feature flag changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$activation_evidence_hash = '';
		if ( true === $flag['enabled'] ) {
			$readiness = SPF_Dependency_Resolver::readiness( $owner );
			if ( empty( $readiness['ready'] ) ) {
				return new WP_Error( 'spf_feature_dependency_not_ready', __( 'Feature activation is blocked because the owner dependency graph is not ready.', 'sabri-platform-foundation' ), array( 'status'=>412, 'readiness_code'=>$readiness['code'] ?? 'not_ready' ) );
			}
			$activation = SPF_Runtime::verify_evidence( 'spf_verify_feature_activation_evidence', array( 'owner_module'=>$owner, 'flag_key'=>$key, 'environment'=>$env, 'readiness_hash'=>SPF_Runtime::hash($readiness) ), array( 'migration_status','health_status','rollback_evidence','gate_evidence','verifier','expires_at' ) );
			if ( is_wp_error( $activation ) ) {
				return $activation;
			}
			if ( ! in_array( sanitize_key( $activation['migration_status'] ), array( 'ready','not_required' ), true ) || 'pass' !== sanitize_key( $activation['health_status'] ) ) {
				return new WP_Error( 'spf_feature_activation_evidence_failed', __( 'Feature activation evidence does not prove migration and health readiness.', 'sabri-platform-foundation' ), array( 'status'=>412 ) );
			}
			$activation_evidence_hash = $activation['evidence_hash'];
		}
		$pre = SPF_Audit::record_required( 'set_flag_precommit', 'foundation_flag', $owner.':'.$key.':'.$env, 'authorized', array( 'purpose'=>$context['purpose']??'feature_flag','activation_evidence_hash'=>$activation_evidence_hash ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$data = array( 'owner_module'=>$owner,'flag_key'=>$key,'environment'=>$env,'enabled'=>$flag['enabled']?1:0,'expires_at'=>$expires_at,'reason'=>substr(sanitize_text_field($flag['reason']),0,500),'updated_at'=>SPF_Runtime::now_mysql() );
			if ( $old ) {
				$data['record_version'] = (int)$old['record_version']+1;
				$ok = $wpdb->update( $table, $data, array( 'id'=>(int)$old['id'],'record_version'=>(int)$old['record_version'] ) );
				if ( 1 !== $ok ) {
					throw new RuntimeException( 'Flag update conflict.' );
				}
			} else {
				$data += array( 'record_version'=>1,'created_at'=>SPF_Runtime::now_mysql() );
				if ( false === $wpdb->insert( $table, $data ) ) {
					throw new RuntimeException( 'Flag insert failed.' );
				}
			}
			$audit = SPF_Audit::record_required( 'set_flag', 'foundation_flag', $owner.':'.$key.':'.$env, 'success', array( 'purpose'=>$context['purpose']??'feature_flag','enabled'=>$flag['enabled']?1:0,'activation_evidence_hash'=>$activation_evidence_hash ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FeatureFlagChanged.v1', 'foundation_flag', $owner.':'.$key.':'.$env, array( 'owner_module'=>$owner,'flag_key'=>$key,'environment'=>$env,'enabled'=>$flag['enabled']?1:0,'expires_at'=>$data['expires_at'] ), 1, 'flag-'.$owner.'-'.$key.'-'.$env.'-'.($data['record_version']??1) );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'owner_module'=>$owner,'flag_key'=>$key,'environment'=>$env,'enabled'=>$flag['enabled'],'record_version'=>$old?(int)$old['record_version']+1:1 );
		} catch ( Throwable $e ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_flag_write_failed', $e->getMessage(), array( 'status' => 409 ) );
		}
	}

	public static function is_flag_enabled( $owner_module, $flag_key, $environment = '' ) {
		global $wpdb;
		$owner_module = sanitize_key( $owner_module );
		$flag_key = sanitize_key( $flag_key );
		$environment = $environment ? sanitize_key( $environment ) : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );
		$table = SPF_Installer::table( 'flags' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_module=%s AND flag_key=%s AND environment IN (%s,'all') ORDER BY (environment=%s) DESC,id DESC LIMIT 1", $owner_module, $flag_key, $environment, $environment ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) <= time() ) {
			return false;
		}
		return 1 === (int) $row['enabled'];
	}

	public static function reconcile_expired_flags() {
		global $wpdb;
		$table = SPF_Installer::table( 'flags' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled=1 AND expires_at IS NOT NULL AND expires_at<=%s LIMIT 100", SPF_Runtime::now_mysql() ), ARRAY_A );
		foreach ( $rows as $row ) {
			$wpdb->update( $table, array( 'enabled'=>0,'record_version'=>(int)$row['record_version']+1,'updated_at'=>SPF_Runtime::now_mysql(),'reason'=>substr($row['reason'].' [expired]',0,500) ), array( 'id'=>(int)$row['id'],'record_version'=>(int)$row['record_version'] ) );
			SPF_Event_Bus::publish( 'FeatureFlagExpired.v1', 'foundation_flag', $row['owner_module'].':'.$row['flag_key'].':'.$row['environment'], array( 'owner_module'=>$row['owner_module'],'flag_key'=>$row['flag_key'],'environment'=>$row['environment'] ), 1, 'flag-expired-'.$row['id'].'-'.$row['record_version'] );
		}
	}

	public static function get_release( $release_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM '.SPF_Installer::table('releases').' WHERE release_id=%s', $release_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['evidence'] = json_decode( $row['evidence_json'], true );
		unset( $row['evidence_json'] );
		$states = $wpdb->get_results( $wpdb->prepare( 'SELECT sequence_no,status,evidence_json,evidence_hash,actor_id,created_at FROM '.SPF_Installer::table('release_states').' WHERE release_id=%s ORDER BY sequence_no', $release_id ), ARRAY_A );
		foreach ( $states as &$state ) {
			$state['evidence'] = json_decode( $state['evidence_json'], true );
			unset( $state['evidence_json'] );
		}
		$row['states'] = $states;
		return $row;
	}

	public static function list_releases( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT release_id,software_version,commit_sha,package_name,checksum_sha256,schema_version,status,record_version,approved_at,deployed_at,created_at,updated_at FROM '.SPF_Installer::table('releases').' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	public static function list_amendments( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.SPF_Installer::table('amendments').' ORDER BY effective_at DESC LIMIT %d', $limit ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['decision'] = json_decode( $row['decision_json'], true );
			unset( $row['decision_json'] );
		}
		return $rows;
	}

	public static function release_transition_allowed( $from, $to ) {
		if ( $from === $to ) {
			return false;
		}
		$map = array(
			'planned'=>array('built','superseded'),
			'built'=>array('verified','superseded'),
			'verified'=>array('staged','superseded'),
			'staged'=>array('approved','rolled_back','superseded'),
			'approved'=>array('deployed','rolled_back','superseded'),
			'deployed'=>array('rolled_back','superseded'),
			'rolled_back'=>array('superseded'),
			'superseded'=>array(),
		);
		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	public static function validate_evidence_for_state( $state, array $evidence ) {
		$requirements = array(
			'planned' => array( 'scope_reference','owner' ),
			'built' => array( 'source_commit_verified','package_checksum_verified','reproducible_build','source_manifest','sbom' ),
			'verified' => array( 'ci_run','test_summary','zero_unresolved_critical_high','security_review' ),
			'staged' => array( 'staging_environment','fresh_install','upgrade_test','cross_file_contracts','backup_restore_test','rollback_rehearsal','browser_accessibility_rtl','founder_acceptance_pending' ),
			'approved' => array( 'founder_approval_id','approved_scope','staging_evidence_hash','rollback_window' ),
			'deployed' => array( 'production_change_id','deployed_package_checksum','smoke_test','monitoring_window','rollback_ready' ),
			'rolled_back' => array( 'reason','rollback_execution','post_rollback_verification' ),
			'superseded' => array( 'superseded_by','reason' ),
		);
		foreach ( $requirements[ $state ] ?? array() as $field ) {
			if ( ! array_key_exists( $field, $evidence ) || '' === (string) $evidence[ $field ] ) {
				return new WP_Error( 'spf_release_evidence_incomplete', sprintf( __( 'Release state %1$s requires evidence field %2$s.', 'sabri-platform-foundation' ), $state, $field ), array( 'status' => 422 ) );
			}
		}
		foreach ( array( 'reproducible_build','source_commit_verified','package_checksum_verified','zero_unresolved_critical_high','fresh_install','upgrade_test','backup_restore_test','rollback_rehearsal','smoke_test','rollback_ready' ) as $boolean_field ) {
			if ( array_key_exists( $boolean_field, $evidence ) && true !== $evidence[ $boolean_field ] ) {
				return new WP_Error( 'spf_release_evidence_failed', sprintf( __( 'Release evidence field %s must be true.', 'sabri-platform-foundation' ), $boolean_field ), array( 'status' => 422 ) );
			}
		}
		return true;
	}

	private static function prior_evidence_complete( $release_id, $target ) {
		global $wpdb;
		$required = 'approved' === $target ? array( 'planned','built','verified','staged' ) : array( 'planned','built','verified','staged','approved' );
		$states = $wpdb->get_col( $wpdb->prepare( 'SELECT status FROM '.SPF_Installer::table('release_states').' WHERE release_id=%s ORDER BY sequence_no', $release_id ) );
		foreach ( $required as $state ) {
			if ( ! in_array( $state, $states, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function sanitize_evidence( array $input ) {
		$out = array();
		foreach ( $input as $k => $v ) {
			$key = substr( sanitize_key( $k ), 0, 128 );
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|patient|message_body|payment|identity_document/i', (string) $k ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $v ) ) {
				$out[ $key ] = self::sanitize_evidence( $v );
			} elseif ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
				$out[ $key ] = $v;
			} elseif ( is_scalar( $v ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $v ), 0, 1000 );
			} else {
				$out[ $key ] = '[unsupported]';
			}
		}
		return SPF_Runtime::canonicalize( $out );
	}
}

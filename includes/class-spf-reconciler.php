<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Reconciler {
	public static function plan() {
		$legacy_map = get_option( 'spf_page_map', array() );
		$legacy_founder = get_option( 'spf_founder_user_id', null );
		$actions = array();
		$blockers = array();
		if ( is_array( $legacy_map ) && $legacy_map ) {
			foreach ( $legacy_map as $key => $page_id ) {
				$page_id = absint( $page_id );
				$owned = $page_id && '1' === get_post_meta( $page_id, '_spf_managed_page', true );
				$owner_plan = apply_filters(
					'spf_owner_reconciliation_plan',
					null,
					array(
						'legacy_key' => sanitize_key( $key ),
						'page_id' => $page_id,
						'owned_by_file01_legacy' => $owned,
						'target_owners' => array( 'file-20','file-21' ),
					)
				);
				$owner_module = is_array( $owner_plan ) ? sanitize_key( $owner_plan['owner_module'] ?? '' ) : '';
				$command_version = is_array( $owner_plan ) ? sanitize_text_field( $owner_plan['command_version'] ?? '' ) : '';
				$owner_plan_json = is_array( $owner_plan ) ? SPF_Runtime::canonical_json( $owner_plan ) : '';
				if ( ! is_array( $owner_plan ) || ! array_key_exists( 'accepted', $owner_plan ) || true !== $owner_plan['accepted'] || ! in_array( $owner_module, array( 'file-20','file-21' ), true ) || ! SPF_Registry::valid_semver( $command_version ) || false === $owner_plan_json || strlen( $owner_plan_json ) > 32768 ) {
					$blockers[] = array( 'code'=>'owner_reconciliation_adapter_missing_or_invalid','legacy_key'=>sanitize_key($key),'page_id'=>$page_id );
				}
				$actions[] = array(
					'action' => 'reconcile_legacy_mapping',
					'legacy_key' => sanitize_key( $key ),
					'page_id' => $page_id,
					'owned' => $owned,
					'owner_plan' => is_array( $owner_plan ) ? SPF_Runtime::canonicalize( $owner_plan ) : null,
					'local_apply' => $owned ? 'mark_quarantined_after_owner_ack' : 'remove_mapping_after_owner_ack_only',
				);
			}
		}
		if ( null !== $legacy_founder ) {
			$actions[] = array(
				'action' => 'remove_unsafe_founder_option',
				'value_hash' => hash( 'sha256', (string) absint( $legacy_founder ) ),
				'apply' => 'delete_file01_legacy_option_only',
			);
		}
		return array(
			'generated_at' => SPF_Runtime::now_mysql(),
			'actions' => $actions,
			'blockers' => $blockers,
			'counts' => array(
				'create'=>0,
				'update'=>0,
				'reconcile'=>count($actions),
				'quarantine'=>count(array_filter($actions,static fn($a)=>!empty($a['owned']))),
				'delete'=>0,
				'skip'=>0,
			),
			'law' => 'File 20/21 owner commands must acknowledge canonical routes/content before File 01 removes any legacy mapping; no foreign page or companion table is directly mutated.',
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return SPF_Runtime::hash( $plan );
	}

	public static function apply( $confirmation, $expected_hash ) {
		if ( 'APPLY FILE 01 RECONCILIATION' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact reconciliation confirmation was not supplied.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$allowed = SPF_Authorization::require_action( 'run_reconciliation', array( 'object_id'=>'file-01-legacy-cutover' ), array( 'purpose'=>'legacy_cutover' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_reconciliation_plan_changed', __( 'The reconciliation plan changed. Generate and review a new dry run.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
		}
		if ( ! empty( $plan['blockers'] ) ) {
			return new WP_Error( 'spf_reconciliation_blocked', __( 'Owner reconciliation adapters are missing or have not accepted the plan.', 'sabri-platform-foundation' ), array( 'status'=>412, 'blockers'=>$plan['blockers'] ) );
		}
		$lock = SPF_Runtime::acquire_lock( 'reconciliation', 1800, get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$precommit = SPF_Audit::record_required( 'reconciliation_precommit', 'foundation_reconciliation', $hash, 'authorized', array( 'purpose'=>'legacy_cutover' ) );
		if ( is_wp_error( $precommit ) ) {
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return $precommit;
		}
		$snapshot = self::capture_snapshot( $hash, $plan );
		update_option( 'spf_reconciliation_snapshot', $snapshot, false );
		$changed = array();
		$receipts = array();
		try {
			foreach ( $plan['actions'] as $action ) {
				if ( 'reconcile_legacy_mapping' !== $action['action'] ) {
					continue;
				}
				$receipt = apply_filters( 'spf_execute_owner_reconciliation', null, $action, $hash );
				$receipt_json = is_array( $receipt ) ? SPF_Runtime::canonical_json( $receipt ) : '';
				$expected_owner = sanitize_key( $action['owner_plan']['owner_module'] ?? '' );
				$expected_version = sanitize_text_field( $action['owner_plan']['command_version'] ?? '' );
				if ( ! is_array( $receipt ) || ! array_key_exists( 'success', $receipt ) || true !== $receipt['success'] || empty( $receipt['receipt_id'] ) || empty( $receipt['owner_module'] ) || empty( $receipt['rollback_command'] ) || sanitize_key( $receipt['owner_module'] ) !== $expected_owner || sanitize_text_field( $receipt['command_version'] ?? '' ) !== $expected_version || false === $receipt_json || strlen( $receipt_json ) > 32768 || strlen( (string) $receipt['receipt_id'] ) > 191 || strlen( (string) $receipt['rollback_command'] ) > 191 ) {
					throw new RuntimeException( 'A canonical owner did not return a bounded, version-matched reversible reconciliation receipt.' );
				}
				$safe_receipt = array(
					'success'=>true,
					'receipt_id'=>substr(sanitize_text_field($receipt['receipt_id']),0,191),
					'owner_module'=>$expected_owner,
					'command_version'=>$expected_version,
					'rollback_command'=>substr(sanitize_key($receipt['rollback_command']),0,191),
					'state_hash'=>preg_match('/^[a-f0-9]{64}$/i',(string)($receipt['state_hash']??''))?strtolower($receipt['state_hash']):'',
				);
				$receipts[] = SPF_Runtime::canonicalize( $safe_receipt );
				if ( ! empty( $action['owned'] ) && $action['page_id'] ) {
					update_post_meta( $action['page_id'], '_spf_legacy_quarantined', '1' );
					update_post_meta( $action['page_id'], '_spf_legacy_quarantined_at', SPF_Runtime::now_mysql() );
					update_post_meta( $action['page_id'], '_spf_legacy_owner_receipt', $safe_receipt['receipt_id'] );
					if ( '1' !== get_post_meta( $action['page_id'], '_spf_legacy_quarantined', true ) ) {
						throw new RuntimeException( 'A legacy page could not be quarantined after owner acknowledgement.' );
					}
				}
				$changed[] = array( 'legacy_key'=>$action['legacy_key'],'page_id'=>$action['page_id'],'owner_module'=>$safe_receipt['owner_module'],'receipt_id'=>$safe_receipt['receipt_id'] );
			}
			delete_option( 'spf_page_map' );
			delete_option( 'spf_founder_user_id' );
			if ( '__missing__' !== get_option( 'spf_page_map', '__missing__' ) || '__missing__' !== get_option( 'spf_founder_user_id', '__missing__' ) ) {
				throw new RuntimeException( 'Legacy options could not be removed.' );
			}
			$snapshot['owner_receipts'] = $receipts;
			update_option( 'spf_reconciliation_snapshot', $snapshot, false );
			update_option( 'spf_reconciliation_state', array( 'status'=>'applied','plan_hash'=>$hash,'applied_at'=>SPF_Runtime::now_mysql(),'receipt_count'=>count($receipts) ), false );
			$trace = SPF_Audit::record_required( 'run_reconciliation', 'foundation_reconciliation', $hash, 'success', array( 'purpose'=>'legacy_cutover','changed_count'=>count($changed),'receipt_count'=>count($receipts) ) );
			if ( is_wp_error( $trace ) ) {
				throw new RuntimeException( $trace->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationLegacyReconciled.v1', 'foundation_reconciliation', $hash, array( 'changed_count'=>count($changed),'owner_receipts'=>array_map(static fn($r)=>$r['receipt_id'],$receipts) ), 1, 'reconcile-'.$hash );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return array( 'trace_id'=>$trace,'plan_hash'=>$hash,'changed'=>$changed,'owner_receipts'=>$receipts,'status'=>'applied' );
		} catch ( Throwable $error ) {
			$owner_compensation = self::rollback_owner_receipts( $receipts, $hash );
			$local_compensation = self::restore_snapshot( $snapshot );
			$compensated = ! is_wp_error( $owner_compensation ) && ! is_wp_error( $local_compensation );
			update_option( 'spf_reconciliation_state', array( 'status'=>$compensated?'compensated':'compensation_incomplete','plan_hash'=>$hash,'failed_at'=>SPF_Runtime::now_mysql(),'error_code'=>$compensated?'reconciliation_compensated':'reconciliation_compensation_incomplete' ), false );
			SPF_Audit::record( 'reconciliation_compensated', 'foundation_reconciliation', $hash, $compensated?'failed':'compensation_incomplete', array( 'purpose'=>'legacy_cutover','receipt_count'=>count($receipts) ) );
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return new WP_Error( $compensated?'spf_reconciliation_failed':'spf_reconciliation_compensation_incomplete', $compensated ? __( 'Legacy reconciliation failed and verified compensation completed.', 'sabri-platform-foundation' ) : __( 'Legacy reconciliation failed and compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>$compensated?409:500,'error_class'=>get_class($error) ) );
		}
	}

	public static function rollback( $confirmation ) {
		if ( 'ROLL BACK FILE 01 RECONCILIATION' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact rollback confirmation was not supplied.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$allowed = SPF_Authorization::require_action( 'run_reconciliation', array( 'object_id'=>'file-01-legacy-cutover' ), array( 'purpose'=>'legacy_cutover_rollback' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$snapshot = get_option( 'spf_reconciliation_snapshot', array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['created_at'] ) || empty( $snapshot['plan_hash'] ) ) {
			return new WP_Error( 'spf_no_reconciliation_snapshot', __( 'No reconciliation snapshot is available.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
		}
		$lock = SPF_Runtime::acquire_lock( 'reconciliation', 1800, get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$precommit = SPF_Audit::record_required( 'rollback_reconciliation_precommit', 'foundation_reconciliation', $snapshot['plan_hash'], 'authorized', array( 'purpose'=>'legacy_cutover_rollback' ) );
		if ( is_wp_error( $precommit ) ) {
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return $precommit;
		}
		$receipts = is_array( $snapshot['owner_receipts'] ?? null ) ? $snapshot['owner_receipts'] : array();
		$current_state = get_option( 'spf_reconciliation_state', array() );
		$current_status = is_array( $current_state ) ? sanitize_key( $current_state['status'] ?? '' ) : '';
		if ( 'rolled_back' === $current_status && hash_equals( (string)($current_state['plan_hash'] ?? ''), (string)$snapshot['plan_hash'] ) ) {
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return array( 'status'=>'rolled_back','owner_receipts'=>count($receipts),'idempotent_replay'=>true );
		}
		if ( ! in_array( $current_status, array( 'applied','owner_rollback_completed' ), true ) || ! hash_equals( (string)($current_state['plan_hash'] ?? ''), (string)$snapshot['plan_hash'] ) ) {
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return new WP_Error( 'spf_reconciliation_rollback_state_invalid', __( 'Reconciliation rollback is not valid from the current lifecycle state.', 'sabri-platform-foundation' ), array( 'status'=>409, 'state'=>$current_status ) );
		}
		if ( 'applied' === $current_status ) {
			$owner_result = self::rollback_owner_receipts( $receipts, $snapshot['plan_hash'] );
			if ( is_wp_error( $owner_result ) ) {
				SPF_Runtime::release_lock( 'reconciliation', $lock );
				return $owner_result;
			}
			$intermediate = array( 'status'=>'owner_rollback_completed','plan_hash'=>$snapshot['plan_hash'],'owner_rollback_completed_at'=>SPF_Runtime::now_mysql(),'receipt_count'=>count($receipts) );
			update_option( 'spf_reconciliation_state', $intermediate, false );
			if ( SPF_Runtime::hash( get_option( 'spf_reconciliation_state', array() ) ) !== SPF_Runtime::hash( $intermediate ) ) {
				SPF_Runtime::release_lock( 'reconciliation', $lock );
				return new WP_Error( 'spf_reconciliation_rollback_checkpoint_failed', __( 'Owner rollback completed but its durable checkpoint could not be verified.', 'sabri-platform-foundation' ), array( 'status'=>500 ) );
			}
		}
		$local_result = self::restore_snapshot( $snapshot );
		if ( is_wp_error( $local_result ) ) {
			SPF_Runtime::release_lock( 'reconciliation', $lock );
			return $local_result;
		}
		update_option( 'spf_reconciliation_state', array( 'status'=>'rolled_back','plan_hash'=>$snapshot['plan_hash'],'rolled_back_at'=>SPF_Runtime::now_mysql() ), false );
		$trace = SPF_Audit::record_required( 'rollback_reconciliation', 'foundation_reconciliation', $snapshot['plan_hash'], 'success', array( 'purpose'=>'legacy_cutover_rollback','receipt_count'=>count($receipts) ) );
		if ( is_wp_error( $trace ) ) { SPF_Runtime::release_lock( 'reconciliation', $lock ); return $trace; }
		$event = SPF_Event_Bus::publish( 'FoundationLegacyReconciliationRolledBack.v1', 'foundation_reconciliation', $snapshot['plan_hash'], array( 'receipt_count'=>count($receipts) ), 1, 'reconcile-rollback-'.$snapshot['plan_hash'] );
		SPF_Runtime::release_lock( 'reconciliation', $lock );
		return is_wp_error( $event ) ? $event : array( 'trace_id'=>$trace,'status'=>'rolled_back','owner_receipts'=>count($receipts) );
	}

	private static function capture_snapshot( $hash, array $plan ) {
		$snapshot = array(
			'created_at' => SPF_Runtime::now_mysql(),
			'plan_hash' => $hash,
			'spf_page_map' => self::option_state( 'spf_page_map' ),
			'spf_founder_user_id' => self::option_state( 'spf_founder_user_id' ),
			'page_meta' => array(),
			'owner_receipts' => array(),
		);
		foreach ( $plan['actions'] as $action ) {
			if ( 'reconcile_legacy_mapping' !== $action['action'] || empty( $action['page_id'] ) ) {
				continue;
			}
			$page_id = absint( $action['page_id'] );
			foreach ( array( '_spf_legacy_quarantined','_spf_legacy_quarantined_at','_spf_legacy_owner_receipt' ) as $key ) {
				$exists = metadata_exists( 'post', $page_id, $key );
				$snapshot['page_meta'][ $page_id ][ $key ] = array( 'exists'=>$exists,'values'=>$exists?get_post_meta($page_id,$key,false):array() );
			}
		}
		return $snapshot;
	}

	private static function restore_snapshot( array $snapshot ) {
		$failures = array();
		foreach ( array( 'spf_page_map','spf_founder_user_id' ) as $option ) {
			$expected = $snapshot[$option] ?? array( 'exists'=>false );
			self::restore_option_state( $option, $expected );
			if ( SPF_Runtime::hash( self::option_state( $option ) ) !== SPF_Runtime::hash( $expected ) ) { $failures[] = $option; }
		}
		foreach ( $snapshot['page_meta'] ?? array() as $page_id => $keys ) {
			foreach ( $keys as $key => $state ) {
				delete_post_meta( absint( $page_id ), $key );
				if ( ! empty( $state['exists'] ) ) { foreach ( (array)$state['values'] as $value ) { add_post_meta( absint($page_id), $key, $value ); } }
				$exists = metadata_exists( 'post', absint($page_id), $key );
				$actual = array( 'exists'=>$exists, 'values'=>$exists?get_post_meta(absint($page_id),$key,false):array() );
				if ( SPF_Runtime::hash( $actual ) !== SPF_Runtime::hash( $state ) ) { $failures[] = 'post:'.absint($page_id).':'.$key; }
			}
		}
		return empty( $failures ) ? true : new WP_Error( 'spf_reconciliation_restore_incomplete', __( 'Local reconciliation snapshot restoration could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'failures'=>$failures ) );
	}

	private static function rollback_owner_receipts( array $receipts, $plan_hash ) {
		$errors = array();
		foreach ( array_reverse( $receipts ) as $receipt ) {
			$result = apply_filters( 'spf_rollback_owner_reconciliation', null, $receipt, $plan_hash );
			if ( ! is_array( $result ) || ! array_key_exists( 'success', $result ) || true !== $result['success'] ) {
				$errors[] = $receipt['receipt_id'] ?? 'unknown';
			}
		}
		return empty( $errors ) ? true : new WP_Error( 'spf_owner_rollback_failed', __( 'One or more canonical owners could not roll back their reconciliation receipt.', 'sabri-platform-foundation' ), array( 'status'=>500,'receipts'=>$errors ) );
	}

	private static function option_state( $name ) {
		$sentinel = new stdClass();
		$value = get_option( $name, $sentinel );
		return array( 'exists'=>$value!==$sentinel,'value'=>$value!==$sentinel?$value:null );
	}

	private static function restore_option_state( $name, array $state ) {
		if ( ! empty( $state['exists'] ) ) {
			update_option( $name, $state['value'], false );
		} else {
			delete_option( $name );
		}
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * File 01 resilience and simulation lab.
 *
 * Implements digital-twin simulation, bounded File-01-only self-healing,
 * non-production chaos/failure injection and time-travel governance snapshots.
 */
final class SPF_Resilience_Lab {
	const SNAPSHOT_OPTION = 'spf_future_time_travel_snapshots';
	const CHAOS_OPTION = 'spf_future_chaos_log';
	const SELF_HEAL_RECOVERY_OPTION = 'spf_future_self_heal_recovery';

	private const OWNED_OPTIONS = array(
		'spf_activation_state',
		'spf_upgrade_state',
		'spf_reconciliation_state',
		'spf_future_policy_catalog',
		'spf_future_event_schema_registry',
		'spf_future_config_baselines',
		'spf_future_progressive_rollouts',
		'spf_future_platform_metrics',
	);

	public static function digital_twin( array $model, array $scenario = array() ) {
		$nodes = array();
		foreach ( (array) ( $model['modules'] ?? array() ) as $module ) {
			$key = sanitize_key( $module['module_key'] ?? '' );
			if ( ! $key ) {
				continue;
			}
			$nodes[ $key ] = array(
				'state'    => sanitize_key( $module['state'] ?? 'active' ),
				'required' => self::dependency_keys( (array) ( $module['required'] ?? array() ) ),
				'optional' => self::dependency_keys( (array) ( $module['optional'] ?? array() ) ),
			);
		}
		$failed = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $scenario['failed_modules'] ?? array() ) ) ) ) );
		$incompatible = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $scenario['incompatible_modules'] ?? array() ) ) ) ) );
		$states = array();
		foreach ( $nodes as $key => $node ) {
			$states[ $key ] = array( 'status'=>in_array( $key, $failed, true ) ? 'unavailable' : ( in_array( $key, $incompatible, true ) ? 'incompatible' : $node['state'] ), 'blocked_by'=>array() );
		}
		$changed = true;
		$passes = 0;
		while ( $changed && $passes < max( 1, count( $nodes ) ) ) {
			$changed = false;
			$passes++;
			foreach ( $nodes as $key => $node ) {
				if ( in_array( $states[ $key ]['status'], array( 'unavailable','incompatible' ), true ) ) {
					continue;
				}
				$blocked_by = array();
				foreach ( $node['required'] as $dependency ) {
					if ( ! isset( $states[ $dependency ] ) || in_array( $states[ $dependency ]['status'], array( 'blocked','unavailable','incompatible','suspended','retired' ), true ) ) {
						$blocked_by[] = $dependency;
					}
				}
				$new_status = $blocked_by ? 'blocked' : $node['state'];
				if ( $new_status !== $states[ $key ]['status'] || $blocked_by !== $states[ $key ]['blocked_by'] ) {
					$states[ $key ] = array( 'status'=>$new_status, 'blocked_by'=>$blocked_by );
					$changed = true;
				}
			}
		}
		$critical = array_filter( $states, static function ( $state ) { return in_array( $state['status'], array( 'blocked','unavailable','incompatible' ), true ); } );
		return array(
			'simulation_only' => true,
			'model_hash'      => SPF_Runtime::hash( $model ),
			'scenario_hash'   => SPF_Runtime::hash( $scenario ),
			'module_states'   => $states,
			'impact_count'    => count( $critical ),
			'release_safe'    => empty( $critical ),
			'recommendation'  => empty( $critical ) ? 'No simulated dependency blocker detected.' : 'Keep rollout gated until simulated blockers are resolved.',
		);
	}

	public static function self_heal_plan() {
		$actions = array();
		foreach ( self::OWNED_OPTIONS as $option ) {
			$value = get_option( $option, null );
			if ( null !== $value && ! is_array( $value ) ) {
				$actions[] = array( 'action'=>'quarantine_malformed_owned_option', 'option'=>$option, 'current_hash'=>SPF_Runtime::hash( $value ) );
			}
		}
		$flags = get_option( 'spf_feature_flags', array() );
		if ( is_array( $flags ) ) {
			$expired = 0;
			foreach ( $flags as $flag ) {
				if ( is_array( $flag ) && ! empty( $flag['expires_at'] ) && strtotime( (string) $flag['expires_at'] ) <= time() ) {
					$expired++;
				}
			}
			if ( $expired ) {
				$actions[] = array( 'action'=>'reconcile_expired_flags', 'count'=>$expired );
			}
		}
		$metric_log = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
		if ( is_array( $metric_log ) && count( $metric_log ) > 500 ) {
			$actions[] = array( 'action'=>'trim_metric_buffer', 'before'=>count( $metric_log ), 'after'=>500 );
		}
		return array(
			'owner_scope' => 'file-01-only',
			'actions'     => $actions,
			'plan_hash'   => SPF_Runtime::hash( $actions ),
			'dry_run'     => true,
		);
	}

	public static function apply_self_heal( $confirmation, $expected_hash ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'bounded-self-heal' ), array( 'purpose'=>'bounded_self_healing' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::self_heal_plan();
		if ( ! hash_equals( (string) $plan['plan_hash'], (string) $expected_hash ) ) {
			return new WP_Error( 'spf_self_heal_plan_changed', __( 'The self-heal plan changed; review the new dry run first.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
		}
		if ( 'APPLY FILE 01 SELF HEAL' !== trim( (string) $confirmation ) ) {
			return new WP_Error( 'spf_self_heal_confirmation_required', __( 'Typed confirmation is required for bounded self-healing.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$recovery_id = 'heal-' . gmdate( 'YmdHis' ) . '-' . substr( SPF_Runtime::hash( $plan ), 0, 10 );
		$recovery = array( 'id'=>$recovery_id, 'created_at'=>SPF_Runtime::now_mysql(), 'options'=>array() );
		foreach ( $plan['actions'] as $action ) {
			if ( 'quarantine_malformed_owned_option' === $action['action'] && in_array( $action['option'], self::OWNED_OPTIONS, true ) ) {
				$recovery['options'][ $action['option'] ] = get_option( $action['option'], null );
			} elseif ( 'reconcile_expired_flags' === $action['action'] ) {
				$recovery['options']['spf_feature_flags'] = get_option( 'spf_feature_flags', array() );
			} elseif ( 'trim_metric_buffer' === $action['action'] ) {
				$recovery['options'][ SPF_Platform_Engineering::METRIC_OPTION ] = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
			}
		}
		$recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
		$recoveries = is_array( $recoveries ) ? $recoveries : array();
		$recoveries[ $recovery_id ] = $recovery;
		update_option( self::SELF_HEAL_RECOVERY_OPTION, array_slice( $recoveries, -20, null, true ), false );
		$applied = array();
		foreach ( $plan['actions'] as $action ) {
			switch ( $action['action'] ) {
				case 'quarantine_malformed_owned_option':
					$option = $action['option'];
					if ( in_array( $option, self::OWNED_OPTIONS, true ) ) {
						$old = get_option( $option, null );
						update_option( $option . '_quarantine_' . time(), array( 'hash'=>SPF_Runtime::hash( $old ), 'quarantined_at'=>SPF_Runtime::now_mysql() ), false );
						update_option( $option, array(), false );
						$applied[] = $action;
					}
					break;
				case 'reconcile_expired_flags':
					SPF_Governance::reconcile_expired_flags();
					$applied[] = $action;
					break;
				case 'trim_metric_buffer':
					$metrics = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
					if ( is_array( $metrics ) ) {
						update_option( SPF_Platform_Engineering::METRIC_OPTION, array_slice( $metrics, -500 ), false );
						$applied[] = $action;
					}
					break;
			}
		}
		return array( 'applied'=>$applied, 'owner_scope'=>'file-01-only', 'companion_data_modified'=>false, 'recovery_id'=>$recovery_id );
	}

	public static function rollback_self_heal( $recovery_id, $confirmation ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'bounded-self-heal-rollback' ), array( 'purpose'=>'bounded_self_healing_rollback' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( 'ROLL BACK FILE 01 SELF HEAL' !== trim( (string) $confirmation ) ) {
			return new WP_Error( 'spf_self_heal_rollback_confirmation_required', __( 'Typed confirmation is required to roll back self-healing.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$recovery_id = sanitize_text_field( $recovery_id );
		$recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
		if ( ! is_array( $recoveries ) || empty( $recoveries[ $recovery_id ]['options'] ) ) {
			return new WP_Error( 'spf_self_heal_recovery_missing', __( 'The requested File 01 self-heal recovery snapshot was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
		}
		$restored = array();
		foreach ( (array) $recoveries[ $recovery_id ]['options'] as $option => $value ) {
			if ( 'spf_feature_flags' === $option || in_array( $option, self::OWNED_OPTIONS, true ) ) {
				update_option( $option, $value, false );
				$restored[] = $option;
			}
		}
		return array( 'rolled_back'=>true, 'recovery_id'=>$recovery_id, 'restored_options'=>$restored, 'companion_data_modified'=>false );
	}

	public static function chaos_scenarios() {
		return array(
			'dependency_timeout' => array( 'target'=>'contract-provider', 'effect'=>'timeout', 'safe_default'=>'degraded/fail-closed' ),
			'malformed_contract' => array( 'target'=>'contract-registry', 'effect'=>'invalid-schema', 'safe_default'=>'reject' ),
			'duplicate_event'    => array( 'target'=>'event-consumer', 'effect'=>'duplicate-delivery', 'safe_default'=>'deduplicate' ),
			'queue_backlog'      => array( 'target'=>'background-work', 'effect'=>'high-lag', 'safe_default'=>'alert-and-backoff' ),
			'stale_cache'        => array( 'target'=>'projection-cache', 'effect'=>'stale-read', 'safe_default'=>'invalidate-or-mark-stale' ),
			'clock_skew'         => array( 'target'=>'time-bound-evidence', 'effect'=>'expiry-skew', 'safe_default'=>'reject-expired-evidence' ),
			'database_interrupt' => array( 'target'=>'transaction', 'effect'=>'partial-failure', 'safe_default'=>'rollback' ),
		);
	}

	public static function run_chaos( $scenario, array $context = array() ) {
		$scenario = sanitize_key( $scenario );
		$catalog = self::chaos_scenarios();
		if ( ! isset( $catalog[ $scenario ] ) ) {
			return new WP_Error( 'spf_chaos_scenario_invalid', __( 'Unknown File 01 chaos scenario.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'unknown' );
		$enabled = defined( 'SPF_CHAOS_MODE' ) && SPF_CHAOS_MODE && 'production' !== $environment;
		$result = array(
			'scenario'        => $scenario,
			'definition'      => $catalog[ $scenario ],
			'environment'     => sanitize_key( $environment ),
			'injection_enabled'=> $enabled,
			'injected'        => false,
			'simulation_only' => ! $enabled,
		);
		if ( $enabled ) {
			do_action( 'spf_chaos_inject_' . $scenario, SPF_Runtime::canonicalize( $context ) );
			$result['injected'] = true;
			$result['simulation_only'] = false;
		}
		if ( function_exists( 'get_option' ) ) {
			$log = get_option( self::CHAOS_OPTION, array() );
			$log = is_array( $log ) ? $log : array();
			$log[] = array( 'scenario'=>$scenario, 'environment'=>$result['environment'], 'injected'=>$result['injected'], 'time'=>SPF_Runtime::now_mysql() );
			update_option( self::CHAOS_OPTION, array_slice( $log, -100 ), false );
		}
		return $result;
	}

	public static function capture_snapshot( $label = '' ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'time-travel-snapshot' ), array( 'purpose'=>'time_travel_snapshot' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$data = array(
			'policy_catalog' => get_option( SPF_Governance_Control_Plane::POLICY_OPTION, array() ),
			'event_schemas'  => get_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, array() ),
			'config_baselines'=> get_option( SPF_Platform_Engineering::CONFIG_BASELINE_OPTION, array() ),
			'feature_flags'  => get_option( 'spf_feature_flags', array() ),
			'activation_state'=> get_option( 'spf_activation_state', array() ),
			'upgrade_state'  => get_option( 'spf_upgrade_state', array() ),
		);
		$id = 'snap-' . gmdate( 'YmdHis' ) . '-' . substr( SPF_Runtime::hash( $data ), 0, 12 );
		$snapshot = array(
			'id'         => $id,
			'label'      => substr( sanitize_text_field( $label ), 0, 120 ),
			'created_at' => SPF_Runtime::now_mysql(),
			'data'       => SPF_Runtime::canonicalize( $data ),
			'data_hash'  => SPF_Runtime::hash( $data ),
		);
		$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
		$snapshots = is_array( $snapshots ) ? $snapshots : array();
		$snapshots[ $id ] = $snapshot;
		$snapshots = array_slice( $snapshots, -50, null, true );
		update_option( self::SNAPSHOT_OPTION, $snapshots, false );
		return array( 'id'=>$id, 'label'=>$snapshot['label'], 'created_at'=>$snapshot['created_at'], 'data_hash'=>$snapshot['data_hash'] );
	}

	public static function list_snapshots() {
		$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $snapshots ) ) {
			return array();
		}
		$out = array();
		foreach ( $snapshots as $snapshot ) {
			if ( is_array( $snapshot ) ) {
				$out[] = array(
					'id'         => sanitize_text_field( $snapshot['id'] ?? '' ),
					'label'      => sanitize_text_field( $snapshot['label'] ?? '' ),
					'created_at' => sanitize_text_field( $snapshot['created_at'] ?? '' ),
					'data_hash'  => sanitize_text_field( $snapshot['data_hash'] ?? '' ),
				);
			}
		}
		return array_reverse( $out );
	}

	public static function diff_snapshot( $snapshot_id, array $current = array() ) {
		$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
		$snapshot_id = sanitize_text_field( $snapshot_id );
		if ( ! is_array( $snapshots ) || empty( $snapshots[ $snapshot_id ]['data'] ) ) {
			return new WP_Error( 'spf_snapshot_missing', __( 'The requested governance snapshot was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
		}
		$before = (array) $snapshots[ $snapshot_id ]['data'];
		$after = $current ?: array(
			'policy_catalog' => get_option( SPF_Governance_Control_Plane::POLICY_OPTION, array() ),
			'event_schemas'  => get_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, array() ),
			'config_baselines'=> get_option( SPF_Platform_Engineering::CONFIG_BASELINE_OPTION, array() ),
			'feature_flags'  => get_option( 'spf_feature_flags', array() ),
			'activation_state'=> get_option( 'spf_activation_state', array() ),
			'upgrade_state'  => get_option( 'spf_upgrade_state', array() ),
		);
		$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		$changes = array();
		foreach ( $keys as $key ) {
			if ( SPF_Runtime::hash( $before[ $key ] ?? null ) !== SPF_Runtime::hash( $after[ $key ] ?? null ) ) {
				$changes[] = array( 'section'=>$key, 'before_hash'=>SPF_Runtime::hash( $before[ $key ] ?? null ), 'after_hash'=>SPF_Runtime::hash( $after[ $key ] ?? null ) );
			}
		}
		return array( 'snapshot_id'=>$snapshot_id, 'changed'=>! empty( $changes ), 'changes'=>$changes );
	}

	public static function restore_snapshot_plan( $snapshot_id ) {
		$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
		$snapshot_id = sanitize_text_field( $snapshot_id );
		if ( ! is_array( $snapshots ) || empty( $snapshots[ $snapshot_id ]['data'] ) ) {
			return new WP_Error( 'spf_snapshot_missing', __( 'The requested governance snapshot was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
		}
		$target = (array) $snapshots[ $snapshot_id ]['data'];
		$mapping = self::restorable_snapshot_mapping();
		$changes = array();
		foreach ( $mapping as $section => $option ) {
			$before = get_option( $option, array() );
			$after = $target[ $section ] ?? array();
			if ( SPF_Runtime::hash( $before ) !== SPF_Runtime::hash( $after ) ) {
				$changes[] = array( 'section'=>$section, 'option'=>$option, 'current_hash'=>SPF_Runtime::hash( $before ), 'snapshot_hash'=>SPF_Runtime::hash( $after ) );
			}
		}
		return array(
			'snapshot_id'       => $snapshot_id,
			'owner_scope'       => 'file-01-governance-config-only',
			'changes'           => $changes,
			'plan_hash'         => SPF_Runtime::hash( array( 'snapshot_id'=>$snapshot_id, 'changes'=>$changes ) ),
			'excluded_sections' => array( 'activation_state', 'upgrade_state' ),
			'dry_run'           => true,
		);
	}

	public static function restore_snapshot( $snapshot_id, $confirmation, $expected_hash ) {
		$snapshot_id = sanitize_text_field( $snapshot_id );
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'time-travel-restore:' . $snapshot_id ), array( 'purpose'=>'time_travel_restore' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::restore_snapshot_plan( $snapshot_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( (string) $plan['plan_hash'], (string) $expected_hash ) ) {
			return new WP_Error( 'spf_snapshot_restore_plan_changed', __( 'The snapshot restore plan changed; review the new dry run first.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
		}
		if ( 'RESTORE FILE 01 GOVERNANCE SNAPSHOT' !== trim( (string) $confirmation ) ) {
			return new WP_Error( 'spf_snapshot_restore_confirmation_required', __( 'Typed confirmation is required for governance snapshot restore.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
		$target = (array) $snapshots[ $snapshot_id ]['data'];
		$mapping = self::restorable_snapshot_mapping();
		$current = array();
		foreach ( $mapping as $section => $option ) {
			$current[ $option ] = get_option( $option, array() );
		}
		$pre = SPF_Audit::record_required( 'time_travel_restore_precommit', 'foundation_snapshot', $snapshot_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'] ) );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		try {
			foreach ( $mapping as $section => $option ) {
				update_option( $option, SPF_Runtime::canonicalize( $target[ $section ] ?? array() ), false );
			}
			$audit = SPF_Audit::record_required( 'time_travel_restore', 'foundation_snapshot', $snapshot_id, 'success', array( 'plan_hash'=>$plan['plan_hash'], 'sections'=>array_keys( $mapping ) ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
		} catch ( Throwable $error ) {
			foreach ( $current as $option => $value ) {
				update_option( $option, $value, false );
			}
			return new WP_Error( 'spf_snapshot_restore_failed', $error->getMessage(), array( 'status'=>409 ) );
		}
		return array( 'restored'=>true, 'snapshot_id'=>$snapshot_id, 'sections'=>array_keys( $mapping ), 'companion_data_modified'=>false, 'excluded_sections'=>$plan['excluded_sections'] );
	}

	public static function periodic_tick() {
		SPF_Governance::reconcile_expired_flags();
		$plan = self::self_heal_plan();
		SPF_Platform_Engineering::record_metric( 'future_foundation_self_heal_actions', count( $plan['actions'] ), array( 'module'=>'file-01' ) );
		do_action( 'spf_future_foundation_health_tick', $plan );
	}

	private static function dependency_keys( array $dependencies ) {
		$out = array();
		foreach ( $dependencies as $dependency ) {
			$key = sanitize_key( is_array( $dependency ) ? ( $dependency['module_key'] ?? '' ) : $dependency );
			if ( $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function restorable_snapshot_mapping() {
		return array(
			'policy_catalog'   => SPF_Governance_Control_Plane::POLICY_OPTION,
			'event_schemas'    => SPF_Platform_Engineering::EVENT_SCHEMA_OPTION,
			'config_baselines' => SPF_Platform_Engineering::CONFIG_BASELINE_OPTION,
			'feature_flags'    => 'spf_feature_flags',
		);
	}

}

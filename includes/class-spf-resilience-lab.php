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
		$critical = array_filter( $states, static function ( $state ) { return in_array( $state['status'], array( 'blocked','unavailable','incompatible','suspended','retired' ), true ); } );
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
        $blockers = array();
        foreach ( self::OWNED_OPTIONS as $option ) {
            $value = get_option( $option, null );
            if ( null !== $value && ! is_array( $value ) ) {
                $actions[] = array( 'action'=>'quarantine_malformed_owned_option', 'option'=>$option, 'current_hash'=>SPF_Runtime::hash( $value ) );
            }
        }
        $expired_flags = self::expired_flag_rows();
        if ( is_wp_error( $expired_flags ) ) {
            $blockers[] = array( 'code'=>$expired_flags->get_error_code(), 'message'=>$expired_flags->get_error_message() );
        } elseif ( $expired_flags ) {
            $actions[] = array( 'action'=>'reconcile_expired_flags', 'count'=>count( $expired_flags ), 'flag_snapshot'=>self::flag_fingerprints( $expired_flags ) );
        }
        $metric_log = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
        if ( is_array( $metric_log ) && count( $metric_log ) > 500 ) {
            $actions[] = array( 'action'=>'trim_metric_buffer', 'before'=>count( $metric_log ), 'after'=>500 );
        }
        $basis = array( 'actions'=>$actions, 'blockers'=>$blockers );
        return array(
            'owner_scope' => 'file-01-only',
            'actions'     => $actions,
            'blockers'    => $blockers,
            'plan_hash'   => SPF_Runtime::hash( $basis ),
            'dry_run'     => true,
        );
    }

    public static function apply_self_heal( $confirmation, $expected_hash ) {
        $allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'bounded-self-heal' ), array( 'purpose'=>'bounded_self_healing' ) );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( 'APPLY FILE 01 SELF HEAL' !== trim( (string) $confirmation ) ) {
            return new WP_Error( 'spf_self_heal_confirmation_required', __( 'Typed confirmation is required for bounded self-healing.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
        }
        $lock_name = 'future-self-heal';
        $lock = SPF_Runtime::acquire_lock( $lock_name, 180 );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $plan = self::self_heal_plan();
            if ( ! hash_equals( (string) $plan['plan_hash'], (string) $expected_hash ) ) {
                return new WP_Error( 'spf_self_heal_plan_changed', __( 'The self-heal plan changed; review the new dry run first.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
            }
            if ( ! empty( $plan['blockers'] ) ) {
                return new WP_Error( 'spf_self_heal_plan_blocked', __( 'The bounded self-heal plan cannot run while canonical File 01 state cannot be read safely.', 'sabri-platform-foundation' ), array( 'status'=>503, 'blockers'=>$plan['blockers'] ) );
            }
            $recovery_id = 'heal-' . gmdate( 'YmdHis' ) . '-' . substr( SPF_Runtime::hash( array( $plan, wp_generate_uuid4() ) ), 0, 10 );
            $before_options = array();
            $before_flags = array();
            foreach ( $plan['actions'] as $action ) {
                if ( 'quarantine_malformed_owned_option' === $action['action'] && in_array( $action['option'], self::OWNED_OPTIONS, true ) ) {
                    $before_options[ $action['option'] ] = get_option( $action['option'], null );
                } elseif ( 'reconcile_expired_flags' === $action['action'] ) {
                    $current_flags = self::expired_flag_rows();
                    if ( is_wp_error( $current_flags ) || ! hash_equals( SPF_Runtime::hash( (array) ( $action['flag_snapshot'] ?? array() ) ), SPF_Runtime::hash( self::flag_fingerprints( is_wp_error( $current_flags ) ? array() : $current_flags ) ) ) ) {
                        return new WP_Error( 'spf_self_heal_flag_plan_changed', __( 'Expired File 01 feature-flag state changed after the dry run; generate a new self-heal plan.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
                    }
                    foreach ( $current_flags as $row ) { $before_flags[ (int) $row['id'] ] = $row; }
                } elseif ( 'trim_metric_buffer' === $action['action'] ) {
                    $before_options[ SPF_Platform_Engineering::METRIC_OPTION ] = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
                }
            }
            $existing_recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
            $existing_recoveries = is_array( $existing_recoveries ) ? $existing_recoveries : array();
            if ( count( $existing_recoveries ) >= 20 ) {
                return new WP_Error( 'spf_self_heal_recovery_capacity_full', __( 'Self-heal recovery capacity is full; reconcile or retire an older recovery first.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
            }
            $pre = SPF_Audit::record_required( 'self_heal_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'], 'option_count'=>count( $before_options ), 'flag_count'=>count( $before_flags ) ) );
            if ( is_wp_error( $pre ) ) { return $pre; }
            $applied = array();
            try {
                foreach ( $plan['actions'] as $action ) {
                    if ( 'quarantine_malformed_owned_option' === $action['action'] ) {
                        $option = $action['option'];
                        if ( in_array( $option, self::OWNED_OPTIONS, true ) ) {
                            update_option( $option, array(), false );
                            if ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( array() ) ) { throw new RuntimeException( 'self_heal_option_write_failed:' . $option ); }
                            $applied[] = $action;
                        }
                    } elseif ( 'trim_metric_buffer' === $action['action'] ) {
                        $metrics = get_option( SPF_Platform_Engineering::METRIC_OPTION, array() );
                        if ( is_array( $metrics ) ) {
                            $trimmed = array_slice( $metrics, -500 );
                            update_option( SPF_Platform_Engineering::METRIC_OPTION, $trimmed, false );
                            if ( SPF_Runtime::hash( get_option( SPF_Platform_Engineering::METRIC_OPTION, array() ) ) !== SPF_Runtime::hash( $trimmed ) ) { throw new RuntimeException( 'self_heal_metric_write_failed' ); }
                            $applied[] = $action;
                        }
                    } elseif ( 'reconcile_expired_flags' === $action['action'] ) {
                        $flag_result = SPF_Governance::reconcile_expired_flags( (array) ( $action['flag_snapshot'] ?? array() ) );
                        if ( is_wp_error( $flag_result ) || (int) ( $flag_result['expired'] ?? -1 ) !== count( (array) ( $action['flag_snapshot'] ?? array() ) ) || ! empty( $flag_result['conflict'] ) || ! empty( $flag_result['failed'] ) || ! empty( $flag_result['event_failed'] ) || ! empty( $flag_result['audit_failed'] ) ) {
                            throw new RuntimeException( is_wp_error( $flag_result ) ? $flag_result->get_error_message() : 'self_heal_flag_reconciliation_incomplete' );
                        }
                        $applied[] = $action;
                    }
                }
                $post_hashes = array();
                foreach ( $before_options as $option => $_value ) { $post_hashes[ $option ] = SPF_Runtime::hash( get_option( $option, null ) ); }
                $flag_post_hashes = array();
                foreach ( $before_flags as $flag_id => $_row ) {
                    $current_row = self::flag_row_by_id( $flag_id );
                    if ( ! is_array( $current_row ) ) { throw new RuntimeException( 'self_heal_flag_post_state_missing:' . $flag_id ); }
                    $flag_post_hashes[ $flag_id ] = SPF_Runtime::hash( $current_row );
                }
                $recovery = array(
                    'id'=>$recovery_id, 'created_at'=>SPF_Runtime::now_mysql(), 'plan_hash'=>$plan['plan_hash'],
                    'options'=>$before_options, 'flags'=>$before_flags, 'post_hashes'=>$post_hashes, 'flag_post_hashes'=>$flag_post_hashes, 'rolled_back'=>false,
                );
                $recoveries = $existing_recoveries;
                $recoveries[ $recovery_id ] = $recovery;
                update_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries, false );
                $persisted = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
                if ( empty( $persisted[ $recovery_id ] ) || SPF_Runtime::hash( $persisted[ $recovery_id ] ) !== SPF_Runtime::hash( $recovery ) ) { throw new RuntimeException( 'self_heal_recovery_persistence_failed' ); }
                $audit = SPF_Audit::record_required( 'self_heal_apply', 'foundation_repair', $recovery_id, 'success', array( 'plan_hash'=>$plan['plan_hash'], 'applied_count'=>count( $applied ), 'flag_count'=>count( $before_flags ) ) );
                if ( is_wp_error( $audit ) ) { throw new RuntimeException( $audit->get_error_message() ); }
            } catch ( Throwable $error ) {
                $compensation_failures = array();
                foreach ( $before_options as $option => $value ) {
                    update_option( $option, $value, false );
                    if ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) { $compensation_failures[] = 'option:'.$option; }
                }
                $flag_restore = self::restore_flag_rows( $before_flags );
                if ( is_wp_error( $flag_restore ) ) { $compensation_failures = array_merge( $compensation_failures, (array) ( $flag_restore->get_error_data()['failures'] ?? array( 'flags' ) ) ); }
                SPF_Audit::record( 'self_heal_compensated', 'foundation_repair', $recovery_id, $compensation_failures ? 'compensation_incomplete' : 'failed', array( 'purpose'=>'bounded_self_healing', 'error'=>$error->getMessage() ) );
                if ( $compensation_failures ) { return new WP_Error( 'spf_self_heal_compensation_incomplete', __( 'Self-heal failed and one or more File 01-owned values could not be restored.', 'sabri-platform-foundation' ), array( 'status'=>500, 'failures'=>$compensation_failures ) ); }
                return new WP_Error( 'spf_self_heal_failed', $error->getMessage(), array( 'status'=>409 ) );
            }
            return array( 'applied'=>$applied, 'owner_scope'=>'file-01-only', 'companion_data_modified'=>false, 'recovery_id'=>$recovery_id );
        } finally {
            SPF_Runtime::release_lock( $lock_name, $lock );
        }
    }

    public static function rollback_self_heal( $recovery_id, $confirmation ) {
        $allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'bounded-self-heal-rollback' ), array( 'purpose'=>'bounded_self_healing_rollback' ) );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( 'ROLL BACK FILE 01 SELF HEAL' !== trim( (string) $confirmation ) ) {
            return new WP_Error( 'spf_self_heal_rollback_confirmation_required', __( 'Typed confirmation is required to roll back self-healing.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
        }
        $recovery_id = sanitize_text_field( $recovery_id );
        $lock_name = 'future-self-heal';
        $lock = SPF_Runtime::acquire_lock( $lock_name, 180 );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
            if ( ! is_array( $recoveries ) || empty( $recoveries[ $recovery_id ] ) ) {
                return new WP_Error( 'spf_self_heal_recovery_missing', __( 'The requested File 01 self-heal recovery snapshot was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
            }
            $recovery = (array) $recoveries[ $recovery_id ];
            $options = (array) ( $recovery['options'] ?? array() );
            $flags = (array) ( $recovery['flags'] ?? array() );
            if ( ! $options && ! $flags ) { return new WP_Error( 'spf_self_heal_recovery_empty', __( 'The requested recovery contains no restorable File 01 state.', 'sabri-platform-foundation' ), array( 'status'=>409 ) ); }
            if ( ! empty( $recovery['rolled_back'] ) ) {
                return array( 'rolled_back'=>true, 'recovery_id'=>$recovery_id, 'restored_options'=>array_keys( $options ), 'restored_flags'=>array_map( 'intval', array_keys( $flags ) ), 'companion_data_modified'=>false, 'idempotent_replay'=>true );
            }
            foreach ( (array) ( $recovery['post_hashes'] ?? array() ) as $option => $post_hash ) {
                if ( ! hash_equals( (string) $post_hash, SPF_Runtime::hash( get_option( $option, null ) ) ) ) { return new WP_Error( 'spf_self_heal_recovery_stale', __( 'File 01 state changed after self-healing; a stale recovery snapshot cannot overwrite newer state.', 'sabri-platform-foundation' ), array( 'status'=>409, 'option'=>$option ) ); }
            }
            foreach ( (array) ( $recovery['flag_post_hashes'] ?? array() ) as $flag_id => $post_hash ) {
                $current = self::flag_row_by_id( (int) $flag_id );
                if ( ! is_array( $current ) || ! hash_equals( (string) $post_hash, SPF_Runtime::hash( $current ) ) ) { return new WP_Error( 'spf_self_heal_flag_recovery_stale', __( 'A File 01 feature flag changed after self-healing; stale recovery cannot overwrite newer state.', 'sabri-platform-foundation' ), array( 'status'=>409, 'flag_id'=>(int)$flag_id ) ); }
            }
            $pre = SPF_Audit::record_required( 'self_heal_rollback_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$recovery['plan_hash'] ?? '' ) );
            if ( is_wp_error( $pre ) ) { return $pre; }
            $current_options = array();
            foreach ( $options as $option => $_value ) { if ( in_array( $option, self::OWNED_OPTIONS, true ) || SPF_Platform_Engineering::METRIC_OPTION === $option ) { $current_options[ $option ] = get_option( $option, null ); } }
            $current_flags = array();
            foreach ( $flags as $flag_id => $_row ) { $current = self::flag_row_by_id( (int) $flag_id ); if ( is_array( $current ) ) { $current_flags[ (int) $flag_id ] = $current; } }
            $recoveries_before = $recoveries;
            $restored_options = array();
            try {
                foreach ( $options as $option => $value ) {
                    if ( in_array( $option, self::OWNED_OPTIONS, true ) || SPF_Platform_Engineering::METRIC_OPTION === $option ) {
                        update_option( $option, $value, false );
                        if ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) { throw new RuntimeException( 'self_heal_rollback_write_failed:' . $option ); }
                        $restored_options[] = $option;
                    }
                }
                $flag_restore = self::restore_flag_rows( $flags );
                if ( is_wp_error( $flag_restore ) ) { throw new RuntimeException( $flag_restore->get_error_message() ); }
                $recovery['rolled_back'] = true;
                $recovery['rolled_back_at'] = SPF_Runtime::now_mysql();
                $recoveries[ $recovery_id ] = $recovery;
                update_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries, false );
                $persisted_recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );
                if ( empty( $persisted_recoveries[ $recovery_id ] ) || SPF_Runtime::hash( $persisted_recoveries[ $recovery_id ] ) !== SPF_Runtime::hash( $recovery ) ) { throw new RuntimeException( 'self_heal_rollback_metadata_write_failed' ); }
                $audit = SPF_Audit::record_required( 'self_heal_rollback', 'foundation_repair', $recovery_id, 'success', array( 'restored_option_count'=>count( $restored_options ), 'restored_flag_count'=>count( $flags ) ) );
                if ( is_wp_error( $audit ) ) { throw new RuntimeException( $audit->get_error_message() ); }
            } catch ( Throwable $error ) {
                $failures = array();
                foreach ( $current_options as $option => $value ) { update_option( $option, $value, false ); if ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) { $failures[] = 'option:'.$option; } }
                $flag_compensation = self::restore_flag_rows( $current_flags );
                if ( is_wp_error( $flag_compensation ) ) { $failures = array_merge( $failures, (array) ( $flag_compensation->get_error_data()['failures'] ?? array( 'flags' ) ) ); }
                update_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );
                if ( SPF_Runtime::hash( get_option( self::SELF_HEAL_RECOVERY_OPTION, array() ) ) !== SPF_Runtime::hash( $recoveries_before ) ) { $failures[] = self::SELF_HEAL_RECOVERY_OPTION; }
                if ( $failures ) { return new WP_Error( 'spf_self_heal_rollback_compensation_incomplete', __( 'Self-heal rollback failed and its compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'failures'=>$failures ) ); }
                return new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );
            }
            return array( 'rolled_back'=>true, 'recovery_id'=>$recovery_id, 'restored_options'=>$restored_options, 'restored_flags'=>array_map( 'intval', array_keys( $flags ) ), 'companion_data_modified'=>false );
        } finally {
            SPF_Runtime::release_lock( $lock_name, $lock );
        }
    }

    private static function expired_flag_rows( $limit = 100 ) {
        global $wpdb;
        if ( ! class_exists( 'SPF_Installer' ) || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return array(); }
        $table = SPF_Installer::table( 'flags' );
        if ( ! SPF_Runtime::table_exists( $table ) ) { return new WP_Error( 'spf_self_heal_flags_table_missing', __( 'The canonical File 01 feature-flag table is unavailable.', 'sabri-platform-foundation' ), array( 'status'=>503 ) ); }
        $limit = max( 1, min( 100, absint( $limit ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled=1 AND expires_at IS NOT NULL AND expires_at<=%s ORDER BY id ASC LIMIT %d", SPF_Runtime::now_mysql(), $limit ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) { return new WP_Error( 'spf_self_heal_flags_query_failed', __( 'Canonical File 01 feature flags could not be read safely.', 'sabri-platform-foundation' ), array( 'status'=>503 ) ); }
        return is_array( $rows ) ? $rows : array();
    }

    private static function flag_fingerprints( array $rows ) {
        $out = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['id'] ) ) { continue; }
            $out[] = array( 'id'=>(int)$row['id'], 'record_version'=>(int)$row['record_version'], 'row_hash'=>SPF_Runtime::hash( $row ) );
        }
        return $out;
    }

    private static function flag_row_by_id( $flag_id ) {
        global $wpdb;
        $table = SPF_Installer::table( 'flags' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $flag_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function restore_flag_rows( array $rows ) {
        global $wpdb;
        $table = SPF_Installer::table( 'flags' );
        $failures = array();
        foreach ( $rows as $flag_id => $row ) {
            if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) !== (int) $flag_id || (int) $flag_id < 1 ) { $failures[] = 'flag:'.(int)$flag_id; continue; }
            $replaced = $wpdb->replace( $table, $row );
            $current = self::flag_row_by_id( (int) $flag_id );
            if ( false === $replaced || ! is_array( $current ) || ! hash_equals( SPF_Runtime::hash( $row ), SPF_Runtime::hash( $current ) ) ) { $failures[] = 'flag:'.(int)$flag_id; }
        }
        return $failures ? new WP_Error( 'spf_self_heal_flag_restore_incomplete', __( 'One or more File 01 feature flags could not be restored exactly.', 'sabri-platform-foundation' ), array( 'status'=>500, 'failures'=>$failures ) ) : true;
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
		$allowed = SPF_Authorization::require_action( 'run_reconciliation', array( 'module_key'=>'file-01', 'object_id'=>'chaos:' . $scenario ), array( 'purpose'=>'non_production_chaos_test' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? sanitize_key( WP_ENVIRONMENT_TYPE ) : 'unknown' );
		$safe_environments = array( 'local', 'development', 'staging', 'ci', 'test' );
		$enabled = defined( 'SPF_CHAOS_MODE' ) && true === SPF_CHAOS_MODE && in_array( $environment, $safe_environments, true );
		$result = array(
			'scenario'         => $scenario,
			'definition'       => $catalog[ $scenario ],
			'environment'      => $environment,
			'injection_enabled'=> $enabled,
			'injected'         => false,
			'simulation_only'  => ! $enabled,
			'fail_closed'      => ! in_array( $environment, $safe_environments, true ),
		);
		if ( $enabled ) {
			do_action( 'spf_chaos_inject_' . $scenario, self::sanitize_chaos_context( $context ) );
			$result['injected'] = true;
			$result['simulation_only'] = false;
		}
		if ( function_exists( 'get_option' ) ) {
			$log = get_option( self::CHAOS_OPTION, array() );
			$log = is_array( $log ) ? $log : array();
			$log[] = array( 'scenario'=>$scenario, 'environment'=>$result['environment'], 'injected'=>$result['injected'], 'time'=>SPF_Runtime::now_mysql() );
			$expected = array_slice( $log, -100 );
			update_option( self::CHAOS_OPTION, $expected, false );
			$persisted = get_option( self::CHAOS_OPTION, array() );
			if ( SPF_Runtime::hash( $persisted ) !== SPF_Runtime::hash( $expected ) ) {
				return new WP_Error( 'spf_chaos_log_persistence_failed', __( 'The chaos-run evidence log could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
		}
		return $result;
	}

	public static function capture_snapshot( $label = '' ) {
		$allowed = SPF_Authorization::require_action( 'repair_owned_mapping', array( 'module_key'=>'file-01', 'object_id'=>'time-travel-snapshot' ), array( 'purpose'=>'time_travel_snapshot' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$lock_name = 'future-snapshot-capture';
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$data = array(
				'policy_catalog' => get_option( SPF_Governance_Control_Plane::POLICY_OPTION, array() ),
				'event_schemas'  => get_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, array() ),
				'config_baselines'=> get_option( SPF_Platform_Engineering::CONFIG_BASELINE_OPTION, array() ),
				'feature_flags'  => get_option( 'spf_feature_flags', array() ),
				'activation_state'=> get_option( 'spf_activation_state', array() ),
				'upgrade_state'  => get_option( 'spf_upgrade_state', array() ),
			);
			$id = 'snap-' . gmdate( 'YmdHis' ) . '-' . substr( SPF_Runtime::hash( array( $data, wp_generate_uuid4() ) ), 0, 12 );
			$snapshot = array(
				'id'         => $id,
				'label'      => substr( sanitize_text_field( $label ), 0, 120 ),
				'created_at' => SPF_Runtime::now_mysql(),
				'data'       => SPF_Runtime::canonicalize( $data ),
				'data_hash'  => SPF_Runtime::hash( $data ),
			);
			$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
			$snapshots = is_array( $snapshots ) ? $snapshots : array();
			if ( count( $snapshots ) >= 50 ) {
				return new WP_Error( 'spf_snapshot_capacity_full', __( 'Governance snapshot capacity is full; explicitly archive or retire an older snapshot before capturing another.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			$snapshots[ $id ] = $snapshot;
			update_option( self::SNAPSHOT_OPTION, $snapshots, false );
			$persisted = get_option( self::SNAPSHOT_OPTION, array() );
			if ( empty( $persisted[ $id ] ) || SPF_Runtime::hash( $persisted[ $id ] ) !== SPF_Runtime::hash( $snapshot ) ) {
				return new WP_Error( 'spf_snapshot_persistence_failed', __( 'The governance snapshot could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			return array( 'id'=>$id, 'label'=>$snapshot['label'], 'created_at'=>$snapshot['created_at'], 'data_hash'=>$snapshot['data_hash'] );
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
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
		if ( 'RESTORE FILE 01 GOVERNANCE SNAPSHOT' !== trim( (string) $confirmation ) ) {
			return new WP_Error( 'spf_snapshot_restore_confirmation_required', __( 'Typed confirmation is required for governance snapshot restore.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$lock_name = 'future-snapshot-restore';
		$lock = SPF_Runtime::acquire_lock( $lock_name, 180 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$plan = self::restore_snapshot_plan( $snapshot_id );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( ! hash_equals( (string) $plan['plan_hash'], (string) $expected_hash ) ) {
				return new WP_Error( 'spf_snapshot_restore_plan_changed', __( 'The snapshot restore plan changed; review the new dry run first.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
			$snapshots = get_option( self::SNAPSHOT_OPTION, array() );
			if ( ! is_array( $snapshots ) || empty( $snapshots[ $snapshot_id ]['data'] ) ) {
				return new WP_Error( 'spf_snapshot_missing', __( 'The requested governance snapshot was not found.', 'sabri-platform-foundation' ), array( 'status'=>404 ) );
			}
			$snapshot = (array) $snapshots[ $snapshot_id ];
			$target = (array) $snapshot['data'];
			if ( empty( $snapshot['data_hash'] ) || ! hash_equals( (string) $snapshot['data_hash'], SPF_Runtime::hash( $target ) ) ) {
				return new WP_Error( 'spf_snapshot_integrity_failed', __( 'The governance snapshot failed its stored integrity hash.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
			}
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
					$desired = SPF_Runtime::canonicalize( $target[ $section ] ?? array() );
					update_option( $option, $desired, false );
					if ( SPF_Runtime::hash( get_option( $option, array() ) ) !== SPF_Runtime::hash( $desired ) ) {
						throw new RuntimeException( 'snapshot_restore_write_failed:' . $option );
					}
				}
				$audit = SPF_Audit::record_required( 'time_travel_restore', 'foundation_snapshot', $snapshot_id, 'success', array( 'plan_hash'=>$plan['plan_hash'], 'sections'=>array_keys( $mapping ) ) );
				if ( is_wp_error( $audit ) ) {
					throw new RuntimeException( $audit->get_error_message() );
				}
			} catch ( Throwable $error ) {
				$compensation_failures = array();
				foreach ( $current as $option => $value ) {
					update_option( $option, $value, false );
					if ( SPF_Runtime::hash( get_option( $option, array() ) ) !== SPF_Runtime::hash( $value ) ) {
						$compensation_failures[] = $option;
					}
				}
				if ( $compensation_failures ) {
					return new WP_Error( 'spf_snapshot_restore_compensation_incomplete', __( 'Governance snapshot restore failed and one or more compensation writes could not be verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'options'=>$compensation_failures ) );
				}
				return new WP_Error( 'spf_snapshot_restore_failed', $error->getMessage(), array( 'status'=>409 ) );
			}
			return array( 'restored'=>true, 'snapshot_id'=>$snapshot_id, 'sections'=>array_keys( $mapping ), 'companion_data_modified'=>false, 'excluded_sections'=>$plan['excluded_sections'] );
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}

	public static function periodic_tick() {
		$reconciled = SPF_Governance::reconcile_expired_flags();
		if ( is_wp_error( $reconciled ) ) {
			do_action( 'spf_future_foundation_tick_failure', $reconciled );
			return $reconciled;
		}
		$plan = self::self_heal_plan();
		$metric = SPF_Platform_Engineering::record_metric( 'future_foundation_self_heal_actions', count( $plan['actions'] ), array( 'module'=>'file-01' ) );
		if ( is_wp_error( $metric ) || false === $metric ) {
			$error = is_wp_error( $metric ) ? $metric : new WP_Error( 'spf_future_foundation_metric_failed', __( 'Future Foundation periodic metric persistence failed.', 'sabri-platform-foundation' ) );
			do_action( 'spf_future_foundation_tick_failure', $error );
			return $error;
		}
		$result = array( 'reconciled'=>$reconciled, 'self_heal_plan'=>$plan, 'metric_recorded'=>true );
		do_action( 'spf_future_foundation_health_tick', $result );
		return $result;
	}

	private static function sanitize_chaos_context( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return array( '_truncated'=>true );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 100, true ) as $key => $item ) {
				$safe_key = is_int( $key ) ? $key : sanitize_key( $key );
				if ( ! is_int( $safe_key ) && '' === $safe_key ) {
					continue;
				}
				if ( ! is_int( $safe_key ) && preg_match( '/(patient|name|address|message|content|token|secret|password|credential|email|phone|document)/i', $safe_key ) ) {
					$out[ $safe_key ] = array( 'redacted'=>true, 'value_hash'=>SPF_Runtime::hash( $item ) );
				} else {
					$out[ $safe_key ] = self::sanitize_chaos_context( $item, $depth + 1 );
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return substr( sanitize_text_field( $value ), 0, 240 );
		}
		return is_scalar( $value ) || null === $value ? $value : null;
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

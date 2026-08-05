<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Reconciler {
	public static function plan() {
		$legacy_map = get_option( 'spf_page_map', array() );
		$legacy_founder = get_option( 'spf_founder_user_id', null );
		$actions = array();
		if ( is_array( $legacy_map ) && $legacy_map ) {
			foreach ( $legacy_map as $key => $page_id ) {
				$page_id = absint( $page_id );
				$owned = $page_id && '1' === get_post_meta( $page_id, '_spf_managed_page', true );
				$actions[] = array(
					'action'     => 'quarantine_legacy_mapping',
					'legacy_key' => sanitize_key( $key ),
					'page_id'    => $page_id,
					'owned'      => $owned,
					'apply'      => $owned ? 'mark_quarantined_and_remove_map' : 'report_only_foreign_page',
				);
			}
		}
		if ( null !== $legacy_founder ) {
			$actions[] = array(
				'action' => 'remove_unsafe_founder_option',
				'value'  => absint( $legacy_founder ),
				'apply'  => 'delete_file01_legacy_option_only',
			);
		}
		return array(
			'generated_at' => current_time( 'mysql', true ),
			'actions'      => $actions,
			'counts'       => array(
				'create'     => 0,
				'update'     => 0,
				'quarantine' => count( $actions ),
				'delete'     => 0,
				'skip'       => 0,
			),
			'law'           => 'No companion data, foreign pages, shell, feed or profile truth will be modified.',
		);
	}

	public static function plan_hash( array $plan ) {
		unset( $plan['generated_at'], $plan['plan_hash'] );
		return hash( 'sha256', wp_json_encode( $plan ) );
	}

	public static function apply( $confirmation, $expected_hash ) {
		if ( 'APPLY FILE 01 RECONCILIATION' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact reconciliation confirmation was not supplied.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'run_reconciliation' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$plan = self::plan();
		$hash = self::plan_hash( $plan );
		if ( ! hash_equals( $hash, (string) $expected_hash ) ) {
			return new WP_Error( 'spf_reconciliation_plan_changed', __( 'The reconciliation plan changed. Generate and review a new dry run.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
		}
		$precommit = SPF_Audit::record_required( 'reconciliation_precommit', 'foundation_reconciliation', $hash, 'authorized', array( 'purpose' => 'legacy_cutover' ) );
		if ( is_wp_error( $precommit ) ) {
			return $precommit;
		}
		$snapshot = array(
			'created_at'          => current_time( 'mysql', true ),
			'plan_hash'           => $hash,
			'spf_page_map'        => get_option( 'spf_page_map', null ),
			'spf_founder_user_id' => get_option( 'spf_founder_user_id', null ),
		);
		update_option( 'spf_reconciliation_snapshot', $snapshot, false );

		$changed = array();
		foreach ( $plan['actions'] as $action ) {
			if ( 'quarantine_legacy_mapping' === $action['action'] && ! empty( $action['owned'] ) && $action['page_id'] ) {
				update_post_meta( $action['page_id'], '_spf_legacy_quarantined', '1' );
				update_post_meta( $action['page_id'], '_spf_legacy_quarantined_at', current_time( 'mysql', true ) );
				if ( '1' !== get_post_meta( $action['page_id'], '_spf_legacy_quarantined', true ) ) {
					self::restore_snapshot( $snapshot );
					return new WP_Error( 'spf_reconciliation_write_failed', __( 'A legacy page could not be quarantined; the snapshot was restored.', 'sabri-platform-foundation' ) );
				}
				$changed[] = array( 'page_id' => $action['page_id'], 'change' => 'marked_quarantined' );
			}
		}
		delete_option( 'spf_page_map' );
		delete_option( 'spf_founder_user_id' );
		if ( '__missing__' !== get_option( 'spf_page_map', '__missing__' ) || '__missing__' !== get_option( 'spf_founder_user_id', '__missing__' ) ) {
			self::restore_snapshot( $snapshot );
			return new WP_Error( 'spf_reconciliation_option_failed', __( 'Legacy options could not be quarantined; the snapshot was restored.', 'sabri-platform-foundation' ) );
		}
		update_option( 'spf_reconciliation_state', array( 'status' => 'applied', 'plan_hash' => $hash, 'applied_at' => current_time( 'mysql', true ) ), false );
		$trace = SPF_Audit::record_required( 'run_reconciliation', 'foundation_reconciliation', $hash, 'success', array( 'purpose' => 'legacy_cutover', 'changed_count' => count( $changed ) ) );
		if ( is_wp_error( $trace ) ) {
			self::restore_snapshot( $snapshot );
			return $trace;
		}
		return array( 'trace_id' => $trace, 'plan_hash' => $hash, 'changed' => $changed, 'status' => 'applied' );
	}

	public static function rollback( $confirmation ) {
		if ( 'ROLL BACK FILE 01 RECONCILIATION' !== $confirmation ) {
			return new WP_Error( 'spf_confirmation_required', __( 'The exact rollback confirmation was not supplied.', 'sabri-platform-foundation' ) );
		}
		$allowed = SPF_Authorization::require_action( 'run_reconciliation' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$snapshot = get_option( 'spf_reconciliation_snapshot', array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['created_at'] ) ) {
			return new WP_Error( 'spf_no_reconciliation_snapshot', __( 'No reconciliation snapshot is available.', 'sabri-platform-foundation' ) );
		}
		$precommit = SPF_Audit::record_required( 'rollback_reconciliation_precommit', 'foundation_reconciliation', $snapshot['plan_hash'], 'authorized', array( 'purpose' => 'rollback' ) );
		if ( is_wp_error( $precommit ) ) {
			return $precommit;
		}
		self::restore_snapshot( $snapshot );
		update_option( 'spf_reconciliation_state', array( 'status' => 'rolled_back', 'rolled_back_at' => current_time( 'mysql', true ) ), false );
		$trace = SPF_Audit::record_required( 'rollback_reconciliation', 'foundation_reconciliation', $snapshot['plan_hash'], 'success', array( 'purpose' => 'rollback' ) );
		return is_wp_error( $trace ) ? $trace : array( 'trace_id' => $trace, 'status' => 'rolled_back' );
	}
	private static function restore_snapshot( array $snapshot ) {
		array_key_exists( 'spf_page_map', $snapshot ) && null !== $snapshot['spf_page_map'] ? update_option( 'spf_page_map', $snapshot['spf_page_map'], false ) : delete_option( 'spf_page_map' );
		array_key_exists( 'spf_founder_user_id', $snapshot ) && null !== $snapshot['spf_founder_user_id'] ? update_option( 'spf_founder_user_id', $snapshot['spf_founder_user_id'], false ) : delete_option( 'spf_founder_user_id' );
		if ( isset( $snapshot['spf_page_map'] ) && is_array( $snapshot['spf_page_map'] ) ) {
			foreach ( $snapshot['spf_page_map'] as $page_id ) {
				delete_post_meta( absint( $page_id ), '_spf_legacy_quarantined' );
				delete_post_meta( absint( $page_id ), '_spf_legacy_quarantined_at' );
			}
		}
	}

}

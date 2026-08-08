<?php
defined( 'ABSPATH' ) || exit;

/**
 * File 01 privacy lifecycle.
 *
 * Immutable governance/audit facts are retained under the approved governance
 * purpose. Personal-data erasure removes transient linkage and records a
 * privacy outcome; it never rewrites a hash-chained audit row or append-only
 * release-state fact.
 */
final class SPF_Privacy {
	private const HEALTH_RETENTION_DAYS = 90;
	private const IDEMPOTENCY_RETENTION_DAYS = 2;
	private const SENT_OUTBOX_RETENTION_DAYS = 30;
	private const DEAD_OUTBOX_RETENTION_DAYS = 365;

	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'spf_privacy_retention', array( __CLASS__, 'run_retention' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['sabri-platform-foundation'] = array(
			'exporter_friendly_name' => __( 'Sabri Platform Foundation governance activity', 'sabri-platform-foundation' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['sabri-platform-foundation'] = array(
			'eraser_friendly_name' => __( 'Sabri Platform Foundation transient actor linkage', 'sabri-platform-foundation' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public static function export_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		global $wpdb;
		$page   = max( 1, absint( $page ) );
		$limit  = 100;
		$offset = ( $page - 1 ) * $limit;
		$items  = array();
		$rowspec = array(
			'audit'            => array( 'user_column' => 'actor_id', 'fields' => array( 'id','action_name','object_type','object_id','purpose','result_code','created_at' ) ),
			'release_states'   => array( 'user_column' => 'actor_id', 'fields' => array( 'id','release_id','sequence_no','status','created_at' ) ),
			'idempotency'      => array( 'user_column' => 'actor_id', 'fields' => array( 'id','action_name','status','created_at','updated_at' ) ),
			'privacy_requests' => array( 'user_column' => 'user_id',  'fields' => array( 'id','request_id','request_type','status','purpose','legal_basis','requested_at','completed_at' ) ),
		);
		$all = array();
		foreach ( $rowspec as $name => $spec ) {
			$table = SPF_Installer::table( $name );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				continue;
			}
			$column = $spec['user_column'];
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column}=%d ORDER BY id ASC", $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table/column.
			foreach ( $rows as $row ) {
				$all[] = array( 'name' => $name, 'fields' => $spec['fields'], 'row' => $row );
			}
		}
		$total = count( $all );
		foreach ( array_slice( $all, $offset, $limit ) as $item ) {
			$data = array();
			foreach ( $item['fields'] as $field ) {
				if ( array_key_exists( $field, $item['row'] ) ) {
					$data[] = array( 'name' => $field, 'value' => (string) $item['row'][ $field ] );
				}
			}
			$items[] = array(
				'group_id'    => 'sabri-platform-foundation-' . $item['name'],
				'group_label' => ucwords( str_replace( '_', ' ', $item['name'] ) ),
				'item_id'     => $item['name'] . '-' . $item['row']['id'],
				'data'        => $data,
			);
		}
		return array( 'data' => $items, 'done' => ( $offset + $limit ) >= $total );
	}

	public static function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		if ( self::has_active_hold( 'user', (string) $user->ID ) ) {
			return array( 'items_removed'=>false, 'items_retained'=>true, 'messages'=>array( __( 'File 01 records are retained under an active legal/privacy hold.', 'sabri-platform-foundation' ) ), 'done'=>true );
		}
		global $wpdb;
		$pseudonym = 'erased-' . substr( hash_hmac( 'sha256', (string) $user->ID, wp_salt( 'auth' ) ), 0, 24 );
		$removed = false;
		$idempotency = SPF_Installer::table( 'idempotency' );
		if ( SPF_Runtime::table_exists( $idempotency ) ) {
			$count = $wpdb->delete( $idempotency, array( 'actor_id' => $user->ID ), array( '%d' ) );
			if ( false === $count ) {
				return array( 'items_removed'=>$removed, 'items_retained'=>true, 'messages'=>array( __( 'Transient idempotency linkage could not be erased; retry is required.', 'sabri-platform-foundation' ) ), 'done'=>false );
			}
			$removed = $count > 0;
		}
		$requests = SPF_Installer::table( 'privacy_requests' );
		if ( SPF_Runtime::table_exists( $requests ) ) {
			$count = $wpdb->update( $requests, array( 'user_id'=>0, 'result_json'=>wp_json_encode( array( 'pseudonym'=>$pseudonym, 'erased_at'=>SPF_Runtime::now_mysql() ) ) ), array( 'user_id'=>$user->ID ), array( '%d','%s' ), array( '%d' ) );
			if ( false === $count ) {
				return array( 'items_removed'=>$removed, 'items_retained'=>true, 'messages'=>array( __( 'Privacy-request linkage could not be pseudonymized; retry is required.', 'sabri-platform-foundation' ) ), 'done'=>false );
			}
			$removed = $removed || $count > 0;
		}
		$audit = SPF_Audit::record_required( 'privacy_erasure', 'foundation_privacy_subject', $pseudonym, 'partially_erased', array( 'purpose'=>'wordpress_privacy_erasure', 'immutable_facts_retained'=>'audit,release_states' ) );
		if ( is_wp_error( $audit ) ) {
			return array( 'items_removed'=>$removed, 'items_retained'=>true, 'messages'=>array( __( 'Privacy erasure changed transient linkage but mandatory audit evidence could not be recorded; operator reconciliation is required.', 'sabri-platform-foundation' ) ), 'done'=>false );
		}
		$event = SPF_Event_Bus::publish( 'FoundationPrivacyErasureCompleted.v1', 'foundation_privacy_subject', $pseudonym, array( 'pseudonym'=>$pseudonym, 'immutable_facts_retained'=>true ), 1, 'privacy-erasure-'.$pseudonym );
		if ( is_wp_error( $event ) ) {
			return array( 'items_removed'=>$removed, 'items_retained'=>true, 'messages'=>array( __( 'Privacy erasure changed transient linkage but completion event persistence failed; operator reconciliation is required.', 'sabri-platform-foundation' ) ), 'done'=>false );
		}
		return array( 'items_removed'=>$removed, 'items_retained'=>true, 'messages'=>array( __( 'Transient linkage was erased. Hash-chained audit and append-only release facts were retained under the approved governance purpose and were not rewritten.', 'sabri-platform-foundation' ) ), 'done'=>true );
	}

	public static function create_request( $user_id, $type, $purpose, $legal_basis ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$type = sanitize_key( $type );
		$purpose = substr( sanitize_text_field( $purpose ), 0, 191 );
		$legal_basis = substr( sanitize_text_field( $legal_basis ), 0, 191 );
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'spf_privacy_request_user_invalid', __( 'Privacy request requires an existing user subject.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		if ( ! in_array( $type, array( 'export','erasure','correction' ), true ) ) {
			return new WP_Error( 'spf_privacy_request_invalid', __( 'Invalid privacy request type.', 'sabri-platform-foundation' ) );
		}
		if ( '' === $purpose || '' === $legal_basis ) {
			return new WP_Error( 'spf_privacy_request_basis_required', __( 'Privacy request purpose and legal basis are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
		}
		$request_id = wp_generate_uuid4();
		$now = SPF_Runtime::now_mysql();
		$due = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$tx = SPF_Runtime::begin();
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		try {
			$ok = $wpdb->insert(
				SPF_Installer::table( 'privacy_requests' ),
				array( 'request_id' => $request_id, 'user_id' => $user_id, 'request_type' => $type, 'status' => 'received', 'purpose' => $purpose, 'legal_basis' => $legal_basis, 'result_json' => '{}', 'record_version' => 1, 'requested_at' => $now, 'due_at' => $due )
			);
			if ( false === $ok ) {
				throw new RuntimeException( 'Privacy request could not be recorded.' );
			}
			$audit = SPF_Audit::record_required( 'privacy_request_received', 'foundation_privacy_request', $request_id, 'success', array( 'purpose' => $purpose, 'request_type' => $type ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$commit = SPF_Runtime::commit();
			if ( is_wp_error( $commit ) ) {
				throw new RuntimeException( $commit->get_error_message() );
			}
			return array( 'request_id' => $request_id, 'status' => 'received', 'due_at' => $due );
		} catch ( Throwable $error ) {
			SPF_Runtime::rollback();
			return new WP_Error( 'spf_privacy_request_failed', $error->getMessage(), array( 'status'=>503 ) );
		}
	}

	public static function has_active_hold( $subject_type, $subject_id ) {
		global $wpdb;
		$table = SPF_Installer::table( 'privacy_holds' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			SPF_Audit::record( 'privacy_hold_registry_missing', 'foundation_privacy', 'hold-registry', 'failed', array( 'purpose'=>'privacy_hold_fail_closed' ) );
			return true;
		}
		$active = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE subject_type=%s AND subject_id=%s AND active=1 LIMIT 1", sanitize_key( $subject_type ), substr( sanitize_text_field( $subject_id ), 0, 191 ) ) );
		if ( ! empty( $wpdb->last_error ) ) {
			SPF_Audit::record( 'privacy_hold_query_failed', 'foundation_privacy', 'hold-registry', 'failed', array( 'purpose'=>'privacy_hold_fail_closed' ) );
			return true;
		}
		return null !== $active;
	}

	public static function run_retention() {
		global $wpdb;
		$defaults = array( 'health_days'=>self::HEALTH_RETENTION_DAYS, 'idempotency_days'=>self::IDEMPOTENCY_RETENTION_DAYS, 'sent_outbox_days'=>self::SENT_OUTBOX_RETENTION_DAYS, 'dead_outbox_days'=>self::DEAD_OUTBOX_RETENTION_DAYS );
		$policies = apply_filters( 'spf_privacy_retention_policy', $defaults );
		$policies = is_array( $policies ) ? $policies : $defaults;
		foreach ( $defaults as $key => $default ) {
			$value = $policies[$key] ?? $default;
			$policies[$key] = is_numeric( $value ) && (int)$value >= 1 ? min( 3650, (int)$value ) : $default;
		}
		$deleted = array();
		$failures = array();
		$health = SPF_Installer::table( 'health' );
		if ( SPF_Runtime::table_exists( $health ) ) {
			$deleted['health'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$health} WHERE created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $policies['health_days'] * DAY_IN_SECONDS ) ) );
			if ( false === $deleted['health'] ) { $failures[] = 'health'; }
		}
		$idempotency = SPF_Installer::table( 'idempotency' );
		if ( SPF_Runtime::table_exists( $idempotency ) ) {
			$deleted['idempotency'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$idempotency} WHERE expires_at<%s AND status IN ('completed','failed') LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $policies['idempotency_days'] * DAY_IN_SECONDS ) ) );
			if ( false === $deleted['idempotency'] ) { $failures[] = 'idempotency'; }
		}
		$outbox = SPF_Installer::table( 'outbox' );
		if ( SPF_Runtime::table_exists( $outbox ) ) {
			$deleted['outbox_sent'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE status='sent' AND sent_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $policies['sent_outbox_days'] * DAY_IN_SECONDS ) ) );
			$deleted['outbox_dead'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE status='dead' AND created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - $policies['dead_outbox_days'] * DAY_IN_SECONDS ) ) );
			if ( false === $deleted['outbox_sent'] ) { $failures[] = 'outbox_sent'; }
			if ( false === $deleted['outbox_dead'] ) { $failures[] = 'outbox_dead'; }
		}
		$deleted['audit'] = 'retained_integrity_chain';
		if ( $failures ) {
			SPF_Audit::record( 'retention_run', 'foundation_privacy', 'file-01', 'failed', array( 'purpose'=>'scheduled_retention', 'failed_targets'=>implode(',', $failures) ) );
			return new WP_Error( 'spf_retention_incomplete', __( 'One or more File 01 retention operations failed.', 'sabri-platform-foundation' ), array( 'targets'=>$failures ) );
		}
		$audit = SPF_Audit::record_required( 'retention_run', 'foundation_privacy', 'file-01', 'success', array( 'purpose'=>'scheduled_retention', 'deleted'=>wp_json_encode($deleted) ) );
		return is_wp_error( $audit ) ? $audit : $deleted;
	}
}

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
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'File 01 records are retained under an active legal/privacy hold.', 'sabri-platform-foundation' ) ),
				'done'           => true,
			);
		}
		global $wpdb;
		$pseudonym = 'erased-' . substr( hash_hmac( 'sha256', (string) $user->ID, wp_salt( 'auth' ) ), 0, 24 );
		$removed = false;

		// Idempotency rows are short-lived technical linkage and may be erased.
		$idempotency = SPF_Installer::table( 'idempotency' );
		if ( SPF_Runtime::table_exists( $idempotency ) ) {
			$count = $wpdb->delete( $idempotency, array( 'actor_id' => $user->ID ), array( '%d' ) );
			$removed = false !== $count && $count > 0;
		}

		// Privacy request linkage may be pseudonymized because it is not part of
		// the tamper-evident audit chain.
		$requests = SPF_Installer::table( 'privacy_requests' );
		if ( SPF_Runtime::table_exists( $requests ) ) {
			$count = $wpdb->update(
				$requests,
				array( 'user_id' => 0, 'result_json' => wp_json_encode( array( 'pseudonym' => $pseudonym, 'erased_at' => SPF_Runtime::now_mysql() ) ) ),
				array( 'user_id' => $user->ID ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			$removed = $removed || ( false !== $count && $count > 0 );
		}

		// Audit and release-state rows remain unchanged: mutating their actor_id
		// would invalidate the append-only integrity chain. Retention is therefore
		// expressly recorded rather than falsely described as erased.
		SPF_Audit::record_required( 'privacy_erasure', 'foundation_privacy_subject', $pseudonym, 'partially_erased', array( 'purpose' => 'wordpress_privacy_erasure', 'immutable_facts_retained' => 'audit,release_states' ) );
		SPF_Event_Bus::publish( 'FoundationPrivacyErasureCompleted.v1', 'foundation_privacy_subject', $pseudonym, array( 'pseudonym' => $pseudonym, 'immutable_facts_retained' => true ), 1, 'privacy-erasure-' . $pseudonym );
		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( __( 'Transient linkage was erased. Hash-chained audit and append-only release facts were retained under the approved governance purpose and were not rewritten.', 'sabri-platform-foundation' ) ),
			'done'           => true,
		);
	}

	public static function create_request( $user_id, $type, $purpose, $legal_basis ) {
		global $wpdb;
		$type = sanitize_key( $type );
		if ( ! in_array( $type, array( 'export','erasure','correction' ), true ) ) {
			return new WP_Error( 'spf_privacy_request_invalid', __( 'Invalid privacy request type.', 'sabri-platform-foundation' ) );
		}
		$request_id = wp_generate_uuid4();
		$now = SPF_Runtime::now_mysql();
		$due = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$ok = $wpdb->insert(
			SPF_Installer::table( 'privacy_requests' ),
			array( 'request_id' => $request_id, 'user_id' => absint( $user_id ), 'request_type' => $type, 'status' => 'received', 'purpose' => substr( sanitize_text_field( $purpose ), 0, 191 ), 'legal_basis' => substr( sanitize_text_field( $legal_basis ), 0, 191 ), 'result_json' => '{}', 'record_version' => 1, 'requested_at' => $now, 'due_at' => $due )
		);
		if ( false === $ok ) {
			return new WP_Error( 'spf_privacy_request_failed', __( 'Privacy request could not be recorded.', 'sabri-platform-foundation' ) );
		}
		SPF_Audit::record_required( 'privacy_request_received', 'foundation_privacy_request', $request_id, 'success', array( 'purpose' => $purpose, 'request_type' => $type ) );
		return array( 'request_id' => $request_id, 'status' => 'received', 'due_at' => $due );
	}

	public static function has_active_hold( $subject_type, $subject_id ) {
		global $wpdb;
		$table = SPF_Installer::table( 'privacy_holds' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE subject_type=%s AND subject_id=%s AND active=1 LIMIT 1", sanitize_key( $subject_type ), substr( sanitize_text_field( $subject_id ), 0, 191 ) ) );
	}

	public static function run_retention() {
		global $wpdb;
		$policies = apply_filters(
			'spf_privacy_retention_policy',
			array(
				'health_days'      => self::HEALTH_RETENTION_DAYS,
				'idempotency_days' => self::IDEMPOTENCY_RETENTION_DAYS,
				'sent_outbox_days' => self::SENT_OUTBOX_RETENTION_DAYS,
				'dead_outbox_days' => self::DEAD_OUTBOX_RETENTION_DAYS,
			)
		);
		$deleted = array();
		$health = SPF_Installer::table( 'health' );
		if ( SPF_Runtime::table_exists( $health ) ) {
			$deleted['health'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$health} WHERE created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - absint( $policies['health_days'] ) * DAY_IN_SECONDS ) ) );
		}
		$idempotency = SPF_Installer::table( 'idempotency' );
		if ( SPF_Runtime::table_exists( $idempotency ) ) {
			$deleted['idempotency'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$idempotency} WHERE expires_at<%s AND status IN ('completed','failed') LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - absint( $policies['idempotency_days'] ) * DAY_IN_SECONDS ) ) );
		}
		$outbox = SPF_Installer::table( 'outbox' );
		if ( SPF_Runtime::table_exists( $outbox ) ) {
			$deleted['outbox_sent'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE status='sent' AND sent_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - absint( $policies['sent_outbox_days'] ) * DAY_IN_SECONDS ) ) );
			$deleted['outbox_dead'] = $wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE status='dead' AND created_at<%s LIMIT 1000", gmdate( 'Y-m-d H:i:s', time() - absint( $policies['dead_outbox_days'] ) * DAY_IN_SECONDS ) ) );
		}
		// Audit rows are not deleted in-place because that would break the hash
		// chain. Archival/deletion requires a separately verified chain checkpoint.
		$deleted['audit'] = 'retained_integrity_chain';
		SPF_Audit::record_required( 'retention_run', 'foundation_privacy', 'file-01', 'success', array( 'purpose' => 'scheduled_retention', 'deleted' => wp_json_encode( $deleted ) ) );
		return $deleted;
	}
}

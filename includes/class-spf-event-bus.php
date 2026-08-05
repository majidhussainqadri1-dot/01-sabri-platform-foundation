<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Event_Bus {
	public static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '' ) {
		global $wpdb;
		$event_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $event_name );
		if ( ! $event_name || $version < 1 ) {
			return new WP_Error( 'spf_invalid_event', __( 'Invalid event contract.', 'sabri-platform-foundation' ) );
		}
		$payload = self::sanitize_payload( $payload );
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json || strlen( $payload_json ) > 262144 ) {
			return new WP_Error( 'spf_event_payload_invalid', __( 'Event payload is invalid or too large.', 'sabri-platform-foundation' ) );
		}
		$dedupe_key = $dedupe_key ? sanitize_text_field( $dedupe_key ) : hash( 'sha256', $event_name . '|' . $aggregate_type . '|' . $aggregate_id . '|' . $payload_json );
		$now = SPF_Runtime::now_mysql();
		$inserted = $wpdb->insert(
			SPF_Installer::table( 'outbox' ),
			array(
				'event_id'=>wp_generate_uuid4(),'event_name'=>$event_name,'event_version'=>absint($version),'aggregate_type'=>sanitize_key($aggregate_type),
				'aggregate_id'=>substr(sanitize_text_field((string)$aggregate_id),0,191),'dedupe_key'=>substr($dedupe_key,0,191),'payload_json'=>$payload_json,
				'status'=>'pending','attempts'=>0,'available_at'=>$now,'created_at'=>$now,
			),
			array( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s' )
		);
		if ( false === $inserted && false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
			return true;
		}
		return false === $inserted ? new WP_Error( 'spf_event_store_failed', __( 'The event could not be stored.', 'sabri-platform-foundation' ) ) : true;
	}

	public static function dispatch_due( $limit = 20 ) {
		global $wpdb;
		$token = SPF_Runtime::acquire_lock( 'outbox_dispatch', 600, get_current_user_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$processed = array( 'sent'=>0,'retry'=>0,'dead'=>0,'recovered'=>0 );
		try {
			$table = SPF_Installer::table( 'outbox' );
			$now = SPF_Runtime::now_mysql();
			// Recover workers that died after claiming an event.
			$processed['recovered'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='retry',available_at=%s,last_error='stale_processing_recovered' WHERE status='processing' AND available_at<%s", $now, gmdate('Y-m-d H:i:s',time()-600) ) );
			$limit = max( 1, min( 100, absint( $limit ) ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('pending','retry') AND available_at<=%s ORDER BY id ASC LIMIT %d", $now, $limit ), ARRAY_A );
			foreach ( $rows as $row ) {
				$claimed = $wpdb->update(
					$table,
					array( 'status'=>'processing','available_at'=>gmdate('Y-m-d H:i:s',time()+600) ),
					array( 'id'=>(int)$row['id'],'status'=>$row['status'] ),
					array( '%s','%s' ), array( '%d','%s' )
				);
				if ( 1 !== $claimed ) {
					continue;
				}
				$hook = 'spf_event_' . sanitize_key( str_replace( '.', '_', strtolower( $row['event_name'] ) ) );
				try {
					$payload = json_decode( $row['payload_json'], true );
					if ( ! is_array( $payload ) ) {
						throw new RuntimeException( 'Invalid stored event payload.' );
					}
					do_action( $hook, $payload, $row );
					$updated = $wpdb->update( $table, array( 'status'=>'sent','sent_at'=>SPF_Runtime::now_mysql(),'last_error'=>'','available_at'=>SPF_Runtime::now_mysql() ), array( 'id'=>(int)$row['id'],'status'=>'processing' ) );
					if ( 1 !== $updated ) {
						throw new RuntimeException( 'The claimed event could not be finalized.' );
					}
					$processed['sent']++;
				} catch ( Throwable $error ) {
					$attempts = (int) $row['attempts'] + 1;
					$status = $attempts >= 7 ? 'dead' : 'retry';
					$delay = min( 21600, 60 * ( 2 ** min( 8, $attempts ) ) );
					$updated = $wpdb->update(
						$table,
						array( 'status'=>$status,'attempts'=>$attempts,'available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error'=>substr(sanitize_text_field($error->getMessage()),0,191) ),
						array( 'id'=>(int)$row['id'],'status'=>'processing' )
					);
					if ( 1 !== $updated ) {
						SPF_Audit::record( 'outbox_finalize_conflict', 'foundation_event', $row['event_id'], 'failed', array( 'purpose'=>'outbox_dispatch' ) );
					}
					$processed[ $status ]++;
					if ( 'dead' === $status ) {
						SPF_Audit::record( 'outbox_dead_letter', 'foundation_event', $row['event_id'], 'failed', array( 'purpose'=>'outbox_dispatch','event_name'=>$row['event_name'],'attempts'=>$attempts ) );
						do_action( 'spf_outbox_dead_letter', $row['event_id'], $row['event_name'], $attempts );
					}
				}
			}
			return $processed;
		} finally {
			SPF_Runtime::release_lock( 'outbox_dispatch', $token );
		}
	}

	private static function sanitize_payload( array $payload ) {
		$result = array();
		foreach ( $payload as $key => $value ) {
			$key = substr( sanitize_key( $key ), 0, 128 );
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|patient|message_body|payment|identity_document/i', (string) $key ) ) {
				$result[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize_payload( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$result[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$result[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 1000 );
			} else {
				$result[ $key ] = '[unsupported]';
			}
		}
		return SPF_Runtime::canonicalize( $result );
	}
}

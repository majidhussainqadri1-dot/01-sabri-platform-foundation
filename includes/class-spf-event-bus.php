<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Event_Bus {
	public static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '', $privacy_class = 'internal' ) {
		global $wpdb;
		$event_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $event_name );
		$aggregate_type = sanitize_key( $aggregate_type );
		$aggregate_id = substr( sanitize_text_field( (string) $aggregate_id ), 0, 191 );
		if ( ! $event_name || ! $aggregate_type || ! $aggregate_id || $version < 1 ) {
			return new WP_Error( 'spf_invalid_event', __( 'Invalid event contract.', 'sabri-platform-foundation' ) );
		}
		$privacy_class = sanitize_key( $privacy_class );
		if ( ! in_array( $privacy_class, array( 'public','internal','restricted','confidential','ephemeral' ), true ) ) {
			return new WP_Error( 'spf_invalid_event_privacy_class', __( 'A valid event privacy classification is required.', 'sabri-platform-foundation' ) );
		}
		$payload = self::sanitize_payload( $payload );
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json || strlen( $payload_json ) > 262144 ) {
			return new WP_Error( 'spf_event_payload_invalid', __( 'Event payload is invalid or too large.', 'sabri-platform-foundation' ) );
		}
		$dedupe_key = $dedupe_key ? sanitize_text_field( $dedupe_key ) : hash( 'sha256', $event_name . '|' . $aggregate_type . '|' . $aggregate_id . '|' . $payload_json );
		if ( strlen( $dedupe_key ) > 191 ) {
			$dedupe_key = hash( 'sha256', $dedupe_key );
		}
		$now = SPF_Runtime::now_mysql();
		$previous_suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			SPF_Installer::table( 'outbox' ),
			array(
				'event_id'=>wp_generate_uuid4(),'event_name'=>$event_name,'event_version'=>absint($version),'aggregate_type'=>$aggregate_type,
				'aggregate_id'=>$aggregate_id,'dedupe_key'=>$dedupe_key,'payload_json'=>$payload_json,'privacy_class'=>$privacy_class,
				'status'=>'pending','attempts'=>0,'available_at'=>$now,'created_at'=>$now,
			),
			array( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s' )
		);
		$insert_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous_suppress );
		if ( false === $inserted && false !== stripos( $insert_error, 'duplicate' ) ) {
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
		$processed = array( 'sent'=>0,'retry'=>0,'dead'=>0,'recovered'=>0,'conflict'=>0 );
		try {
			$table = SPF_Installer::table( 'outbox' );
			$now = SPF_Runtime::now_mysql();
			$stale_before = gmdate( 'Y-m-d H:i:s', time() - 600 );
			$recovered = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='retry',available_at=%s,last_error='stale_processing_recovered' WHERE status='processing' AND available_at<%s",
					$now,
					$stale_before
				)
			);
			if ( false === $recovered ) {
				return new WP_Error( 'spf_outbox_recovery_query_failed', __( 'The outbox stale-lease recovery query failed.', 'sabri-platform-foundation' ), array( 'status'=>503 ) );
			}
			$processed['recovered'] = (int) $recovered;
			$limit = max( 1, min( 100, absint( $limit ) ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('pending','retry') AND available_at<=%s ORDER BY id ASC LIMIT %d", $now, $limit ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'spf_outbox_select_failed', __( 'The outbox dispatch query failed.', 'sabri-platform-foundation' ), array( 'status'=>503 ) );
			}
			foreach ( $rows as $row ) {
				$lease_until = gmdate( 'Y-m-d H:i:s', time() + 600 );
				$claimed = $wpdb->update(
					$table,
					array( 'status'=>'processing','available_at'=>$lease_until ),
					array( 'id'=>(int)$row['id'],'status'=>$row['status'],'available_at'=>$row['available_at'] ),
					array( '%s','%s' ), array( '%d','%s','%s' )
				);
				if ( 1 !== $claimed ) {
					$processed['conflict']++;
					continue;
				}
				$hook = 'spf_event_' . sanitize_key( str_replace( '.', '_', strtolower( $row['event_name'] ) ) );
				try {
					$payload = json_decode( $row['payload_json'], true );
					if ( ! is_array( $payload ) || json_last_error() !== JSON_ERROR_NONE ) {
						throw new RuntimeException( 'Invalid stored event payload.' );
					}
					do_action( $hook, $payload, $row );
					$updated = $wpdb->update(
						$table,
						array( 'status'=>'sent','sent_at'=>SPF_Runtime::now_mysql(),'last_error'=>'','available_at'=>SPF_Runtime::now_mysql() ),
						array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until ),
						array( '%s','%s','%s','%s' ), array( '%d','%s','%s' )
					);
					if ( 1 !== $updated ) {
						throw new RuntimeException( 'The event lease changed before successful finalization.' );
					}
					$processed['sent']++;
				} catch ( Throwable $error ) {
					$attempts = (int) $row['attempts'] + 1;
					$status = $attempts >= 7 ? 'dead' : 'retry';
					$delay = min( 21600, 60 * ( 2 ** min( 8, $attempts ) ) );
					$updated = $wpdb->update(
						$table,
						array( 'status'=>$status,'attempts'=>$attempts,'available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error'=>substr(sanitize_text_field($error->getMessage()),0,191) ),
						array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until ),
						array( '%s','%d','%s','%s' ), array( '%d','%s','%s' )
					);
					if ( 1 !== $updated ) {
						$processed['conflict']++;
						SPF_Audit::record( 'outbox_finalize_conflict', 'foundation_event', $row['event_id'], 'failed', array( 'purpose'=>'outbox_dispatch','attempts'=>$attempts ) );
						continue;
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

	private static function sanitize_payload( array $payload, $depth = 0 ) {
		if ( $depth > 5 ) {
			return array( '_truncated' => true );
		}
		$result = array();
		foreach ( array_slice( $payload, 0, 100, true ) as $key => $value ) {
			$key = substr( sanitize_key( (string) $key ), 0, 128 );
			if ( '' === $key ) {
				continue;
			}
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|patient|message|payment|identity|document|credential|private|key/i', $key ) ) {
				$result[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize_payload( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$result[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$result[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 1000 );
			} else {
				$result[ $key ] = '[unsupported]';
			}
		}
		if ( count( $payload ) > 100 ) {
			$result['_truncated'] = true;
		}
		return SPF_Runtime::canonicalize( $result );
	}
}

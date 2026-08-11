<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Event_Bus {
	public static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '', $privacy_class = 'internal' ) {
		global $wpdb;
		$raw_event_name = (string) $event_name;
		$event_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', $raw_event_name );
		$raw_aggregate_type = (string) $aggregate_type;
		$aggregate_type = sanitize_key( $raw_aggregate_type );
		$raw_aggregate_id = (string) $aggregate_id;
		$aggregate_id = sanitize_text_field( $raw_aggregate_id );
		$version_raw = $version;
		$version = is_int( $version_raw ) ? $version_raw : ( is_string( $version_raw ) && ctype_digit( $version_raw ) ? (int) $version_raw : 0 );
		if (
			! $event_name || $raw_event_name !== $event_name || strlen( $event_name ) > 191 ||
			! $aggregate_type || $raw_aggregate_type !== $aggregate_type || strlen( $aggregate_type ) > 64 ||
			! $aggregate_id || $raw_aggregate_id !== $aggregate_id || strlen( $aggregate_id ) > 191 ||
			$version < 1 || $version > 65535
		) {
			return new WP_Error( 'spf_invalid_event', __( 'Event identity fields must already be canonical and within their bounded contract envelope.', 'sabri-platform-foundation' ) );
		}
		$raw_privacy_class = (string) $privacy_class;
		$privacy_class = sanitize_key( $raw_privacy_class );
		if ( $raw_privacy_class !== $privacy_class || ! in_array( $privacy_class, array( 'public','internal','restricted','confidential','ephemeral' ), true ) ) {
			return new WP_Error( 'spf_invalid_event_privacy_class', __( 'A canonical event privacy classification is required.', 'sabri-platform-foundation' ) );
		}
		$payload = self::sanitize_payload( $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json || strlen( $payload_json ) > 262144 ) {
			return new WP_Error( 'spf_event_payload_invalid', __( 'Event payload is invalid or too large.', 'sabri-platform-foundation' ) );
		}
		if ( $dedupe_key ) {
			$raw_dedupe_key = (string) $dedupe_key;
			$canonical_dedupe_key = sanitize_text_field( $raw_dedupe_key );
			$legacy_dedupe_key = ( $raw_dedupe_key === $canonical_dedupe_key && strlen( $canonical_dedupe_key ) <= 191 )
				? $canonical_dedupe_key
				: hash( 'sha256', $raw_dedupe_key );
			$scope_dedupe_key = $legacy_dedupe_key;
			$dedupe_key = hash( 'sha256', $event_name . '|' . $version . '|' . $aggregate_type . '|' . $aggregate_id . '|' . $privacy_class . '|custom|' . $scope_dedupe_key );
			$legacy_match = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT event_id FROM ' . SPF_Installer::table( 'outbox' ) . ' WHERE dedupe_key=%s AND event_name=%s AND event_version=%d AND aggregate_type=%s AND aggregate_id=%s AND privacy_class=%s LIMIT 1',
					$legacy_dedupe_key, $event_name, $version, $aggregate_type, $aggregate_id, $privacy_class
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'spf_event_dedupe_lookup_failed', __( 'Existing event idempotency state could not be verified.', 'sabri-platform-foundation' ) );
			}
			if ( is_string( $legacy_match ) && '' !== $legacy_match ) {
				return true;
			}
		} else {
			$dedupe_key = hash( 'sha256', $event_name . '|' . $version . '|' . $aggregate_type . '|' . $aggregate_id . '|' . $privacy_class . '|' . $payload_json );
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
		$processed = array( 'sent'=>0,'retry'=>0,'dead'=>0,'recovered'=>0,'reconciliation_required'=>0,'conflict'=>0 );
		try {
			$table = SPF_Installer::table( 'outbox' );
			$now = SPF_Runtime::now_mysql();
			$frozen = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='reconciliation_required',available_at=%s,last_error='handler_completion_ambiguous' WHERE status='processing' AND last_error='handler_started' AND available_at<%s",
					$now,
					$now
				)
			);
			if ( false === $frozen ) {
				return new WP_Error( 'spf_outbox_ambiguous_recovery_failed', __( 'The outbox ambiguous-completion recovery query failed.', 'sabri-platform-foundation' ), array( 'status'=>503 ) );
			}
			$processed['reconciliation_required'] = (int) $frozen;
			$recovered = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='retry',available_at=%s,last_error='expired_processing_lease_recovered' WHERE status='processing' AND (last_error IS NULL OR last_error<>'handler_started') AND available_at<%s",
					$now,
					$now
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
					array( 'status'=>'processing','available_at'=>$lease_until,'last_error'=>'claimed_not_started' ),
					array( 'id'=>(int)$row['id'],'status'=>$row['status'],'available_at'=>$row['available_at'] ),
					array( '%s','%s','%s' ), array( '%d','%s','%s' )
				);
				if ( 1 !== $claimed ) {
					$processed['conflict']++;
					continue;
				}
				$hook = 'spf_event_' . sanitize_key( str_replace( '.', '_', strtolower( $row['event_name'] ) ) );
				$handler_started = false;
				try {
					$payload = json_decode( $row['payload_json'], true );
					if ( ! is_array( $payload ) || json_last_error() !== JSON_ERROR_NONE ) {
						throw new RuntimeException( 'Invalid stored event payload.' );
					}
					$marked = $wpdb->update(
						$table,
						array( 'last_error'=>'handler_started' ),
						array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until ),
						array( '%s' ), array( '%d','%s','%s' )
					);
					if ( 1 !== $marked ) {
						throw new RuntimeException( 'The event lease could not be durably marked before handler execution.' );
					}
					$handler_started = true;
					do_action( $hook, $payload, $row );
					$updated = $wpdb->update(
						$table,
						array( 'status'=>'sent','sent_at'=>SPF_Runtime::now_mysql(),'last_error'=>'','available_at'=>SPF_Runtime::now_mysql() ),
						array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until,'last_error'=>'handler_started' ),
						array( '%s','%s','%s','%s' ), array( '%d','%s','%s','%s' )
					);
					if ( 1 !== $updated ) {
						throw new RuntimeException( 'The event lease changed before successful finalization.' );
					}
					$processed['sent']++;
				} catch ( Throwable $error ) {
					if ( $handler_started ) {
						$freeze = $wpdb->update(
							$table,
							array( 'status'=>'reconciliation_required','available_at'=>SPF_Runtime::now_mysql(),'last_error'=>'handler_completion_ambiguous' ),
							array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until,'last_error'=>'handler_started' ),
							array( '%s','%s','%s' ), array( '%d','%s','%s','%s' )
						);
						if ( 1 === $freeze ) {
							$processed['reconciliation_required']++;
						} else {
							$processed['conflict']++;
						}
						SPF_Audit::record( 'outbox_handler_completion_ambiguous', 'foundation_event', $row['event_id'], 'reconciliation_required', array( 'purpose'=>'outbox_dispatch','event_name'=>$row['event_name'] ) );
						do_action( 'spf_outbox_reconciliation_required', $row['event_id'], $row['event_name'] );
						continue;
					}
					$attempts = (int) $row['attempts'] + 1;
					$status = $attempts >= 7 ? 'dead' : 'retry';
					$delay = min( 21600, 60 * ( 2 ** min( 8, $attempts ) ) );
					$updated = $wpdb->update(
						$table,
						array( 'status'=>$status,'attempts'=>$attempts,'available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error'=>substr(sanitize_text_field($error->getMessage()),0,191) ),
						array( 'id'=>(int)$row['id'],'status'=>'processing','available_at'=>$lease_until,'last_error'=>'claimed_not_started' ),
						array( '%s','%d','%s','%s' ), array( '%d','%s','%s','%s' )
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
			return new WP_Error( 'spf_event_payload_too_deep', __( 'Event payload nesting exceeds the bounded contract envelope.', 'sabri-platform-foundation' ) );
		}
		if ( count( $payload ) > 100 ) {
			return new WP_Error( 'spf_event_payload_too_many_fields', __( 'Event payload fields exceed the bounded contract envelope.', 'sabri-platform-foundation' ) );
		}
		$result = array();
		foreach ( $payload as $key => $value ) {
			$raw_key = (string) $key;
			$safe_key = substr( sanitize_key( $raw_key ), 0, 128 );
			if ( '' === $safe_key || $raw_key !== $safe_key || array_key_exists( $safe_key, $result ) ) {
				return new WP_Error( 'spf_event_payload_key_invalid', __( 'Event payload keys must already be unique canonical keys.', 'sabri-platform-foundation' ) );
			}
			if ( preg_match( '/(^|_)(password|token|secret|authorization|cookie|nonce|patient|message|payment|identity|document|credential|private_key|api_key|encryption_key)($|_)/i', $safe_key ) ) {
				$result[ $safe_key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$nested = self::sanitize_payload( $value, $depth + 1 );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$result[ $safe_key ] = $nested;
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$result[ $safe_key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$raw_value = (string) $value;
				$canonical_value = sanitize_text_field( $raw_value );
				if ( strlen( $raw_value ) > 1000 ) {
					return new WP_Error( 'spf_event_payload_value_too_large', __( 'Event payload string value exceeds the bounded contract envelope.', 'sabri-platform-foundation' ) );
				}
				if ( $raw_value !== $canonical_value ) {
					return new WP_Error( 'spf_event_payload_value_noncanonical', __( 'Event payload string values must already be canonical plain text.', 'sabri-platform-foundation' ) );
				}
				$result[ $safe_key ] = $raw_value;
			} else {
				return new WP_Error( 'spf_event_payload_value_invalid', __( 'Event payload contains an unsupported value type.', 'sabri-platform-foundation' ) );
			}
		}
		return SPF_Runtime::canonicalize( $result );
	}
}
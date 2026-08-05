<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Event_Bus {
	public static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '' ) {
		global $wpdb;
		$event_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $event_name );
		if ( ! $event_name || $version < 1 ) {
			return new WP_Error( 'spf_invalid_event', __( 'Invalid event contract.', 'sabri-platform-foundation' ) );
		}
		$dedupe_key = $dedupe_key ? sanitize_text_field( $dedupe_key ) : hash( 'sha256', $event_name . '|' . $aggregate_type . '|' . $aggregate_id . '|' . wp_json_encode( $payload ) );
		$inserted = $wpdb->insert(
			SPF_Installer::table( 'outbox' ),
			array(
				'event_id'       => wp_generate_uuid4(),
				'event_name'     => $event_name,
				'event_version'  => absint( $version ),
				'aggregate_type' => sanitize_key( $aggregate_type ),
				'aggregate_id'   => substr( sanitize_text_field( (string) $aggregate_id ), 0, 191 ),
				'dedupe_key'     => substr( $dedupe_key, 0, 191 ),
				'payload_json'   => wp_json_encode( self::sanitize_payload( $payload ) ),
				'status'         => 'pending',
				'attempts'       => 0,
				'available_at'   => current_time( 'mysql', true ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted && false !== stripos( $wpdb->last_error, 'duplicate' ) ) {
			return true;
		}
		return false === $inserted ? new WP_Error( 'spf_event_store_failed', __( 'The event could not be stored.', 'sabri-platform-foundation' ) ) : true;
	}

	public static function dispatch_due( $limit = 20 ) {
		global $wpdb;
		$token = self::acquire_dispatch_lock();
		if ( is_wp_error( $token ) ) {
			return;
		}
		try {
			$table = SPF_Installer::table( 'outbox' );
			$limit = max( 1, min( 100, absint( $limit ) ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status IN ('pending','retry') AND available_at <= %s ORDER BY id ASC LIMIT %d",
					current_time( 'mysql', true ),
					$limit
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status='processing' WHERE id=%d AND status IN ('pending','retry')",
						(int) $row['id']
					)
				);
				if ( 1 !== $claimed ) {
					continue;
				}
				$hook = 'spf_event_' . sanitize_key( str_replace( '.', '_', strtolower( $row['event_name'] ) ) );
				try {
					do_action( $hook, json_decode( $row['payload_json'], true ), $row );
					$updated = $wpdb->update(
						$table,
						array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ), 'last_error' => '' ),
						array( 'id' => (int) $row['id'], 'status' => 'processing' ),
						array( '%s', '%s', '%s' ),
						array( '%d', '%s' )
					);
					if ( 1 !== $updated ) {
						throw new RuntimeException( 'The claimed event could not be finalized.' );
					}
				} catch ( Throwable $error ) {
					$attempts = (int) $row['attempts'] + 1;
					$status = $attempts >= 5 ? 'dead' : 'retry';
					$delay = min( 3600, 60 * ( 2 ** min( 5, $attempts ) ) );
					$wpdb->update(
						$table,
						array(
							'status'       => $status,
							'attempts'     => $attempts,
							'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
							'last_error'   => substr( sanitize_text_field( $error->getMessage() ), 0, 191 ),
						),
						array( 'id' => (int) $row['id'], 'status' => 'processing' ),
						array( '%s', '%d', '%s', '%s' ),
						array( '%d', '%s' )
					);
				}
			}
		} finally {
			self::release_dispatch_lock( $token );
		}
	}

	private static function sanitize_payload( array $payload ) {
		$result = array();
		foreach ( $payload as $key => $value ) {
			$key_text = (string) $key;
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|patient|message_body|payment|identity_document/i', $key_text ) ) {
				$result[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize_payload( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$result[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 1000 ) : $value;
			} else {
				$result[ $key ] = '[unsupported]';
			}
		}
		return $result;
	}

	private static function acquire_dispatch_lock() {
		$token = wp_generate_uuid4();
		$payload = array( 'token' => $token, 'created' => time() );
		if ( add_option( 'spf_outbox_dispatch_lock', $payload, '', 'no' ) ) {
			return $token;
		}
		$current = get_option( 'spf_outbox_dispatch_lock', array() );
		if ( is_array( $current ) && isset( $current['created'] ) && ( time() - (int) $current['created'] ) > 600 ) {
			delete_option( 'spf_outbox_dispatch_lock' );
			if ( add_option( 'spf_outbox_dispatch_lock', $payload, '', 'no' ) ) {
				return $token;
			}
		}
		return new WP_Error( 'spf_outbox_locked', __( 'The File 01 event dispatcher is already running.', 'sabri-platform-foundation' ) );
	}

	private static function release_dispatch_lock( $token ) {
		$current = get_option( 'spf_outbox_dispatch_lock', array() );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) {
			delete_option( 'spf_outbox_dispatch_lock' );
		}
	}
}

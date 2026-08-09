<?php
defined( 'ABSPATH' ) || exit;

/**
 * Low-level, File 01-owned runtime primitives.
 *
 * This class deliberately contains no domain business authority. It only offers
 * deterministic locking, canonical hashing, transaction checks and evidence
 * verification used by the canonical owners above it.
 */
final class SPF_Runtime {
	const LOCK_PREFIX = 'spf_lock_';

	public static function now_mysql() {
		return current_time( 'mysql', true );
	}

	public static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				return array_map( array( __CLASS__, 'canonicalize' ), $value );
			}
			ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			return self::canonicalize( get_object_vars( $value ) );
		}
		return $value;
	}

	public static function canonical_json( $value ) {
		return wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function hash( $value ) {
		return hash( 'sha256', self::canonical_json( $value ) );
	}

	public static function acquire_lock( $name, $ttl = 300, $owner = 0 ) {
		$name = sanitize_key( $name );
		if ( '' === $name ) {
			return new WP_Error( 'spf_lock_name_invalid', __( 'A valid File 01 lock name is required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$option = self::LOCK_PREFIX . $name;
		$token = wp_generate_uuid4();
		$ttl = max( 30, min( DAY_IN_SECONDS, absint( $ttl ) ) );
		$created = time();
		$payload = array(
			'token'   => $token,
			'created' => $created,
			'expires' => $created + $ttl,
			'ttl'     => $ttl,
			'owner'   => absint( $owner ),
		);
		if ( add_option( $option, $payload, '', 'no' ) ) {
			return $token;
		}
		$current = get_option( $option, array() );
		$current_expires = is_array( $current ) && isset( $current['expires'] ) ? (int) $current['expires'] : 0;
		if ( is_array( $current ) && isset( $current['created'], $current['token'] ) && wp_is_uuid( (string) $current['token'] ) && $current_expires <= 0 ) {
			// Legacy locks did not persist their own TTL. Use a conservative one-hour
			// safety window rather than allowing a contender's shorter TTL to steal it.
			$current_expires = (int) $current['created'] + HOUR_IN_SECONDS;
		}
		if ( is_array( $current ) && isset( $current['created'], $current['token'] ) && wp_is_uuid( (string) $current['token'] ) && $current_expires > 0 && time() >= $current_expires ) {
			// Stale takeover is compare-and-delete at the database row itself. An
			// unconditional delete_option() here could delete a newer owner's lock
			// if another worker replaced the stale value between read and delete.
			$latest = get_option( $option, array() );
			if ( $latest === $current && self::delete_lock_if_matches( $option, $current ) && add_option( $option, $payload, '', 'no' ) ) {
				return $token;
			}
		}
		return new WP_Error( 'spf_operation_locked', __( 'The requested File 01 operation is already running.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
	}

	public static function release_lock( $name, $token ) {
		$option = self::LOCK_PREFIX . sanitize_key( $name );
		$current = get_option( $option, array() );
		if ( is_array( $current ) && isset( $current['token'] ) && wp_is_uuid( (string) $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) {
			return self::delete_lock_if_matches( $option, $current );
		}
		return false;
	}

	private static function delete_lock_if_matches( $option, array $payload ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			$current = get_option( $option, null );
			return $current === $payload && function_exists( 'delete_option' ) ? (bool) delete_option( $option ) : false;
		}
		$deleted = $wpdb->delete(
			$wpdb->options,
			array( 'option_name' => $option, 'option_value' => maybe_serialize( $payload ) ),
			array( '%s', '%s' )
		);
		wp_cache_delete( $option, 'options' );
		return 1 === $deleted;
	}

	public static function begin() {
		global $wpdb;
		$result = $wpdb->query( 'START TRANSACTION' );
		return false === $result ? new WP_Error( 'spf_transaction_start_failed', __( 'A database transaction could not be started.', 'sabri-platform-foundation' ) ) : true;
	}

	public static function commit() {
		global $wpdb;
		$result = $wpdb->query( 'COMMIT' );
		return false === $result ? new WP_Error( 'spf_transaction_commit_failed', __( 'The database transaction could not be committed.', 'sabri-platform-foundation' ) ) : true;
	}

	public static function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	public static function table_exists( $table ) {
		global $wpdb;
		$table = is_string( $table ) ? trim( $table ) : '';
		if ( '' === $table ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
		return is_string( $found ) && hash_equals( $table, $found );
	}

	public static function table_engine( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ), ARRAY_A );
		return is_array( $row ) && ! empty( $row['ENGINE'] ) ? strtoupper( (string) $row['ENGINE'] ) : '';
	}

	public static function verify_owned_tables_transactional() {
		$failures = array();
		foreach ( SPF_Installer::table_names() as $name ) {
			$table = SPF_Installer::table( $name );
			if ( ! self::table_exists( $table ) ) {
				$failures[ $name ] = 'missing';
				continue;
			}
			$engine = self::table_engine( $table );
			if ( 'INNODB' !== $engine ) {
				$failures[ $name ] = $engine ? strtolower( $engine ) : 'unknown_engine';
			}
		}
		return empty( $failures ) ? true : new WP_Error( 'spf_non_transactional_schema', __( 'File 01 requires all owned tables to use InnoDB.', 'sabri-platform-foundation' ), array( 'tables' => $failures ) );
	}

	/**
	 * Verify a structured external evidence claim. A plain string/boolean never
	 * satisfies a destructive or production-grade evidence gate.
	 */
	public static function verify_evidence( $filter, array $context, array $required_fields ) {
		$claim = apply_filters( $filter, null, $context );
		if ( ! is_array( $claim ) || ! array_key_exists( 'verified', $claim ) || true !== $claim['verified'] ) {
			return new WP_Error( 'spf_evidence_unverified', __( 'Required external evidence has not been independently verified.', 'sabri-platform-foundation' ), array( 'status' => 412 ) );
		}
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $claim ) || '' === (string) $claim[ $field ] ) {
				return new WP_Error( 'spf_evidence_incomplete', sprintf( /* translators: %s evidence field */ __( 'Verified evidence is missing field: %s', 'sabri-platform-foundation' ), $field ), array( 'status' => 412 ) );
			}
			if ( str_ends_with( (string) $field, '_at' ) && false === strtotime( (string) $claim[ $field ] ) ) {
				return new WP_Error( 'spf_evidence_timestamp_invalid', sprintf( /* translators: %s evidence field */ __( 'Verified evidence contains an invalid timestamp field: %s', 'sabri-platform-foundation' ), $field ), array( 'status' => 412 ) );
			}
		}
		if ( ! empty( $claim['expires_at'] ) && strtotime( (string) $claim['expires_at'] ) <= time() ) {
			return new WP_Error( 'spf_evidence_expired', __( 'The external evidence verification has expired.', 'sabri-platform-foundation' ), array( 'status' => 412 ) );
		}
		$canonical = self::canonicalize( $claim );
		$encoded = wp_json_encode( $canonical );
		if ( false === $encoded || strlen( $encoded ) > 65536 ) {
			return new WP_Error( 'spf_evidence_oversized', __( 'The external evidence claim exceeds the bounded evidence envelope.', 'sabri-platform-foundation' ), array( 'status' => 412 ) );
		}
		$claim['evidence_hash'] = self::hash( $canonical );
		return $claim;
	}

	public static function is_list( array $array ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $array );
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}

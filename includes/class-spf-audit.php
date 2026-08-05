<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Audit {
	const LOCK_OPTION = 'spf_audit_chain_lock';

	public static function trace_id() {
		return wp_generate_uuid4();
	}

	public static function record( $action, $object_type, $object_id, $result, array $context = array(), $trace_id = '' ) {
		$written = self::write( $action, $object_type, $object_id, $result, $context, $trace_id );
		if ( is_wp_error( $written ) ) {
			do_action( 'spf_audit_failure', $written, $action, $object_type, $object_id );
			return $trace_id ? $trace_id : self::trace_id();
		}
		return $written;
	}

	public static function record_required( $action, $object_type, $object_id, $result, array $context = array(), $trace_id = '' ) {
		return self::write( $action, $object_type, $object_id, $result, $context, $trace_id );
	}

	private static function write( $action, $object_type, $object_id, $result, array $context, $trace_id ) {
		global $wpdb;
		$trace_id = $trace_id ? $trace_id : self::trace_id();
		$token = self::acquire_lock();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$table = SPF_Installer::table( 'audit' );
		$context = self::sanitize_context( $context );
		$context_hash = hash( 'sha256', wp_json_encode( $context ) );
		$previous_hash = (string) $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $previous_hash ) ) {
			$previous_hash = str_repeat( '0', 64 );
		}
		$created_at = current_time( 'mysql', true );
		$actor_id = get_current_user_id();
		$purpose = substr( sanitize_text_field( isset( $context['purpose'] ) ? $context['purpose'] : '' ), 0, 191 );
		$action_name = sanitize_key( $action );
		$object_type = sanitize_key( $object_type );
		$object_id = substr( sanitize_text_field( (string) $object_id ), 0, 191 );
		$result_code = substr( sanitize_key( $result ), 0, 64 );
		$entry_hash = hash( 'sha256', implode( '|', array( $previous_hash, $trace_id, $action_name, $object_type, $object_id, (string) $actor_id, $purpose, $result_code, $context_hash, $created_at ) ) );
		$ok = $wpdb->insert(
			$table,
			array(
				'trace_id'      => $trace_id,
				'action_name'   => $action_name,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'actor_id'      => $actor_id,
				'purpose'       => $purpose,
				'result_code'   => $result_code,
				'context_hash'  => $context_hash,
				'previous_hash' => $previous_hash,
				'entry_hash'    => $entry_hash,
				'created_at'    => $created_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		self::release_lock( $token );
		if ( false === $ok ) {
			return new WP_Error( 'spf_audit_write_failed', __( 'The mandatory audit record could not be written.', 'sabri-platform-foundation' ) );
		}
		do_action( 'spf_audit_recorded', $trace_id, $action, $object_type, $object_id, $result, $entry_hash );
		return $trace_id;
	}

	private static function sanitize_context( array $context ) {
		$deny = array( 'password', 'token', 'secret', 'authorization', 'cookie', 'nonce', 'sql', 'path', 'patient', 'message_body' );
		foreach ( $context as $key => $value ) {
			foreach ( $deny as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					$context[ $key ] = '[redacted]';
					continue 2;
				}
			}
			if ( is_scalar( $value ) || null === $value ) {
				$context[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
			} else {
				$context[ $key ] = '[structured]';
			}
		}
		return $context;
	}

	private static function acquire_lock() {
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$token = wp_generate_uuid4();
			$payload = array( 'token' => $token, 'created' => microtime( true ) );
			if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
				return $token;
			}
			$current = get_option( self::LOCK_OPTION, array() );
			if ( is_array( $current ) && isset( $current['created'] ) && ( microtime( true ) - (float) $current['created'] ) > 5 ) {
				delete_option( self::LOCK_OPTION );
				continue;
			}
			usleep( 50000 );
		}
		return new WP_Error( 'spf_audit_locked', __( 'The audit chain is temporarily busy.', 'sabri-platform-foundation' ) );
	}

	private static function release_lock( $token ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}

<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Audit {
	const LOCK_NAME = 'audit_chain';

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
		$trace_id = $trace_id ? sanitize_text_field( $trace_id ) : self::trace_id();
		$token = SPF_Runtime::acquire_lock( self::LOCK_NAME, 30, get_current_user_id() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		try {
			$table = SPF_Installer::table( 'audit' );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				return new WP_Error( 'spf_audit_table_missing', __( 'The mandatory audit table is unavailable.', 'sabri-platform-foundation' ) );
			}
			$context = self::sanitize_context( $context );
			$context_hash = SPF_Runtime::hash( $context );
			$previous_hash = (string) $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $previous_hash ) ) {
				$previous_hash = str_repeat( '0', 64 );
			}
			$created_at = SPF_Runtime::now_mysql();
			$actor_id = get_current_user_id();
			$purpose = substr( sanitize_text_field( $context['purpose'] ?? '' ), 0, 191 );
			$action_name = substr( sanitize_key( $action ), 0, 128 );
			$object_type = substr( sanitize_key( $object_type ), 0, 64 );
			$object_id = substr( sanitize_text_field( (string) $object_id ), 0, 191 );
			$result_code = substr( sanitize_key( $result ), 0, 64 );
			$entry_hash = self::entry_hash( $previous_hash, $trace_id, $action_name, $object_type, $object_id, $actor_id, $purpose, $result_code, $context_hash, $created_at );
			$ok = $wpdb->insert(
				$table,
				array(
					'trace_id'=>$trace_id,'action_name'=>$action_name,'object_type'=>$object_type,'object_id'=>$object_id,'actor_id'=>$actor_id,
					'purpose'=>$purpose,'result_code'=>$result_code,'context_hash'=>$context_hash,'previous_hash'=>$previous_hash,'entry_hash'=>$entry_hash,'created_at'=>$created_at,
				),
				array( '%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' )
			);
			if ( false === $ok ) {
				return new WP_Error( 'spf_audit_write_failed', __( 'The mandatory audit record could not be written.', 'sabri-platform-foundation' ) );
			}
			do_action( 'spf_audit_recorded', $trace_id, $action, $object_type, $object_id, $result, $entry_hash );
			return $trace_id;
		} finally {
			SPF_Runtime::release_lock( self::LOCK_NAME, $token );
		}
	}

	public static function verify_chain( $limit = 10000 ) {
		global $wpdb;
		$table = SPF_Installer::table( 'audit' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return new WP_Error( 'spf_audit_table_missing', __( 'Audit chain is unavailable.', 'sabri-platform-foundation' ) );
		}
		$limit = max( 1, min( 50000, absint( $limit ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
		$previous = str_repeat( '0', 64 );
		foreach ( $rows as $row ) {
			if ( ! hash_equals( $previous, (string) $row['previous_hash'] ) ) {
				return new WP_Error( 'spf_audit_chain_broken', __( 'Audit chain predecessor mismatch.', 'sabri-platform-foundation' ), array( 'row_id'=>(int)$row['id'] ) );
			}
			$expected = self::entry_hash( $row['previous_hash'], $row['trace_id'], $row['action_name'], $row['object_type'], $row['object_id'], (int)$row['actor_id'], $row['purpose'], $row['result_code'], $row['context_hash'], $row['created_at'] );
			if ( ! hash_equals( $expected, (string) $row['entry_hash'] ) ) {
				return new WP_Error( 'spf_audit_chain_broken', __( 'Audit chain entry hash mismatch.', 'sabri-platform-foundation' ), array( 'row_id'=>(int)$row['id'] ) );
			}
			$previous = $row['entry_hash'];
		}
		return array( 'verified'=>true,'rows'=>count($rows),'head'=>$previous );
	}

	private static function entry_hash( $previous_hash, $trace_id, $action_name, $object_type, $object_id, $actor_id, $purpose, $result_code, $context_hash, $created_at ) {
		return hash( 'sha256', implode( '|', array( $previous_hash,$trace_id,$action_name,$object_type,$object_id,(string)$actor_id,$purpose,$result_code,$context_hash,$created_at ) ) );
	}

	private static function sanitize_context( array $context ) {
		$result = array();
		foreach ( $context as $key => $value ) {
			$key = substr( sanitize_key( $key ), 0, 128 );
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|sql|path|patient|message_body|payment|identity_document/i', (string) $key ) ) {
				$result[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize_context( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$result[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$result[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 500 );
			} else {
				$result[ $key ] = '[unsupported]';
			}
		}
		return SPF_Runtime::canonicalize( $result );
	}
}

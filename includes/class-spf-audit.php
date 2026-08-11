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
			return $trace_id && wp_is_uuid( $trace_id ) ? $trace_id : self::trace_id();
		}
		return $written;
	}

	public static function record_required( $action, $object_type, $object_id, $result, array $context = array(), $trace_id = '' ) {
		return self::write( $action, $object_type, $object_id, $result, $context, $trace_id );
	}

	private static function write( $action, $object_type, $object_id, $result, array $context, $trace_id ) {
		global $wpdb;
		if ( $trace_id && ! wp_is_uuid( $trace_id ) ) {
			return new WP_Error( 'spf_audit_trace_invalid', __( 'The audit trace identifier is invalid.', 'sabri-platform-foundation' ) );
		}
		$trace_id = $trace_id ? strtolower( sanitize_text_field( $trace_id ) ) : self::trace_id();
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
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$context_hash = SPF_Runtime::hash( $context );
			$previous_raw = $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
			if ( ! empty( $wpdb->last_error ) ) {
				return new WP_Error( 'spf_audit_head_read_failed', __( 'The audit chain head could not be read safely.', 'sabri-platform-foundation' ) );
			}
			if ( null === $previous_raw || '' === (string) $previous_raw ) {
				$previous_hash = str_repeat( '0', 64 );
			} elseif ( preg_match( '/^[a-f0-9]{64}$/', (string) $previous_raw ) ) {
				$previous_hash = (string) $previous_raw;
			} else {
				return new WP_Error( 'spf_audit_chain_head_invalid', __( 'The audit chain head is malformed; a new entry was not appended.', 'sabri-platform-foundation' ) );
			}
			$created_at = SPF_Runtime::now_mysql();
			$actor_id = get_current_user_id();
			$raw_purpose = (string) ( $context['purpose'] ?? '' );
			$raw_action = (string) $action;
			$raw_object_type = (string) $object_type;
			$raw_object_id = (string) $object_id;
			$raw_result = (string) $result;
			$purpose = sanitize_text_field( $raw_purpose );
			$action_name = sanitize_key( $raw_action );
			$object_type = sanitize_key( $raw_object_type );
			$object_id = sanitize_text_field( $raw_object_id );
			$result_code = sanitize_key( $raw_result );
			if (
				'' === $action_name || '' === $object_type || '' === $result_code ||
				$raw_action !== $action_name || $raw_object_type !== $object_type || $raw_object_id !== $object_id || $raw_result !== $result_code || $raw_purpose !== $purpose ||
				strlen( $action_name ) > 128 || strlen( $object_type ) > 64 || strlen( $object_id ) > 191 || strlen( $result_code ) > 64 || strlen( $purpose ) > 191
			) {
				return new WP_Error( 'spf_audit_record_invalid', __( 'Mandatory audit identities and purpose must already be canonical and within their bounded storage envelope; silent normalization or truncation is forbidden.', 'sabri-platform-foundation' ) );
			}
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

	/**
	 * Verify the complete chain up to the requested hard ceiling. Returning a
	 * partial prefix as "verified" would be misleading, so oversized chains fail
	 * closed and must be verified with an explicitly larger bounded ceiling.
	 */
	public static function verify_chain( $limit = 10000 ) {
		global $wpdb;
		$table = SPF_Installer::table( 'audit' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return new WP_Error( 'spf_audit_table_missing', __( 'Audit chain is unavailable.', 'sabri-platform-foundation' ) );
		}
		$limit = max( 1, min( 1000000, absint( $limit ) ) );
		$total_raw = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
		if ( ! empty( $wpdb->last_error ) || null === $total_raw ) {
			return new WP_Error( 'spf_audit_verification_query_failed', __( 'Audit chain row count could not be verified.', 'sabri-platform-foundation' ) );
		}
		$total = (int) $total_raw;
		if ( $total > $limit ) {
			return new WP_Error( 'spf_audit_verification_incomplete', __( 'The audit chain exceeds the explicitly bounded verification ceiling; no partial verification claim was made.', 'sabri-platform-foundation' ), array( 'rows'=>$total, 'limit'=>$limit ) );
		}
		$previous = str_repeat( '0', 64 );
		$last_id = 0;
		$verified_rows = 0;
		$batch_size = 5000;
		while ( $verified_rows < $total ) {
			$batch = min( $batch_size, $total - $verified_rows );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id>%d ORDER BY id ASC LIMIT %d", $last_id, $batch ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) || count( $rows ) !== $batch ) {
				return new WP_Error( 'spf_audit_verification_query_failed', __( 'Audit chain rows could not be read completely for paged verification.', 'sabri-platform-foundation' ) );
			}
			foreach ( $rows as $row ) {
				if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $row['previous_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $row['entry_hash'] ) || ! wp_is_uuid( (string) $row['trace_id'] ) ) {
					return new WP_Error( 'spf_audit_chain_broken', __( 'Audit chain contains malformed integrity fields.', 'sabri-platform-foundation' ), array( 'row_id'=>(int)$row['id'] ) );
				}
				if ( ! hash_equals( $previous, (string) $row['previous_hash'] ) ) {
					return new WP_Error( 'spf_audit_chain_broken', __( 'Audit chain predecessor mismatch.', 'sabri-platform-foundation' ), array( 'row_id'=>(int)$row['id'] ) );
				}
				$expected = self::entry_hash( $row['previous_hash'], $row['trace_id'], $row['action_name'], $row['object_type'], $row['object_id'], (int)$row['actor_id'], $row['purpose'], $row['result_code'], $row['context_hash'], $row['created_at'] );
				if ( ! hash_equals( $expected, (string) $row['entry_hash'] ) ) {
					return new WP_Error( 'spf_audit_chain_broken', __( 'Audit chain entry hash mismatch.', 'sabri-platform-foundation' ), array( 'row_id'=>(int)$row['id'] ) );
				}
				$previous = $row['entry_hash'];
				$last_id = (int) $row['id'];
				$verified_rows++;
			}
		}
		$stored_head_raw = $wpdb->get_var( "SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'spf_audit_verification_query_failed', __( 'Audit chain head could not be re-read after verification.', 'sabri-platform-foundation' ) );
		}
		$stored_head = (string) $stored_head_raw;
		if ( $total > 0 && ! hash_equals( $previous, $stored_head ) ) {
			return new WP_Error( 'spf_audit_chain_head_mismatch', __( 'Audit chain verification did not reach the stored head.', 'sabri-platform-foundation' ) );
		}
		return array( 'verified'=>true, 'complete'=>true, 'rows'=>$verified_rows, 'head'=>$previous, 'batch_size'=>$batch_size, 'ceiling'=>$limit );
	}

	private static function entry_hash( $previous_hash, $trace_id, $action_name, $object_type, $object_id, $actor_id, $purpose, $result_code, $context_hash, $created_at ) {
		return hash( 'sha256', implode( '|', array( $previous_hash,$trace_id,$action_name,$object_type,$object_id,(string)$actor_id,$purpose,$result_code,$context_hash,$created_at ) ) );
	}

	private static function sanitize_context( array $context, $depth = 0 ) {
		if ( $depth > 5 ) {
			return new WP_Error( 'spf_audit_context_too_deep', __( 'Audit context nesting exceeds the bounded evidence envelope.', 'sabri-platform-foundation' ) );
		}
		if ( count( $context ) > 100 ) {
			return new WP_Error( 'spf_audit_context_too_large', __( 'Audit context exceeds the bounded evidence envelope.', 'sabri-platform-foundation' ) );
		}
		$result = array();
		foreach ( $context as $key => $value ) {
			$raw_key = (string) $key;
			$safe_key = sanitize_key( $raw_key );
			if ( '' === $safe_key || $raw_key !== $safe_key || strlen( $safe_key ) > 128 || array_key_exists( $safe_key, $result ) ) {
				return new WP_Error( 'spf_audit_context_key_invalid', __( 'Audit context keys must already be unique canonical keys within the bounded envelope.', 'sabri-platform-foundation' ) );
			}
			if ( preg_match( '/password|token|secret|authorization|cookie|nonce|sql|path|patient|message|payment|identity|document|private|credential|key/i', $safe_key ) ) {
				$result[ $safe_key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$nested = self::sanitize_context( $value, $depth + 1 );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$result[ $safe_key ] = $nested;
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$result[ $safe_key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$raw_scalar = (string) $value;
				$scalar = sanitize_text_field( $raw_scalar );
				if ( $raw_scalar !== $scalar ) {
					return new WP_Error( 'spf_audit_context_value_invalid', __( 'Audit context scalar evidence must already be canonical; silent normalization is forbidden.', 'sabri-platform-foundation' ) );
				}
				if ( strlen( $scalar ) > 500 ) {
					return new WP_Error( 'spf_audit_context_value_too_large', __( 'Audit context scalar evidence exceeds the bounded envelope.', 'sabri-platform-foundation' ) );
				}
				$result[ $safe_key ] = $scalar;
			} else {
				return new WP_Error( 'spf_audit_context_value_invalid', __( 'Audit context contains an unsupported value type.', 'sabri-platform-foundation' ) );
			}
		}
		return SPF_Runtime::canonicalize( $result );
	}

}

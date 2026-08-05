<?php
defined( 'ABSPATH' ) || exit;

/**
 * Atomic request reservation and replay for REST/command mutations.
 */
final class SPF_Idempotency {
	public static function execute( WP_REST_Request $request, $action, callable $callback ) {
		global $wpdb;
		$key = trim( (string) $request->get_header( 'X-Idempotency-Key' ) );
		if ( strlen( $key ) < 16 || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $key ) ) {
			return new WP_Error( 'spf_idempotency_required', __( 'A valid 16–191 character X-Idempotency-Key is required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$actor = get_current_user_id();
		$action = sanitize_key( $action );
		$body = $request->get_json_params();
		if ( null === $body ) {
			$body = $request->get_body_params();
		}
		$request_hash = SPF_Runtime::hash(
			array(
				'route'  => $request->get_route(),
				'method' => strtoupper( $request->get_method() ),
				'body'   => $body,
			)
		);
		$scope_hash = hash( 'sha256', $actor . '|' . $action . '|' . $key );
		$table = SPF_Installer::table( 'idempotency' );
		$token = wp_generate_uuid4();
		$now = SPF_Runtime::now_mysql();
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$inserted = $wpdb->insert(
			$table,
			array(
				'idempotency_key' => $key,
				'scope_hash'      => $scope_hash,
				'actor_id'        => $actor,
				'action_name'     => $action,
				'request_hash'    => $request_hash,
				'status'          => 'processing',
				'owner_token'     => $token,
				'response_json'   => '',
				'response_status' => 0,
				'attempts'        => 1,
				'locked_at'       => $now,
				'expires_at'      => $expires,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s' )
		);

		if ( false === $inserted ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope_hash=%s", $scope_hash ), ARRAY_A );
			if ( ! $existing ) {
				return new WP_Error( 'spf_idempotency_store_failed', __( 'The idempotency reservation could not be created.', 'sabri-platform-foundation' ), array( 'status' => 503 ) );
			}
			if ( ! hash_equals( (string) $existing['request_hash'], $request_hash ) ) {
				return new WP_Error( 'spf_idempotency_conflict', __( 'The idempotency key was reused with a different request.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
			}
			if ( 'completed' === $existing['status'] || 'failed' === $existing['status'] ) {
				return self::replay( $existing );
			}
			$stale = strtotime( (string) $existing['locked_at'] ) < time() - 300 || strtotime( (string) $existing['expires_at'] ) <= time();
			if ( ! $stale ) {
				return new WP_Error( 'spf_idempotency_in_progress', __( 'An identical request is still processing.', 'sabri-platform-foundation' ), array( 'status' => 409, 'retry_after' => 2 ) );
			}
			$claimed = $wpdb->update(
				$table,
				array( 'status'=>'processing','owner_token'=>$token,'locked_at'=>$now,'expires_at'=>$expires,'attempts'=>(int)$existing['attempts']+1,'updated_at'=>$now ),
				array( 'id'=>(int)$existing['id'],'owner_token'=>$existing['owner_token'],'status'=>$existing['status'] ),
				array( '%s','%s','%s','%s','%d','%s' ), array( '%d','%s','%s' )
			);
			if ( 1 !== $claimed ) {
				return new WP_Error( 'spf_idempotency_in_progress', __( 'Another worker claimed the identical request.', 'sabri-platform-foundation' ), array( 'status' => 409, 'retry_after' => 2 ) );
			}
		}

		if ( ! self::rate_limit( $actor, $action ) ) {
			self::finalize_error( $scope_hash, $token, new WP_Error( 'spf_rate_limited', __( 'Too many foundation mutations.', 'sabri-platform-foundation' ), array( 'status' => 429 ) ) );
			return new WP_Error( 'spf_rate_limited', __( 'Too many foundation mutations.', 'sabri-platform-foundation' ), array( 'status' => 429 ) );
		}

		try {
			$result = $callback();
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'spf_mutation_exception', $error->getMessage(), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $result ) ) {
			self::finalize_error( $scope_hash, $token, $result );
			return $result;
		}
		$response = array( 'success'=>true, 'result'=>$result, 'trace_id'=>SPF_Audit::trace_id() );
		$updated = $wpdb->update(
			$table,
			array( 'status'=>'completed','response_json'=>wp_json_encode($response),'response_status'=>200,'updated_at'=>SPF_Runtime::now_mysql() ),
			array( 'scope_hash'=>$scope_hash,'owner_token'=>$token,'status'=>'processing' ),
			array( '%s','%s','%d','%s' ), array( '%s','%s','%s' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'spf_idempotency_finalize_failed', __( 'The mutation completed but its replay record could not be finalized; reconciliation is required.', 'sabri-platform-foundation' ), array( 'status' => 503, 'scope_hash' => $scope_hash ) );
		}
		return rest_ensure_response( $response );
	}

	private static function finalize_error( $scope_hash, $token, WP_Error $error ) {
		global $wpdb;
		$data = $error->get_error_data();
		$status = is_array( $data ) && ! empty( $data['status'] ) ? absint( $data['status'] ) : 400;
		$payload = array(
			'success' => false,
			'error' => array(
				'code' => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data' => self::safe_error_data( $data ),
			),
		);
		$wpdb->update(
			SPF_Installer::table( 'idempotency' ),
			array( 'status'=>'failed','response_json'=>wp_json_encode($payload),'response_status'=>$status,'updated_at'=>SPF_Runtime::now_mysql() ),
			array( 'scope_hash'=>$scope_hash,'owner_token'=>$token,'status'=>'processing' ),
			array( '%s','%s','%d','%s' ), array( '%s','%s','%s' )
		);
	}

	private static function replay( array $row ) {
		$payload = json_decode( $row['response_json'], true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'spf_idempotency_corrupt', __( 'The stored idempotency result is invalid.', 'sabri-platform-foundation' ), array( 'status' => 500 ) );
		}
		if ( 'failed' === $row['status'] || empty( $payload['success'] ) ) {
			$error = $payload['error'] ?? array();
			$data = is_array( $error['data'] ?? null ) ? $error['data'] : array();
			$data['status'] = absint( $row['response_status'] ?: 400 );
			$data['idempotent_replay'] = true;
			return new WP_Error( sanitize_key( $error['code'] ?? 'spf_replayed_failure' ), sanitize_text_field( $error['message'] ?? __( 'The original request failed.', 'sabri-platform-foundation' ) ), $data );
		}
		$payload['idempotent_replay'] = true;
		return rest_ensure_response( $payload );
	}

	private static function safe_error_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( preg_match( '/password|token|secret|cookie|nonce|authorization|patient|message|payment/i', (string) $key ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
			}
		}
		return $out;
	}

	private static function rate_limit( $actor, $action ) {
		$key = 'spf_rl_' . md5( $actor . '|' . $action . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, 90 );
		return true;
	}
}

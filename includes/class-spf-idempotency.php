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
		if ( $actor < 1 || ! is_user_logged_in() ) {
			return new WP_Error( 'spf_idempotency_actor_required', __( 'An authenticated actor is required for a foundation mutation.', 'sabri-platform-foundation' ), array( 'status' => 401 ) );
		}
		$action = sanitize_key( $action );
		if ( '' === $action ) {
			return new WP_Error( 'spf_idempotency_action_required', __( 'A valid mutation action is required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$body = $request->get_json_params();
		if ( null === $body ) {
			$body = $request->get_body_params();
		}
		$request_hash = SPF_Runtime::hash(
			array(
				'actor'  => $actor,
				'action' => $action,
				'route'  => $request->get_route(),
				'method' => strtoupper( $request->get_method() ),
				'query'  => $request->get_query_params(),
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
			if ( (int) $existing['actor_id'] !== $actor || sanitize_key( $existing['action_name'] ) !== $action || ! hash_equals( (string) $existing['request_hash'], $request_hash ) ) {
				return new WP_Error( 'spf_idempotency_conflict', __( 'The idempotency key was reused outside its original actor/action/request scope.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
			}
			if ( 'completed' === $existing['status'] || 'failed' === $existing['status'] ) {
				return self::replay( $existing );
			}
			$locked_at = strtotime( (string) $existing['locked_at'] );
			$expires_at = strtotime( (string) $existing['expires_at'] );
			$stale = ! $locked_at || $locked_at < time() - 300 || ! $expires_at || $expires_at <= time();
			if ( ! $stale ) {
				return new WP_Error( 'spf_idempotency_in_progress', __( 'An identical request is still processing.', 'sabri-platform-foundation' ), array( 'status' => 409, 'retry_after' => 2 ) );
			}
			$claimed = $wpdb->update(
				$table,
				array( 'status'=>'processing','owner_token'=>$token,'locked_at'=>$now,'expires_at'=>$expires,'attempts'=>(int)$existing['attempts']+1,'updated_at'=>$now ),
				array( 'id'=>(int)$existing['id'],'actor_id'=>$actor,'request_hash'=>$request_hash,'owner_token'=>$existing['owner_token'],'status'=>$existing['status'] ),
				array( '%s','%s','%s','%s','%d','%s' ), array( '%d','%d','%s','%s','%s' )
			);
			if ( 1 !== $claimed ) {
				return new WP_Error( 'spf_idempotency_in_progress', __( 'Another worker claimed the identical request.', 'sabri-platform-foundation' ), array( 'status' => 409, 'retry_after' => 2 ) );
			}
		}

		if ( ! self::rate_limit( $actor, $action ) ) {
			$error = new WP_Error( 'spf_rate_limited', __( 'Too many foundation mutations.', 'sabri-platform-foundation' ), array( 'status' => 429 ) );
			self::finalize_error( $scope_hash, $token, $error );
			return $error;
		}

		try {
			$result = $callback();
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'spf_mutation_exception', __( 'The foundation mutation failed unexpectedly.', 'sabri-platform-foundation' ), array( 'status' => 500, 'exception_class' => get_class( $error ) ) );
		}
		if ( is_wp_error( $result ) ) {
			self::finalize_error( $scope_hash, $token, $result );
			return $result;
		}
		$response = array( 'success'=>true, 'result'=>$result, 'trace_id'=>SPF_Audit::trace_id() );
		$updated = $wpdb->update(
			$table,
			array( 'status'=>'completed','response_json'=>wp_json_encode($response),'response_status'=>200,'updated_at'=>SPF_Runtime::now_mysql() ),
			array( 'scope_hash'=>$scope_hash,'actor_id'=>$actor,'request_hash'=>$request_hash,'owner_token'=>$token,'status'=>'processing' ),
			array( '%s','%s','%d','%s' ), array( '%s','%d','%s','%s','%s' )
		);
		if ( 1 !== $updated ) {
			$receipt = hash( 'sha256', $scope_hash . '|' . $request_hash . '|' . $token );
			SPF_Audit::record( 'idempotency_finalize_conflict', 'foundation_mutation', $receipt, 'failed', array( 'purpose'=>'idempotency_reconciliation','action'=>$action ) );
			return new WP_Error( 'spf_idempotency_finalize_failed', __( 'The mutation completed but its replay record could not be finalized; reconciliation is required.', 'sabri-platform-foundation' ), array( 'status' => 503, 'recovery_receipt' => $receipt ) );
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
				'code' => sanitize_key( $error->get_error_code() ),
				'message' => sanitize_text_field( $error->get_error_message() ),
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

	private static function safe_error_data( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 5 ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $data, 0, 100, true ) as $key => $value ) {
			$key = substr( sanitize_key( (string) $key ), 0, 128 );
			if ( preg_match( '/password|token|secret|cookie|nonce|authorization|patient|message|payment|identity|document|key/i', $key ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::safe_error_data( $value, $depth + 1 );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
			}
		}
		return $out;
	}

	private static function rate_limit( $actor, $action ) {
		$bucket = gmdate( 'YmdHi' );
		$key = 'spf_rl_' . md5( $actor . '|' . $action . '|' . $bucket );
		$lock_name = 'rate_' . substr( hash( 'sha256', $actor . '|' . $action ), 0, 32 );
		$lock = SPF_Runtime::acquire_lock( $lock_name, 30, $actor );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$count = (int) get_transient( $key );
			if ( $count >= 30 ) {
				return false;
			}
			return set_transient( $key, $count + 1, 90 );
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
	}
}

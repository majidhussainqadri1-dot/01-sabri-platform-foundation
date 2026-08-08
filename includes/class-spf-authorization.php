<?php
defined( 'ABSPATH' ) || exit;

/**
 * File 01 authorization adapter.
 *
 * File 00 remains the canonical authority. File 01 accepts only versioned,
 * short-lived, actor/object/purpose-bound structured claims for protected
 * operations. WordPress roles provide bootstrap viewing/limited File 01
 * maintenance only and never grant Founder, release-approval or purge authority.
 */
final class SPF_Authorization {
	const CAP_VIEW    = 'view_sabri_foundation';
	const CAP_MANAGE  = 'manage_sabri_foundation';
	const CAP_RELEASE = 'release_sabri_foundation';
	const CAP_FOUNDER = 'govern_sabri_foundation';
	const CAP_PURGE   = 'purge_sabri_foundation';

	private const SENSITIVE_ACTIONS = array(
		'record_release',
		'transition_release',
		'approve_release',
		'deploy_release',
		'approve_amendment',
		'purge',
		'production_cutover',
		'run_reconciliation',
		'run_schema_upgrade',
	);

	public static function install_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAP_VIEW );
			$role->add_cap( self::CAP_MANAGE );
		}
	}

	public static function remove_bootstrap_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( self::CAP_VIEW );
			$role->remove_cap( self::CAP_MANAGE );
		}
	}

	public static function can( $action, $object = null, array $context = array() ) {
		$action  = sanitize_key( $action );
		$user_id = get_current_user_id();

		$policy = apply_filters( 'spf_authorization_policy_decision', null, $action, $object, $user_id, $context );
		if ( false === $policy ) {
			return false;
		}

		$required       = self::required_capability( $action );
		$file00_present = self::file00_present();
		$object_ref     = self::object_reference( $object );
		$purpose        = sanitize_key( $context['purpose'] ?? $action );
		$claim = apply_filters(
			'spf_file00_authorization_claim',
			null,
			array(
				'user_id'      => $user_id,
				'actor_id'     => $user_id,
				'action'       => $action,
				'capability'   => $required,
				'object'       => $object_ref,
				'object_hash'  => SPF_Runtime::hash( $object_ref ),
				'purpose'      => $purpose,
				'plugin'       => 'file-01',
				'contract'     => SPF_CONTRACT_VERSION,
				'current_time' => time(),
			)
		);

		if ( is_array( $claim ) ) {
			return self::validate_claim( $claim, $user_id, $action, $required, $object, $context );
		}

		// Legacy booleans remain a migration bridge only for non-sensitive
		// read/maintenance operations. Sensitive operations always fail closed.
		if ( ! in_array( $action, self::SENSITIVE_ACTIONS, true ) ) {
			$legacy = apply_filters( 'spf_file00_capability_claim', null, $required, $user_id, $action, $object );
			if ( is_bool( $legacy ) ) {
				return $legacy;
			}
		}

		if ( $file00_present ) {
			return false;
		}

		return self::bootstrap_allowed( $action, $required, $object );
	}

	public static function require_action( $action, $object = null, array $context = array() ) {
		if ( ! self::can( $action, $object, $context ) ) {
			return new WP_Error( 'spf_forbidden', __( 'You are not authorized to perform this foundation action.', 'sabri-platform-foundation' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_permission( WP_REST_Request $request ) {
		$action = $request->get_attribute( 'spf_action' );
		$object = $request->get_attribute( 'spf_object' );
		if ( ! $action ) {
			$action = 'view';
		}
		return self::can( $action, $object, array( 'purpose' => 'rest_' . sanitize_key( $action ) ) );
	}

	public static function required_capability( $action ) {
		$action = sanitize_key( $action );
		if ( in_array( $action, array( 'view', 'system_check' ), true ) ) {
			return self::CAP_VIEW;
		}
		if ( in_array( $action, array( 'record_release', 'transition_release', 'run_reconciliation', 'run_schema_upgrade' ), true ) ) {
			return self::CAP_RELEASE;
		}
		if ( in_array( $action, array( 'approve_release', 'deploy_release', 'approve_amendment', 'production_cutover' ), true ) ) {
			return self::CAP_FOUNDER;
		}
		if ( 'purge' === $action ) {
			return self::CAP_PURGE;
		}
		return self::CAP_MANAGE;
	}

	public static function validate_claim( array $claim, $user_id, $action, $required, $object = null, array $context = array() ) {
		$required_fields = array(
			'claim_version', 'allowed', 'user_id', 'action', 'capability', 'issued_at',
			'expires_at', 'claim_id', 'object_hash', 'purpose', 'institutional_role',
		);
		foreach ( $required_fields as $field ) {
			if ( ! array_key_exists( $field, $claim ) ) {
				return false;
			}
		}
		if ( ! is_bool( $claim['allowed'] ) || true !== $claim['allowed'] ) {
			return false;
		}
		if ( ! SPF_Registry::valid_semver( (string) $claim['claim_version'] ) || version_compare( (string) $claim['claim_version'], '1.0.0', '<' ) ) {
			return false;
		}
		$claim_id = trim( (string) $claim['claim_id'] );
		if ( '' === $claim_id || strlen( $claim_id ) > 191 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $claim_id ) ) {
			return false;
		}
		if ( absint( $claim['user_id'] ) !== absint( $user_id ) || sanitize_key( $claim['action'] ) !== sanitize_key( $action ) ) {
			return false;
		}
		if ( array_key_exists( 'actor_id', $claim ) && absint( $claim['actor_id'] ) !== absint( $user_id ) ) {
			return false;
		}
		if ( sanitize_key( $claim['capability'] ) !== sanitize_key( $required ) ) {
			return false;
		}
		$issued  = is_numeric( $claim['issued_at'] ) ? (int) $claim['issued_at'] : strtotime( (string) $claim['issued_at'] );
		$expires = is_numeric( $claim['expires_at'] ) ? (int) $claim['expires_at'] : strtotime( (string) $claim['expires_at'] );
		if ( ! $issued || ! $expires || $issued > time() + 60 || $issued < time() - 900 || $expires <= time() || ( $expires - $issued ) > 900 ) {
			return false;
		}
		if ( ! empty( $claim['suspended'] ) || ! empty( $claim['revoked'] ) ) {
			return false;
		}
		$expected_hash = SPF_Runtime::hash( self::object_reference( $object ) );
		$object_hash   = strtolower( (string) $claim['object_hash'] );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $object_hash ) || ! hash_equals( $expected_hash, $object_hash ) ) {
			return false;
		}
		$expected_purpose = sanitize_key( $context['purpose'] ?? $action );
		if ( '' === $expected_purpose || sanitize_key( $claim['purpose'] ) !== $expected_purpose ) {
			return false;
		}
		$institutional_role = sanitize_key( $claim['institutional_role'] );
		if ( '' === $institutional_role ) {
			return false;
		}
		if ( in_array( $action, array( 'approve_release', 'deploy_release', 'approve_amendment', 'purge', 'production_cutover' ), true ) && 'founder' !== $institutional_role ) {
			return false;
		}
		if ( in_array( $action, array( 'record_release', 'transition_release', 'run_reconciliation', 'run_schema_upgrade' ), true ) && ! in_array( $institutional_role, array( 'release_operator', 'founder' ), true ) ) {
			return false;
		}
		return true;
	}

	private static function bootstrap_allowed( $action, $required, $object ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( in_array( $action, self::SENSITIVE_ACTIONS, true ) ) {
			return false;
		}
		if ( in_array( $action, array( 'view', 'system_check' ), true ) ) {
			return current_user_can( self::CAP_VIEW ) || current_user_can( self::CAP_MANAGE );
		}
		$object_ref = self::object_reference( $object );
		$object_key = sanitize_key( $object_ref['module_key'] ?? $object_ref['owner_module'] ?? $object_ref['object_id'] ?? '' );
		if ( $object_key && 'file-01' !== $object_key ) {
			return false;
		}
		return self::CAP_MANAGE === $required && current_user_can( self::CAP_MANAGE );
	}

	private static function file00_present() {
		return defined( 'SMC_VERSION' ) || class_exists( 'SMC_Plugin', false ) || function_exists( 'smc_membership_contract' ) || has_filter( 'spf_file00_authorization_claim' );
	}

	private static function object_reference( $object ) {
		if ( is_array( $object ) ) {
			return SPF_Runtime::canonicalize( $object );
		}
		if ( is_object( $object ) ) {
			return SPF_Runtime::canonicalize( get_object_vars( $object ) );
		}
		return array( 'object_id' => substr( sanitize_text_field( (string) $object ), 0, 191 ) );
	}
}

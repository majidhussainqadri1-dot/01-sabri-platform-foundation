<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Authorization {
	const CAP_MANAGE  = 'manage_sabri_foundation';
	const CAP_RELEASE = 'release_sabri_foundation';
	const CAP_PURGE   = 'purge_sabri_foundation';

	public static function install_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAP_MANAGE );
			$role->add_cap( self::CAP_RELEASE );
			$role->add_cap( self::CAP_PURGE );
		}
	}

	public static function can( $action, $object = null ) {
		$user_id = get_current_user_id();
		$decision = apply_filters( 'spf_authorize_action', null, $action, $object, $user_id );
		if ( is_bool( $decision ) ) {
			return $decision;
		}

		$required = self::CAP_MANAGE;
		if ( in_array( $action, array( 'record_release', 'approve_amendment' ), true ) ) {
			$required = self::CAP_RELEASE;
		}
		if ( 'purge' === $action ) {
			$required = self::CAP_PURGE;
		}

		$claim = apply_filters( 'spf_file00_capability_claim', null, $required, $user_id, $action, $object );
		if ( is_bool( $claim ) ) {
			return $claim;
		}

		$file00_present = defined( 'SMC_VERSION' ) || class_exists( 'SMC_Plugin', false ) || function_exists( 'smc_membership_contract' );
		if ( $file00_present && ! in_array( $action, array( 'view', 'system_check' ), true ) ) {
			return false;
		}

		return current_user_can( $required ) || current_user_can( 'manage_options' );
	}

	public static function require_action( $action, $object = null ) {
		if ( ! self::can( $action, $object ) ) {
			return new WP_Error( 'spf_forbidden', __( 'You are not authorized to perform this foundation action.', 'sabri-platform-foundation' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_permission( WP_REST_Request $request ) {
		$action = $request->get_attribute( 'spf_action' );
		if ( ! $action ) {
			$action = 'view';
		}
		return self::can( $action );
	}
}

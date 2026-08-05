<?php
defined( 'ABSPATH' ) || exit;

final class SPF_System_Check {
	public static function run( $persist = true ) {
		global $wpdb;
		$trace = SPF_Audit::trace_id();
		$checks = array();

		$checks[] = self::check( 'php_version', version_compare( PHP_VERSION, '8.1.0', '>=' ), PHP_VERSION, 'PHP 8.1 or newer is required.' );
		$checks[] = self::check( 'wordpress_version', version_compare( get_bloginfo( 'version' ), '6.0', '>=' ), get_bloginfo( 'version' ), 'WordPress 6.0 or newer is required.' );
		$checks[] = self::check( 'database_connection', ! empty( $wpdb->dbh ), 'connected', 'Database connection is unavailable.' );
		$checks[] = self::check( 'database_charset', false !== stripos( (string) $wpdb->get_charset_collate(), 'utf8' ), 'utf8-compatible', 'Database character set should be UTF-8 compatible.' );
		$checks[] = self::check( 'cron', ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON, defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled', 'WP-Cron is disabled; an external scheduler must be documented.' );
		$checks[] = self::check( 'rewrite_rules', is_array( get_option( 'rewrite_rules', array() ) ), 'present', 'Rewrite rules are missing.' );
		$checks[] = self::check( 'object_cache', true, wp_using_ext_object_cache() ? 'persistent' : 'wordpress-default', 'Object cache state could not be determined.' );
		$checks[] = self::check( 'mail_configuration', (bool) get_option( 'admin_email' ), 'configured-address', 'No administrative mail address is configured.' );
		$upload = wp_upload_dir();
		$upload_path = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
		$writable = $upload_path && ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $upload_path ) : is_writable( $upload_path ) );
		$checks[] = self::check( 'uploads', $writable, $writable ? 'writable' : 'not-writable', 'Uploads directory is not writable.' );
		$checks[] = self::check( 'file00_contract', has_filter( 'spf_file00_capability_claim' ) || function_exists( 'smc_membership_contract' ), has_filter( 'spf_file00_capability_claim' ) ? 'adapter-filter' : ( function_exists( 'smc_membership_contract' ) ? 'native-function' : 'unavailable' ), 'File 00 capability assertions are unavailable; privileged runtime must remain bootstrap-limited.' );
		$checks[] = self::check( 'file20_contract', function_exists( 'sabri_shell_register_route' ) || has_action( 'sabri_shell_register_provider' ), function_exists( 'sabri_shell_register_route' ) ? 'route-api' : ( has_action( 'sabri_shell_register_provider' ) ? 'provider-hook' : 'unavailable' ), 'File 20 shell contract is unavailable; no public cutover is authorized.' );

		foreach ( array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' ) as $name ) {
			$table = SPF_Installer::table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$checks[] = self::check( 'table_' . $name, $exists, $exists ? 'present' : 'missing', 'A File 01-owned table is missing.' );
		}

		$overall = 'pass';
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				$overall = 'fail';
				break;
			}
			if ( 'warning' === $check['status'] && 'pass' === $overall ) {
				$overall = 'warning';
			}
		}
		$result = array(
			'trace_id'       => $trace,
			'overall_status' => $overall,
			'checks'         => $checks,
			'checked_at'     => current_time( 'mysql', true ),
			'redaction'      => 'No secrets, paths, SQL, credentials or personal data are included.',
		);
		if ( $persist ) {
			$wpdb->insert(
				SPF_Installer::table( 'health' ),
				array(
					'trace_id'       => $trace,
					'overall_status' => $overall,
					'results_json'   => wp_json_encode( $result ),
					'created_at'     => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s' )
			);
			SPF_Audit::record( 'system_check', 'foundation_health', $trace, $overall, array( 'purpose' => 'operational_health' ), $trace );
			SPF_Event_Bus::publish( 'FoundationHealthChanged.v1', 'foundation_health', $trace, array( 'status' => $overall ), 1, 'health-' . $trace );
		}
		return $result;
	}

	public static function latest() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . SPF_Installer::table( 'health' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return $row ? json_decode( $row['results_json'], true ) : null;
	}

	private static function check( $code, $passed, $value, $failure_message ) {
		$status = $passed ? 'pass' : 'fail';
		if ( ! $passed && in_array( $code, array( 'cron', 'file00_contract', 'file20_contract' ), true ) ) {
			$status = 'warning';
		}
		return array(
			'code'    => sanitize_key( $code ),
			'status'  => $status,
			'value'   => sanitize_text_field( (string) $value ),
			'message' => $passed ? 'OK' : sanitize_text_field( $failure_message ),
		);
	}
}

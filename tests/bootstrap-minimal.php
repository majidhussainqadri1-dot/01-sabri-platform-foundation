<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'SPF_CONTRACT_VERSION', '2.0.0' );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	private string $code;
	private string $message;
	private $data;
	public function __construct( string $code = '', string $message = '', $data = null ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	public function get_json_params() { return array(); }
	public function get_params() { return array(); }
}
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function __( $s, $domain = null ) { return $s; }
function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $s ) ); }
function sanitize_text_field( $s ): string { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_title( $s ): string { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function absint( $v ): int { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function current_time( $type, $gmt = false ) { return '2026-08-08 05:09:00'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function esc_url_raw( $url ) { return (string) $url; }
function wp_is_uuid( $v ): bool { return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $v ); }
function wp_generate_uuid4(): string { return '123e4567-e89b-42d3-a456-426614174000'; }
function wp_get_environment_type(): string { return 'development'; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function do_action( $tag, ...$args ): void {}

$GLOBALS['spf_test_options'] = [];
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['spf_test_options'] ) ? $GLOBALS['spf_test_options'][$key] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) { if ( array_key_exists( $key, $GLOBALS['spf_test_options'] ) ) { return false; } $GLOBALS['spf_test_options'][$key]=$value; return true; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['spf_test_options'][$key]=$value; return true; }
function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['spf_test_options'] ) ) return false; unset( $GLOBALS['spf_test_options'][$key] ); return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }

require_once dirname( __DIR__ ) . '/includes/class-spf-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-authorization.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-governance.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-governance-control-plane.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-platform-engineering.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-resilience-lab.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-future-foundation.php';

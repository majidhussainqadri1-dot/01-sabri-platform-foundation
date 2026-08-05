<?php
declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;
		public function __construct( string $code = '', string $message = '', array $data = [] ) { $this->code=$code; $this->message=$message; $this->data=$data; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ): string { return (string) $text; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { return $value; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id(): int { return 7; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ): bool { return false; } }
if ( ! function_exists( 'defined' ) ) { /* built-in */ }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require_once dirname( __DIR__ ) . '/includes/class-spf-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-authorization.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-spf-governance.php';

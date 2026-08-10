<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/includes/class-spf-audit.php';
$text = file_get_contents($path);
if (!is_string($text)) {
    fwrite(STDERR, "Unable to read audit source.\n");
    exit(1);
}
$startNeedle = "\tprivate static function sanitize_context";
$start = strpos($text, $startNeedle);
$endMarker = "\n\t}\n\n}";
$endStart = strrpos($text, $endMarker);
if ($start === false || $endStart === false || $endStart <= $start) {
    fwrite(STDERR, "Audit sanitize_context boundaries not found.\n");
    exit(1);
}
$end = $endStart + strlen("\n\t}");
$replacement = <<<'PHPFUNC'
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
				$scalar = sanitize_text_field( (string) $value );
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
PHPFUNC;
$new = substr($text, 0, $start) . $replacement . substr($text, $end);
if (strpos($new, 'spf_audit_context_key_invalid') === false || strpos($new, 'spf_audit_context_value_too_large') === false || strpos($new, 'spf_audit_context_value_invalid') === false) {
    fwrite(STDERR, "Audit patch verification failed.\n");
    exit(1);
}
if (file_put_contents($path, $new) === false) {
    fwrite(STDERR, "Unable to write audit source.\n");
    exit(1);
}
echo "Audit context patch applied.\n";

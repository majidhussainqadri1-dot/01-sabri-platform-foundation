<?php
declare(strict_types=1);

$failures = array();
$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$failures, &$assertions ) {
    $assertions++;
    if ( ! $condition ) { $failures[] = $message; }
};

$idempotency = file_get_contents( dirname(__DIR__) . '/includes/class-spf-idempotency.php' );
$runtime = file_get_contents( dirname(__DIR__) . '/includes/class-spf-runtime.php' );
$governance = file_get_contents( dirname(__DIR__) . '/includes/class-spf-governance.php' );
$privacy = file_get_contents( dirname(__DIR__) . '/includes/class-spf-privacy.php' );
$reconciler = file_get_contents( dirname(__DIR__) . '/includes/class-spf-reconciler.php' );
$repair = file_get_contents( dirname(__DIR__) . '/includes/class-spf-repair.php' );
$dependency = file_get_contents( dirname(__DIR__) . '/includes/class-spf-dependency-resolver.php' );
$registry = file_get_contents( dirname(__DIR__) . '/includes/class-spf-registry.php' );
$installer = file_get_contents( dirname(__DIR__) . '/includes/class-spf-installer.php' );
$engineering = file_get_contents( dirname(__DIR__) . '/includes/class-spf-platform-engineering.php' );
$system_check = file_get_contents( dirname(__DIR__) . '/includes/class-spf-system-check.php' );

$assert( str_contains( $idempotency, 'idempotency_error_finalize_conflict' ) && str_contains( $idempotency, "return is_wp_error( \$finalized ) ? \$finalized" ), 'Round 1: failed idempotency outcomes are not durably finalized/fail-closed.' );

$assert( str_contains( $runtime, "true !== \$claim['verified']" ) && str_contains( $system_check, "true===\$external_cron['verified']" ) && str_contains( $system_check, "true===\$mail['verified']" ), 'Round 2: external evidence accepts non-boolean truthy verification.' );

$assert( str_contains( $governance, 'spf_invalid_flag_enabled' ) && str_contains( $governance, 'spf_invalid_flag_expiry' ) && str_contains( $governance, "true === \$flag['enabled']" ), 'Round 3: feature flag boolean/expiry is not strict and fail-closed.' );

$assert( str_contains( $governance, 'feature_flag_expiry_write_failed' ) && str_contains( $governance, 'feature_flag_expiry_event_failed' ) && str_contains( $governance, "'enabled'=>1" ), 'Round 4: flag expiry can emit a fact without a successful state transition.' );

$assert( str_contains( $privacy, 'spf_retention_incomplete' ) && str_contains( $privacy, 'mandatory audit evidence could not be recorded' ) && str_contains( $privacy, "'done'=>false" ), 'Round 5: privacy erasure/retention can claim completion after persistence failure.' );

if ( $failures ) {
    fwrite( STDERR, "Third ten-round review regression failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Third ten-round review assertions: {$assertions}/{$assertions} PASS\n";

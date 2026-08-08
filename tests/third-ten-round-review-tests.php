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

if ( $failures ) {
    fwrite( STDERR, "Third ten-round review regression failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo "Third ten-round review assertions: {$assertions}/{$assertions} PASS\n";

<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$authorization = file_get_contents( $root . '/includes/class-spf-authorization.php' );
$plugin = file_get_contents( $root . '/includes/class-spf-plugin.php' );

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

// Review 2: a File 00 authorization claim must be scoped to this plugin and
// the exact File 01 contract version; action/object/purpose alone is not enough.
$assert( str_contains( $authorization, "'institutional_role', 'plugin', 'contract'" ), 'Authorization claim required fields do not include plugin and contract binding.' );
$assert( str_contains( $authorization, "'file-01' !== sanitize_key( (string) \$claim['plugin'] )" ), 'Authorization claim is not bound to File 01.' );
$assert( str_contains( $authorization, "hash_equals( (string) SPF_CONTRACT_VERSION, (string) \$claim['contract'] )" ), 'Authorization claim is not bound to the exact File 01 contract version.' );
$assert( str_contains( $authorization, "'plugin'       => 'file-01'" ) && str_contains( $authorization, "'contract'     => SPF_CONTRACT_VERSION" ), 'Authorization request does not ask File 00 for plugin/contract-scoped evidence.' );
$assert( str_contains( $plugin, "'plugin'=>array('type'=>'string','required'=>true)" ) && str_contains( $plugin, "'contract'=>array('type'=>'semver','required'=>true)" ), 'Published FoundationAuthorizationClaim contract schema is stale relative to runtime validation.' );

if ( $failures ) {
	fwrite( STDERR, "Twelfth ten-round review tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Twelfth ten-round review assertions: {$assertions}/{$assertions} PASS\n";

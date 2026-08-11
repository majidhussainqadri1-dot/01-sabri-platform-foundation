<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$authorization = file_get_contents( $root . '/includes/class-spf-authorization.php' );
$plugin = file_get_contents( $root . '/includes/class-spf-plugin.php' );
$event_bus = file_get_contents( $root . '/includes/class-spf-event-bus.php' );
$governance = file_get_contents( $root . '/includes/class-spf-governance.php' );
$privacy = file_get_contents( $root . '/includes/class-spf-privacy.php' );
$system_check = file_get_contents( $root . '/includes/class-spf-system-check.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );

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
$assert( str_contains( $authorization, "'file-01' !== (string) \$claim['plugin']" ), 'Authorization claim is not bound to File 01.' );
$assert( str_contains( $authorization, "hash_equals( (string) SPF_CONTRACT_VERSION, (string) \$claim['contract'] )" ), 'Authorization claim is not bound to the exact File 01 contract version.' );
$assert( str_contains( $authorization, "'plugin'       => 'file-01'" ) && str_contains( $authorization, "'contract'     => SPF_CONTRACT_VERSION" ), 'Authorization request does not ask File 00 for plugin/contract-scoped evidence.' );
$assert( str_contains( $plugin, "'plugin'=>array('type'=>'string','required'=>true)" ) && str_contains( $plugin, "'contract'=>array('type'=>'semver','required'=>true)" ), 'Published FoundationAuthorizationClaim contract schema is stale relative to runtime validation.' );

// Review 3: event identity and event facts must not be silently normalized or truncated.
$assert( str_contains( $event_bus, '$raw_event_name !== $event_name' ) && str_contains( $event_bus, '$raw_aggregate_type !== $aggregate_type' ) && str_contains( $event_bus, '$raw_aggregate_id !== $aggregate_id' ), 'Event identity fields can still normalize into a different canonical identity.' );
$assert( str_contains( $event_bus, 'spf_event_payload_value_too_large' ) && str_contains( $event_bus, 'spf_event_payload_value_noncanonical' ) && ! str_contains( $event_bus, "substr( sanitize_text_field( (string) \$value ), 0, 1000 )" ), 'Event payload values can still be silently truncated or normalized.' );

// Review 5: malformed schema-version storage must block automatic migration, not look current.
$assert( str_contains( $plugin, 'spf_schema_version_invalid' ) && str_contains( $plugin, "'status'=>'invalid_schema_version'" ), 'Malformed stored schema version does not block the automatic upgrade entry point.' );

// Review 6: immutable governance evidence must not be silently shortened.
$assert( str_contains( $governance, 'spf_governance_evidence_value_noncanonical' ) && ! str_contains( $governance, "substr( sanitize_text_field( (string) \$v ), 0, 1000 )" ), 'Governance evidence can still be silently truncated or normalized before hashing/storage.' );

// Review 7: every File 01 scheduled task, including Future Foundation health, must be removed on uninstall.
$assert( str_contains( $uninstall, "'spf_future_foundation_tick'" ), 'Future Foundation cron remains scheduled after non-destructive uninstall.' );

// Review 8: privacy erasure must preserve any nonterminal idempotency record.
$assert( str_contains( $privacy, "status NOT IN ('completed','failed')" ) && str_contains( $privacy, "status IN ('completed','failed')" ), 'Privacy erasure does not distinguish in-flight idempotency state from terminal linkage.' );
$assert( str_contains( $privacy, 'Erasure will retry after the mutation reaches a terminal state.' ), 'Privacy erasure does not fail closed while an in-flight mutation exists.' );

// Review 9: staging deployment is not staging acceptance, and malformed schema state must remain visible in health.
$assert( str_contains( $system_check, "'staging_accepted', 'approved'===\$release_status || 'deployed'===\$release_status" ) && ! str_contains( $system_check, "'staging_accepted', 'staged'=== \$release_status" ), 'System Check still treats staged deployment as staging acceptance.' );
$assert( str_contains( $system_check, "'invalid_schema_version'" ), 'System Check can report the upgrade state as healthy while stored schema-version evidence is malformed.' );

if ( $failures ) {
	fwrite( STDERR, "Twelfth ten-round review tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Twelfth ten-round review assertions: {$assertions}/{$assertions} PASS\n";

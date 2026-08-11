<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/sabri-platform-foundation.php' );
$staging = file_get_contents( $root . '/STAGING-ACCEPTANCE.md' );
$checklist = file_get_contents( $root . '/RELEASE-CHECKLIST.md' );
$migration = file_get_contents( $root . '/MIGRATION.md' );
$evidence = json_decode( file_get_contents( $root . '/RELEASE-EVIDENCE-TEMPLATE.json' ), true );

$value = static function ( string $name ) use ( $plugin ): string {
	if ( ! preg_match( "/define\\(\\s*'" . preg_quote( $name, '/' ) . "'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin, $match ) ) {
		fwrite( STDERR, "Missing constant {$name}.\n" );
		exit( 1 );
	}
	return $match[1];
};

$software = $value( 'SPF_VERSION' );
$schema = $value( 'SPF_SCHEMA_VERSION' );
$contract = $value( 'SPF_CONTRACT_VERSION' );
$expected_package = "01-sabri-platform-foundation-{$software}-FUTURE-FOUNDATION-SUPERSET-CANDIDATE.zip";

$assertions = 0;
$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( str_contains( $staging, "# Hostinger Staging Acceptance — File 01 {$software}" ), 'Staging acceptance title is stale relative to runtime software version.' );
$assert( str_contains( $staging, "Database/schema version: `{$schema}`" ), 'Staging acceptance schema identity is stale.' );
$assert( str_contains( $staging, "Contract version: `{$contract}`" ), 'Staging acceptance contract identity is stale.' );
$assert( str_contains( $staging, $expected_package ), 'Staging acceptance canonical package name is stale.' );
$assert( str_contains( $staging, 'Staging Reality Freeze' ) && str_contains( $staging, 'Exact deployed code ابھی unverified ہے' ), 'Live/staging reality-freeze guard is missing from staging acceptance.' );
$assert( str_contains( $checklist, "# Release Checklist — File 01 {$software}" ), 'Release checklist title is stale relative to runtime software version.' );
$assert( str_contains( $checklist, "historical→{$software}" ), 'Release checklist upgrade target is stale.' );
$assert( is_array( $evidence ), 'Release evidence template is not valid JSON.' );
$assert( ( $evidence['software_version'] ?? null ) === $software, 'Release evidence software version is stale.' );
$assert( ( $evidence['schema_version'] ?? null ) === $schema, 'Release evidence schema version is stale.' );
$assert( ( $evidence['contract_version'] ?? null ) === $contract, 'Release evidence contract version is stale.' );
$assert( ( $evidence['package_name'] ?? null ) === $expected_package, 'Release evidence package name is stale.' );
$assert( isset( $evidence['reality_freeze']['repository_head'], $evidence['reality_freeze']['deployed_version'], $evidence['reality_freeze']['db_version'], $evidence['reality_freeze']['migration_state'], $evidence['reality_freeze']['live_verification_status'] ), 'Release evidence reality-freeze fields are incomplete.' );
$assert( str_contains( $migration, "software to {$software} (schema {$schema})" ), 'Migration heading confuses current software and schema versions.' );
$assert( str_contains( $migration, "contract `{$contract}`" ), 'Migration contract identity is stale.' );

if ( $failures ) {
	fwrite( STDERR, "Release-handoff contract tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Release-handoff contract assertions: {$assertions}/{$assertions} PASS\n";

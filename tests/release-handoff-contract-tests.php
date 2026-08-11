<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/sabri-platform-foundation.php' );
$staging = file_get_contents( $root . '/STAGING-ACCEPTANCE.md' );
$checklist = file_get_contents( $root . '/RELEASE-CHECKLIST.md' );
$migration = file_get_contents( $root . '/MIGRATION.md' );
$readme = file_get_contents( $root . '/README.md' );
$wp_readme = file_get_contents( $root . '/readme.txt' );
$qa_report = file_get_contents( $root . '/QA-REPORT.md' );
$known = file_get_contents( $root . '/KNOWN-LIMITATIONS.md' );
$traceability = file_get_contents( $root . '/TRACEABILITY.md' );
$runtime_notes = file_get_contents( $root . '/RUNTIME-QA-NOTES.md' );
$sbom = json_decode( file_get_contents( $root . '/SBOM.cdx.json' ), true );
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
$expected_purl = "pkg:wordpress/sabri-platform-foundation@{$software}";

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
$assert( str_starts_with( $readme, "# File 01 — Sabri Platform Foundation {$software}" ), 'README current software identity is stale.' );
$assert( str_contains( $readme, "schema {$schema}" ) && str_contains( $readme, "contract {$contract}" ), 'README does not preserve distinct current software/schema/contract identity.' );
$assert( (bool) preg_match( '/^Stable tag:\\s*' . preg_quote( $software, '/' ) . '\\s*$/m', $wp_readme ), 'WordPress Stable tag is stale.' );
$assert( str_contains( $qa_report, "File 01 {$software} Repository Candidate" ), 'QA report current candidate identity is stale.' );
$assert( str_contains( $known, "{$software} repository candidate scope" ), 'Known-limitations current candidate identity is stale.' );
$assert( str_contains( $traceability, "Software {$software} / Schema {$schema} / Contract {$contract}" ), 'Traceability identity is stale.' );
$assert( str_contains( $runtime_notes, "Runtime QA Notes — {$software}" ), 'Runtime QA notes current identity is stale.' );
$assert( is_array( $sbom ), 'SBOM is not valid JSON.' );
$assert( ( $sbom['metadata']['component']['version'] ?? null ) === $software, 'SBOM component version is stale.' );
$assert( ( $sbom['metadata']['component']['purl'] ?? null ) === $expected_purl, 'SBOM component purl is stale.' );
$assert( ( $sbom['dependencies'][0]['ref'] ?? null ) === $expected_purl, 'SBOM dependency ref is stale.' );

if ( $failures ) {
	fwrite( STDERR, "Release-handoff contract tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Release-handoff contract assertions: {$assertions}/{$assertions} PASS\n";

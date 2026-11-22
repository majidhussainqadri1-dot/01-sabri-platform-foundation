<?php
declare(strict_types=1);
/**
 * File 01 V12.3: independent source-pin, traceability and chaos-receipt
 * fail-closed regressions. Source provenance is NOT staging/live acceptance.
 */
require __DIR__ . '/bootstrap-minimal.php';

$passed = 0;
$failures = array();
$check = static function ( $condition, string $reason ) use ( &$passed, &$failures ) : void {
    if ( true === $condition ) {
        $passed++;
    } else {
        $failures[] = $reason;
    }
};
$root = dirname( __DIR__ );
$ids = SPF_Governance_Control_Plane::file01_v12_requirement_ids();
$check( 43 === count( $ids ), 'The canonical V12.3 File 01 registry must have 43 requirements.' );
$check( 43 === count( array_unique( $ids ) ), 'V12.3 requirement IDs must be unique.' );
$check( in_array( 'F01-FR-012', $ids, true ) && in_array( 'F01-NFR-010', $ids, true ) && in_array( 'F01-CEN-03', $ids, true ) && in_array( 'F01-FUT-018', $ids, true ), 'V12.3 family endpoints are missing.' );
$trace_path = $root . '/TRACEABILITY.md';
$trace_digest = hash_file( 'sha256', $trace_path );
$check( SPF_Governance_Control_Plane::verify_v12_source_file( $root, 'TRACEABILITY.md', $trace_digest ), 'A matching pinned local source must be accepted.' );
$check( ! SPF_Governance_Control_Plane::verify_v12_source_file( $root, 'TRACEABILITY.md', str_repeat( '0', 64 ) ), 'A wrong digest must be rejected.' );
$check( ! SPF_Governance_Control_Plane::verify_v12_source_file( $root, '../TRACEABILITY.md', $trace_digest ), 'Parent traversal must be rejected.' );
$check( ! SPF_Governance_Control_Plane::verify_v12_source_file( $root, '/TRACEABILITY.md', $trace_digest ), 'Absolute paths must be rejected.' );
$check( ! SPF_Governance_Control_Plane::verify_v12_source_file( $root, 'missing-file.php', $trace_digest ), 'Missing source files must not pass source pinning.' );

$invalid_pin = SPF_Governance_Control_Plane::lint_pinned_repository_sources( array( array(
    'repository'=>'invalid-repository', 'module_key'=>'file-01', 'commit_sha'=>str_repeat( 'a', 40 ),
    'path'=>'architecture-manifest.json', 'expected_sha256'=>str_repeat( 'b', 64 ),
) ) );
$check( false === ( $invalid_pin['pass'] ?? null ), 'Invalid repository claims must fail closed.' );
$check( in_array( 'source_pin_invalid', array_column( $invalid_pin['findings'] ?? array(), 'code' ), true ), 'Missing pinned-repository identity finding.' );
$empty_pin = SPF_Governance_Control_Plane::lint_pinned_repository_sources( array() );
$check( false === ( $empty_pin['pass'] ?? null ) && in_array( 'source_count_invalid', array_column( $empty_pin['findings'] ?? array(), 'code' ), true ), 'An empty repository source set must fail closed.' );

$declared = array();
foreach ( $ids as $id ) {
    // Deliberately supply optimistic booleans without independent proof.
    $declared[$id] = array( 'design'=>true, 'code'=>true, 'test'=>true, 'package'=>true,
        'staging'=>true, 'approval'=>true, 'live'=>true, 'operational'=>true );
}
$claims = SPF_Governance_Control_Plane::build_traceability_report( $ids, $declared );
$check( true === ( $claims['report_valid'] ?? null ) && true === ( $claims['v12_requirement_coverage_complete'] ?? null ), 'Canonical 43-item coverage and valid IDs must be recognized.' );
$check( 0 === ( $claims['coded_complete'] ?? null ) && 0 === ( $claims['production_complete'] ?? null ), 'Unattested booleans must never establish coded or production completion.' );
$check( false === ( $claims['coded_completion_claim_allowed'] ?? null ), 'Unsupported completion claim must be prohibited.' );
$incomplete = SPF_Governance_Control_Plane::build_traceability_report( array( 'F01-FR-001' ), array( 'F01-FR-001'=>$declared['F01-FR-001'] ) );
$check( false === ( $incomplete['v12_requirement_coverage_complete'] ?? null ), 'Partial V12.3 requirement sets must not claim complete coverage.' );

$challenge = str_repeat( 'a', 32 );
$receipt = array( 'scenario'=>'dependency_timeout', 'challenge'=>$challenge, 'adapter'=>'test_observer',
    'fault_observed'=>true, 'recovery_observed'=>true,
    'fault_evidence_sha256'=>str_repeat( 'b', 64 ),
    'recovery_evidence_sha256'=>str_repeat( 'c', 64 ) );
$check( true === SPF_Resilience_Lab::verify_chaos_receipt( $receipt, 'dependency_timeout', $challenge ), 'Bounded structurally valid observer receipt must parse.' );
$wrong_challenge = $receipt; $wrong_challenge['challenge'] = str_repeat( 'd', 32 );
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $wrong_challenge, 'dependency_timeout', $challenge ), 'Challenge mismatch must fail closed.' );
$no_recovery = $receipt; $no_recovery['recovery_observed'] = false;
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $no_recovery, 'dependency_timeout', $challenge ), 'A fault without observed recovery must not pass.' );
$same_evidence = $receipt; $same_evidence['recovery_evidence_sha256'] = $receipt['fault_evidence_sha256'];
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $same_evidence, 'dependency_timeout', $challenge ), 'Duplicate fault/recovery evidence must not count as independent proof.' );
$string_bool = $receipt; $string_bool['fault_observed'] = 'true';
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $string_bool, 'dependency_timeout', $challenge ), 'Truthy strings are not independent observer truth.' );
$bad_adapter = $receipt; $bad_adapter['adapter'] = '../unsafe';
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $bad_adapter, 'dependency_timeout', $challenge ), 'Unsafe observer identities must be rejected.' );
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $receipt, 'unknown_scenario', $challenge ), 'Unknown chaos scenarios must fail closed.' );
$check( false === SPF_Resilience_Lab::verify_chaos_receipt( $receipt, 'dependency_timeout', 'not-a-valid-challenge' ), 'Malformed observer challenges must fail closed.' );

$source_scan = SPF_Governance_Control_Plane::audit_v12_local_sources( $root, str_repeat( 'a', 40 ) );
$check( 43 === ( $source_scan['total'] ?? null ) && 43 === ( $source_scan['declared_count'] ?? null ), 'All 43 V12.3 traceability declarations must exist.' );
$check( 43 === ( $source_scan['source_mapped'] ?? null ), 'Each V12.3 requirement needs pinned source evidence.' );
$check( 43 === ( $source_scan['test_ref_verified_count'] ?? null ), 'Each V12.3 requirement needs a pinned existing test file.' );
$check( 'source_scan_complete_acceptance_pending' === ( $source_scan['status'] ?? null ), 'Source scan state must preserve external acceptance gate.' );
$check( false === ( $source_scan['coded_completion_claim_allowed'] ?? null ) && false === ( $source_scan['ci_result_verified'] ?? null ) && false === ( $source_scan['commit_verified'] ?? null ), 'Local source scan must never manufacture trusted CI/commit/coded-complete proof.' );
$check( empty( $source_scan['missing_requirements'] ?? array() ) && empty( $source_scan['unexpected_requirements'] ?? array() ), 'Traceability source coverage has missing or extra V12.3 IDs.' );

if ( $failures ) {
    fwrite( STDERR, "V12.3 source safety tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}
echo 'V12.3 source safety assertions: ' . $passed . '/' . $passed . " PASS\n";

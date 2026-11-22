<?php
declare(strict_types=1);
/**
 * Read-only local 43-requirement source/provenance audit.
 * A source tree and checksum match do NOT prove signed CI attestation,
 * staging acceptance, live deployment, or Founder release approval.
 */
require dirname( __DIR__ ) . '/tests/bootstrap-minimal.php';
$root = dirname( __DIR__ );
$claimed_commit = $argv[1] ?? str_repeat( '0', 40 );
$result = SPF_Governance_Control_Plane::audit_v12_local_sources( $root, $claimed_commit );
$summary = array(
    'source_only'=>true,
    'total'=>$result['total'] ?? 0,
    'declared_count'=>$result['declared_count'] ?? 0,
    'source_mapped'=>$result['source_mapped'] ?? 0,
    'test_ref_verified_count'=>$result['test_ref_verified_count'] ?? 0,
    'missing_requirements'=>$result['missing_requirements'] ?? array(),
    'unexpected_requirements'=>$result['unexpected_requirements'] ?? array(),
    'status'=>$result['status'] ?? 'unavailable',
    'commit_verified'=>false,
    'ci_result_verified'=>false,
    'coded_completion_claim_allowed'=>false,
    'staging_accepted'=>false,
    'live_deployed_by_this_audit'=>false,
);
echo json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( 43 !== $summary['total'] || 43 !== $summary['declared_count']
    || 43 !== $summary['source_mapped'] || 43 !== $summary['test_ref_verified_count']
    || ! empty( $summary['missing_requirements'] ) || ! empty( $summary['unexpected_requirements'] )
    || 'source_scan_complete_acceptance_pending' !== $summary['status'] ) {
    exit( 1 );
}

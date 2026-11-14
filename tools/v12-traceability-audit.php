<?php
declare(strict_types=1);
/**
 * File 01 V12 source-evidence scanner, intentionally NOT a release approver.
 * Called after source QA. A completed GitHub workflow run must be inspected
 * independently before claiming CI success; staging/live remain unverified.
 */
require dirname(__DIR__) . '/tests/bootstrap-minimal.php';

$root = dirname(__DIR__);
$claim = getenv('GITHUB_SHA') ?: getenv('SPF_V12_COMMIT_SHA') ?: str_repeat('0',40);
$report = SPF_Governance_Control_Plane::audit_v12_local_sources($root, $claim);
$report['ci_run_id_claim'] = (string) (getenv('GITHUB_RUN_ID') ?: '');
$report['ci_job_execution_status'] = 'scanner_executed_after_source_tests';
$report['ci_result_verified'] = false;
$report['staging_verified'] = false;
$report['live_verified'] = false;
$report['coded_completion_claim_allowed'] = false;
$report['missing_source_symbol_ids'] = array();
$report['missing_test_file_ids'] = array();
foreach ( $report['rows'] as $requirement_id => $row ) {
    if ( empty( $row['source_mapped'] ) ) { $report['missing_source_symbol_ids'][] = $requirement_id; }
    if ( empty( $row['test_files_pinned'] ) ) { $report['missing_test_file_ids'][] = $requirement_id; }
}
$ok = $report['total'] === 43 && count($report['rows']) === 43
    && empty($report['missing_requirements'])
    && empty($report['unexpected_requirements'])
    && $report['source_mapped'] === 43
    && $report['test_ref_verified_count'] === 43;
if (!is_dir($root.'/build')) { mkdir($root.'/build', 0700, true); }
$encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded) || file_put_contents($root.'/build/v12-source-evidence.json', $encoded."\n") === false) {
    fwrite(STDERR, "Unable to write V12 source-evidence report.\n"); exit(1);
}
echo "V12 source scanner: {$report['source_mapped']}/43 source-mapped; "
    . "{$report['test_ref_verified_count']}/43 referenced test files pinned; "
    . "43/43 independently CI-attested = NO; staging/live = UNVERIFIED\n";
if (!$ok) {
    fwrite(STDERR, "V12 source/test-file provenance gap: source=" . implode(',', $report['missing_source_symbol_ids'])
        . "; tests=" . implode(',', $report['missing_test_file_ids']) . "\n");
    exit(1);
}

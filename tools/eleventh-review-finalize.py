from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def replace(path, old, new):
    p = ROOT / path
    s = p.read_text(encoding='utf-8')
    if old not in s:
        raise SystemExit(f'Expected finalizer context missing: {path}')
    p.write_text(s.replace(old, new, 1), encoding='utf-8')

# Preserve the earlier strict-boolean QA invariant while retaining the new
# evidence-rich Operational validator.
replace(
    'includes/class-spf-plugin.php',
    "\tprivate static function validate_operational_claim( $claim, array $context ) {\n\t\tif ( ! is_array( $claim ) || true !== ( $claim['verified'] ?? false ) || 'deployed' !== ( $context['release_status'] ?? '' ) ) {",
    "\tprivate static function validate_operational_claim( $claim, array $context ) {\n\t\t$operational_claim = $claim;\n\t\tif ( ! is_array( $operational_claim ) || ! array_key_exists( 'verified', $operational_claim ) || ! ( true === $operational_claim['verified'] ) || 'deployed' !== ( $context['release_status'] ?? '' ) ) {",
)

# Fresh-80 R57/R58 are inherited static QA contracts. R57 follows the stricter
# helper location. R58 was stale: `staged` is NOT Staging-Accepted.
replace(
    'tests/fresh-eighty-round-review-tests.php',
    "$assert( str_contains($plugin, \"'operational'=>\\$operational\") && str_contains($plugin, \"'deployed' === \\$release_status\"), 'Round 57: operational completion can be asserted before deployment.' );",
    "$assert( str_contains($plugin, \"'operational'=>\\$operational\") && str_contains($plugin, \"'deployed' !== ( \\$context['release_status'] ?? '' )\") && str_contains($plugin, 'deployed_package_checksum'), 'Round 57: operational completion can be asserted before deployment or without exact deployed-package binding.' );",
)
replace(
    'tests/fresh-eighty-round-review-tests.php',
    "$assert( str_contains($plugin, \"'staging_accepted'=>in_array\") && str_contains($plugin, \"array('staged','approved','deployed')\"), 'Round 58: staging completion status mapping is missing.' );",
    "$assert( str_contains($plugin, \"'staging_accepted'=>in_array\") && str_contains($plugin, \"array('approved','deployed')\") && ! str_contains($plugin, \"array('staged','approved','deployed')\"), 'Round 58: Staging-Accepted still collapses the merely-staged state.' );",
)

# Existing positive migration runtime fixture supplies exact context fields.
replace(
    'qa/wp-runtime-smoke.php',
    """add_filter( 'spf_verify_migration_backup_evidence', static fn() => [
\t'verified'=>true,'backup_id'=>'ci-backup-1','restore_tested_at'=>gmdate('c'),'environment'=>'staging','verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600),
] );""",
    """add_filter( 'spf_verify_migration_backup_evidence', static function ( $claim, array $context ) {
\treturn [
\t\t'verified'=>true,'backup_id'=>'ci-backup-1','restore_tested_at'=>gmdate('c'),'environment'=>(string)($context['environment']??''),'verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600),
\t\t'module'=>(string)($context['module']??''),'from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),
\t];
}, 10, 2 );""",
)

# The permanent review record must expose, rather than hide, the inherited QA
# contract defect found during final regression.
replace(
    'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md',
    '| 10 | Staging/live/operational boundary | No new defect found after R1/R2; staging checklist and rollback gates remain separate. | Clean. |',
    '| 10 | Staging/live/operational + regression contract | **F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`. The QA contract was corrected to require only `approved`/`deployed`; product code was not weakened. | QA defect fixed + full regression rerun. |',
)
replace(
    'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md',
    '**Defect-bearing rounds: 1, 2, 3, 4, 5, 6.**  \n**Clean rounds: 7, 8, 9, 10.**',
    '**Defect-bearing rounds: 1, 2, 3, 4, 5, 6, 10.**  \n**Clean rounds: 7, 8, 9.**',
)

# Round 10 of the new suite explicitly guards the corrected inherited QA rule.
replace(
    'tests/eleventh-ten-round-review-tests.php',
    "$assert($has('STAGING-ACCEPTANCE.md','- [ ]')&&$has('KNOWN-LIMITATIONS.md','staging')&&$has('RELEASE-CHECKLIST.md','rollback'),'Round 10: staging/live/operational boundary is not explicit.');",
    "$fresh80=$read('tests/fresh-eighty-round-review-tests.php');$assert($has('STAGING-ACCEPTANCE.md','- [ ]')&&$has('KNOWN-LIMITATIONS.md','staging')&&$has('RELEASE-CHECKLIST.md','rollback')&&str_contains($fresh80,\"array('approved','deployed')\")&&!str_contains($fresh80,\"array('staged','approved','deployed')\"),'Round 10: staging/live/operational QA contract still collapses merely-staged into Staging-Accepted.');",
)

print('Eleventh review truthful QA finalization prepared.')

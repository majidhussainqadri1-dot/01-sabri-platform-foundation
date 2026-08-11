from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def replace(path,old,new):
    p=ROOT/path;s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'Expected v3 finalizer context missing: {path}')
    p.write_text(s.replace(old,new,1),encoding='utf-8')

# Integrate the four new negative/runtime regressions into the runtime suite
# already executed by the immutable corrective workflow. This avoids asking a
# GitHub Action to rewrite another workflow file.
marker="""$assert( is_array( SPF_System_Check::latest() ), 'Latest health snapshot unavailable.' );

if ( $failures ) {"""
block="""$assert( is_array( SPF_System_Check::latest() ), 'Latest health snapshot unavailable.' );

// Eleventh ten-round integrated runtime regressions.
global $wpdb;
$outbox = SPF_Installer::table( 'outbox' );
$wpdb->query( \"DELETE FROM {$outbox} WHERE event_name LIKE 'EleventhReview.%'\" );
$e1 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'a', [ 'value'=>1 ], 1, 'same-caller-key', 'internal' );
$e2 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'b', [ 'value'=>1 ], 1, 'same-caller-key', 'internal' );
$e3 = SPF_Event_Bus::publish( 'EleventhReview.One.v1', 'review_probe', 'a', [ 'value'=>999 ], 1, 'same-caller-key', 'internal' );
$e_count = (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$outbox} WHERE event_name='EleventhReview.One.v1'\" );
$assert( true === $e1 && true === $e2 && true === $e3 && 2 === $e_count, 'Explicit event dedupe key is not safely identity-scoped/idempotent.' );

update_option( SPF_Installer::SCHEMA_OPTION, '1.0.0', false );
add_filter( 'spf_verify_migration_backup_evidence', static function ( $claim, array $context ) {
    return [ 'verified'=>true,'backup_id'=>'wrong-context-backup','restore_tested_at'=>gmdate('c'),'verifier'=>'CI negative probe','module'=>(string)($context['module']??''),'from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),'environment'=>'production','expires_at'=>gmdate('c',time()+3600) ];
}, 50, 2 );
$bad_upgrade = SPF_Installer::maybe_upgrade();
$assert( is_wp_error( $bad_upgrade ) && 'spf_migration_backup_evidence_binding_invalid' === $bad_upgrade->get_error_code(), 'Wrong-context migration backup evidence was accepted.' );
update_option( SPF_Installer::SCHEMA_OPTION, SPF_SCHEMA_VERSION, false );

add_filter( 'spf_verify_backup_restore_evidence', static function ( $claim, array $context ) {
    return [ 'verified'=>true,'backup_id'=>'negative-probe','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI negative probe','expires_at'=>gmdate('c',time()+3600),'operation'=>'file01_purge','plan_hash'=>str_repeat('0',64) ];
}, 50, 2 );
$purge_plan = SPF_Purge::plan();
$bad_purge = SPF_Purge::apply( 'PURGE FILE 01 GOVERNANCE DATA', [ 'backup_id'=>'submitted' ], SPF_Purge::plan_hash( $purge_plan ) );
$assert( is_wp_error( $bad_purge ) && 'spf_purge_backup_evidence_binding_invalid' === $bad_purge->get_error_code(), 'Wrong-plan purge backup evidence was accepted.' );

$bad_manifest = SPF_Registry::get_module( 'file-01' );
$bad_manifest['canonical_entities'] = [ 'Not Canonical' ];
$bad_manifest['writes'] = [];
$bad_manifest_result = SPF_Installer::with_internal_seed( static fn() => SPF_Registry::register_manifest( $bad_manifest ) );
$assert( is_wp_error( $bad_manifest_result ) && 'spf_invalid_manifest_entity' === $bad_manifest_result->get_error_code(), 'Noncanonical manifest entity was silently normalized.' );

if ( $failures ) {"""
replace('qa/wp-runtime-smoke.php',marker,block)

# Permanent record states that the new runtime regressions are integrated into
# the existing exact-head runtime job, while the standalone probe is retained.
replace(
 'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md',
 'Added verification: `tests/eleventh-ten-round-review-tests.php` (10/10 review assertions), `qa/wp-eleventh-ten-round-smoke.php` (runtime negative regressions), updated purge smoke, and corrective workflow execution before deterministic packaging.',
 'Added verification: `tests/eleventh-ten-round-review-tests.php` (10/10 review assertions); four negative/runtime regressions are integrated into `qa/wp-runtime-smoke.php` and mirrored in `qa/wp-eleventh-ten-round-smoke.php`; destructive purge smoke is updated. The existing corrective workflow therefore exercises the new runtime invariants without self-modifying workflow permissions.'
)
print('Eleventh review integrated runtime regressions prepared.')

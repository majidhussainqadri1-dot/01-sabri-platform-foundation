<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';

$assertions = 0;
$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};

$unordered = [ 'z'=>1, 'a'=>[ 'b'=>2, 'a'=>1 ] ];
$ordered   = [ 'a'=>[ 'a'=>1, 'b'=>2 ], 'z'=>1 ];
$assert( SPF_Runtime::canonical_json( $unordered ) === SPF_Runtime::canonical_json( $ordered ), 'Canonical JSON is not stable.' );
$assert( SPF_Runtime::hash( $unordered ) === SPF_Runtime::hash( $ordered ), 'Canonical hash is not stable.' );
$assert( SPF_Registry::valid_semver( '1.2.0' ), 'Valid semantic version rejected.' );
$assert( ! SPF_Registry::valid_semver( '1.2.0.2' ), 'Four-part non-semver accepted.' );

$valid_claim = [
	'claim_version'=>'1.2.0','allowed'=>true,'user_id'=>7,'actor_id'=>7,'action'=>'record_release','capability'=>SPF_Authorization::CAP_RELEASE,
	'issued_at'=>time()-5,'expires_at'=>time()+300,'claim_id'=>'claim-1','object_hash'=>SPF_Runtime::hash(['object_id'=>'1.2.0']),
	'purpose'=>'release_evidence','institutional_role'=>'release_operator','plugin'=>'file-01','contract'=>SPF_CONTRACT_VERSION,'suspended'=>false,'revoked'=>false,
];
$assert( SPF_Authorization::validate_claim( $valid_claim, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Valid structured File 00 claim rejected.' );
$invalid = $valid_claim; $invalid['institutional_role']='administrator';
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Administrator improperly accepted as Release Operator.' );
$invalid = $valid_claim; $invalid['expires_at']=time()-1;
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Expired claim accepted.' );
$invalid = $valid_claim; $invalid['issued_at']=time()-901; $invalid['expires_at']=time()+1;
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Over-age claim accepted.' );
$invalid = $valid_claim; unset( $invalid['object_hash'] );
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Claim without object binding accepted.' );
$invalid = $valid_claim; $invalid['purpose']='different_purpose';
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Claim with wrong purpose accepted.' );
$invalid = $valid_claim; $invalid['actor_id']=8;
$assert( ! SPF_Authorization::validate_claim( $invalid, 7, 'record_release', SPF_Authorization::CAP_RELEASE, [ 'object_id'=>'1.2.0' ], [ 'purpose'=>'release_evidence' ] ), 'Claim for another actor accepted.' );
$founder = $valid_claim; $founder['action']='approve_release'; $founder['capability']=SPF_Authorization::CAP_FOUNDER; $founder['purpose']='release_transition'; $founder['institutional_role']='founder'; $founder['object_hash']=SPF_Runtime::hash(['object_id'=>'id','next_status'=>'approved']);
$assert( SPF_Authorization::validate_claim( $founder, 7, 'approve_release', SPF_Authorization::CAP_FOUNDER, ['object_id'=>'id','next_status'=>'approved'], ['purpose'=>'release_transition'] ), 'Founder approval claim rejected.' );
$founder['institutional_role']='release_operator';
$assert( ! SPF_Authorization::validate_claim( $founder, 7, 'approve_release', SPF_Authorization::CAP_FOUNDER, ['object_id'=>'id','next_status'=>'approved'], ['purpose'=>'release_transition'] ), 'Release Operator accepted for Founder approval.' );

$dependency_method = new ReflectionMethod( SPF_Registry::class, 'normalize_dependencies' );
$dependency_method->setAccessible( true );
$duplicate_dependencies = $dependency_method->invoke( null, [
	[ 'module_key'=>'file-20','minimum_version'=>'1.2.0' ],
	[ 'module_key'=>'file-20','minimum_version'=>'1.2.0' ],
] );
$assert( is_wp_error( $duplicate_dependencies ), 'Duplicate dependency declaration accepted.' );
$oversized_dependencies = [];
for ( $i = 0; $i < 65; $i++ ) { $oversized_dependencies[] = [ 'module_key'=>'file-20','minimum_version'=>'1.2.0' ]; }
$assert( is_wp_error( $dependency_method->invoke( null, $oversized_dependencies ) ), 'Oversized dependency list accepted.' );

$assert( SPF_Governance::release_transition_allowed( 'planned', 'built' ), 'planned→built rejected.' );
$assert( ! SPF_Governance::release_transition_allowed( 'planned', 'deployed' ), 'planned→deployed accepted.' );
$assert( SPF_Governance::release_transition_allowed( 'staged', 'approved' ), 'staged→approved rejected.' );
$assert( ! SPF_Governance::release_transition_allowed( 'approved', 'verified' ), 'Backward invalid transition accepted.' );

$planned = SPF_Governance::validate_evidence_for_state( 'planned', [ 'scope_reference'=>'plan','owner'=>'file-01' ] );
$assert( true === $planned, 'Valid planned evidence rejected.' );
$built = SPF_Governance::validate_evidence_for_state( 'built', [
	'source_commit_verified'=>true,'package_checksum_verified'=>true,'reproducible_build'=>true,'source_manifest'=>'manifest','sbom'=>'sbom',
] );
$assert( true === $built, 'Valid built evidence rejected.' );
$bad_built = SPF_Governance::validate_evidence_for_state( 'built', [
	'source_commit_verified'=>true,'package_checksum_verified'=>false,'reproducible_build'=>true,'source_manifest'=>'manifest','sbom'=>'sbom',
] );
$assert( is_wp_error( $bad_built ), 'Failed package-checksum evidence accepted.' );
$bad_staged = SPF_Governance::validate_evidence_for_state( 'staged', [ 'staging_environment'=>'x' ] );
$assert( is_wp_error( $bad_staged ), 'Incomplete staging evidence accepted.' );

if ( $failures ) {
	fwrite( STDERR, "Unit tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Unit assertions: {$assertions}/{$assertions} PASS\n";

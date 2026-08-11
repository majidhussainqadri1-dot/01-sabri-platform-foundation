<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-minimal.php';

$failures = array();
$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) { $failures[] = $message; }
};
$root = dirname( __DIR__ );
$registry = file_get_contents( $root . '/includes/class-spf-registry.php' );
$audit = file_get_contents( $root . '/includes/class-spf-audit.php' );
$runtime = file_get_contents( $root . '/includes/class-spf-runtime.php' );
$auth = file_get_contents( $root . '/includes/class-spf-authorization.php' );
$system = file_get_contents( $root . '/includes/class-spf-system-check.php' );
$rest = file_get_contents( $root . '/includes/class-spf-rest.php' );
$admin = file_get_contents( $root . '/includes/class-spf-admin.php' );

// R1 — closed-world integrity must not retain the interrupted apply workflow.
$manifest = file_get_contents( $root . '/SOURCE-CHECKSUMS.sha256' );
$assert( ! str_contains( $manifest, 'thirteenth-review-apply.yml' ), 'R1: deleted review-apply workflow is still present in the source manifest.' );
$assert( str_contains( $manifest, './.github/workflows/corrective-qa.yml' ), 'R1: current corrective QA workflow is absent from the source manifest.' );

// R2 — immutable audit identities/context values must fail closed instead of normalizing/truncating.
$assert( str_contains( $audit, '$raw_action !== $action_name' ) && str_contains( $audit, '$raw_object_id !== $object_id' ) && str_contains( $audit, '$raw_purpose !== $purpose' ), 'R2: immutable audit facts can still normalize silently.' );
$assert( str_contains( $audit, '$raw_scalar !== $scalar' ) && str_contains( $audit, 'spf_audit_context_value_invalid' ), 'R2: audit scalar evidence can still normalize silently.' );

// R3/R4 — manifest top-level identities, collections, writes and dependencies are exact canonical facts.
$normalize_manifest = new ReflectionMethod( SPF_Registry::class, 'normalize_manifest' );
$normalize_manifest->setAccessible( true );
$base_manifest = array(
	'module_key'=>'file-01','owner_file'=>'01','owner_name'=>'File 01','slug'=>'file-01','namespace_prefix'=>'SPF_',
	'software_version'=>'2.0.0','contract_version'=>'2.0.0','state'=>'active','required'=>array(),'optional'=>array(),
	'capabilities'=>array('registry'),'commands'=>array('Register.v1'),'queries'=>array('List.v1'),'events'=>array('Event.v1'),
	'routes'=>array('/platform-foundation/status/'),'data_classes'=>array('governance'),'health'=>array(),
	'canonical_entities'=>array(),'writes'=>array(),
);
$bad = $base_manifest; $bad['module_key'] = 'FILE-01';
$r = $normalize_manifest->invoke( null, $bad );
$assert( is_wp_error( $r ) && 'spf_invalid_manifest_owner' === $r->get_error_code(), 'R3: noncanonical module identity was normalized and accepted.' );
$bad = $base_manifest; $bad['owner_name'] = '<b>File 01</b>';
$r = $normalize_manifest->invoke( null, $bad );
$assert( is_wp_error( $r ) && 'spf_invalid_manifest_identity' === $r->get_error_code(), 'R3: noncanonical owner identity was normalized and accepted.' );
$bad = $base_manifest; $bad['commands'] = array(' Register.v1 ');
$r = $normalize_manifest->invoke( null, $bad );
$assert( is_wp_error( $r ) && 'spf_manifest_collection_invalid' === $r->get_error_code(), 'R4: noncanonical manifest collection value was normalized and accepted.' );
$bad = $base_manifest; $bad['optional'] = array(array('module_key'=>'FILE-20','minimum_version'=>'1.2.0'));
$r = $normalize_manifest->invoke( null, $bad );
$assert( is_wp_error( $r ) && 'spf_invalid_dependency' === $r->get_error_code(), 'R4: noncanonical dependency identity was normalized and accepted.' );
$bad = $base_manifest; $bad['writes'] = array(array('owner_module'=>'file-20','operation'=>'write','purpose'=>'<b>route mapping</b>'));
$r = $normalize_manifest->invoke( null, $bad );
$assert( is_wp_error( $r ) && 'spf_invalid_manifest_write_purpose' === $r->get_error_code(), 'R4: noncanonical write purpose was normalized and accepted.' );

// R5 — contracts/routes/acknowledgements preserve exact identities.
$normalize_contract = new ReflectionMethod( SPF_Registry::class, 'normalize_contract' );
$normalize_contract->setAccessible( true );
$r = $normalize_contract->invoke( null, array('contract_key'=>'Bad Contract','contract_version'=>'2.0.0','owner_module'=>'file-01','status'=>'current','schema'=>array(),'consumers'=>array()) );
$assert( is_wp_error( $r ) && 'spf_invalid_contract' === $r->get_error_code(), 'R5: noncanonical contract key was silently normalized.' );
$normalize_route = new ReflectionMethod( SPF_Registry::class, 'normalize_route' );
$normalize_route->setAccessible( true );
$r = $normalize_route->invoke( null, array('route_key'=>'file01-test','route_path'=>'platform-test','owner_module'=>'file-01','status'=>'active','layout_context'=>'minimal','destination'=>'','redirects'=>array()) );
$assert( is_wp_error( $r ) && 'spf_invalid_route' === $r->get_error_code(), 'R5: noncanonical route path was silently canonicalized.' );
$assert( str_contains( $registry, 'spf_invalid_contract_acknowledgement' ) && str_contains( $registry, '$raw_consumer !== $consumer' ), 'R5: contract acknowledgement identities are not exact-bound.' );

// R6 — empty structured placeholders are not release evidence.
$built = array(
	'source_commit_verified'=>true,'package_checksum_verified'=>true,'reproducible_build'=>true,
	'source_manifest'=>array(),'sbom'=>'sbom-ref',
);
$r = SPF_Governance::validate_evidence_for_state( 'built', $built );
$assert( is_wp_error( $r ) && 'spf_release_evidence_incomplete' === $r->get_error_code(), 'R6: empty structured release evidence was accepted as meaningful.' );
$assert( str_contains( file_get_contents( $root . '/includes/class-spf-governance.php' ), 'has_meaningful_evidence_value' ), 'R6: meaningful evidence predicate is missing.' );

// R7 — lock names and generic external evidence must not collapse through normalization/empty arrays.
$r = SPF_Runtime::acquire_lock( 'Audit Chain', 30, 1 );
$assert( is_wp_error( $r ) && 'spf_lock_name_invalid' === $r->get_error_code(), 'R7: noncanonical lock name was silently collapsed.' );
$assert( str_contains( $runtime, 'has_meaningful_evidence_value' ) && str_contains( $runtime, 'missing meaningful field' ), 'R7: generic external evidence still accepts empty structured placeholders.' );

// R8 — list_contracts implements the documented owner/version/status filters and bounded pagination.
$assert( str_contains( $registry, "'owner_module'" ) && str_contains( $registry, "'contract_version'" ) && str_contains( $registry, "'status'" ) && str_contains( $registry, 'OFFSET %d' ), 'R8: contract query filters or bounded offset pagination are missing.' );
$assert( str_contains( $rest, "'owner_module'    => \$r->get_param( 'owner_module' )" ) && str_contains( $rest, "'offset'          => \$r->get_param( 'offset' )" ), 'R8: REST contract query does not forward documented filters/pagination.' );

// R9 — persisted System Check is a mutation and cannot use the read-only legacy bridge.
$assert( str_contains( $auth, "LEGACY_BOOLEAN_BRIDGE_ACTIONS = array( 'view' )" ) && str_contains( $auth, "'run_system_check' === \$action" ), 'R9: persistent System Check remains on read-only/legacy authorization.' );
$assert( str_contains( $system, "require_action( 'run_system_check'" ) && str_contains( $rest, "'/system-check', 'POST'" ) && str_contains( $rest, "'run_system_check'" ), 'R9: business/REST System Check mutation authorization is incomplete.' );
$assert( str_contains( $admin, "self::guard( 'run_system_check'" ) && str_contains( $admin, '$can_system_check' ), 'R9: admin System Check write path is not capability-aware.' );

// R10 — permanent regression/evidence closure is part of the current exact source tree.
$qa = file_get_contents( $root . '/qa/run-tests.sh' );
$workflow = file_get_contents( $root . '/.github/workflows/corrective-qa.yml' );
$assert( str_contains( $qa, 'fourteenth-ten-round-review-tests.php' ), 'R10: Fourteenth source regression suite is not wired into aggregate QA.' );
$assert( str_contains( $workflow, 'wp-fourteenth-ten-round-smoke.php' ), 'R10: Fourteenth WordPress/MySQL runtime regression is not wired into CI.' );

if ( $failures ) {
	fwrite( STDERR, "Fourteenth ten-round review tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Fourteenth ten-round review assertions: {$assertions}/{$assertions} PASS\n";

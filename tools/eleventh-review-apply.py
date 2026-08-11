from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def replace(path, old, new):
    p = ROOT / path
    s = p.read_text(encoding='utf-8')
    if old not in s:
        raise SystemExit(f'Expected patch context missing: {path}')
    p.write_text(s.replace(old, new, 1), encoding='utf-8')

# R1/R2 — lifecycle truth.
replace('includes/class-spf-plugin.php',
"""\t\t$releases = SPF_Governance::list_releases( 1 );
\t\t$release_status = $releases ? $releases[0]['status'] : 'not-recorded';
\t\t$schema = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
\t\t$health = SPF_System_Check::latest();
\t\t$operational_claim = apply_filters( 'spf_operational_acceptance_status', null, array( 'release_status' => $release_status, 'health' => $health ) );
\t\t$operational = is_array( $operational_claim ) && array_key_exists( 'verified', $operational_claim ) && true === $operational_claim['verified'] && 'deployed' === $release_status && is_array( $health ) && 'pass' === ( $health['overall_status'] ?? '' );
""",
"""\t\t$releases = SPF_Governance::list_releases( 1 );
\t\t$latest_release = $releases ? $releases[0] : array();
\t\t$release_status = $latest_release ? $latest_release['status'] : 'not-recorded';
\t\t$schema = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
\t\t$health = SPF_System_Check::latest();
\t\t$operational_context = array(
\t\t\t'release_id' => (string) ( $latest_release['release_id'] ?? '' ),
\t\t\t'deployed_package_checksum' => (string) ( $latest_release['checksum_sha256'] ?? '' ),
\t\t\t'release_status' => $release_status,
\t\t\t'health' => $health,
\t\t);
\t\t$operational_claim = apply_filters( 'spf_operational_acceptance_status', null, $operational_context );
\t\t$operational = self::validate_operational_claim( $operational_claim, $operational_context );
""")
replace('includes/class-spf-plugin.php',
"'staging_accepted'=>in_array($release_status,array('staged','approved','deployed'),true),",
"'staging_accepted'=>in_array($release_status,array('approved','deployed'),true),")
replace('includes/class-spf-plugin.php',
"\n\tpublic function action_links( $links ) {",
"""

\tprivate static function validate_operational_claim( $claim, array $context ) {
\t\tif ( ! is_array( $claim ) || true !== ( $claim['verified'] ?? false ) || 'deployed' !== ( $context['release_status'] ?? '' ) ) {
\t\t\treturn false;
\t\t}
\t\t$health = $context['health'] ?? null;
\t\tif ( ! is_array( $health ) || 'pass' !== ( $health['overall_status'] ?? '' ) ) {
\t\t\treturn false;
\t\t}
\t\t$release_id = (string) ( $context['release_id'] ?? '' );
\t\t$checksum = strtolower( (string) ( $context['deployed_package_checksum'] ?? '' ) );
\t\tif ( '' === $release_id || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
\t\t\treturn false;
\t\t}
\t\tif ( ! hash_equals( $release_id, (string) ( $claim['release_id'] ?? '' ) ) || ! hash_equals( $checksum, strtolower( (string) ( $claim['deployed_package_checksum'] ?? '' ) ) ) ) {
\t\t\treturn false;
\t\t}
\t\t$required_states = array(
\t\t\t'monitoring_status' => 'pass',
\t\t\t'support_status' => 'ready',
\t\t\t'backup_restore_status' => 'pass',
\t\t\t'slo_status' => 'pass',
\t\t);
\t\tforeach ( $required_states as $field => $expected ) {
\t\t\tif ( $expected !== sanitize_key( (string) ( $claim[ $field ] ?? '' ) ) ) {
\t\t\t\treturn false;
\t\t\t}
\t\t}
\t\t$observed_at = strtotime( (string) ( $claim['observed_at'] ?? '' ) );
\t\treturn false !== $observed_at && $observed_at <= time() + 60;
\t}

\tpublic function action_links( $links ) {""")

# R3 — explicit dedupe must be identity-scoped while honoring exact legacy replay.
replace('includes/class-spf-event-bus.php',
"""\t\tif ( $dedupe_key ) {
\t\t\t$raw_dedupe_key = (string) $dedupe_key;
\t\t\t$canonical_dedupe_key = sanitize_text_field( $raw_dedupe_key );
\t\t\t$dedupe_key = ( $raw_dedupe_key === $canonical_dedupe_key && strlen( $canonical_dedupe_key ) <= 191 )
\t\t\t\t? $canonical_dedupe_key
\t\t\t\t: hash( 'sha256', $raw_dedupe_key );
\t\t} else {
""",
"""\t\tif ( $dedupe_key ) {
\t\t\t$raw_dedupe_key = (string) $dedupe_key;
\t\t\t$canonical_dedupe_key = sanitize_text_field( $raw_dedupe_key );
\t\t\t$legacy_dedupe_key = ( $raw_dedupe_key === $canonical_dedupe_key && strlen( $canonical_dedupe_key ) <= 191 )
\t\t\t\t? $canonical_dedupe_key
\t\t\t\t: hash( 'sha256', $raw_dedupe_key );
\t\t\t$scope_dedupe_key = $legacy_dedupe_key;
\t\t\t$dedupe_key = hash( 'sha256', $event_name . '|' . $version . '|' . $aggregate_type . '|' . $aggregate_id . '|' . $privacy_class . '|custom|' . $scope_dedupe_key );
\t\t\t$legacy_match = $wpdb->get_var(
\t\t\t\t$wpdb->prepare(
\t\t\t\t\t'SELECT event_id FROM ' . SPF_Installer::table( 'outbox' ) . ' WHERE dedupe_key=%s AND event_name=%s AND event_version=%d AND aggregate_type=%s AND aggregate_id=%s AND privacy_class=%s LIMIT 1',
\t\t\t\t\t$legacy_dedupe_key, $event_name, $version, $aggregate_type, $aggregate_id, $privacy_class
\t\t\t\t)
\t\t\t);
\t\t\tif ( ! empty( $wpdb->last_error ) ) {
\t\t\t\treturn new WP_Error( 'spf_event_dedupe_lookup_failed', __( 'Existing event idempotency state could not be verified.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\tif ( is_string( $legacy_match ) && '' !== $legacy_match ) {
\t\t\t\treturn true;
\t\t\t}
\t\t} else {
""")

# R4 — migration backup evidence exact binding.
replace('includes/class-spf-installer.php',
"""\t\t$evidence = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_migration_backup_evidence',
\t\t\tarray( 'module'=>'file-01', 'from'=>$current, 'to'=>SPF_SCHEMA_VERSION, 'environment'=>$environment ),
\t\t\tarray( 'backup_id','restore_tested_at','environment','verifier' )
\t\t);
\t\t$internal_snapshot_allowed = defined( 'SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE' ) && true === SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE && in_array( $environment, array( 'local','development','staging' ), true );
""",
"""\t\t$evidence_context = array( 'module'=>'file-01', 'from'=>$current, 'to'=>SPF_SCHEMA_VERSION, 'environment'=>$environment );
\t\t$evidence = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_migration_backup_evidence',
\t\t\t$evidence_context,
\t\t\tarray( 'backup_id','restore_tested_at','environment','verifier','module','from','to' )
\t\t);
\t\tif ( ! is_wp_error( $evidence ) ) {
\t\t\tforeach ( array( 'module','from','to','environment' ) as $binding_field ) {
\t\t\t\tif ( ! array_key_exists( $binding_field, $evidence ) || ! hash_equals( (string) $evidence_context[ $binding_field ], (string) $evidence[ $binding_field ] ) ) {
\t\t\t\t\treturn new WP_Error( 'spf_migration_backup_evidence_binding_invalid', __( 'Migration backup evidence is not bound to this exact File 01 upgrade context.', 'sabri-platform-foundation' ), array( 'status'=>412, 'field'=>$binding_field ) );
\t\t\t\t}
\t\t\t}
\t\t}
\t\t$internal_snapshot_allowed = defined( 'SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE' ) && true === SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE && in_array( $environment, array( 'local','development','staging' ), true );
""")

# R5 — purge evidence chain exact binding.
replace('includes/class-spf-purge.php',
"""\t\t$verified = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_backup_restore_evidence',
\t\t\tarray( 'operation'=>'file01_purge','plan_hash'=>$hash,'submitted_evidence'=>$backup_evidence,'table_summary'=>$plan['tables'] ),
\t\t\tarray( 'backup_id','backup_checksum','restore_tested_at','restore_environment','verifier','expires_at' )
\t\t);
\t\tif ( is_wp_error( $verified ) ) {
\t\t\treturn $verified;
\t\t}
\t\t$assurance = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_file24_purge_assurance',
\t\t\tarray( 'operation'=>'file01_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'audit_chain_head'=>$plan['audit_chain_head'] ),
\t\t\tarray( 'assurance_id','reviewed_at','verifier','expires_at' )
\t\t);
\t\tif ( is_wp_error( $assurance ) ) {
\t\t\treturn $assurance;
\t\t}
""",
"""\t\t$backup_context = array( 'operation'=>'file01_purge','plan_hash'=>$hash,'submitted_evidence'=>$backup_evidence,'table_summary'=>$plan['tables'] );
\t\t$verified = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_backup_restore_evidence',
\t\t\t$backup_context,
\t\t\tarray( 'backup_id','backup_checksum','restore_tested_at','restore_environment','verifier','expires_at','operation','plan_hash' )
\t\t);
\t\tif ( is_wp_error( $verified ) ) {
\t\t\treturn $verified;
\t\t}
\t\tif ( ! hash_equals( 'file01_purge', (string) $verified['operation'] ) || ! hash_equals( $hash, strtolower( (string) $verified['plan_hash'] ) ) ) {
\t\t\treturn new WP_Error( 'spf_purge_backup_evidence_binding_invalid', __( 'Backup/restore evidence is not bound to this exact purge plan.', 'sabri-platform-foundation' ), array( 'status'=>412 ) );
\t\t}
\t\t$assurance_context = array( 'operation'=>'file01_purge','plan_hash'=>$hash,'backup_evidence_hash'=>$verified['evidence_hash'],'audit_chain_head'=>$plan['audit_chain_head'] );
\t\t$assurance = SPF_Runtime::verify_evidence(
\t\t\t'spf_verify_file24_purge_assurance',
\t\t\t$assurance_context,
\t\t\tarray( 'assurance_id','reviewed_at','verifier','expires_at','operation','plan_hash','backup_evidence_hash','audit_chain_head' )
\t\t);
\t\tif ( is_wp_error( $assurance ) ) {
\t\t\treturn $assurance;
\t\t}
\t\tforeach ( array( 'operation','plan_hash','backup_evidence_hash','audit_chain_head' ) as $binding_field ) {
\t\t\tif ( ! hash_equals( (string) $assurance_context[ $binding_field ], (string) $assurance[ $binding_field ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_purge_assurance_binding_invalid', __( 'File 24 purge assurance is not bound to this exact destructive-operation evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>412, 'field'=>$binding_field ) );
\t\t\t}
\t\t}
""")

# R6 — canonical registry declarations fail closed rather than normalize/collapse.
replace('includes/class-spf-registry.php',
"""\t\t$canonical_entities = array();
\t\tforeach ( (array) ( $manifest['canonical_entities'] ?? array() ) as $entity ) {
\t\t\t$key = sanitize_key( is_array( $entity ) ? ( $entity['key'] ?? '' ) : $entity );
\t\t\tif ( '' === $key ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_entity', __( 'Every canonical entity declaration requires a valid canonical key.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$canonical_entities[] = $key;
\t\t}
\t\t$manifest['canonical_entities'] = array_values( array_unique( $canonical_entities ) );
\t\t$writes = array();
""",
"""\t\t$canonical_entities = array();
\t\t$seen_entities = array();
\t\tforeach ( (array) ( $manifest['canonical_entities'] ?? array() ) as $entity ) {
\t\t\t$raw_key = (string) ( is_array( $entity ) ? ( $entity['key'] ?? '' ) : $entity );
\t\t\t$key = sanitize_key( $raw_key );
\t\t\tif ( '' === $key || $raw_key !== $key ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_entity', __( 'Every canonical entity declaration must already use its exact canonical key.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\tif ( isset( $seen_entities[ $key ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_duplicate_manifest_entity', __( 'A canonical entity may be declared only once in a module manifest.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$seen_entities[ $key ] = true;
\t\t\t$canonical_entities[] = $key;
\t\t}
\t\t$manifest['canonical_entities'] = $canonical_entities;
\t\t$writes = array();
\t\t$seen_writes = array();
""")
replace('includes/class-spf-registry.php',
"""\t\t\t$target = sanitize_key( $write['owner_module'] ?? '' );
\t\t\tif ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $target ) ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_write_owner', __( 'Manifest write declarations require a canonical owner module.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$operation = substr( sanitize_key( $write['operation'] ?? 'write' ), 0, 64 );
\t\t\tif ( '' === $operation ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_write_operation', __( 'Manifest write declarations require a valid operation.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$writes[] = array(
\t\t\t\t'owner_module' => $target,
\t\t\t\t'operation'    => $operation,
\t\t\t\t'purpose'      => substr( sanitize_text_field( $write['purpose'] ?? '' ), 0, 191 ),
\t\t\t);
""",
"""\t\t\t$raw_target = (string) ( $write['owner_module'] ?? '' );
\t\t\t$target = sanitize_key( $raw_target );
\t\t\tif ( $raw_target !== $target || ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $target ) ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_write_owner', __( 'Manifest write declarations require an exact canonical owner module.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$raw_operation = (string) ( $write['operation'] ?? 'write' );
\t\t\t$operation = substr( sanitize_key( $raw_operation ), 0, 64 );
\t\t\tif ( '' === $operation || $raw_operation !== $operation ) {
\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_write_operation', __( 'Manifest write declarations require an exact canonical operation.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$write_identity = $target . '|' . $operation;
\t\t\tif ( isset( $seen_writes[ $write_identity ] ) ) {
\t\t\t\treturn new WP_Error( 'spf_duplicate_manifest_write', __( 'A manifest write target/operation pair may be declared only once.', 'sabri-platform-foundation' ) );
\t\t\t}
\t\t\t$seen_writes[ $write_identity ] = true;
\t\t\t$writes[] = array(
\t\t\t\t'owner_module' => $target,
\t\t\t\t'operation'    => $operation,
\t\t\t\t'purpose'      => substr( sanitize_text_field( $write['purpose'] ?? '' ), 0, 191 ),
\t\t\t);
""")

# Static 10-round regression suite.
(ROOT / 'tests/eleventh-ten-round-review-tests.php').write_text(r'''<?php
declare(strict_types=1);
$assertions=0;$failures=[];
$assert=static function(bool $c,string $m)use(&$assertions,&$failures):void{$assertions++;if(!$c){$failures[]=$m;}};
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);$has=static fn(string $p,string $n):bool=>str_contains($read($p),$n);
$plugin=$read('includes/class-spf-plugin.php');
$assert(str_contains($plugin,"'staging_accepted'=>in_array(\$release_status,array('approved','deployed'),true)")&&!str_contains($plugin,"'staging_accepted'=>in_array(\$release_status,array('staged','approved','deployed'),true)"),'Round 1: staged can still be mislabeled Staging-Accepted.');
$assert($has('includes/class-spf-plugin.php','validate_operational_claim')&&$has('includes/class-spf-plugin.php',"'monitoring_status' => 'pass'")&&$has('includes/class-spf-plugin.php',"'backup_restore_status' => 'pass'")&&$has('includes/class-spf-plugin.php',"'slo_status' => 'pass'")&&$has('includes/class-spf-plugin.php','deployed_package_checksum'),'Round 2: Operational remains satisfiable by generic verified state.');
$assert($has('includes/class-spf-event-bus.php','$scope_dedupe_key')&&$has('includes/class-spf-event-bus.php','$legacy_dedupe_key')&&$has('includes/class-spf-event-bus.php',"'|custom|' . \$scope_dedupe_key"),'Round 3: explicit dedupe remains globally collision-prone or legacy replay-unsafe.');
$assert($has('includes/class-spf-installer.php','spf_migration_backup_evidence_binding_invalid')&&$has('includes/class-spf-installer.php',"array( 'module','from','to','environment' )"),'Round 4: migration evidence lacks exact context binding.');
$assert($has('includes/class-spf-purge.php','spf_purge_backup_evidence_binding_invalid')&&$has('includes/class-spf-purge.php','spf_purge_assurance_binding_invalid'),'Round 5: purge evidence chain lacks exact binding.');
$assert($has('includes/class-spf-registry.php','spf_duplicate_manifest_entity')&&$has('includes/class-spf-registry.php','spf_duplicate_manifest_write')&&$has('includes/class-spf-registry.php','$raw_target !== $target')&&$has('includes/class-spf-registry.php','$raw_operation !== $operation'),'Round 6: architecture declarations can still normalize/collapse ambiguously.');
$assert($has('includes/class-spf-authorization.php','SENSITIVE_ACTIONS')&&$has('includes/class-spf-authorization.php','validate_claim'),'Round 7: authorization boundary regressed.');
$assert($has('includes/class-spf-event-bus.php','handler_completion_ambiguous')&&$has('includes/class-spf-event-bus.php','reconciliation_required'),'Round 8: ambiguous event completion can auto-replay.');
$assert($has('tools/build-package.sh','TOP="sabri-platform-foundation-01"')&&$has('includes/class-spf-plugin.php','No public shell, feed, profile, identity, Security Center, notification truth or search-truth ownership.'),'Round 9: package/ownership boundary drifted.');
$assert($has('STAGING-ACCEPTANCE.md','- [ ]')&&$has('KNOWN-LIMITATIONS.md','staging')&&$has('RELEASE-CHECKLIST.md','rollback'),'Round 10: staging/live/operational boundary is not explicit.');
if(10!==$assertions){$failures[]='Expected exactly 10 assertions; got '.$assertions.'.';}if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}echo "Eleventh ten-round review tests: 10/10 PASS\n";
''',encoding='utf-8')

# WordPress/MySQL runtime negative regressions.
(ROOT / 'qa/wp-eleventh-ten-round-smoke.php').write_text(r'''<?php
$assertions=0;$failures=[];$assert=static function(bool $c,string $m)use(&$assertions,&$failures):void{$assertions++;if(!$c){$failures[]=$m;}};$admin=get_user_by('login','admin');wp_set_current_user($admin?$admin->ID:1);global $wpdb;
$outbox=SPF_Installer::table('outbox');$wpdb->query("DELETE FROM {$outbox} WHERE event_name LIKE 'EleventhReview.%'");
$one=SPF_Event_Bus::publish('EleventhReview.One.v1','review_probe','a',['value'=>1],1,'same-caller-key','internal');$two=SPF_Event_Bus::publish('EleventhReview.One.v1','review_probe','b',['value'=>1],1,'same-caller-key','internal');$dup=SPF_Event_Bus::publish('EleventhReview.One.v1','review_probe','a',['value'=>999],1,'same-caller-key','internal');$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$outbox} WHERE event_name='EleventhReview.One.v1'");$assert(true===$one&&true===$two&&true===$dup&&2===$count,'Explicit dedupe key was not safely scoped/idempotent.');
add_filter('spf_file00_authorization_claim',static function($claim,array $r){$role=in_array($r['action'],['approve_release','deploy_release','approve_amendment','purge','production_cutover'],true)?'founder':'release_operator';return['claim_version'=>'1.2.0','allowed'=>true,'user_id'=>$r['user_id'],'actor_id'=>$r['user_id'],'action'=>$r['action'],'capability'=>$r['capability'],'issued_at'=>time()-5,'expires_at'=>time()+300,'claim_id'=>wp_generate_uuid4(),'object_hash'=>$r['object_hash'],'purpose'=>$r['purpose'],'institutional_role'=>$role,'suspended'=>false,'revoked'=>false];},50,2);
update_option(SPF_Installer::SCHEMA_OPTION,'1.0.0',false);add_filter('spf_verify_migration_backup_evidence',static function($claim,array $c){return['verified'=>true,'backup_id'=>'wrong-context-backup','restore_tested_at'=>gmdate('c'),'verifier'=>'CI negative probe','module'=>(string)($c['module']??''),'from'=>(string)($c['from']??''),'to'=>(string)($c['to']??''),'environment'=>'production','expires_at'=>gmdate('c',time()+3600)];},50,2);$upgrade=SPF_Installer::maybe_upgrade();$assert(is_wp_error($upgrade)&&'spf_migration_backup_evidence_binding_invalid'===$upgrade->get_error_code(),'Wrong-context migration evidence was accepted.');update_option(SPF_Installer::SCHEMA_OPTION,SPF_SCHEMA_VERSION,false);
add_filter('spf_verify_backup_restore_evidence',static function($claim,array $c){return['verified'=>true,'backup_id'=>'negative-probe','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI negative probe','expires_at'=>gmdate('c',time()+3600),'operation'=>'file01_purge','plan_hash'=>str_repeat('0',64)];},50,2);$plan=SPF_Purge::plan();$purge=SPF_Purge::apply('PURGE FILE 01 GOVERNANCE DATA',['backup_id'=>'submitted'],SPF_Purge::plan_hash($plan));$assert(is_wp_error($purge)&&'spf_purge_backup_evidence_binding_invalid'===$purge->get_error_code(),'Wrong-plan purge backup evidence was accepted.');
$manifest=SPF_Registry::get_module('file-01');$manifest['canonical_entities']=['Not Canonical'];$manifest['writes']=[];$mr=SPF_Installer::with_internal_seed(static fn()=>SPF_Registry::register_manifest($manifest));$assert(is_wp_error($mr)&&'spf_invalid_manifest_entity'===$mr->get_error_code(),'Noncanonical manifest entity was silently normalized.');
if($failures){fwrite(STDERR,"Eleventh ten-round runtime smoke failed:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Eleventh ten-round runtime assertions: {$assertions}/{$assertions} PASS\n";
''',encoding='utf-8')

# Existing source runner includes the new suite.
replace('qa/run-tests.sh',
"if [[ -f tests/tenth-fresh-eighty-round-review-tests.php ]]; then php tests/tenth-fresh-eighty-round-review-tests.php; fi\nphp tests/source-quality-tests.php",
"if [[ -f tests/tenth-fresh-eighty-round-review-tests.php ]]; then php tests/tenth-fresh-eighty-round-review-tests.php; fi\nphp tests/eleventh-ten-round-review-tests.php\nphp tests/source-quality-tests.php")

# Existing destructive-purge smoke supplies the newly bound evidence envelope.
replace('qa/wp-purge-smoke.php',
"""add_filter( 'spf_verify_backup_restore_evidence', static fn() => [
\t'verified'=>true,'backup_id'=>'disposable-ci-backup','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI','expires_at'=>gmdate('c',time()+3600),
] );
add_filter( 'spf_verify_file24_purge_assurance', static fn() => [
\t'verified'=>true,'assurance_id'=>'file24-ci-assurance','reviewed_at'=>gmdate('c'),'verifier'=>'CI File24 adapter','expires_at'=>gmdate('c',time()+3600),
] );
""",
"""add_filter( 'spf_verify_backup_restore_evidence', static function ( $claim, array $context ) {
\treturn [
\t\t'verified'=>true,'backup_id'=>'disposable-ci-backup','backup_checksum'=>str_repeat('a',64),'restore_tested_at'=>gmdate('c'),'restore_environment'=>'disposable-ci','verifier'=>'CI','expires_at'=>gmdate('c',time()+3600),
\t\t'operation'=>(string)($context['operation']??''),'plan_hash'=>(string)($context['plan_hash']??''),
\t];
}, 10, 2 );
add_filter( 'spf_verify_file24_purge_assurance', static function ( $claim, array $context ) {
\treturn [
\t\t'verified'=>true,'assurance_id'=>'file24-ci-assurance','reviewed_at'=>gmdate('c'),'verifier'=>'CI File24 adapter','expires_at'=>gmdate('c',time()+3600),
\t\t'operation'=>(string)($context['operation']??''),'plan_hash'=>(string)($context['plan_hash']??''),'backup_evidence_hash'=>(string)($context['backup_evidence_hash']??''),'audit_chain_head'=>(string)($context['audit_chain_head']??''),
\t];
}, 10, 2 );
""")

# Runtime workflow executes the new regression smoke before destructive purge.
replace('.github/workflows/corrective-qa.yml',
"""      - name: Run Future Foundation 18-enhancement runtime smoke
        run: wp eval-file /tmp/wp/wp-content/plugins/sabri-platform-foundation-01/qa/wp-future-foundation-smoke.php --user=admin --path=/tmp/wp
      - name: Run concurrent idempotency test
""",
"""      - name: Run Future Foundation 18-enhancement runtime smoke
        run: wp eval-file /tmp/wp/wp-content/plugins/sabri-platform-foundation-01/qa/wp-future-foundation-smoke.php --user=admin --path=/tmp/wp
      - name: Run eleventh ten-round runtime regressions
        run: wp eval-file /tmp/wp/wp-content/plugins/sabri-platform-foundation-01/qa/wp-eleventh-ten-round-smoke.php --user=admin --path=/tmp/wp
      - name: Run concurrent idempotency test
""")

(ROOT / 'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md').write_text('''# File 01 — Eleventh Fresh Ten-Round Corrective Review — 2026-08-11

## Governing boundary
Repository/source and automated-runtime evidence only. It does not convert repository truth into Hostinger staging, live deployment, or sustained operational truth. File 01 remains the foundation/governance owner only; File 00 identity/authorization, File 20 shell, File 19 notifications, File 21 feed/publication, File 24 assurance, and other numbered domain owners remain canonical.

## Round-by-round record
| Round | Lens | Finding / correction | Closure |
|---|---|---|---|
| 1 | Seven-status lifecycle | **F01-R11-D001:** `staged` was mislabeled `Staging-Accepted`. Corrected so acceptance begins only at `approved`/`deployed`. | Fixed + regression. |
| 2 | Operational truth | **F01-R11-D002:** generic `verified=true` could satisfy Operational. Added release/package binding plus monitoring, support, backup/restore, SLO and observation evidence. | Fixed + regression. |
| 3 | Event dedupe | **F01-R11-D003:** explicit caller key was globally collision-prone. Scoped it to event/version/aggregate/privacy, with exact legacy replay lookup to avoid one-time upgrade re-emission. | Fixed + WP/MySQL regression. |
| 4 | Migration evidence | **F01-R11-D004:** backup evidence was not bound to module/from/to/environment. Added exact binding and fail-closed mismatch error. | Fixed + negative regression. |
| 5 | Purge evidence chain | **F01-R11-D005:** backup/File24 claims were not bound to exact purge plan/evidence chain. Added operation/plan/backup-hash/audit-head binding. | Fixed + purge regressions. |
| 6 | Architecture registry | **F01-R11-D006:** canonical entities/writes could silently normalize/collapse. Exact canonical keys and duplicate rejection are now mandatory. | Fixed + regression. |
| 7 | File 00 authorization boundary | No new defect found; sensitive mutations remain structured-claim fail-closed. | Clean. |
| 8 | Outbox replay/failure safety | No new defect found; ambiguous handler completion remains `reconciliation_required`. | Clean. |
| 9 | Ownership/package boundary | No new defect found; canonical folder and non-owner boundaries remain intact. | Clean. |
| 10 | Staging/live/operational boundary | No new defect found after R1/R2; staging checklist and rollback gates remain separate. | Clean. |

**Defect-bearing rounds: 1, 2, 3, 4, 5, 6.**  
**Clean rounds: 7, 8, 9, 10.**

Added verification: `tests/eleventh-ten-round-review-tests.php` (10/10 review assertions), `qa/wp-eleventh-ten-round-smoke.php` (runtime negative regressions), updated purge smoke, and corrective workflow execution before deterministic packaging.

## Acceptance boundary
Exact-head green CI/package proves only tested repository/source and disposable WordPress/MySQL scope. Hostinger staging, real companion coexistence, browser/device/a11y/RTL/weak-network, load/cache/cron, independent backup/restore + rollback, Founder staging acceptance, live cutover, and sustained Operational evidence remain separate mandatory gates.
''',encoding='utf-8')

print('Eleventh ten-round corrective source patch prepared.')

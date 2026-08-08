#!/usr/bin/env python3
from pathlib import Path
import hashlib
import json
import re
import subprocess


def sh(*args):
    subprocess.run(args, check=True)


def text(path):
    return Path(path).read_text()


def write(path, value):
    Path(path).write_text(value)


def replace_once(path, old, new):
    s = text(path)
    if old not in s:
        raise SystemExit(f"expected block missing in {path}: {old[:90]!r}")
    write(path, s.replace(old, new, 1))


def sub_once(path, pattern, repl):
    s = text(path)
    out, count = re.subn(pattern, repl, s, flags=re.S)
    if count != 1:
        raise SystemExit(f"expected exactly one regex match in {path}; got {count}: {pattern[:100]}")
    write(path, out)


def regen_checksums():
    sh('git', 'add', '-A')
    tracked = subprocess.check_output(['git', 'ls-files', '-z']).split(b'\0')
    rows = []
    for raw in tracked:
        if not raw:
            continue
        f = raw.decode()
        if f == 'SOURCE-CHECKSUMS.sha256' or f.startswith('build/') or f.startswith('dist/'):
            continue
        p = Path(f)
        if not p.is_file():
            continue
        rows.append((f, hashlib.sha256(p.read_bytes()).hexdigest()))
    rows.sort(key=lambda x: x[0])
    Path('SOURCE-CHECKSUMS.sha256').write_text(''.join(f'{digest}  {f}\n' for f, digest in rows))
    sh('git', 'add', 'SOURCE-CHECKSUMS.sha256')


def finish_round(message):
    regen_checksums()
    sh('bash', 'qa/run-tests.sh')
    sh('git', 'add', '-A')
    sh('git', 'commit', '-m', message)


# ROUND 1 — Runtime architecture-linter manifest fidelity.
replace_once(
    'includes/class-spf-registry.php',
    """\t\tif ( ! is_array( $manifest['health'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_manifest', 'Manifest health declaration must be structured.' );\n\t\t}\n\t\t$manifest['module_key'] = $module_key;""",
    """\t\tif ( ! is_array( $manifest['health'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_manifest', 'Manifest health declaration must be structured.' );\n\t\t}\n\t\tforeach ( array( 'canonical_entities', 'writes' ) as $architecture_field ) {\n\t\t\tif ( isset( $manifest[ $architecture_field ] ) && ! is_array( $manifest[ $architecture_field ] ) ) {\n\t\t\t\treturn new WP_Error( 'spf_invalid_manifest', 'Manifest architecture field must be an array: ' . $architecture_field );\n\t\t\t}\n\t\t}\n\t\t$canonical_entities = array();\n\t\tforeach ( array_slice( (array) ( $manifest['canonical_entities'] ?? array() ), 0, 128 ) as $entity ) {\n\t\t\t$key = sanitize_key( is_array( $entity ) ? ( $entity['key'] ?? '' ) : $entity );\n\t\t\tif ( $key ) { $canonical_entities[] = $key; }\n\t\t}\n\t\t$manifest['canonical_entities'] = array_values( array_unique( $canonical_entities ) );\n\t\t$writes = array();\n\t\tforeach ( array_slice( (array) ( $manifest['writes'] ?? array() ), 0, 128 ) as $write ) {\n\t\t\tif ( ! is_array( $write ) ) { continue; }\n\t\t\t$target = sanitize_key( $write['owner_module'] ?? '' );\n\t\t\tif ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $target ) ) {\n\t\t\t\treturn new WP_Error( 'spf_invalid_manifest_write_owner', __( 'Manifest write declarations require a canonical owner module.', 'sabri-platform-foundation' ) );\n\t\t\t}\n\t\t\t$writes[] = array(\n\t\t\t\t'owner_module' => $target,\n\t\t\t\t'operation'    => substr( sanitize_key( $write['operation'] ?? 'write' ), 0, 64 ),\n\t\t\t\t'purpose'      => substr( sanitize_text_field( $write['purpose'] ?? '' ), 0, 191 ),\n\t\t\t);\n\t\t}\n\t\t$manifest['writes'] = $writes;\n\t\t$manifest['global_shell_owner'] = ! empty( $manifest['global_shell_owner'] );\n\t\t$manifest['application_shell_owner'] = ! empty( $manifest['application_shell_owner'] );\n\t\t$manifest['module_key'] = $module_key;""",
)
replace_once(
    'includes/class-spf-registry.php',
    """\t\t\t'events' => $manifest['events'] ?? array(), 'routes' => $manifest['routes'] ?? array(), 'data_classes' => $manifest['data_classes'] ?? array(),\n\t\t\t'health' => $manifest['health'] ?? array(), 'record_version' => (int) $row['record_version'], 'updated_at' => $row['updated_at'],""",
    """\t\t\t'events' => $manifest['events'] ?? array(), 'routes' => $manifest['routes'] ?? array(), 'data_classes' => $manifest['data_classes'] ?? array(),\n\t\t\t'canonical_entities' => $manifest['canonical_entities'] ?? array(), 'writes' => $manifest['writes'] ?? array(),\n\t\t\t'global_shell_owner' => ! empty( $manifest['global_shell_owner'] ), 'application_shell_owner' => ! empty( $manifest['application_shell_owner'] ),\n\t\t\t'health' => $manifest['health'] ?? array(), 'record_version' => (int) $row['record_version'], 'updated_at' => $row['updated_at'],""",
)
replace_once(
    'includes/class-spf-governance-control-plane.php',
    "\t\t\t$manifest = (array) ( $module['manifest'] ?? array() );",
    "\t\t\t$manifest = (array) ( $module['manifest'] ?? $module );",
)
s = text('tests/future-foundation-tests.php')
marker = "$trace=SPF_Governance_Control_Plane::build_traceability_report"
insert = """$normalize_manifest=new ReflectionMethod(SPF_Registry::class,'normalize_manifest');$normalize_manifest->setAccessible(true);\n$architecture_manifest=$normalize_manifest->invoke(null,[\n 'module_key'=>'file-20','owner_file'=>'20','owner_name'=>'Shell','slug'=>'shell','namespace_prefix'=>'SHELL_','software_version'=>'1.0.0','contract_version'=>'1.0.0','state'=>'active',\n 'required'=>[],'optional'=>[],'capabilities'=>[],'commands'=>[],'queries'=>[],'events'=>[],'routes'=>[],'data_classes'=>[],'health'=>[],\n 'canonical_entities'=>['global-shell'],'writes'=>[['owner_module'=>'file-20','operation'=>'write']],'global_shell_owner'=>true,\n]);\n$assert(!is_wp_error($architecture_manifest)&&($architecture_manifest['canonical_entities'][0]??'')==='global-shell','Manifest architecture declarations were not preserved.');\n$assert(($architecture_manifest['global_shell_owner']??false)===true,'Shell-owner declaration was not preserved.');\n\n"""
if marker not in s:
    raise SystemExit('round1 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, insert + marker, 1))
finish_round('Review 1: preserve runtime architecture manifest declarations')

# ROUND 2 — Golden-path scaffolder must generate a registry-valid manifest.
replace_once(
    'includes/class-spf-platform-engineering.php',
    """\t\tif ( '' === $module_key || '' === $owner_file || '' === $slug || '' === $prefix || strlen( $prefix ) > 16 ) {\n\t\t\treturn new WP_Error( 'spf_scaffold_invalid', __( 'Module key, owner file, slug and bounded namespace prefix are required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );\n\t\t}""",
    """\t\tif ( '' === $module_key || '' === $owner_file || '' === $slug || '' === $prefix || strlen( $prefix ) > 16\n\t\t\t|| ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $module_key )\n\t\t\t|| ! preg_match( '/^(?:0[0-9]|1[0-9]|2[0-6])$/', $owner_file )\n\t\t\t|| $module_key !== 'file-' . $owner_file ) {\n\t\t\treturn new WP_Error( 'spf_scaffold_invalid', __( 'A currently approved canonical module key, matching owner file, slug and bounded namespace prefix are required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );\n\t\t}""",
)
replace_once(
    'includes/class-spf-platform-engineering.php',
    """\t\t\t'commands'         => array(),\n\t\t\t'queries'          => array(),\n\t\t\t'events'           => array(),\n\t\t\t'privacy_classes'  => array( 'internal' ),""",
    """\t\t\t'capabilities'     => array(),\n\t\t\t'commands'         => array(),\n\t\t\t'queries'          => array(),\n\t\t\t'events'           => array(),\n\t\t\t'routes'           => array(),\n\t\t\t'data_classes'     => array( 'internal' ),\n\t\t\t'health'           => array( 'callback' => '', 'contract' => '' ),\n\t\t\t'canonical_entities'=> array(),\n\t\t\t'writes'           => array(),\n\t\t\t'global_shell_owner'=> false,\n\t\t\t'application_shell_owner'=> false,""",
)
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\n\tprivate static function normalize_scaffold_dependencies\( array \$dependencies \) \{.*?\n\t\}\n\n\tprivate static function sanitize_numeric_map",
    """
\tprivate static function normalize_scaffold_dependencies( array $dependencies ) {
\t\t$out = array();
\t\t$seen = array();
\t\tforeach ( array_slice( $dependencies, 0, 100 ) as $dependency ) {
\t\t\tif ( is_array( $dependency ) ) {
\t\t\t\t$key = sanitize_key( $dependency['module_key'] ?? '' );
\t\t\t\t$minimum = sanitize_text_field( $dependency['minimum_version'] ?? '0.0.0' );
\t\t\t\t$maximum = sanitize_text_field( $dependency['maximum_version'] ?? '' );
\t\t\t} else {
\t\t\t\t$key = sanitize_key( $dependency );
\t\t\t\t$minimum = '0.0.0';
\t\t\t\t$maximum = '';
\t\t\t}
\t\t\tif ( ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/', $key ) || ! SPF_Registry::valid_semver( $minimum )
\t\t\t\t|| ( $maximum && ! SPF_Registry::valid_semver( $maximum ) ) || ( $maximum && version_compare( $minimum, $maximum, '>' ) ) ) {
\t\t\t\treturn new WP_Error( 'spf_scaffold_dependency_invalid', __( 'Generated module dependencies require a canonical module key and valid semantic version range.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\tif ( isset( $seen[ $key ] ) ) { continue; }
\t\t\t$seen[ $key ] = true;
\t\t\t$out[] = array( 'module_key'=>$key, 'minimum_version'=>$minimum, 'maximum_version'=>$maximum );
\t\t}
\t\treturn $out;
\t}

\tprivate static function sanitize_numeric_map""",
)
s = text('tests/future-foundation-tests.php')
s = s.replace("['module_key'=>'file-27','owner_file'=>'27','owner_name'=>'Example Module'", "['module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Example Module'", 1)
marker = "$assert(($scaffold['manifest']['required'][0]['minimum_version']??'')==='2.0.0','Scaffolder lost minimum dependency version.');"
addition = """$assert(isset($scaffold['manifest']['capabilities'],$scaffold['manifest']['routes'],$scaffold['manifest']['data_classes'],$scaffold['manifest']['health']),'Generated manifest is missing registry-required fields.');\n$generated_manifest_check=$normalize_manifest->invoke(null,$scaffold['manifest']);\n$assert(!is_wp_error($generated_manifest_check),'Golden-path scaffold is not accepted by the File 01 manifest contract.');\n"""
if marker not in s:
    raise SystemExit('round2 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, marker + '\n' + addition, 1))
finish_round('Review 2: make golden-path scaffolds registry-valid and canonically numbered')

# ROUND 3 — Developer service catalog and File 01 v2 capability declaration.
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\tpublic static function service_catalog\(\) \{.*?\n\t\}\n\n\tpublic static function scaffold_module",
    """\tpublic static function service_catalog() {
\t\t$modules = SPF_Registry::list_modules( array( 'limit' => 200 ) );
\t\t$contracts = SPF_Registry::list_contracts( array( 'limit' => 200 ) );
\t\t$routes = SPF_Registry::list_routes();
\t\t$readiness = SPF_Dependency_Resolver::all_readiness();
\t\t$readiness_by_key = array();
\t\tforeach ( $readiness as $item ) {
\t\t\tif ( is_array( $item ) && ! empty( $item['module_key'] ) ) { $readiness_by_key[ sanitize_key( $item['module_key'] ) ] = $item; }
\t\t}
\t\t$catalog = array();
\t\tforeach ( $modules as $module ) {
\t\t\tif ( ! is_array( $module ) ) { continue; }
\t\t\t$key = sanitize_key( $module['module_key'] ?? '' );
\t\t\t$catalog[] = array(
\t\t\t\t'module_key'=>$key, 'owner_file'=>sanitize_text_field($module['owner_file']??''), 'owner_name'=>sanitize_text_field($module['owner_name']??''),
\t\t\t\t'software_version'=>sanitize_text_field($module['software_version']??''), 'contract_version'=>sanitize_text_field($module['contract_version']??''), 'state'=>sanitize_key($module['state']??''),
\t\t\t\t'capabilities'=>array_values((array)($module['capabilities']??array())), 'required'=>array_values((array)($module['required']??array())), 'optional'=>array_values((array)($module['optional']??array())),
\t\t\t\t'canonical_entities'=>array_values((array)($module['canonical_entities']??array())), 'readiness'=>$readiness_by_key[$key]??array('ready'=>false,'code'=>'not_evaluated'),
\t\t\t);
\t\t}
\t\t$contract_catalog = array();
\t\tforeach ( $contracts as $contract ) {
\t\t\tif ( ! is_array( $contract ) ) { continue; }
\t\t\t$contract_catalog[] = array('contract_key'=>sanitize_text_field($contract['contract_key']??''),'contract_version'=>sanitize_text_field($contract['contract_version']??''),'owner_module'=>sanitize_key($contract['owner_module']??''),'status'=>sanitize_key($contract['status']??''),'consumers'=>array_values((array)($contract['consumers']??array())),'deprecation_at'=>sanitize_text_field($contract['deprecation_at']??''));
\t\t}
\t\t$route_catalog = array();
\t\tforeach ( $routes as $route ) {
\t\t\tif ( ! is_array( $route ) ) { continue; }
\t\t\t$route_catalog[] = array('route_key'=>sanitize_key($route['route_key']??''),'route_path'=>sanitize_text_field($route['route_path']??''),'owner_module'=>sanitize_key($route['owner_module']??''),'layout_context'=>sanitize_key($route['layout_context']??''),'status'=>sanitize_key($route['status']??''));
\t\t}
\t\treturn array('generated_at'=>SPF_Runtime::now_mysql(),'modules'=>$catalog,'contracts'=>$contract_catalog,'routes'=>$route_catalog,'contract_count'=>count($contract_catalog),'route_count'=>count($route_catalog),'health'=>SPF_System_Check::latest(),'ownership_note'=>'Catalog only. Canonical domain ownership remains with each numbered file.');
\t}

\tpublic static function scaffold_module""",
)
replace_once(
    'includes/class-spf-installer.php',
    "\t\t\t'capabilities'     => array( 'registry', 'contracts', 'foundational_routes', 'dependency_readiness', 'system_check', 'release_evidence', 'legacy_reconciliation', 'safe_repair', 'privacy_lifecycle', 'event_backbone', 'feature_flags' ),",
    "\t\t\t'capabilities'     => array( 'registry', 'contracts', 'foundational_routes', 'dependency_readiness', 'system_check', 'release_evidence', 'legacy_reconciliation', 'safe_repair', 'privacy_lifecycle', 'event_backbone', 'feature_flags', 'policy_as_code', 'amendment_impact_simulation', 'architecture_lint', 'spec_code_traceability', 'developer_service_catalog', 'golden_path_scaffolder', 'contract_compatibility_lab', 'event_schema_registry', 'config_drift_detection', 'release_train_planning', 'progressive_delivery', 'slo_error_budget_gate', 'platform_digital_twin', 'bounded_self_heal', 'chaos_harness', 'telemetry_context', 'governance_snapshots', 'ai_governance_advisory' ),",
)
s = text('tests/future-foundation-tests.php')
marker = "$assert(str_contains($eng_source,\"'future-event-schema-registry'\") && str_contains($eng_source,\"'future-config-baselines'\"),'Future registries lack concurrency locks.');"
addition = """$assert(str_contains($eng_source,"'contracts'=>$contract_catalog") && str_contains($eng_source,"'routes'=>$route_catalog"),'Developer service catalog omits contract/route summaries.');\n$installer_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-installer.php');\n$assert(str_contains($installer_source,"'policy_as_code'")&&str_contains($installer_source,"'ai_governance_advisory'"),'File 01 v2 manifest omits Future Foundation capabilities.');\n"""
if marker not in s:
    raise SystemExit('round3 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, marker + '\n' + addition, 1))
finish_round('Review 3: complete developer service catalog and v2 capability declaration')

# ROUND 4 — Release-train canonical/version-range validation.
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\tpublic static function plan_release_train\( array \$manifests \) \{.*?\n\t\}\n\n\tpublic static function create_rollout",
    """\tpublic static function plan_release_train( array $manifests ) {
\t\t$versions=array(); $dependencies=array(); $ranges=array(); $manifest_errors=array(); $seen=array();
\t\tforeach($manifests as $index=>$manifest){
\t\t\tif(!is_array($manifest)){$manifest_errors[]=array('index'=>$index,'code'=>'manifest_not_object');continue;}
\t\t\t$key=sanitize_key($manifest['module_key']??'');
\t\t\tif(!preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$key)){$manifest_errors[]=array('index'=>$index,'code'=>'module_key_invalid','value'=>$key);continue;}
\t\t\tif(isset($seen[$key])){$manifest_errors[]=array('module_key'=>$key,'code'=>'duplicate_module_key');continue;} $seen[$key]=true;
\t\t\t$version=sanitize_text_field($manifest['software_version']??''); if(!SPF_Registry::valid_semver($version)){$manifest_errors[]=array('module_key'=>$key,'code'=>'software_version_invalid','value'=>$version);} $versions[$key]=$version; $dependencies[$key]=array(); $ranges[$key]=array();
\t\t\tforeach((array)($manifest['required']??array()) as $dependency){
\t\t\t\tif(is_array($dependency)){$dep_key=sanitize_key($dependency['module_key']??'');$minimum=sanitize_text_field($dependency['minimum_version']??'0.0.0');$maximum=sanitize_text_field($dependency['maximum_version']??'');}else{$dep_key=sanitize_key($dependency);$minimum='0.0.0';$maximum='';}
\t\t\t\tif(!preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$dep_key)){$manifest_errors[]=array('module_key'=>$key,'code'=>'dependency_key_invalid','value'=>$dep_key);continue;}
\t\t\t\tif($dep_key===$key){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'self_dependency');}
\t\t\t\tif(!SPF_Registry::valid_semver($minimum)||($maximum&&!SPF_Registry::valid_semver($maximum))||($maximum&&version_compare($minimum,$maximum,'>'))){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'dependency_version_range_invalid','minimum'=>$minimum,'maximum'=>$maximum);continue;}
\t\t\t\t$new_range=array('minimum'=>$minimum,'maximum'=>$maximum); if(isset($ranges[$key][$dep_key])&&$ranges[$key][$dep_key]!==$new_range){$manifest_errors[]=array('module_key'=>$key,'dependency'=>$dep_key,'code'=>'dependency_version_conflict');continue;}
\t\t\t\t$dependencies[$key][$dep_key]=$minimum; $ranges[$key][$dep_key]=$new_range;
\t\t\t}
\t\t}
\t\t$in_degree=array_fill_keys(array_keys($dependencies),0);$edges=array_fill_keys(array_keys($dependencies),array());$missing=array();$incompatible=array();
\t\tforeach($dependencies as $module=>$deps){foreach($deps as $dep=>$minimum){if(!isset($dependencies[$dep])){$missing[$module][]=$dep;continue;}$maximum=$ranges[$module][$dep]['maximum']??'';$actual=$versions[$dep]??'';if(SPF_Registry::valid_semver($actual)&&(('0.0.0'!==$minimum&&version_compare($actual,$minimum,'<'))||($maximum&&version_compare($actual,$maximum,'>')))){$incompatible[$module][]=array('module_key'=>$dep,'minimum_version'=>$minimum,'maximum_version'=>$maximum,'actual_version'=>$actual);}$edges[$dep][]=$module;$in_degree[$module]++;}}
\t\t$queue=array_keys(array_filter($in_degree,static function($degree){return 0===$degree;}));sort($queue,SORT_STRING);$order=array();while($queue){$node=array_shift($queue);$order[]=$node;foreach($edges[$node] as $consumer){$in_degree[$consumer]--;if(0===$in_degree[$consumer]){$queue[]=$consumer;sort($queue,SORT_STRING);}}}$cycles=array_keys(array_filter($in_degree,static function($degree){return $degree>0;}));
\t\treturn array('valid'=>empty($manifest_errors)&&empty($missing)&&empty($incompatible)&&empty($cycles),'order'=>$order,'manifest_errors'=>$manifest_errors,'missing'=>$missing,'incompatible'=>$incompatible,'cycle_candidates'=>$cycles,'plan_hash'=>SPF_Runtime::hash(array('versions'=>$versions,'dependencies'=>$ranges,'order'=>$order,'errors'=>$manifest_errors,'missing'=>$missing,'incompatible'=>$incompatible)),'execution_mode'=>'plan-only-until-approved-deployment-adapter');
\t}

\tpublic static function create_rollout""",
)
s = text('tests/future-foundation-tests.php')
s = s.replace("$bad_version=SPF_Platform_Engineering::plan_release_train([['module_key'=>'x','software_version'=>'banana','required'=>[]]]);", "$bad_version=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-02','software_version'=>'banana','required'=>[]]]);", 1)
s = s.replace("$duplicate=SPF_Platform_Engineering::plan_release_train([['module_key'=>'x','software_version'=>'1.0.0'],['module_key'=>'x','software_version'=>'2.0.0']]);", "$duplicate=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-02','software_version'=>'1.0.0'],['module_key'=>'file-02','software_version'=>'2.0.0']]);", 1)
s = s.replace("$bad_train=SPF_Platform_Engineering::plan_release_train([['module_key'=>'a','software_version'=>'1.0.0','required'=>['b']],['module_key'=>'b','software_version'=>'1.0.0','required'=>['a']]]);", "$bad_train=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-01','software_version'=>'1.0.0','required'=>['file-20']],['module_key'=>'file-20','software_version'=>'1.0.0','required'=>['file-01']]]);", 1)
marker = "$assert($bad_train['valid']===false && !empty($bad_train['cycle_candidates']),'Release-train cycle missed.');"
addition = """$too_new=SPF_Platform_Engineering::plan_release_train([['module_key'=>'file-01','software_version'=>'2.1.0','required'=>[]],['module_key'=>'file-20','software_version'=>'1.0.0','required'=>[['module_key'=>'file-01','minimum_version'=>'2.0.0','maximum_version'=>'2.0.9']]]]);\n$assert($too_new['valid']===false && !empty($too_new['incompatible']['file-20']),'Maximum dependency version was not enforced.');\n$noncanonical=SPF_Platform_Engineering::plan_release_train([['module_key'=>'rogue','software_version'=>'1.0.0','required'=>[]]]);\n$assert($noncanonical['valid']===false,'Non-canonical release-train module was accepted.');\n"""
if marker not in s:
    raise SystemExit('round4 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, marker + '\n' + addition, 1))
finish_round('Review 4: harden release-train canonical and version-range validation')

# ROUND 5 — Event schema owner/privacy governance.
replace_once(
    'includes/class-spf-platform-engineering.php',
    """\t\t$normalized = self::normalize_event_schema( $schema );\n\t\tif ( is_wp_error( $normalized ) ) {\n\t\t\treturn $normalized;\n\t\t}\n\t\t$lock_name = 'future-event-schema-registry';""",
    """\t\t$normalized = self::normalize_event_schema( $schema );\n\t\tif ( is_wp_error( $normalized ) ) {\n\t\t\treturn $normalized;\n\t\t}\n\t\tif ( ! SPF_Registry::get_module( $normalized['owner_module'] ) ) {\n\t\t\treturn new WP_Error( 'spf_event_schema_owner_unregistered', __( 'The event-schema owner must be a registered canonical module.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );\n\t\t}\n\t\t$lock_name = 'future-event-schema-registry';""",
)
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\tprivate static function normalize_event_schema\( array \$schema \) \{.*?\n\t\}\n\n\tprivate static function value_matches_type",
    """\tprivate static function normalize_event_schema( array $schema ) {
\t\t$name=preg_replace('/[^A-Za-z0-9_.-]/','',(string)($schema['event_name']??''));$version=sanitize_text_field($schema['version']??'1.0.0');$owner=sanitize_key($schema['owner_module']??'');$privacy_class=sanitize_key($schema['privacy_class']??'internal');$allowed_privacy=array('public','internal','personal','sensitive','restricted','secret','security');$fields=array();
\t\tforeach(array_slice((array)($schema['fields']??array()),0,100,true) as $field=>$definition){$field=sanitize_key($field);$definition=(array)$definition;$type=sanitize_key($definition['type']??'string');if($field&&in_array($type,array('string','integer','number','boolean','array','object','timestamp'),true)){$fields[$field]=array('type'=>$type,'required'=>!empty($definition['required']));}}
\t\tif(''===$name||!SPF_Registry::valid_semver($version)||!preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner)||!in_array($privacy_class,$allowed_privacy,true)||empty($fields)){return new WP_Error('spf_event_schema_invalid',__('Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.','sabri-platform-foundation'),array('status'=>400));}
\t\treturn array('event_name'=>substr($name,0,160),'version'=>$version,'owner_module'=>$owner,'privacy_class'=>$privacy_class,'allow_additional'=>!empty($schema['allow_additional']),'fields'=>$fields,'deprecated_at'=>substr(sanitize_text_field($schema['deprecated_at']??''),0,40));
\t}

\tprivate static function value_matches_type""",
)
s = text('tests/future-foundation-tests.php')
marker = "$event_extra=SPF_Platform_Engineering::validate_event_fixture($event+['secret'=>'x'],$schema);"
addition = """$invalid_owner_schema=$schema;$invalid_owner_schema['owner_module']='rogue';\n$assert(is_wp_error(SPF_Platform_Engineering::validate_event_fixture($event,$invalid_owner_schema)),'Non-canonical event-schema owner was accepted.');\n$invalid_privacy_schema=$schema;$invalid_privacy_schema['privacy_class']='mystery';\n$assert(is_wp_error(SPF_Platform_Engineering::validate_event_fixture($event,$invalid_privacy_schema)),'Unknown event privacy class was accepted.');\n"""
if marker not in s:
    raise SystemExit('round5 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, addition + marker, 1))
finish_round('Review 5: bind event schemas to canonical owners and approved privacy classes')

# ROUND 6 — SLO direction must never be guessed for an unknown metric.
replace_once(
    'includes/class-spf-platform-engineering.php',
    """\t\t\t$value = $metrics[ $name ];\n\t\t\t$lower_is_better = self::metric_lower_is_better( $name );\n\t\t\t$ok = $lower_is_better ? $value <= $threshold : $value >= $threshold;\n\t\t\tif ( ! $ok ) {\n\t\t\t\t$violations[] = array( 'metric'=>$name, 'value'=>$value, 'threshold'=>$threshold, 'direction'=>$lower_is_better ? 'max' : 'min' );\n\t\t\t}""",
    """\t\t\t$value = $metrics[ $name ];\n\t\t\t$lower_is_better = self::metric_lower_is_better( $name );\n\t\t\tif ( null === $lower_is_better ) {\n\t\t\t\t$violations[] = array( 'metric'=>$name, 'value'=>$value, 'threshold'=>$threshold, 'code'=>'metric_direction_unknown' );\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\t$ok = $lower_is_better ? $value <= $threshold : $value >= $threshold;\n\t\t\tif ( ! $ok ) {\n\t\t\t\t$violations[] = array( 'metric'=>$name, 'value'=>$value, 'threshold'=>$threshold, 'direction'=>$lower_is_better ? 'max' : 'min' );\n\t\t\t}""",
)
replace_once(
    'includes/class-spf-platform-engineering.php',
    """\tprivate static function metric_lower_is_better( $name ) {\n\t\t$name = sanitize_key( $name );\n\t\tif ( str_contains( $name, 'budget_remaining' ) || str_contains( $name, 'availability' ) || str_contains( $name, 'success' ) || str_contains( $name, 'throughput' ) || str_contains( $name, 'coverage' ) ) {\n\t\t\treturn false;\n\t\t}\n\t\treturn str_contains( $name, 'latency' ) || str_contains( $name, 'error' ) || str_contains( $name, 'lag' ) || str_contains( $name, 'failure' );\n\t}""",
    """\tprivate static function metric_lower_is_better( $name ) {\n\t\t$name = sanitize_key( $name );\n\t\tif ( str_contains( $name, 'budget_remaining' ) || str_contains( $name, 'availability' ) || str_contains( $name, 'success' ) || str_contains( $name, 'throughput' ) || str_contains( $name, 'coverage' ) ) { return false; }\n\t\tif ( str_contains( $name, 'latency' ) || str_contains( $name, 'error' ) || str_contains( $name, 'lag' ) || str_contains( $name, 'failure' ) || str_contains( $name, 'utilization' ) || str_contains( $name, 'saturation' ) || str_contains( $name, 'queue' ) || str_contains( $name, 'depth' ) || str_contains( $name, 'duration' ) ) { return true; }\n\t\treturn null;\n\t}""",
)
s = text('tests/future-foundation-tests.php')
marker = "$assert(SPF_Platform_Engineering::evaluate_slo_gate(['availability'=>100],[])['reason']==='slo_objectives_missing','Missing SLO objectives did not fail closed.');"
addition = """$unknown_slo=SPF_Platform_Engineering::evaluate_slo_gate(['mystery_metric'=>50],['mystery_metric'=>40]);\n$assert($unknown_slo['allow']===false && ($unknown_slo['violations'][0]['code']??'')==='metric_direction_unknown','Unknown SLO metric direction was guessed instead of failing closed.');\n"""
if marker not in s:
    raise SystemExit('round6 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, marker + '\n' + addition, 1))
finish_round('Review 6: make unknown SLO metric semantics fail closed')

# ROUND 7 — Telemetry metric option lost-update window.
replace_once(
    'includes/class-spf-runtime.php',
    """\tprivate static function delete_lock_if_matches( $option, array $payload ) {\n\t\tglobal $wpdb;\n\t\t$deleted = $wpdb->delete(""",
    """\tprivate static function delete_lock_if_matches( $option, array $payload ) {\n\t\tglobal $wpdb;\n\t\tif ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {\n\t\t\t$current = get_option( $option, null );\n\t\t\treturn $current === $payload && function_exists( 'delete_option' ) ? (bool) delete_option( $option ) : false;\n\t\t}\n\t\t$deleted = $wpdb->delete(""",
)
replace_once(
    'tests/bootstrap-minimal.php',
    "function update_option( $key, $value, $autoload = null ) { $GLOBALS['spf_test_options'][$key]=$value; return true; }",
    "function update_option( $key, $value, $autoload = null ) { $GLOBALS['spf_test_options'][$key]=$value; return true; }\nfunction delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['spf_test_options'] ) ) return false; unset( $GLOBALS['spf_test_options'][$key] ); return true; }\nfunction wp_cache_delete( $key, $group = '' ) { return true; }",
)
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\tpublic static function record_metric\( \$name, \$value, array \$labels = array\(\) \) \{.*?\n\t\}\n\n\tprivate static function normalize_event_schema",
    """\tpublic static function record_metric( $name, $value, array $labels = array() ) {
\t\t$name=sanitize_key($name);if(''===$name||!is_numeric($value)){return false;}$safe_labels=array();foreach(array_slice($labels,0,12,true) as $key=>$label){$key=sanitize_key($key);if(''===$key||preg_match('/(email|phone|token|secret|patient|message|content|document|address|name)/i',$key)){continue;}if(is_scalar($label)){$safe_labels[$key]=substr(sanitize_text_field((string)$label),0,80);}}
\t\t$lock_name='future-metrics';$lock=SPF_Runtime::acquire_lock($lock_name,60);if(is_wp_error($lock)){return $lock;}
\t\ttry{$metrics=get_option(self::METRIC_OPTION,array());$metrics=is_array($metrics)?$metrics:array();$metric=array('name'=>$name,'value'=>(float)$value,'labels'=>$safe_labels,'time'=>SPF_Runtime::now_mysql());$metrics[]=$metric;$expected=array_slice($metrics,-500);update_option(self::METRIC_OPTION,$expected,false);if(SPF_Runtime::hash(get_option(self::METRIC_OPTION,array()))!==SPF_Runtime::hash($expected)){return new WP_Error('spf_metric_persistence_failed',__('The telemetry metric buffer could not be verified after persistence.','sabri-platform-foundation'),array('status'=>409));}do_action('spf_telemetry_metric',$metric);return true;}finally{SPF_Runtime::release_lock($lock_name,$lock);}
\t}

\tprivate static function normalize_event_schema""",
)
s = text('tests/future-foundation-tests.php')
marker = "SPF_Platform_Engineering::record_metric('privacy_test',1,['module'=>'file-01','patient_name'=>'sensitive']);"
if marker not in s:
    raise SystemExit('round7 metric test marker missing')
s = s.replace(marker, marker + "\n$metric_result=SPF_Platform_Engineering::record_metric('second_metric',2,['module'=>'file-01']);\n$assert($metric_result===true,'Locked telemetry metric persistence failed.');", 1)
marker2 = "$assert(str_contains($eng_source,\"'rollback_required','rolled_back'\") && str_contains($eng_source,\"'future-rollout-'\"),'Progressive rollout stale/rollback guard absent.');"
if marker2 not in s:
    raise SystemExit('round7 source test marker missing')
s = s.replace(marker2, marker2 + "\n$assert(str_contains($eng_source,\"'future-metrics'\")&&str_contains($eng_source,'spf_metric_persistence_failed'),'Telemetry metric lost-update/persistence guard absent.');", 1)
write('tests/future-foundation-tests.php', s)
finish_round('Review 7: close telemetry metric lost-update and persistence window')

# ROUND 8 — Canonical catalog and runtime dependency version/source drift.
p = Path('DEPENDENCY-MANIFEST.json')
d = json.loads(p.read_text())
d['software_version'] = '2.0.0'
d['contract_version'] = '2.0.0'
d['schema_version'] = '1.2.0'
d['governing_sources'] = ['SSH-F01-PLAN-2026-v1.0', 'File 01 Future Foundation 18 Enhancements v2.0', 'Continuous Value / Third Central Plan v1.0']
p.write_text(json.dumps(d, indent=2, ensure_ascii=False) + '\n')
replace_once('includes/class-spf-installer.php', "'owner_name'       => 'Platform Foundation and Master Governance'", "'owner_name'       => 'Sabri Platform Foundation'")
sub_once(
    'includes/class-spf-installer.php',
    r"\t\t\$names = array\(\n\t\t\t'00'=>.*?\n\t\t\);",
    """\t\t$names = array(
\t\t\t'00'=>'Sabri Membership Core','01'=>'Sabri Platform Foundation','02'=>'Authentication and Accounts','03'=>'Profiles and Doctors','04'=>'News Feed and Publishing — Legacy Foundation Adapter','05'=>'Learn Sabri Classical Homeopathy','06'=>'Homeopathy Encyclopedia','07'=>'Doctors Directory and Discovery','08'=>'Worldwide Clinic and Appointments','09'=>'Global Doctor Onboarding and Verification','10'=>'Video Wall and Live Broadcasting','11'=>'Reels and Short Video Discovery','12'=>'PDF Library and Digital Reading','13'=>'Welcome Intro Animation','14'=>'Global Clinic USP and Conversion Integration','15'=>'Radar, Symptom, Remedy Research and Trend Intelligence','16'=>'Sabri Classical Homeopathy AI','17'=>'Communication Network','18'=>'Marketplace','19'=>'Unified Notifications and Alerts','20'=>'Sabri Unified Application Shell','21'=>'Sabri Complete Home and News Feed','22'=>'Universal Post Composer','23'=>'Doctor and Founder Publishing Dashboard','24'=>'Sabri Platform Security, Privacy, Compliance and Resilience Center','25'=>'Sabri Unified Global Visual Experience and Design System','26'=>'Search, Discovery and Ranking',
\t\t);""",
)
replace_once(
    'includes/class-spf-installer.php',
    "\t\t\t'source'           => 'SSH-F01-PLAN-2026-v1.0',",
    "\t\t\t'source'           => 'SSH-F01-PLAN-2026-v1.0',\n\t\t\t'governing_sources'=> array( 'SSH-F01-PLAN-2026-v1.0', 'File 01 Future Foundation 18 Enhancements v2.0', 'Continuous Value / Third Central Plan v1.0' ),",
)
s = text('tests/future-foundation-tests.php')
marker = "$installer_source=file_get_contents(dirname(__DIR__).'/includes/class-spf-installer.php');"
if marker not in s:
    raise SystemExit('round8 installer marker missing')
s = s.replace(marker, "$dependency_manifest=json_decode(file_get_contents(dirname(__DIR__).'/DEPENDENCY-MANIFEST.json'),true);\n$assert(($dependency_manifest['software_version']??'')==='2.0.0'&&($dependency_manifest['contract_version']??'')==='2.0.0','Dependency manifest version identity drift remains.');\n" + marker, 1)
marker2 = "$assert(str_contains($installer_source,\"'policy_as_code'\")&&str_contains($installer_source,\"'ai_governance_advisory'\"),'File 01 v2 manifest omits Future Foundation capabilities.');"
if marker2 not in s:
    raise SystemExit('round8 catalog test marker missing')
s = s.replace(marker2, marker2 + "\n$assert(str_contains($installer_source,\"'25'=>'Sabri Unified Global Visual Experience and Design System'\")&&str_contains($installer_source,\"'26'=>'Search, Discovery and Ranking'\"),'Canonical module catalog is stale against the latest central plan.');", 1)
write('tests/future-foundation-tests.php', s)
finish_round('Review 8: reconcile canonical catalog and v2 dependency/source identity')

# ROUND 9 — Dependency fail-mode preservation and contract version monotonicity.
replace_once(
    'includes/class-spf-registry.php',
    "\t\t\t$result[] = array( 'module_key' => $key, 'minimum_version' => $min, 'maximum_version' => $max, 'purpose' => substr( sanitize_text_field( $dependency['purpose'] ?? '' ), 0, 191 ) );",
    "\t\t\t$result[] = array( 'module_key' => $key, 'minimum_version' => $min, 'maximum_version' => $max, 'purpose' => substr( sanitize_text_field( $dependency['purpose'] ?? '' ), 0, 191 ), 'fail_mode' => substr( sanitize_text_field( $dependency['fail_mode'] ?? '' ), 0, 240 ) );",
)
for old, new in {
    "'purpose' => 'versioned authorization and current institutional-role claims' )": "'purpose' => 'versioned authorization and current institutional-role claims', 'fail_mode' => 'Sensitive actions fail closed; read-only diagnostics may remain under bootstrap policy' )",
    "'purpose' => 'shell provider and route placement' )": "'purpose' => 'shell provider and route placement', 'fail_mode' => 'No duplicate shell; File 01 remains restricted/admin-only' )",
    "'purpose' => 'canonical Home/News owner for legacy reconciliation' )": "'purpose' => 'canonical Home/News owner for legacy reconciliation', 'fail_mode' => 'Legacy reconciliation is blocked until a versioned reversible owner adapter is available' )",
    "'purpose' => 'assurance evidence' )": "'purpose' => 'assurance evidence', 'fail_mode' => 'Destructive or production-grade claims remain gated without independently verified evidence' )",
    "'purpose' => 'canonical search/discovery/ranking owner registration' )": "'purpose' => 'canonical search/discovery/ranking owner registration', 'fail_mode' => 'File 01 never creates a parallel search, discovery or ranking truth' )",
}.items():
    replace_once('includes/class-spf-installer.php', old, new)
sub_once(
    'includes/class-spf-platform-engineering.php',
    r"\tpublic static function contract_compatibility\( array \$old, array \$new \) \{.*?\n\t\}\n\n\tpublic static function register_event_schema",
    """\tpublic static function contract_compatibility( array $old, array $new ) {
\t\t$issues=array();$old_schema=(array)($old['schema']??array());$new_schema=(array)($new['schema']??array());foreach($old_schema as $field=>$definition){$definition=(array)$definition;if(!array_key_exists($field,$new_schema)){$issues[]=array('severity'=>'breaking','code'=>'field_removed','field'=>$field);continue;}$new_definition=(array)$new_schema[$field];if(($definition['type']??'')!==($new_definition['type']??'')){$issues[]=array('severity'=>'breaking','code'=>'type_changed','field'=>$field);}if(empty($definition['required'])&&!empty($new_definition['required'])){$issues[]=array('severity'=>'breaking','code'=>'optional_became_required','field'=>$field);}}foreach($new_schema as $field=>$definition){if(!array_key_exists($field,$old_schema)&&!empty($definition['required'])){$issues[]=array('severity'=>'breaking','code'=>'new_required_field','field'=>$field);}}
\t\t$old_version=sanitize_text_field($old['contract_version']??'0.0.0');$new_version=sanitize_text_field($new['contract_version']??'0.0.0');$version_valid=SPF_Registry::valid_semver($old_version)&&SPF_Registry::valid_semver($new_version);if(!$version_valid){$issues[]=array('severity'=>'breaking','code'=>'contract_version_invalid');}elseif(version_compare($new_version,$old_version,'<')){$issues[]=array('severity'=>'breaking','code'=>'contract_version_regressed','old_version'=>$old_version,'new_version'=>$new_version);}$breaking=(bool)array_filter($issues,static function($issue){return 'breaking'===($issue['severity']??'');});$major_bumped=$version_valid&&(int)strtok($new_version,'.')>(int)strtok($old_version,'.');
\t\treturn array('compatible'=>!$breaking,'breaking_change'=>$breaking,'major_bump_ok'=>!$breaking||$major_bumped,'version_valid'=>$version_valid,'version_monotonic'=>$version_valid&&version_compare($new_version,$old_version,'>='),'issues'=>$issues,'old_hash'=>SPF_Runtime::hash($old_schema),'new_hash'=>SPF_Runtime::hash($new_schema));
\t}

\tpublic static function register_event_schema""",
)
s = text('tests/future-foundation-tests.php')
marker = "$assert($compat['major_bump_ok']===true,'Major bump rejected for breaking change.');"
addition = """$regressed=SPF_Platform_Engineering::contract_compatibility(['contract_version'=>'2.0.0','schema'=>['id'=>['type'=>'string','required'=>true]]],['contract_version'=>'1.9.9','schema'=>['id'=>['type'=>'string','required'=>true]]]);\n$assert($regressed['compatible']===false && $regressed['version_monotonic']===false,'Contract version regression was accepted as compatible.');\n$normalize_dependencies=new ReflectionMethod(SPF_Registry::class,'normalize_dependencies');$normalize_dependencies->setAccessible(true);\n$dep=$normalize_dependencies->invoke(null,[['module_key'=>'file-20','minimum_version'=>'1.2.0','fail_mode'=>'No duplicate shell']]);\n$assert(($dep[0]['fail_mode']??'')==='No duplicate shell','Dependency fail-mode metadata was discarded.');\n"""
if marker not in s:
    raise SystemExit('round9 test marker missing')
write('tests/future-foundation-tests.php', s.replace(marker, marker + '\n' + addition, 1))
finish_round('Review 9: preserve fail-mode contracts and reject contract version regression')

# ROUND 10 — Fresh complete regression review after all corrections.
Path('SECOND-TEN-ROUND-REVIEW-2026-08-08.md').write_text("""# File 01 — Second Fresh Ten-Round Review and Correction Record

Date: 2026-08-08 (Asia/Karachi)
Scope: File 01 Platform Foundation v2.0, re-opened after newer central/companion plans were supplied.
Rule: every defect was corrected and the complete source suite rerun before the next round. Staging/live/operational remain separate evidence gates.

| Round | Fresh review focus | Result |
|---:|---|---|
| 1 | Runtime architecture-linter manifest fidelity | Defects found and corrected: architecture ownership/write/shell declarations were not retained through registry DTOs; runtime inventory therefore could be blind to real claims. |
| 2 | Golden-path SDK/scaffolder | Defects found and corrected: generated manifest omitted registry-required fields, scalar dependencies were not registry-compatible, and unapproved file numbers were accepted. |
| 3 | Developer service catalog / v2 capability exposure | Defects found and corrected: catalog exposed only counts rather than contract/route summaries; File 01 manifest omitted the 18 v2 capability families. |
| 4 | Cross-file release train | Defects found and corrected: non-canonical module keys and maximum dependency versions were not fail-closed. |
| 5 | Event schema registry | Defects found and corrected: schema owner/privacy class was insufficiently constrained; registered writes now require a registered canonical owner. |
| 6 | SLO/error-budget semantics | Defect found and corrected: unknown metric names were implicitly treated as higher-is-better instead of failing closed. |
| 7 | Telemetry persistence/concurrency | Defect found and corrected: metric buffer used unlocked read-modify-write and could lose concurrent metrics; bounded locking and persistence verification added. |
| 8 | Latest central-plan catalog/version/source reconciliation | Defects found and corrected: dependency manifest still said runtime/contract 1.2.0 while plugin is 2.0.0; runtime catalog retained stale File 01/10/14/15/25/26 naming/boundaries. |
| 9 | Dependency semantics + contract compatibility | Defects found and corrected: dependency fail-mode metadata was discarded; a contract version could regress while an unchanged schema was reported compatible. |
| 10 | Full post-correction source regression | No new source-level defect found in the complete local automated suite after Rounds 1–9 corrections. Exact-head WordPress/MySQL CI remains the independent confirmation gate. |

This record does not claim Staging-Accepted, Live-Deployed or Operational status.
""")
finish_round('Review 10: record fresh full-regression result after nine corrective rounds')

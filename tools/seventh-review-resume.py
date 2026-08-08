from pathlib import Path
import subprocess

ROOT = Path('.')
BRANCH = 'codex/file-01-complete-foundation-1.0.0'


def read(path): return (ROOT / path).read_text()
def write(path, text): (ROOT / path).write_text(text)
def replace_once(path, old, new):
    text = read(path)
    if old not in text:
        raise SystemExit(f'Expected patch anchor missing in {path}: {old[:140]!r}')
    write(path, text.replace(old, new, 1))
def sh(cmd):
    print('+', cmd, flush=True)
    subprocess.run(cmd, shell=True, check=True)
def regression(files):
    for file in files:
        sh(f"php -l {file} >/dev/null")
    sh('php tests/unit-tests.php')
    sh('php tests/future-foundation-tests.php')
    sh('php tests/sixth-ten-round-review-tests.php')
    sh('git diff --check')
def commit_round(round_no, message, files):
    regression(files)
    sh('git add ' + ' '.join(files))
    sh('git diff --cached --check')
    sh(f"git commit -m '[seventh review R{round_no}] {message}'")
    sh(f'git push origin HEAD:{BRANCH}')


def round5():
    p = 'includes/class-spf-platform-engineering.php'
    old = "actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2\\n      - name: PHP syntax"
    new = "actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\\n      - name: Set up PHP\\n        uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc\\n        with:\\n          php-version: '8.1'\\n          coverage: none\\n      - name: PHP syntax"
    replace_once(p, old, new)
    t = 'tests/future-foundation-tests.php'
    replace_once(
        t,
        "$assert(str_contains($scaffold['files']['.github/workflows/qa.yml'],'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683'),'Scaffold checkout action not pinned.');",
        "$assert(str_contains($scaffold['files']['.github/workflows/qa.yml'],'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1'),'Scaffold checkout action not pinned to current v7.0.1.');\n$assert(str_contains($scaffold['files']['.github/workflows/qa.yml'],'shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc'),'Generated CI does not pin PHP setup.');",
    )
    commit_round(5, 'Refresh Golden Path generated CI pins', [p, t])


def round6():
    p = 'includes/class-spf-platform-engineering.php'
    replace_once(p,"\t\t$sanitized = self::sanitize_config( $config );\n\t\t$lock_name = 'future-config-baselines';","\t\t$sanitized = self::sanitize_config( $config );\n\t\tif ( is_wp_error( $sanitized ) ) {\n\t\t\treturn $sanitized;\n\t\t}\n\t\t$lock_name = 'future-config-baselines';")
    replace_once(p,"\t\t$current = self::sanitize_config( $current );\n\t\t$keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $current ) ) ) );","\t\t$current = self::sanitize_config( $current );\n\t\tif ( is_wp_error( $current ) ) {\n\t\t\treturn $current;\n\t\t}\n\t\t$keys = array_values( array_unique( array_merge( array_keys( $baseline ), array_keys( $current ) ) ) );")
    old="""\tprivate static function sanitize_config( array $config ) {
\t\treturn self::sanitize_config_level( $config, 0 );
\t}

\tprivate static function sanitize_config_level( array $config, $depth ) {
\t\tif ( $depth > 4 ) {
\t\t\treturn array( '_truncated'=>true );
\t\t}
\t\t$out = array();
\t\tforeach ( array_slice( $config, 0, 200, true ) as $key => $value ) {
\t\t\t$key = sanitize_key( $key );
\t\t\tif ( '' === $key ) {
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( preg_match( '/(secret|password|token|key|credential)/i', $key ) ) {
\t\t\t\t$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( is_array( $value ) ) {
\t\t\t\t$out[ $key ] = self::sanitize_config_level( $value, $depth + 1 );
\t\t\t} elseif ( is_scalar( $value ) || null === $value ) {
\t\t\t\t$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    new="""\tprivate static function sanitize_config( array $config ) {
\t\treturn self::sanitize_config_level( $config, 0 );
\t}

\tprivate static function sanitize_config_level( array $config, $depth ) {
\t\tif ( $depth > 4 ) {
\t\t\treturn new WP_Error( 'spf_config_too_deep', __( 'Configuration nesting exceeds the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\tif ( count( $config ) > 200 ) {
\t\t\treturn new WP_Error( 'spf_config_too_large', __( 'Configuration keys exceed the bounded drift-detection envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t}
\t\t$out = array();
\t\tforeach ( $config as $raw_key => $value ) {
\t\t\tif ( ! is_string( $raw_key ) ) {
\t\t\t\treturn new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\t$key = sanitize_key( $raw_key );
\t\t\tif ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) ) {
\t\t\t\treturn new WP_Error( 'spf_config_key_invalid', __( 'Configuration keys must already be unique canonical keys.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t\tif ( preg_match( '/(^|_)(secret|password|token|private_key|api_key|encryption_key|credential)($|_)/i', $key ) ) {
\t\t\t\t$out[ $key ] = array( 'secret_hash'=>SPF_Runtime::hash( $value ), 'redacted'=>true );
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif ( is_array( $value ) ) {
\t\t\t\t$nested = self::sanitize_config_level( $value, $depth + 1 );
\t\t\t\tif ( is_wp_error( $nested ) ) { return $nested; }
\t\t\t\t$out[ $key ] = $nested;
\t\t\t} elseif ( is_scalar( $value ) || null === $value ) {
\t\t\t\t$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 500 ) : $value;
\t\t\t} else {
\t\t\t\treturn new WP_Error( 'spf_config_value_invalid', __( 'Configuration drift input contains an unsupported value type.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    replace_once(p,old,new)
    commit_round(6,'Make config drift fail closed on truncation risks',[p])


def round7():
    p='includes/class-spf-platform-engineering.php'
    old="""\t\t$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
\t\t$rings = array_values( array_unique( array_filter( array_map( 'sanitize_key', $rings ) ) ) );
\t\t$slo = self::sanitize_numeric_map( $slo );
\t\t$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );
"""
    new="""\t\t$release_id = substr( sanitize_text_field( $release_id ), 0, 191 );
\t\tif ( count( $rings ) > 20 ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings exceed the bounded rollout envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t$normalized_rings = array();
\t\t$seen_rings = array();
\t\tforeach ( $rings as $raw_ring ) {
\t\t\tif ( ! is_string( $raw_ring ) ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$ring = sanitize_key( $raw_ring );
\t\t\tif ( '' === $ring || $raw_ring !== $ring || isset( $seen_rings[ $ring ] ) ) { return new WP_Error( 'spf_rollout_invalid', __( 'Rollout rings must be unique canonical values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$seen_rings[ $ring ] = true;
\t\t\t$normalized_rings[] = $ring;
\t\t}
\t\t$rings = $normalized_rings;
\t\t$slo = self::sanitize_numeric_map( $slo );
\t\tif ( is_wp_error( $slo ) ) { return $slo; }
\t\t$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );
"""
    replace_once(p,old,new)
    replace_once(p,"\tpublic static function evaluate_slo_gate( array $metrics, array $objectives ) {\n\t\t$metrics = self::sanitize_numeric_map( $metrics );\n\t\t$objectives = self::sanitize_numeric_map( $objectives );\n\t\tif ( empty( $objectives ) ) {","\tpublic static function evaluate_slo_gate( array $metrics, array $objectives ) {\n\t\t$metrics = self::sanitize_numeric_map( $metrics );\n\t\t$objectives = self::sanitize_numeric_map( $objectives );\n\t\tif ( is_wp_error( $metrics ) || is_wp_error( $objectives ) ) {\n\t\t\t$error = is_wp_error( $objectives ) ? $objectives : $metrics;\n\t\t\treturn array( 'allow'=>false, 'reason'=>'invalid_slo_input', 'violations'=>array( array( 'code'=>$error->get_error_code() ) ), 'checked_at'=>function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ) );\n\t\t}\n\t\tif ( empty( $objectives ) ) {")
    old="""\tprivate static function sanitize_numeric_map( array $values ) {
\t\t$out = array();
\t\tforeach ( array_slice( $values, 0, 100, true ) as $key => $value ) {
\t\t\t$key = sanitize_key( $key );
\t\t\tif ( $key && is_numeric( $value ) ) { $out[ $key ] = (float) $value; }
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    # Current source is formatted with the assignment on its own line in some revisions.
    if old not in read(p):
        old="""\tprivate static function sanitize_numeric_map( array $values ) {
\t\t$out = array();
\t\tforeach ( array_slice( $values, 0, 100, true ) as $key => $value ) {
\t\t\t$key = sanitize_key( $key );
\t\t\tif ( $key && is_numeric( $value ) ) {
\t\t\t\t$out[ $key ] = (float) $value;
\t\t\t}
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    new="""\tprivate static function sanitize_numeric_map( array $values ) {
\t\tif ( count( $values ) > 100 ) { return new WP_Error( 'spf_numeric_map_too_large', __( 'Numeric metric/objective maps exceed the bounded envelope.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t$out = array();
\t\tforeach ( $values as $raw_key => $value ) {
\t\t\tif ( ! is_string( $raw_key ) ) { return new WP_Error( 'spf_numeric_map_key_invalid', __( 'Metric/objective keys must be canonical strings.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$key = sanitize_key( $raw_key );
\t\t\tif ( '' === $key || $raw_key !== $key || array_key_exists( $key, $out ) || ! is_numeric( $value ) ) { return new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective entries must use unique canonical keys and numeric values.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$number = (float) $value;
\t\t\tif ( ! is_finite( $number ) ) { return new WP_Error( 'spf_numeric_map_invalid', __( 'Metric/objective values must be finite numbers.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$out[ $key ] = $number;
\t\t}
\t\tksort( $out, SORT_STRING );
\t\treturn $out;
\t}
"""
    replace_once(p,old,new)
    commit_round(7,'Make SLO and rollout declarations strict',[p])


def round8():
    p='includes/class-spf-platform-engineering.php'
    replace_once(p,"\t\t$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string)($schema['event_name']??'') );\n\t\t$version = sanitize_text_field( $schema['version']??'1.0.0' );","\t\t$raw_name = (string) ( $schema['event_name'] ?? '' );\n\t\t$name = preg_replace( '/[^A-Za-z0-9_.-]/', '', $raw_name );\n\t\t$version = sanitize_text_field( $schema['version']??'1.0.0' );")
    replace_once(p,"\t\t$allowed_privacy = array( 'public','internal','personal','sensitive','restricted','secret','security' );","\t\t$allowed_privacy = array( 'public','internal','restricted','confidential','ephemeral' );")
    replace_once(p,"\t\t\t$type = sanitize_key( $definition['type']??'string' );\n\t\t\tif ( array_key_exists( 'required', $definition ) && ! is_bool( $definition['required'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\tif ( $field && in_array( $type, array('string','integer','number','boolean','array','object','timestamp'), true ) ) { $fields[$field] = array( 'type'=>$type, 'required'=>true === ($definition['required']??false) ); }","\t\t\t$type = sanitize_key( $definition['type']??'string' );\n\t\t\tif ( array_key_exists( 'required', $definition ) && ! is_bool( $definition['required'] ) ) { return new WP_Error( 'spf_event_schema_boolean_invalid', __( 'Event-schema boolean fields must be literal booleans.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\tif ( ! in_array( $type, array('string','integer','number','boolean','array','object','timestamp'), true ) ) { return new WP_Error( 'spf_event_schema_type_invalid', __( 'Event-schema field type is not supported.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\t\t$fields[$field] = array( 'type'=>$type, 'required'=>true === ($definition['required']??false) );")
    old="\t\tif ( ''===$name || !SPF_Registry::valid_semver($version) || !preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner) || !in_array($privacy_class,$allowed_privacy,true) || empty($fields) ) { return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }\n\t\treturn array( 'event_name'=>substr($name,0,160), 'version'=>$version, 'owner_module'=>$owner, 'privacy_class'=>$privacy_class, 'allow_additional'=>true===($schema['allow_additional']??false), 'fields'=>$fields, 'deprecated_at'=>substr(sanitize_text_field($schema['deprecated_at']??''),0,40) );"
    new="""\t\tif ( ''===$name || $raw_name !== $name || strlen( $name ) > 160 || !SPF_Registry::valid_semver($version) || !preg_match('/^file-(?:0[0-9]|1[0-9]|2[0-6])$/',$owner) || !in_array($privacy_class,$allowed_privacy,true) || empty($fields) ) { return new WP_Error( 'spf_event_schema_invalid', __( 'Event name, semantic version, canonical owner, approved privacy class and bounded fields are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t$deprecated_at = '';
\t\tif ( '' !== trim( (string) ( $schema['deprecated_at'] ?? '' ) ) ) {
\t\t\t$deprecated_ts = strtotime( (string) $schema['deprecated_at'] );
\t\t\tif ( false === $deprecated_ts ) { return new WP_Error( 'spf_event_schema_deprecation_invalid', __( 'Event-schema deprecation timestamp is invalid.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
\t\t\t$deprecated_at = gmdate( 'Y-m-d H:i:s', $deprecated_ts );
\t\t}
\t\treturn array( 'event_name'=>$name, 'version'=>$version, 'owner_module'=>$owner, 'privacy_class'=>$privacy_class, 'allow_additional'=>true===($schema['allow_additional']??false), 'fields'=>$fields, 'deprecated_at'=>$deprecated_at );"""
    replace_once(p,old,new)
    commit_round(8,'Align and harden event schema contracts',[p])


def round9():
    p='includes/class-spf-governance.php'
    replace_once(p,"\t\t$status = sanitize_key( $release['status'] ?? 'planned' );\n\t\tif ( 'planned' !== $status ) {","\t\t$status = sanitize_key( $release['status'] ?? 'planned' );\n\t\t$package_name = substr( sanitize_file_name( (string) $release['package_name'] ), 0, 191 );\n\t\t$raw_evidence_json = wp_json_encode( $release['evidence'] );\n\t\tif ( '' === $package_name ) { return new WP_Error( 'spf_release_package_name_invalid', __( 'Release evidence must bind a non-empty canonical package filename.', 'sabri-platform-foundation' ), array( 'status'=>422 ) ); }\n\t\tif ( false === $raw_evidence_json || strlen( $raw_evidence_json ) > 262144 ) { return new WP_Error( 'spf_release_evidence_too_large', __( 'Release evidence exceeds the bounded immutable evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) ); }\n\t\tif ( 'planned' !== $status ) {")
    replace_once(p,"\t\t\t\t\t'package_name' => substr( sanitize_file_name( $release['package_name'] ), 0, 191 ),","\t\t\t\t\t'package_name' => $package_name,")
    replace_once(p,"\t\t$evidence = self::sanitize_evidence( $evidence );\n\t\t$evidence_error = self::validate_evidence_for_state( $next_status, $evidence );","\t\t$raw_evidence_json = wp_json_encode( $evidence );\n\t\tif ( false === $raw_evidence_json || strlen( $raw_evidence_json ) > 262144 ) { return new WP_Error( 'spf_release_evidence_too_large', __( 'Release transition evidence exceeds the bounded immutable evidence envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) ); }\n\t\t$evidence = self::sanitize_evidence( $evidence );\n\t\t$evidence_error = self::validate_evidence_for_state( $next_status, $evidence );")
    replace_once(p,"\t\tif ( ! is_array( $amendment['decision'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );","\t\tif ( ! is_array( $amendment['decision'] ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_amendment', __( 'Decision must be structured.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$decision_json = wp_json_encode( $amendment['decision'] );\n\t\tif ( false === $decision_json || strlen( $decision_json ) > 262144 ) { return new WP_Error( 'spf_amendment_decision_too_large', __( 'Amendment decision evidence exceeds the bounded governance envelope.', 'sabri-platform-foundation' ), array( 'status'=>422 ) ); }\n\t\t$id = substr( sanitize_text_field( $amendment['amendment_id'] ), 0, 64 );")
    commit_round(9,'Bound release and amendment evidence truth',[p])


def round10():
    p='includes/class-spf-resilience-lab.php'
    replace_once(p,"\t\t\t$pre = SPF_Audit::record_required( 'self_heal_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'], 'option_count'=>count( $before ) ) );\n","\t\t\t$existing_recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );\n\t\t\t$existing_recoveries = is_array( $existing_recoveries ) ? $existing_recoveries : array();\n\t\t\tif ( count( $existing_recoveries ) >= 20 ) { return new WP_Error( 'spf_self_heal_recovery_capacity_full', __( 'Self-heal recovery capacity is full; explicitly reconcile or retire an older recovery before another repair.', 'sabri-platform-foundation' ), array( 'status'=>409 ) ); }\n\t\t\t$pre = SPF_Audit::record_required( 'self_heal_precommit', 'foundation_repair', $recovery_id, 'authorized', array( 'plan_hash'=>$plan['plan_hash'], 'option_count'=>count( $before ) ) );\n")
    replace_once(p,"\t\t\t\t\t\t\t\tupdate_option( $option . '_quarantine_' . time(), array( 'hash'=>SPF_Runtime::hash( $old ), 'quarantined_at'=>SPF_Runtime::now_mysql() ), false );\n\t\t\t\t\t\t\t\tupdate_option( $option, array(), false );","\t\t\t\t\t\t\t\t// The canonical recovery snapshot below is the quarantine evidence; avoid creating orphan dynamic options.\n\t\t\t\t\t\t\t\tupdate_option( $option, array(), false );")
    replace_once(p,"\t\t\t\t$recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );\n\t\t\t\t$recoveries = is_array( $recoveries ) ? $recoveries : array();\n\t\t\t\t$recoveries[ $recovery_id ] = $recovery;\n\t\t\t\t$expected_recoveries = array_slice( $recoveries, -20, null, true );\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $expected_recoveries, false );","\t\t\t\t$recoveries = $existing_recoveries;\n\t\t\t\t$recoveries[ $recovery_id ] = $recovery;\n\t\t\t\t$expected_recoveries = $recoveries;\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $expected_recoveries, false );")
    old="""\t\t\t} catch ( Throwable $error ) {
\t\t\t\tforeach ( $before as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t}
\t\t\t\treturn new WP_Error( 'spf_self_heal_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    new="""\t\t\t} catch ( Throwable $error ) {
\t\t\t\t$compensation_failures = array();
\t\t\t\tforeach ( $before as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t\tif ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) { $compensation_failures[] = $option; }
\t\t\t\t}
\t\t\t\tif ( $compensation_failures ) { return new WP_Error( 'spf_self_heal_compensation_incomplete', __( 'Self-heal failed and one or more File 01-owned values could not be restored.', 'sabri-platform-foundation' ), array( 'status'=>500, 'options'=>$compensation_failures ) ); }
\t\t\t\treturn new WP_Error( 'spf_self_heal_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    replace_once(p,old,new)
    old="""\t\t\t} catch ( Throwable $error ) {
\t\t\t\tforeach ( $current_values as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t}
\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );
\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    new="""\t\t\t} catch ( Throwable $error ) {
\t\t\t\t$compensation_failures = array();
\t\t\t\tforeach ( $current_values as $option => $value ) {
\t\t\t\t\tupdate_option( $option, $value, false );
\t\t\t\t\tif ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) { $compensation_failures[] = $option; }
\t\t\t\t}
\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );
\t\t\t\tif ( SPF_Runtime::hash( get_option( self::SELF_HEAL_RECOVERY_OPTION, array() ) ) !== SPF_Runtime::hash( $recoveries_before ) ) { $compensation_failures[] = self::SELF_HEAL_RECOVERY_OPTION; }
\t\t\t\tif ( $compensation_failures ) { return new WP_Error( 'spf_self_heal_rollback_compensation_incomplete', __( 'Self-heal rollback failed and its compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'status'=>500, 'options'=>$compensation_failures ) ); }
\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );
\t\t\t}
"""
    replace_once(p,old,new)
    commit_round(10,'Preserve and verify self-heal recovery truth',[p])


def finalize():
    tests='''<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';
$root=dirname(__DIR__);$pass=0;
$fail=static function(string $m):void{fwrite(STDERR,"FAIL: {$m}\\n");exit(1);};
$expect=static function(bool $c,string $m)use(&$pass,$fail):void{if(!$c)$fail($m);$pass++;};
$src=static fn(string $f):string=>(string)file_get_contents($root.'/'.$f);
$runtime=$src('includes/class-spf-runtime.php');$expect(str_contains($runtime,'information_schema.TABLES')&&str_contains($runtime,'time() >= $current_expires'),'R1 exact runtime truth missing');
$mm=new ReflectionMethod(SPF_Registry::class,'normalize_manifest');$mm->setAccessible(true);$m=['module_key'=>'file-01','owner_file'=>'01','owner_name'=>'File 01','slug'=>'file-01','namespace_prefix'=>'SPF_','software_version'=>'2.0.0','contract_version'=>'2.0.0','state'=>'active','required'=>[],'optional'=>[],'capabilities'=>[],'commands'=>['ok',''],'queries'=>[],'events'=>[],'routes'=>[],'data_classes'=>['internal'],'health'=>[],'canonical_entities'=>[],'writes'=>[]];$r=$mm->invoke(null,$m);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_manifest_collection_invalid','R2 invalid manifest value not rejected');
$cm=new ReflectionMethod(SPF_Registry::class,'normalize_contract');$cm->setAccessible(true);$r=$cm->invoke(null,['contract_key'=>'Test.v1','contract_version'=>'1.0.0','owner_module'=>'file-01','status'=>'current','schema'=>['x'=>['type'=>'string']],'consumers'=>['file-00','file-00']]);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_duplicate_contract_consumer','R2 duplicate consumer not rejected');
$system=$src('includes/class-spf-system-check.php');$expect(str_contains($system,"'scheduler_id'")&&str_contains($system,"'verified_at'")&&str_contains($system,"'expires_at'")&&str_contains($system,'array_diff( $required_hooks, $reported_hooks )'),'R3 external cron evidence incomplete');
$pm=new ReflectionMethod(SPF_Event_Bus::class,'sanitize_payload');$pm->setAccessible(true);$x=[];for($i=0;$i<101;$i++)$x['f'.$i]=$i;$r=$pm->invoke(null,$x,0);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_payload_too_many_fields','R4 oversized event payload not rejected');
$sc=SPF_Platform_Engineering::scaffold_module(['module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>['file-01'],'optional'=>[]]);$ci=$sc['files']['.github/workflows/qa.yml']??'';$expect(str_contains($ci,'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1')&&str_contains($ci,'shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc'),'R5 generated CI not current');
$cfg=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_config');$cfg->setAccessible(true);$big=[];for($i=0;$i<201;$i++)$big['k'.$i]=$i;$r=$cfg->invoke(null,$big);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_config_too_large','R6 config truncation remains');
$num=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_numeric_map');$num->setAccessible(true);$r=$num->invoke(null,['latency_p95'=>'bad']);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_numeric_map_invalid','R7 malformed SLO silently dropped');$g=SPF_Platform_Engineering::evaluate_slo_gate(['latency_p95'=>100],['Bad Metric'=>200]);$expect(empty($g['allow'])&&($g['reason']??'')==='invalid_slo_input','R7 invalid SLO did not fail closed');
$esm=new ReflectionMethod(SPF_Platform_Engineering::class,'normalize_event_schema');$esm->setAccessible(true);$r=$esm->invoke(null,['event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>['x'=>['type'=>'mystery']]]);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_schema_type_invalid','R8 invalid schema type not rejected');$r=$esm->invoke(null,['event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'confidential','fields'=>['x'=>['type'=>'string']]]);$expect(is_array($r)&&($r['privacy_class']??'')==='confidential','R8 privacy vocabulary mismatch remains');
$gov=$src('includes/class-spf-governance.php');$expect(str_contains($gov,'spf_release_package_name_invalid')&&substr_count($gov,'spf_release_evidence_too_large')>=2&&str_contains($gov,'spf_amendment_decision_too_large'),'R9 evidence bounds missing');
$res=$src('includes/class-spf-resilience-lab.php');$expect(str_contains($res,'spf_self_heal_recovery_capacity_full')&&!str_contains($res,'array_slice( $recoveries, -20')&&str_contains($res,'spf_self_heal_compensation_incomplete')&&str_contains($res,'spf_self_heal_rollback_compensation_incomplete'),'R10 self-heal recovery truth missing');
printf("Seventh ten-round review assertions: %d/%d PASS\\n",$pass,$pass);
'''
    write('tests/seventh-ten-round-review-tests.php',tests)
    qa=read('qa/run-tests.sh');anchor='php tests/sixth-ten-round-review-tests.php\n'
    if 'seventh-ten-round-review-tests.php' not in qa: qa=qa.replace(anchor,anchor+'php tests/seventh-ten-round-review-tests.php\n',1)
    write('qa/run-tests.sh',qa)
    review='''# File 01 — Seventh Fresh Ten-Round Review and Fix Cycle — 2026-08-08

A seventh independent adversarial review was completed after the sixth cycle. Every round was corrected and regression-checked before proceeding to the next round. The governing basis remains the consolidated central plan and File 01 v2.0 Future Foundation plan. Staging/live/operational acceptance remain separate gates.

1. Exact database/lock truth: fixed SQL LIKE wildcard table detection and exact lease expiry.
2. Registry normalization truth: malformed/duplicate manifest values, consumers and redirects now fail instead of disappearing/collapsing.
3. External cron evidence: now requires identified, fresh, expiring evidence covering all required hooks.
4. Event fact completeness: oversized/deep/noncanonical payloads now fail instead of silently truncating.
5. Golden Path CI: generated workflow now uses immutable checkout v7.0.1 and pinned PHP setup.
6. Config drift: oversized/deep/noncanonical input now fails instead of being truncated.
7. SLO/rings: malformed metrics/objectives and duplicate/noncanonical rollout rings now fail closed.
8. Event schema: registry privacy vocabulary now matches runtime, invalid types fail, deprecation timestamps are validated.
9. Release/amendment evidence: canonical non-empty package binding and 256 KiB evidence envelopes enforced.
10. Self-heal: recovery snapshots are no longer silently evicted; compensation is read-back verified; dynamic quarantine options removed.

**Defects found:** rounds 1, 2, 3, 4, 5, 6, 7, 8, 9 and 10.

**Defect-free rounds before correction:** none.

Automated-QA status for the final head is reasserted only after exact-head source, WordPress/MySQL runtime, concurrency, purge and deterministic-package jobs finish green. Hostinger staging and production gates remain pending.
'''
    write('SEVENTH-TEN-ROUND-REVIEW-2026-08-08.md',review)
    changelog=read('CHANGELOG.md')
    if 'Seventh fresh ten-round corrective cycle' not in changelog: changelog+='\n## 2.0.0 — Seventh fresh ten-round corrective cycle (2026-08-08)\n- Hardened runtime table/lock truth, registry normalization, cron evidence, event payloads, generated CI, config drift, SLO/rings, event schemas, release evidence and self-heal recovery/compensation.\n- Added seventh-cycle regression assertions and permanent review evidence.\n'
    write('CHANGELOG.md',changelog)
    for f in ['.github/workflows/seventh-fix-runner.yml','.github/workflows/seventh-resume-runner.yml','tools/seventh-review-fix.py','tools/seventh-review-resume.py']:
        q=Path(f)
        if q.exists(): q.unlink()
    sh("find . -type f -not -path './.git/*' -not -path './build/*' -not -path './dist/*' -not -name 'SOURCE-CHECKSUMS.sha256' -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SOURCE-CHECKSUMS.sha256")
    sh('bash qa/run-tests.sh')
    sh('bash tools/build-package.sh')
    sh('git add -A');sh('git diff --cached --check');sh("git commit -m '[seventh review final] Add regression evidence and refresh exact checksums'");sh(f'git push origin HEAD:{BRANCH}')
    print('SEVENTH_CYCLE_FINAL_HEAD='+subprocess.check_output('git rev-parse HEAD',shell=True,text=True).strip())


def main():
    sh("git config user.name 'majidhussainqadri1-dot'");sh("git config user.email 'majidhussainqadri1@gmail.com'")
    round5();round6();round7();round8();round9();round10();finalize()

if __name__=='__main__':main()

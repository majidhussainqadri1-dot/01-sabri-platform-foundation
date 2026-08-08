#!/usr/bin/env python3
from pathlib import Path
import hashlib
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8', newline='\n')


def replace_once(path, old, new, label):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match in {path}, found {count}')
    write(path, text.replace(old, new, 1))


def regex_once(path, pattern, replacement, label):
    text = read(path)
    new_text, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one regex match in {path}, found {count}')
    write(path, new_text)


# Round 1 — traceability evidence must be literal booleans, not merely truthy.
replace_once(
    'includes/class-spf-governance-control-plane.php',
    "\t\t\t\t'design'      => ! empty( $item['design'] ),\n\t\t\t\t'code'        => ! empty( $item['code'] ),\n\t\t\t\t'test'        => ! empty( $item['test'] ),\n\t\t\t\t'package'     => ! empty( $item['package'] ),\n\t\t\t\t'staging'     => ! empty( $item['staging'] ),\n\t\t\t\t'approval'    => ! empty( $item['approval'] ),\n\t\t\t\t'live'        => ! empty( $item['live'] ) || ! empty( $item['deployed'] ),\n\t\t\t\t'operational' => ! empty( $item['operational'] ),",
    "\t\t\t\t'design'      => true === ( $item['design'] ?? false ),\n\t\t\t\t'code'        => true === ( $item['code'] ?? false ),\n\t\t\t\t'test'        => true === ( $item['test'] ?? false ),\n\t\t\t\t'package'     => true === ( $item['package'] ?? false ),\n\t\t\t\t'staging'     => true === ( $item['staging'] ?? false ),\n\t\t\t\t'approval'    => true === ( $item['approval'] ?? false ),\n\t\t\t\t'live'        => true === ( $item['live'] ?? false ) || true === ( $item['deployed'] ?? false ),\n\t\t\t\t'operational' => true === ( $item['operational'] ?? false ),",
    'round1-traceability-truth'
)

# Round 2 — deployment adapter evidence must be literally verified and exactly release-bound.
replace_once(
    'includes/class-spf-platform-engineering.php',
    "\t\t\t\t\t$execution_valid = is_array( $execution ) && ! empty( $execution['verified'] )\n\t\t\t\t\t\t&& sanitize_key( $execution['release_id'] ?? '' ) === sanitize_key( $release_id )",
    "\t\t\t\t\t$execution_release_id = is_array( $execution ) ? substr( sanitize_text_field( (string) ( $execution['release_id'] ?? '' ) ), 0, 191 ) : '';\n\t\t\t\t\t$execution_valid = is_array( $execution ) && true === ( $execution['verified'] ?? false )\n\t\t\t\t\t\t&& '' !== $execution_release_id && hash_equals( $release_id, $execution_release_id )",
    'round2-deployment-evidence-truth'
)

# Round 3 — canonical rollout ring taxonomy and terminal state may not bypass adapter evidence.
replace_once(
    'includes/class-spf-platform-engineering.php',
    "\t\t$rings = array_values( array_unique( array_filter( array_map( 'sanitize_key', $rings ) ) ) );\n\t\t$slo = self::sanitize_numeric_map( $slo );\n\t\tif ( '' === $release_id || empty( $rings ) || count( $rings ) > 20 || empty( $slo ) ) {\n\t\t\treturn new WP_Error( 'spf_rollout_invalid', __( 'A release id, bounded rollout rings and at least one SLO objective are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );\n\t\t}",
    "\t\t$rings = array_values( array_unique( array_filter( array_map( 'sanitize_key', $rings ) ) ) );\n\t\t$slo = self::sanitize_numeric_map( $slo );\n\t\t$allowed_rings = array( 'local','ci','staging','staff','canary','gradual','production','full' );\n\t\t$terminal_rings = array( 'production','full' );\n\t\t$unknown_rings = array_values( array_diff( $rings, $allowed_rings ) );\n\t\t$terminal_count = count( array_intersect( $rings, $terminal_rings ) );\n\t\t$last_ring = $rings ? $rings[ count( $rings ) - 1 ] : '';\n\t\tif ( '' === $release_id || count( $rings ) < 2 || count( $rings ) > 20 || $unknown_rings || 1 !== $terminal_count || ! in_array( $last_ring, $terminal_rings, true ) || empty( $slo ) ) {\n\t\t\treturn new WP_Error( 'spf_rollout_invalid', __( 'A release id, two or more canonical rollout rings ending in exactly one production/full ring, and at least one SLO objective are required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );\n\t\t}",
    'round3-rollout-taxonomy'
)
replace_once(
    'includes/class-spf-platform-engineering.php',
    "\t\t\t\tif ( $next_index === $current_index ) {\n\t\t\t\t\t$rollout['status'] = 'full';\n\t\t\t\t} else {",
    "\t\t\t\tif ( $next_index === $current_index ) {\n\t\t\t\t\treturn new WP_Error( 'spf_rollout_terminal_state_invalid', __( 'A non-final rollout state cannot be promoted to full without a new verified deployment-adapter transition.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );\n\t\t\t\t} else {",
    'round3-terminal-bypass'
)

# Round 4 — registry capacity cannot silently drop the newly accepted schema.
replace_once(
    'includes/class-spf-platform-engineering.php',
    "\t\t\t$key = $normalized['event_name'] . '@' . $normalized['version'];\n\t\t\t$registry[ $key ] = $normalized;",
    "\t\t\t$key = $normalized['event_name'] . '@' . $normalized['version'];\n\t\t\tif ( ! isset( $registry[ $key ] ) && count( $registry ) >= 500 ) {\n\t\t\t\treturn new WP_Error( 'spf_event_schema_registry_full', __( 'The bounded event-schema registry is full; retire or migrate an existing schema before adding another.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );\n\t\t\t}\n\t\t\t$registry[ $key ] = $normalized;",
    'round4-event-registry-capacity'
)

# Round 5 — architecture inventory must not fabricate File 20 presence; missing owner is a finding.
replace_once(
    'includes/class-spf-governance-control-plane.php',
    "\t\tif ( ! in_array( 'file-20', $shell_owners, true ) ) {\n\t\t\t$shell_owners[] = 'file-20';\n\t\t}\n",
    "",
    'round5-remove-fabricated-shell-owner'
)
replace_once(
    'includes/class-spf-governance-control-plane.php',
    "\t\t$known_shell_owners = array_values( array_filter( array_map( 'sanitize_key', (array) ( $inventory['global_shell_owners'] ?? array() ) ) ) );\n\t\tif ( count( array_unique( $known_shell_owners ) ) > 1 || ( $known_shell_owners && 'file-20' !== $known_shell_owners[0] ) ) {\n\t\t\t$findings[] = self::finding( 'critical', 'shell_owner_violation', 'global-shell', 'File 20 must remain the only application-shell owner.', array( 'owners' => $known_shell_owners ) );\n\t\t}",
    "\t\t$known_shell_owners = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $inventory['global_shell_owners'] ?? array() ) ) ) ) );\n\t\tif ( empty( $known_shell_owners ) ) {\n\t\t\t$findings[] = self::finding( 'critical', 'shell_owner_missing', 'global-shell', 'The canonical File 20 application-shell owner is not present in runtime inventory.', array( 'owners' => array() ) );\n\t\t} elseif ( count( $known_shell_owners ) > 1 || 'file-20' !== $known_shell_owners[0] ) {\n\t\t\t$findings[] = self::finding( 'critical', 'shell_owner_violation', 'global-shell', 'File 20 must remain the only application-shell owner.', array( 'owners' => $known_shell_owners ) );\n\t\t}",
    'round5-shell-owner-truth'
)

# Round 6 — chaos execution switch requires a literal boolean true.
replace_once(
    'includes/class-spf-resilience-lab.php',
    "\t\t$enabled = defined( 'SPF_CHAOS_MODE' ) && SPF_CHAOS_MODE && in_array( $environment, $safe_environments, true );",
    "\t\t$enabled = defined( 'SPF_CHAOS_MODE' ) && true === SPF_CHAOS_MODE && in_array( $environment, $safe_environments, true );",
    'round6-chaos-literal-gate'
)

# Round 7 — self-heal rollback must compensate if any restore/metadata/audit step fails.
regex_once(
    'includes/class-spf-resilience-lab.php',
    r"\t\t\t\$restored = array\(\);\n\t\t\tforeach \( \(array\) \$recovery\['options'\] as \$option => \$value \) \{.*?\n\t\t\treturn array\( 'rolled_back'=>true, 'recovery_id'=>\$recovery_id, 'restored_options'=>\$restored, 'companion_data_modified'=>false \);",
    "\t\t\t$restored = array();\n\t\t\t$current_values = array();\n\t\t\tforeach ( (array) $recovery['options'] as $option => $value ) {\n\t\t\t\tif ( 'spf_feature_flags' === $option || in_array( $option, self::OWNED_OPTIONS, true ) ) {\n\t\t\t\t\t$current_values[ $option ] = get_option( $option, null );\n\t\t\t\t}\n\t\t\t}\n\t\t\t$recoveries_before = $recoveries;\n\t\t\ttry {\n\t\t\t\tforeach ( (array) $recovery['options'] as $option => $value ) {\n\t\t\t\t\tif ( 'spf_feature_flags' === $option || in_array( $option, self::OWNED_OPTIONS, true ) ) {\n\t\t\t\t\t\tupdate_option( $option, $value, false );\n\t\t\t\t\t\tif ( SPF_Runtime::hash( get_option( $option, null ) ) !== SPF_Runtime::hash( $value ) ) {\n\t\t\t\t\t\t\tthrow new RuntimeException( 'self_heal_rollback_write_failed:' . $option );\n\t\t\t\t\t\t}\n\t\t\t\t\t\t$restored[] = $option;\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t\t$recovery['rolled_back'] = true;\n\t\t\t\t$recovery['rolled_back_at'] = SPF_Runtime::now_mysql();\n\t\t\t\t$recoveries[ $recovery_id ] = $recovery;\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries, false );\n\t\t\t\t$persisted_recoveries = get_option( self::SELF_HEAL_RECOVERY_OPTION, array() );\n\t\t\t\tif ( empty( $persisted_recoveries[ $recovery_id ] ) || SPF_Runtime::hash( $persisted_recoveries[ $recovery_id ] ) !== SPF_Runtime::hash( $recovery ) ) {\n\t\t\t\t\tthrow new RuntimeException( 'self_heal_rollback_metadata_write_failed' );\n\t\t\t\t}\n\t\t\t\t$audit = SPF_Audit::record_required( 'self_heal_rollback', 'foundation_repair', $recovery_id, 'success', array( 'restored_count'=>count( $restored ) ) );\n\t\t\t\tif ( is_wp_error( $audit ) ) {\n\t\t\t\t\tthrow new RuntimeException( $audit->get_error_message() );\n\t\t\t\t}\n\t\t\t} catch ( Throwable $error ) {\n\t\t\t\tforeach ( $current_values as $option => $value ) {\n\t\t\t\t\tupdate_option( $option, $value, false );\n\t\t\t\t}\n\t\t\t\tupdate_option( self::SELF_HEAL_RECOVERY_OPTION, $recoveries_before, false );\n\t\t\t\treturn new WP_Error( 'spf_self_heal_rollback_failed', $error->getMessage(), array( 'status'=>409 ) );\n\t\t\t}\n\t\t\treturn array( 'rolled_back'=>true, 'recovery_id'=>$recovery_id, 'restored_options'=>$restored, 'companion_data_modified'=>false );",
    'round7-self-heal-rollback-atomicity'
)

# Round 8 — periodic tick must surface reconciliation/metric failures rather than looking healthy.
replace_once(
    'includes/class-spf-resilience-lab.php',
    "\tpublic static function periodic_tick() {\n\t\tSPF_Governance::reconcile_expired_flags();\n\t\t$plan = self::self_heal_plan();\n\t\tSPF_Platform_Engineering::record_metric( 'future_foundation_self_heal_actions', count( $plan['actions'] ), array( 'module'=>'file-01' ) );\n\t\tdo_action( 'spf_future_foundation_health_tick', $plan );\n\t}",
    "\tpublic static function periodic_tick() {\n\t\t$reconciled = SPF_Governance::reconcile_expired_flags();\n\t\tif ( is_wp_error( $reconciled ) ) {\n\t\t\tdo_action( 'spf_future_foundation_tick_failure', $reconciled );\n\t\t\treturn $reconciled;\n\t\t}\n\t\t$plan = self::self_heal_plan();\n\t\t$metric = SPF_Platform_Engineering::record_metric( 'future_foundation_self_heal_actions', count( $plan['actions'] ), array( 'module'=>'file-01' ) );\n\t\tif ( is_wp_error( $metric ) || false === $metric ) {\n\t\t\t$error = is_wp_error( $metric ) ? $metric : new WP_Error( 'spf_future_foundation_metric_failed', __( 'Future Foundation periodic metric persistence failed.', 'sabri-platform-foundation' ) );\n\t\t\tdo_action( 'spf_future_foundation_tick_failure', $error );\n\t\t\treturn $error;\n\t\t}\n\t\t$result = array( 'reconciled'=>$reconciled, 'self_heal_plan'=>$plan, 'metric_recorded'=>true );\n\t\tdo_action( 'spf_future_foundation_health_tick', $result );\n\t\treturn $result;\n\t}",
    'round8-periodic-tick-truth'
)

# Round 9 — privacy health checks must fail closed on DB query errors.
regex_once(
    'includes/class-spf-system-check.php',
    r"\tprivate static function privacy_checks\(\) \{.*?\n\t\}\n\n\tprivate static function transaction_probe\(\)",
    "\tprivate static function privacy_checks() {\n\t\tglobal $wpdb;\n\t\t$checks=array();\n\t\t$table=SPF_Installer::table('privacy_requests');\n\t\tif ( ! SPF_Runtime::table_exists( $table ) ) {\n\t\t\t$checks[]=self::check('privacy_requests_registry',false,'missing','Privacy request registry is unavailable.','fail');\n\t\t} else {\n\t\t\t$overdue_raw=$wpdb->get_var($wpdb->prepare(\"SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('completed','rejected') AND due_at<%s\",SPF_Runtime::now_mysql()));\n\t\t\tif ( ! empty( $wpdb->last_error ) || null === $overdue_raw ) {\n\t\t\t\t$checks[]=self::check('privacy_requests_query',false,'query-failed','Privacy request health query failed.','fail');\n\t\t\t} else {\n\t\t\t\t$overdue=(int)$overdue_raw;\n\t\t\t\t$checks[]=self::check('privacy_requests_overdue',0===$overdue,(string)$overdue,'Privacy requests are overdue.','warning');\n\t\t\t}\n\t\t}\n\t\t$holds=SPF_Installer::table('privacy_holds');\n\t\tif ( ! SPF_Runtime::table_exists( $holds ) ) {\n\t\t\t$checks[]=self::check('privacy_holds_registry',false,'missing','Privacy hold registry is unavailable.','fail');\n\t\t} else {\n\t\t\t$active_raw=$wpdb->get_var(\"SELECT COUNT(*) FROM {$holds} WHERE active=1\"); // phpcs:ignore\n\t\t\tif ( ! empty( $wpdb->last_error ) || null === $active_raw ) {\n\t\t\t\t$checks[]=self::check('privacy_holds_query',false,'query-failed','Privacy hold health query failed.','fail');\n\t\t\t} else {\n\t\t\t\t$active=(int)$active_raw;\n\t\t\t\t$checks[]=self::check('privacy_holds_registry',true,'active-'.$active,'Privacy hold registry is unavailable.','fail');\n\t\t\t}\n\t\t}\n\t\t$retention=wp_next_scheduled('spf_privacy_retention');\n\t\t$checks[]=self::check('privacy_retention_schedule',(bool)$retention,$retention?'scheduled':'missing','Privacy retention job is not scheduled.','fail');\n\t\treturn $checks;\n\t}\n\n\tprivate static function transaction_probe()",
    'round9-privacy-health-db-truth'
)

# Round 10 — Golden Path must never generate a self-dependent module.
replace_once(
    'includes/class-spf-platform-engineering.php',
    "\t\tif ( is_wp_error( $required ) || is_wp_error( $optional ) ) {\n\t\t\treturn is_wp_error( $required ) ? $required : $optional;\n\t\t}\n\t\t$manifest = array(",
    "\t\tif ( is_wp_error( $required ) || is_wp_error( $optional ) ) {\n\t\t\treturn is_wp_error( $required ) ? $required : $optional;\n\t\t}\n\t\tif ( in_array( $module_key, $required, true ) || in_array( $module_key, $optional, true ) ) {\n\t\t\treturn new WP_Error( 'spf_scaffold_self_dependency', __( 'Golden-path scaffolding cannot generate a module that depends on itself.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );\n\t\t}\n\t\t$manifest = array(",
    'round10-scaffold-self-dependency'
)

# Runtime regression additions for rounds 1, 4, 5 and 10.
replace_once(
    'qa/wp-future-foundation-smoke.php',
    "$scaffold = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Runtime Test','slug'=>'runtime-test','prefix'=>'RTT' ) );\n$assert( ! is_wp_error( $scaffold ) && empty( $scaffold['write_performed'] ), 'Golden-path scaffold must be generated without foreign writes.' );\n",
    "$scaffold = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Runtime Test','slug'=>'runtime-test','prefix'=>'RTT' ) );\n$assert( ! is_wp_error( $scaffold ) && empty( $scaffold['write_performed'] ), 'Golden-path scaffold must be generated without foreign writes.' );\n$self_scaffold = SPF_Platform_Engineering::scaffold_module( array( 'module_key'=>'file-01','owner_file'=>'01','owner_name'=>'Self Test','slug'=>'self-test','prefix'=>'SELF' ) );\n$assert( is_wp_error( $self_scaffold ) && 'spf_scaffold_self_dependency' === $self_scaffold->get_error_code(), 'Golden-path scaffold must reject self-dependencies.' );\n$truthy_trace = SPF_Governance_Control_Plane::build_traceability_report( array('F01-STRICT-BOOL'), array('F01-STRICT-BOOL'=>array('design'=>'yes','code'=>'yes','test'=>'yes')) );\n$assert( 0 === (int) $truthy_trace['coded_complete'], 'Traceability must not accept truthy strings as verified evidence.' );\n$missing_shell = SPF_Governance_Control_Plane::lint_architecture( array( 'modules'=>array(), 'routes'=>array(), 'global_shell_owners'=>array() ) );\n$missing_shell_codes = array_column( (array) $missing_shell['findings'], 'code' );\n$assert( in_array( 'shell_owner_missing', $missing_shell_codes, true ), 'Architecture linter must report a missing File 20 shell owner instead of fabricating one.' );\n",
    'runtime-round1-5-10'
)
replace_once(
    'qa/wp-future-foundation-smoke.php',
    "$registered = SPF_Platform_Engineering::register_event_schema( $event_schema );\n$assert( ! is_wp_error( $registered ), 'Authorized File 01 event schema registration failed.' );\n",
    "$registered = SPF_Platform_Engineering::register_event_schema( $event_schema );\n$assert( ! is_wp_error( $registered ), 'Authorized File 01 event schema registration failed.' );\n$schemas_before_capacity = get_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, array() );\n$full_registry = array();\nfor ( $i = 0; $i < 500; $i++ ) { $full_registry[ 'CapacityEvent' . $i . '@1.0.0' ] = array( 'placeholder'=>true ); }\nupdate_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, $full_registry, false );\n$capacity_schema = $event_schema; $capacity_schema['event_name'] = 'CapacityOverflowEvent.v1';\n$capacity_result = SPF_Platform_Engineering::register_event_schema( $capacity_schema );\n$assert( is_wp_error( $capacity_result ) && 'spf_event_schema_registry_full' === $capacity_result->get_error_code(), 'Event-schema registry must reject overflow instead of silently dropping the new schema.' );\nupdate_option( SPF_Platform_Engineering::EVENT_SCHEMA_OPTION, $schemas_before_capacity, false );\n",
    'runtime-round4-capacity'
)

# Dedicated fifth-cycle source regressions.
test = r'''<?php
$root = dirname( __DIR__ );
$pass = 0;
$fail = static function ( $message ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); };
$expect = static function ( $condition, $message ) use ( &$pass, $fail ) { if ( ! $condition ) { $fail( $message ); } $pass++; };
$src = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . $file ); };

$control = $src( 'includes/class-spf-governance-control-plane.php' );
$expect( str_contains( $control, "true === ( \$item['design'] ?? false )" ) && str_contains( $control, "true === ( \$item['operational'] ?? false )" ), 'Round 1 strict traceability evidence truth missing' );
$engineering = $src( 'includes/class-spf-platform-engineering.php' );
$expect( str_contains( $engineering, "true === ( \$execution['verified'] ?? false )" ) && str_contains( $engineering, 'hash_equals( $release_id, $execution_release_id )' ), 'Round 2 strict deployment evidence binding missing' );
$expect( str_contains( $engineering, '$allowed_rings' ) && str_contains( $engineering, 'spf_rollout_terminal_state_invalid' ), 'Round 3 rollout taxonomy/terminal gate missing' );
$expect( str_contains( $engineering, 'spf_event_schema_registry_full' ), 'Round 4 event-schema capacity guard missing' );
$expect( str_contains( $control, 'shell_owner_missing' ) && ! str_contains( $control, "$shell_owners[] = 'file-20';" ), 'Round 5 runtime shell-owner truth guard missing' );
$resilience = $src( 'includes/class-spf-resilience-lab.php' );
$expect( str_contains( $resilience, "true === SPF_CHAOS_MODE" ), 'Round 6 literal chaos-mode gate missing' );
$expect( str_contains( $resilience, 'spf_self_heal_rollback_failed' ) && str_contains( $resilience, '$recoveries_before' ), 'Round 7 compensating self-heal rollback missing' );
$expect( str_contains( $resilience, 'spf_future_foundation_tick_failure' ) && str_contains( $resilience, 'spf_future_foundation_metric_failed' ), 'Round 8 periodic-tick failure propagation missing' );
$system = $src( 'includes/class-spf-system-check.php' );
$expect( str_contains( $system, 'privacy_requests_query' ) && str_contains( $system, 'privacy_holds_query' ), 'Round 9 privacy health DB fail-closed guards missing' );
$expect( str_contains( $engineering, 'spf_scaffold_self_dependency' ), 'Round 10 Golden-Path self-dependency guard missing' );
printf( "Fifth ten-round review assertions: %d/%d PASS\n", $pass, 10 );
'''
write('tests/fifth-ten-round-review-tests.php', test)

# Wire regressions into source QA.
replace_once(
    'qa/run-tests.sh',
    "php tests/fourth-ten-round-review-tests.php\nphp tests/source-quality-tests.php",
    "php tests/fourth-ten-round-review-tests.php\nphp tests/fifth-ten-round-review-tests.php\nphp tests/source-quality-tests.php",
    'wire-fifth-review-test'
)

review = '''# File 01 — Fifth Fresh Ten-Round Review and Fix Cycle — 2026-08-08

This is a fifth independent adversarial review of the exact File 01 v2.0 Future Foundation corrective source after four earlier ten-round cycles. Every defect below was corrected before the next round and receives regression protection.

1. Traceability evidence truth: truthy strings could satisfy design/code/test/package/staging/approval/live/operational evidence. All evidence flags now require literal boolean `true`.
2. Progressive-delivery adapter truth: truthy `verified` and normalized release-id comparison could accept weak evidence. Verification is literal boolean and evidence is exactly bound to the release identifier.
3. Rollout terminal authority: arbitrary ring names or a terminal-state edge case could reach `full` without a fresh verified adapter transition. Canonical bounded rings, exactly one final production/full ring, minimum two rings, and terminal-state rejection are enforced.
4. Event-schema registry capacity: a 501st new schema could be sliced away while the method still returned success. Capacity now fails explicitly before mutation.
5. Architecture inventory truth: runtime inventory injected File 20 even when absent, hiding a missing shell-owner condition. Synthetic presence was removed and missing File 20 is now a critical linter finding.
6. Chaos gate type safety: a truthy non-boolean `SPF_CHAOS_MODE` constant could enable injection in a non-production environment. Literal boolean `true` is now required.
7. Self-heal rollback atomicity: a later option/metadata/audit failure could leave an earlier rollback write applied. Restore state and recovery metadata are now compensated on any failure.
8. Periodic health tick truth: expiry reconciliation or metric persistence failures were ignored. The tick now returns/surfaces failure and emits a dedicated failure hook.
9. Privacy System Check DB truth: failed privacy-request or privacy-hold COUNT queries could look like zero healthy rows. Query failures now create explicit failing checks.
10. Golden-Path dependency safety: scaffolding File 01 with defaults could generate a self-dependency. Any required/optional self-dependency is now rejected.

Defects found: rounds 1, 2, 3, 4, 5, 6, 7, 8, 9, 10.
Defect-free rounds before correction in this fifth cycle: none.

Acceptance remains evidence-bounded: repository/source and automated WordPress/MySQL correctness are separate from Hostinger staging acceptance, live deployment and operational acceptance.
'''
write('FIFTH-TEN-ROUND-REVIEW-2026-08-08.md', review)

# Package the permanent review record.
replace_once(
    'tools/build-package.sh',
    "    THIRD-TEN-ROUND-REVIEW-2026-08-08.md FOURTH-TEN-ROUND-REVIEW-2026-08-08.md\n",
    "    THIRD-TEN-ROUND-REVIEW-2026-08-08.md FOURTH-TEN-ROUND-REVIEW-2026-08-08.md\n    FIFTH-TEN-ROUND-REVIEW-2026-08-08.md\n",
    'package-fifth-review-record'
)

# Regenerate the exact source checksum manifest using its governed file set plus new permanent files.
manifest_path = ROOT / 'SOURCE-CHECKSUMS.sha256'
paths = []
for line in manifest_path.read_text(encoding='utf-8').splitlines():
    parts = line.split('  ', 1)
    if len(parts) == 2 and parts[1]:
        paths.append(parts[1])
for extra in ('FIFTH-TEN-ROUND-REVIEW-2026-08-08.md', 'tests/fifth-ten-round-review-tests.php'):
    if extra not in paths:
        paths.append(extra)
lines = []
for rel in paths:
    data = (ROOT / rel).read_bytes()
    lines.append(f'{hashlib.sha256(data).hexdigest()}  {rel}')
manifest_path.write_text('\n'.join(lines) + '\n', encoding='utf-8', newline='\n')

print('Fifth ten-round corrective patch applied successfully.')

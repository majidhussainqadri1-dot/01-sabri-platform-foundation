<?php
/**
 * Plugin Name: Sabri Platform Foundation
 * Plugin URI: https://www.sabrihomeopathy.com/
 * Description: Canonical File 01-B governance, registry, dependency, route, privacy, health, reconciliation, platform-engineering control-plane and release-evidence runtime for the Sabri Social Homeopathy Platform.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-platform-foundation
 */

defined( 'ABSPATH' ) || exit;

define( 'SPF_VERSION', '2.0.0' );
define( 'SPF_SCHEMA_VERSION', '1.2.0' );
define( 'SPF_CONTRACT_VERSION', '2.0.0' );
define( 'SPF_FUTURE_FOUNDATION_VERSION', '2.0.0' );
define( 'SPF_PLAN_ID', 'SSH-F01-PLAN-2026-v1.0' );
define( 'SPF_FILE', __FILE__ );
define( 'SPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPF_URL', plugin_dir_url( __FILE__ ) );

require_once SPF_DIR . 'includes/class-spf-runtime.php';
require_once SPF_DIR . 'includes/class-spf-authorization.php';
require_once SPF_DIR . 'includes/class-spf-audit.php';
require_once SPF_DIR . 'includes/class-spf-event-bus.php';
require_once SPF_DIR . 'includes/class-spf-installer.php';
require_once SPF_DIR . 'includes/class-spf-registry.php';
require_once SPF_DIR . 'includes/class-spf-dependency-resolver.php';
require_once SPF_DIR . 'includes/class-spf-idempotency.php';
require_once SPF_DIR . 'includes/class-spf-governance.php';
require_once SPF_DIR . 'includes/class-spf-privacy.php';
require_once SPF_DIR . 'includes/class-spf-system-check.php';
require_once SPF_DIR . 'includes/class-spf-reconciler.php';
require_once SPF_DIR . 'includes/class-spf-repair.php';
require_once SPF_DIR . 'includes/class-spf-purge.php';
require_once SPF_DIR . 'includes/class-spf-rest.php';
require_once SPF_DIR . 'includes/class-spf-governance-control-plane.php';
require_once SPF_DIR . 'includes/class-spf-platform-engineering.php';
require_once SPF_DIR . 'includes/class-spf-resilience-lab.php';
require_once SPF_DIR . 'includes/class-spf-future-foundation.php';
require_once SPF_DIR . 'includes/class-spf-admin.php';
require_once SPF_DIR . 'includes/class-spf-plugin.php';

function spf_foundation_cron_schedules( $schedules ) {
	$schedules['spf_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Every five minutes (Sabri Foundation)', 'sabri-platform-foundation' ) );
	return $schedules;
}
add_filter( 'cron_schedules', 'spf_foundation_cron_schedules' );

register_activation_hook( SPF_FILE, array( 'SPF_Installer', 'activate' ) );
register_deactivation_hook( SPF_FILE, array( 'SPF_Installer', 'deactivate' ) );
register_deactivation_hook( SPF_FILE, array( 'SPF_Future_Foundation', 'deactivate' ) );

function spf_start_plugin() {
	SPF_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'spf_start_plugin', 5 );

/**
 * Versioned public integration helpers. These expose DTOs/commands only; no
 * internal table schema is an integration contract.
 */
function spf_register_module_manifest( array $manifest, array $context = array() ) {
	return SPF_Registry::register_manifest( $manifest, $context );
}

function spf_register_contract( array $contract, array $context = array() ) {
	return SPF_Registry::register_contract( $contract, $context );
}

function spf_register_route( array $route, array $context = array() ) {
	return SPF_Registry::map_route( $route, $context );
}

function spf_get_module_readiness( $module_key ) {
	return SPF_Dependency_Resolver::readiness( sanitize_key( $module_key ) );
}

function spf_list_contracts( array $filters = array() ) {
	return SPF_Registry::list_contracts( $filters );
}

function spf_get_release_evidence( $release_id ) {
	return SPF_Governance::get_release( sanitize_text_field( $release_id ) );
}

function spf_foundation_status() {
	return SPF_Plugin::instance()->status_dto();
}

function spf_acknowledge_contract( $contract_key, $contract_version, $consumer_module, array $context = array() ) {
	return SPF_Registry::acknowledge_contract( $contract_key, $contract_version, $consumer_module, $context );
}

function spf_record_amendment( array $amendment, array $context = array() ) {
	return SPF_Governance::record_amendment( $amendment, $context );
}

function spf_transition_release( $release_id, $next_status, array $evidence = array(), array $context = array() ) {
	return SPF_Governance::transition_release( $release_id, $next_status, $evidence, $context );
}

function spf_is_feature_enabled( $owner_module, $flag_key, $environment = '' ) {
	return SPF_Governance::is_flag_enabled( sanitize_key( $owner_module ), sanitize_key( $flag_key ), sanitize_key( $environment ) );
}

/* File 01 v2.0 Future Foundation Superset — 18 bounded control-plane helpers. */
function spf_future_feature_catalog() { return SPF_Future_Foundation::feature_catalog(); }
function spf_evaluate_constitution_policy( $action, array $context = array() ) { return SPF_Governance_Control_Plane::evaluate_policy( $action, $context ); }
function spf_simulate_amendment_impact( array $amendment, array $inventory = array() ) { return SPF_Governance_Control_Plane::simulate_amendment( $amendment, $inventory ); }
function spf_lint_platform_architecture( array $inventory ) { return SPF_Governance_Control_Plane::lint_architecture( $inventory ); }
function spf_lint_runtime_architecture() { return SPF_Governance_Control_Plane::lint_runtime_architecture(); }
function spf_save_constitution_policy( array $policy ) { return SPF_Governance_Control_Plane::save_policy( $policy ); }
function spf_build_traceability_report( array $requirements, array $evidence ) { return SPF_Governance_Control_Plane::build_traceability_report( $requirements, $evidence ); }
function spf_developer_service_catalog() { return SPF_Platform_Engineering::service_catalog(); }
function spf_scaffold_golden_path_module( array $spec ) { return SPF_Platform_Engineering::scaffold_module( $spec ); }
function spf_test_contract_compatibility( array $old, array $new ) { return SPF_Platform_Engineering::contract_compatibility( $old, $new ); }
function spf_register_event_schema( array $schema ) { return SPF_Platform_Engineering::register_event_schema( $schema ); }
function spf_replay_event_fixture( array $event, array $schema ) { return SPF_Platform_Engineering::replay_event_fixture( $event, $schema, false ); }
function spf_set_config_baseline( $environment, array $config ) { return SPF_Platform_Engineering::set_config_baseline( $environment, $config ); }
function spf_detect_config_drift( $environment, array $current ) { return SPF_Platform_Engineering::detect_config_drift( $environment, $current ); }
function spf_plan_release_train( array $manifests ) { return SPF_Platform_Engineering::plan_release_train( $manifests ); }
function spf_create_progressive_rollout( $release_id, array $rings, array $slo ) { return SPF_Platform_Engineering::create_rollout( $release_id, $rings, $slo ); }
function spf_advance_progressive_rollout( $release_id, array $metrics ) { return SPF_Platform_Engineering::advance_rollout( $release_id, $metrics ); }
function spf_evaluate_slo_release_gate( array $metrics, array $objectives ) { return SPF_Platform_Engineering::evaluate_slo_gate( $metrics, $objectives ); }
function spf_simulate_platform_digital_twin( array $model, array $scenario = array() ) { return SPF_Resilience_Lab::digital_twin( $model, $scenario ); }
function spf_future_self_heal_plan() { return SPF_Resilience_Lab::self_heal_plan(); }
function spf_apply_future_self_heal( $confirmation, $expected_hash ) { return SPF_Resilience_Lab::apply_self_heal( $confirmation, $expected_hash ); }
function spf_rollback_future_self_heal( $recovery_id, $confirmation ) { return SPF_Resilience_Lab::rollback_self_heal( $recovery_id, $confirmation ); }
function spf_run_safe_chaos_scenario( $scenario, array $context = array() ) { return SPF_Resilience_Lab::run_chaos( $scenario, $context ); }
function spf_new_telemetry_context( array $parent = array() ) { return SPF_Platform_Engineering::new_telemetry_context( $parent ); }
function spf_capture_governance_snapshot( $label = '' ) { return SPF_Resilience_Lab::capture_snapshot( $label ); }
function spf_diff_governance_snapshot( $snapshot_id ) { return SPF_Resilience_Lab::diff_snapshot( $snapshot_id ); }
function spf_governance_snapshot_restore_plan( $snapshot_id ) { return SPF_Resilience_Lab::restore_snapshot_plan( $snapshot_id ); }
function spf_restore_governance_snapshot( $snapshot_id, $confirmation, $expected_hash ) { return SPF_Resilience_Lab::restore_snapshot( $snapshot_id, $confirmation, $expected_hash ); }
function spf_ai_governance_advice( array $input ) { return SPF_Governance_Control_Plane::advisory_copilot( $input ); }

<?php
/**
 * Plugin Name: Sabri Platform Foundation
 * Plugin URI: https://www.sabrihomeopathy.com/
 * Description: Canonical File 01-B governance, registry, dependency, route, privacy, health, reconciliation, repair and release-evidence runtime for the Sabri Social Homeopathy Platform.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-platform-foundation
 */

defined( 'ABSPATH' ) || exit;

define( 'SPF_VERSION', '1.1.0' );
define( 'SPF_SCHEMA_VERSION', '1.1.0' );
define( 'SPF_CONTRACT_VERSION', '1.1.0' );
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
require_once SPF_DIR . 'includes/class-spf-admin.php';
require_once SPF_DIR . 'includes/class-spf-plugin.php';


function spf_foundation_cron_schedules( $schedules ) {
	$schedules['spf_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Every five minutes (Sabri Foundation)', 'sabri-platform-foundation' ) );
	return $schedules;
}
add_filter( 'cron_schedules', 'spf_foundation_cron_schedules' );

register_activation_hook( SPF_FILE, array( 'SPF_Installer', 'activate' ) );
register_deactivation_hook( SPF_FILE, array( 'SPF_Installer', 'deactivate' ) );

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

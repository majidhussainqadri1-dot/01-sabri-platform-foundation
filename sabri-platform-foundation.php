<?php
/**
 * Plugin Name: Sabri Platform Foundation
 * Plugin URI: https://www.sabrihomeopathy.com/
 * Description: Canonical File 01-B governance, registry, dependency, route, privacy, health, reconciliation, platform-engineering control-plane and release-evidence runtime for the Sabri Social Homeopathy Platform.
 * Version: 2.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-platform-foundation
 */

defined( 'ABSPATH' ) || exit;

define( 'SPF_VERSION', '2.0.1' );
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

SPF_Plugin::instance()->boot();

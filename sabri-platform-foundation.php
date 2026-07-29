<?php
/**
 * Plugin Name: Sabri Platform Foundation
 * Plugin URI: https://www.sabrihomeopathy.com/
 * Description: Modular foundation, navigation, public home and news shell for the Sabri Social Homeopathy Platform.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Dr. Allama Majid Hussain Sabri
 * License: GPL-2.0-or-later
 * Text Domain: sabri-platform-foundation
 */

defined( 'ABSPATH' ) || exit;

define( 'SPF_VERSION', '0.1.0' );
define( 'SPF_FILE', __FILE__ );
define( 'SPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPF_URL', plugin_dir_url( __FILE__ ) );

require_once SPF_DIR . 'includes/class-spf-activator.php';
require_once SPF_DIR . 'includes/class-spf-renderer.php';
require_once SPF_DIR . 'includes/class-spf-plugin.php';

register_activation_hook( SPF_FILE, array( 'SPF_Activator', 'activate' ) );

function spf_start_plugin() {
	$plugin = new SPF_Plugin();
	$plugin->run();
}
add_action( 'plugins_loaded', 'spf_start_plugin' );


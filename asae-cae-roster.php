<?php
/**
 * Plugin Name:       ASAE CAE Roster
 * Plugin URI:        https://github.com/ksoaresasae/asae-cae-roster
 * Description:       (placeholder — populate via instructions/_start.md)
 * Version:           0.0.1
 * Author:            Keith M. Soares
 * Author URI:        https://keithmsoares.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       asae-cae-roster
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin Constants ──────────────────────────────────────────────────────────

define( 'ASAE_CAE_VERSION', '0.0.1' );
define( 'ASAE_CAE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASAE_CAE_URL', plugin_dir_url( __FILE__ ) );
define( 'ASAE_CAE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ASAE_CAE_PREFIX', 'asae_cae' );

// ── Load Dependencies ─────────────────────────────────────────────────────────

$asae_cae_classes = [
	'class-asae-cae-admin.php',
	'class-github-updater.php',
];

foreach ( $asae_cae_classes as $class_file ) {
	$path = ASAE_CAE_PATH . 'includes/' . $class_file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

// ── Activation / Deactivation Hooks ───────────────────────────────────────────

function asae_cae_activate() {
	update_option( 'asae_cae_version', ASAE_CAE_VERSION );
}
register_activation_hook( __FILE__, 'asae_cae_activate' );

function asae_cae_deactivate() {
	// Clear any scheduled cron events for this plugin (none yet).
}
register_deactivation_hook( __FILE__, 'asae_cae_deactivate' );

// ── Plugin Bootstrap ──────────────────────────────────────────────────────────

function asae_cae_init() {
	// Self-hosted update checker (GitHub Releases).
	new ASAE_CAE_GitHub_Updater();

	// Admin UI (menus, AJAX handlers, asset enqueues).
	if ( is_admin() ) {
		ASAE_CAE_Admin::init();
	}
}
add_action( 'plugins_loaded', 'asae_cae_init' );

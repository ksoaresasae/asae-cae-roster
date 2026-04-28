<?php
/**
 * Plugin Name:       ASAE CAE Roster
 * Plugin URI:        https://github.com/ksoaresasae/asae-cae-roster
 * Description:       Pull the full list of CAEs (Certified Association Executives) from a Wicket datasource and render them as a paginated, searchable, last-name-organized public roster via the [asae_cae_roster] shortcode.
 * Version:           0.0.13
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

define( 'ASAE_CAE_VERSION', '0.0.13' );
define( 'ASAE_CAE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASAE_CAE_URL', plugin_dir_url( __FILE__ ) );
define( 'ASAE_CAE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ASAE_CAE_PREFIX', 'asae_cae' );

// ── Load Dependencies ─────────────────────────────────────────────────────────

$asae_cae_classes = [
	'class-asae-cae-db.php',
	'class-asae-cae-logger.php',
	'class-asae-cae-settings.php',
	'class-asae-cae-wicket-client.php',
	'class-asae-cae-photos.php',
	'class-asae-cae-sync.php',
	'class-asae-cae-shortcode.php',
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
	ASAE_CAE_DB::create_tables();
	ASAE_CAE_Sync::schedule();
	update_option( 'asae_cae_version', ASAE_CAE_VERSION );
}
register_activation_hook( __FILE__, 'asae_cae_activate' );

function asae_cae_deactivate() {
	ASAE_CAE_Sync::unschedule();
}
register_deactivation_hook( __FILE__, 'asae_cae_deactivate' );

// ── Plugin Bootstrap ──────────────────────────────────────────────────────────

function asae_cae_init() {
	// Run a DB schema upgrade if the stored version differs. dbDelta() is
	// idempotent and safe to call on every load.
	if ( get_option( 'asae_cae_db_version' ) !== ASAE_CAE_VERSION ) {
		ASAE_CAE_DB::create_tables();
	}

	// One-time settings migrations. Each migration writes a flag option so
	// it doesn't run again. Migrations only ever bump values that are still
	// at the previous default — manual customizations are left alone.
	if ( '1' !== get_option( 'asae_cae_v0013_migrated', '' ) ) {
		$current = get_option( ASAE_CAE_Settings::OPTION_KEY );
		if ( is_array( $current ) ) {
			$changed = false;
			// Bump items_per_page 20 → 50 if user is still at the prior default.
			if ( isset( $current['items_per_page'] ) && 20 === (int) $current['items_per_page'] ) {
				$current['items_per_page'] = 50;
				$changed                   = true;
			}
			// Seed schedule_days with all 7 days for installs that pre-date
			// the day-of-week setting (so existing users keep their daily
			// behaviour after upgrade — no surprise sync-off).
			if ( ! isset( $current['schedule_days'] ) || ! is_array( $current['schedule_days'] ) ) {
				$current['schedule_days'] = array( 0, 1, 2, 3, 4, 5, 6 );
				$changed                  = true;
			}
			if ( $changed ) {
				update_option( ASAE_CAE_Settings::OPTION_KEY, $current, false );
			}
		}
		update_option( 'asae_cae_v0013_migrated', '1', false );
	}

	// Cron callback must be wired regardless of context (cron also fires from
	// frontend page loads).
	ASAE_CAE_Sync::register_cron_action();

	// Make sure the daily sync stays scheduled even if it was somehow lost
	// (e.g. another plugin clears all events). schedule() is idempotent.
	ASAE_CAE_Sync::schedule();

	// Public shortcode (registers add_shortcode + frontend asset enqueue).
	ASAE_CAE_Shortcode::init();

	// Self-hosted update checker (GitHub Releases).
	new ASAE_CAE_GitHub_Updater();

	// Admin UI (menus, AJAX handlers, asset enqueues).
	if ( is_admin() ) {
		ASAE_CAE_Admin::init();
	}
}
add_action( 'plugins_loaded', 'asae_cae_init' );

<?php
/**
 * ASAE CAE Roster – Uninstall Script
 *
 * Runs automatically when an administrator deletes the plugin via the WP
 * Plugins screen. Removes plugin data: scheduled cron, options, and the
 * three custom tables.
 *
 * Note: photo attachments sideloaded by the plugin are intentionally NOT
 * removed here — they're indistinguishable from manually-uploaded media
 * once stored, and the admin can clean them up via the media library if
 * desired.
 *
 * @package ASAE_CAE_Roster
 * @since   0.0.1
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_dir = plugin_dir_path( __FILE__ );
require_once $plugin_dir . 'includes/class-asae-cae-db.php';

// ── Clear WP-Cron events ─────────────────────────────────────────────────────
// Sync class isn't loaded in uninstall context; clear the hook directly.
wp_clear_scheduled_hook( 'asae_cae_run_sync' );

// ── Remove wp_options entries ────────────────────────────────────────────────
$options_to_delete = array(
	'asae_cae_settings',
	'asae_cae_version',
	'asae_cae_db_version',
);
foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

// ── Drop custom database tables ──────────────────────────────────────────────
ASAE_CAE_DB::drop_tables();

// ── Clear updater cache ──────────────────────────────────────────────────────
delete_transient( 'asae_cae_github_release' );

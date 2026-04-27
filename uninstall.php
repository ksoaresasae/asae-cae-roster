<?php
/**
 * ASAE CAE Roster – Uninstall Script
 *
 * Runs automatically when an administrator deletes the plugin via the WP
 * Plugins screen. Permanently removes plugin data.
 *
 * @package ASAE_CAE_Roster
 * @since   0.0.1
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Remove wp_options entries ─────────────────────────────────────────────────

$options_to_delete = [
	'asae_cae_version',
];

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

// Add additional cleanup (custom tables, transients, user meta) here as the
// plugin grows. Keep operations idempotent so re-running is safe.

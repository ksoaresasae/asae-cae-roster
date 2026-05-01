<?php
/**
 * ASAE CAE Roster — Database schema and table operations.
 *
 * Two tables for cached CAE records:
 *   - {prefix}_people         — live data served to the public shortcode.
 *   - {prefix}_people_staging — sync writes here; promoted to live on success.
 *
 * Plus one log table:
 *   - {prefix}_sync_log       — one row per sync attempt (success or failure).
 *
 * The stage-and-swap design is what makes "revert to prior data on failure"
 * automatic: if a sync aborts partway through, the live table is never touched.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_DB {

	/** @return string */
	public static function people_table() {
		global $wpdb;
		return $wpdb->prefix . 'asae_cae_people';
	}

	/** @return string */
	public static function staging_table() {
		global $wpdb;
		return $wpdb->prefix . 'asae_cae_people_staging';
	}

	/** @return string */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'asae_cae_sync_log';
	}

	/**
	 * Create or upgrade all plugin tables. Idempotent; safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$people_schema = "
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wicket_uuid VARCHAR(36) NOT NULL,
			family_name VARCHAR(255) NOT NULL DEFAULT '',
			family_name_initial CHAR(1) NOT NULL DEFAULT '',
			given_name VARCHAR(255) NOT NULL DEFAULT '',
			full_name VARCHAR(255) NOT NULL DEFAULT '',
			honorific_suffix VARCHAR(255) NOT NULL DEFAULT '',
			job_title VARCHAR(500) NOT NULL DEFAULT '',
			organization_name VARCHAR(500) NOT NULL DEFAULT '',
			city VARCHAR(255) NOT NULL DEFAULT '',
			state VARCHAR(100) NOT NULL DEFAULT '',
			country VARCHAR(100) NOT NULL DEFAULT '',
			photo_url TEXT NULL,
			photo_attachment_id BIGINT UNSIGNED NULL,
			cae_start_date DATE NULL,
			cae_end_date DATE NULL,
			last_synced_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY wicket_uuid (wicket_uuid),
			KEY family_name_initial (family_name_initial),
			KEY family_name (family_name)
		";

		dbDelta( "CREATE TABLE " . self::people_table()  . " ( $people_schema ) $charset_collate;" );
		dbDelta( "CREATE TABLE " . self::staging_table() . " ( $people_schema ) $charset_collate;" );

		$log_schema = "
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ended_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			triggered_by VARCHAR(20) NOT NULL DEFAULT 'cron',
			requests_made INT UNSIGNED NOT NULL DEFAULT 0,
			records_processed INT UNSIGNED NOT NULL DEFAULT 0,
			records_added INT UNSIGNED NOT NULL DEFAULT 0,
			records_updated INT UNSIGNED NOT NULL DEFAULT 0,
			records_removed INT UNSIGNED NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at)
		";

		dbDelta( "CREATE TABLE " . self::log_table() . " ( $log_schema ) $charset_collate;" );

		update_option( 'asae_cae_db_version', ASAE_CAE_VERSION );
	}

	/**
	 * Drop all plugin tables. Called from uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::staging_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::people_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::log_table() );
		delete_option( 'asae_cae_db_version' );
	}

	/**
	 * Atomically promote staging to live and clear staging for next run.
	 *
	 * Uses MySQL's three-way RENAME TABLE rotation, which is atomic. The old
	 * live table becomes the new staging table (and gets truncated). If the
	 * rename fails, the live table is untouched.
	 *
	 * @return bool True on success.
	 */
	public static function promote_staging_to_live() {
		global $wpdb;

		$live    = self::people_table();
		$staging = self::staging_table();
		$tmp     = $live . '_swap_' . wp_rand( 1000, 9999 );

		// Three-way rotation: live → tmp, staging → live, tmp → staging.
		$sql = "RENAME TABLE `$live` TO `$tmp`, `$staging` TO `$live`, `$tmp` TO `$staging`";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are computed locally, no user input.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return false;
		}

		// New staging table inherits the old live data; clear it for the next run.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE `$staging`" );

		return true;
	}

	/**
	 * Clear the staging table without touching live (used on sync failure or
	 * before starting a new sync).
	 *
	 * @return void
	 */
	public static function truncate_staging() {
		global $wpdb;
		$staging = self::staging_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE `$staging`" );
	}

	/**
	 * Total count of CAE records currently visible to the public.
	 *
	 * @return int
	 */
	public static function count_live() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::people_table() );
	}

	/**
	 * Most recent sync log entry (any status).
	 *
	 * @return object|null
	 */
	public static function latest_sync() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( 'SELECT * FROM ' . self::log_table() . ' ORDER BY started_at DESC LIMIT 1' );
	}
}

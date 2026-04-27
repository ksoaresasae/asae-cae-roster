<?php
/**
 * ASAE CAE Roster — Sync logger.
 *
 * Writes progress and outcome rows into wp_asae_cae_sync_log. Each sync run
 * starts a row, increments counters as it goes, then closes with a final
 * status. Optional mirror to PHP error_log when WP_DEBUG_LOG is enabled.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Logger {

	const STATUS_RUNNING = 'running';
	const STATUS_SUCCESS = 'success';
	const STATUS_FAILED  = 'failed';
	const STATUS_ABORTED = 'aborted';

	const TRIGGER_CRON       = 'cron';
	const TRIGGER_MANUAL     = 'manual';
	const TRIGGER_ACTIVATION = 'activation';

	/**
	 * Start a new sync log row. Returns the inserted log ID for later updates.
	 *
	 * @param string $triggered_by One of TRIGGER_*.
	 * @return int Log row ID, or 0 on failure.
	 */
	public static function start( $triggered_by = self::TRIGGER_CRON ) {
		global $wpdb;
		$ok = $wpdb->insert(
			ASAE_CAE_DB::log_table(),
			array(
				'started_at'   => current_time( 'mysql' ),
				'status'       => self::STATUS_RUNNING,
				'triggered_by' => sanitize_key( $triggered_by ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			self::debug( 'Failed to insert sync log row: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update counters / notes on an in-progress sync row.
	 *
	 * @param int   $id     Log row ID.
	 * @param array $fields Subset of: requests_made, records_processed,
	 *                      records_added, records_updated, records_removed,
	 *                      notes (string).
	 * @return void
	 */
	public static function update( $id, array $fields ) {
		if ( $id <= 0 || empty( $fields ) ) {
			return;
		}

		$allowed = array(
			'requests_made'     => '%d',
			'records_processed' => '%d',
			'records_added'     => '%d',
			'records_updated'   => '%d',
			'records_removed'   => '%d',
			'notes'             => '%s',
		);

		$data    = array();
		$formats = array();
		foreach ( $fields as $key => $value ) {
			if ( isset( $allowed[ $key ] ) ) {
				$data[ $key ] = $value;
				$formats[]    = $allowed[ $key ];
			}
		}
		if ( empty( $data ) ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			ASAE_CAE_DB::log_table(),
			$data,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Mark a sync row finished with a terminal status.
	 *
	 * @param int    $id            Log row ID.
	 * @param string $status        One of STATUS_SUCCESS / STATUS_FAILED / STATUS_ABORTED.
	 * @param string $error_message Optional human-readable failure detail.
	 * @return void
	 */
	public static function finish( $id, $status, $error_message = '' ) {
		if ( $id <= 0 ) {
			return;
		}

		$status = in_array( $status, array( self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_ABORTED ), true )
			? $status
			: self::STATUS_FAILED;

		global $wpdb;
		$wpdb->update(
			ASAE_CAE_DB::log_table(),
			array(
				'ended_at'      => current_time( 'mysql' ),
				'status'        => $status,
				'error_message' => $error_message ? (string) $error_message : null,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( self::STATUS_SUCCESS !== $status && $error_message ) {
			self::debug( 'Sync ' . $status . ' (log #' . (int) $id . '): ' . $error_message );
		}
	}

	/**
	 * Most recent log rows for the Logs tab and the Roster status panel.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public static function recent( $limit = 25 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; limit is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . ASAE_CAE_DB::log_table() . ' ORDER BY started_at DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Mirror a message to the PHP error log when WP_DEBUG_LOG is enabled.
	 * No-ops in production sites that don't have debug logging on.
	 *
	 * @param string $message
	 * @return void
	 */
	public static function debug( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[ASAE CAE Roster] ' . $message );
		}
	}
}

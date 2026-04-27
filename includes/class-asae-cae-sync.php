<?php
/**
 * ASAE CAE Roster — Sync orchestration.
 *
 * Pulls active CAE records from Wicket, normalizes them, and stages them
 * into wp_asae_cae_people_staging. On full success the staging table is
 * atomically promoted to live (see ASAE_CAE_DB::promote_staging_to_live).
 * Any failure leaves the live table untouched, so readers always see the
 * last good snapshot.
 *
 * Per _start.md: this plugin's data is VERY LOW priority. On any error
 * (auth, rate budget, timeout) we log the failure, abandon the run, and
 * wait for the next scheduled tick. We never retry forever or hammer
 * Wicket in ways that could starve another, more critical plugin.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Sync {

	/** WP-Cron hook fired by the daily schedule. */
	const CRON_HOOK = 'asae_cae_run_sync';

	/**
	 * wp_options key holding the cooperative stop signal. When set to '1' a
	 * running sync will exit cleanly at its next per-record check. Cleared at
	 * the top of every fresh run so a stale flag doesn't kill new work.
	 */
	const STOP_FLAG = 'asae_cae_stop_signal';

	/** wp_options key holding the live progress snapshot (autoload=no). */
	const PROGRESS_KEY = 'asae_cae_sync_progress';

	/** A 'running' log row older than this is treated as crashed and recovered. */
	const STALE_RUN_SECONDS = 30 * MINUTE_IN_SECONDS;

	/** How often the foreach loop persists progress (every N records). */
	const PROGRESS_TICK_EVERY = 10;

	/**
	 * Register the cron action so WP knows what to call when the event fires.
	 * Called from plugins_loaded — must be wired regardless of admin context
	 * since cron also runs on frontend page loads.
	 *
	 * @return void
	 */
	public static function register_cron_action() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_from_cron' ) );
	}

	/**
	 * Cron entry point. Always runs as TRIGGER_CRON.
	 *
	 * @return void
	 */
	public static function run_from_cron() {
		self::run( ASAE_CAE_Logger::TRIGGER_CRON );
	}

	/**
	 * Schedule the daily sync at the configured local time.
	 *
	 * Idempotent: if an event is already scheduled, we leave it alone unless
	 * the configured time has changed (in which case the caller should call
	 * unschedule() first via reschedule()).
	 *
	 * @return bool True if a new event was scheduled or one already existed.
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return true;
		}

		$timestamp = self::next_run_timestamp();
		$result    = wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
		return false !== $result;
	}

	/**
	 * Clear all scheduled occurrences of our cron event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Reschedule from scratch. Call after settings changes to a new schedule
	 * time.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::unschedule();
		self::schedule();
	}

	/**
	 * Compute the next Unix timestamp at which the configured local-time
	 * schedule (HH:MM in wp_timezone()) will next occur. If today's slot has
	 * already passed, returns tomorrow's.
	 *
	 * @return int
	 */
	public static function next_run_timestamp() {
		$tz     = wp_timezone();
		$hour   = ASAE_CAE_Settings::get_schedule_hour();
		$minute = ASAE_CAE_Settings::get_schedule_minute();

		$now    = new DateTime( 'now', $tz );
		$target = new DateTime(
			sprintf( 'today %02d:%02d:00', $hour, $minute ),
			$tz
		);
		if ( $target <= $now ) {
			$target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	/**
	 * Run a sync. Used by both cron and the admin "Sync Now" button.
	 *
	 * @param string $triggered_by ASAE_CAE_Logger::TRIGGER_*
	 * @return array{ok:bool, message:string, log_id:int}
	 */
	public static function run( $triggered_by = ASAE_CAE_Logger::TRIGGER_CRON ) {
		// Clear any leftover stop signal from a prior request — otherwise this
		// fresh run would abort on its first kill-switch check.
		delete_option( self::STOP_FLAG );

		// Recover stale 'running' log rows (e.g. PHP crashed mid-sync) and
		// then refuse to start if a fresh sync is genuinely active.
		self::recover_stale_runs();
		if ( self::is_sync_in_progress() ) {
			$msg = __( 'Another sync is already in progress. Wait for it to finish or click Stop All Active Jobs.', 'asae-cae-roster' );
			return array( 'ok' => false, 'message' => $msg, 'log_id' => 0 );
		}

		$log_id = ASAE_CAE_Logger::start( $triggered_by );
		self::set_progress( 0, 0, __( 'Preparing…', 'asae-cae-roster' ) );

		if ( ! ASAE_CAE_Settings::is_wicket_configured() ) {
			$msg = __( 'Wicket is not fully configured (base URL, secret, and person ID required).', 'asae-cae-roster' );
			ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $msg );
			self::clear_progress();
			return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
		}

		// Always start from a clean staging table. Anything left over from a
		// prior failed run is stale and would corrupt the new sync.
		self::set_progress( 0, 0, __( 'Clearing staging table…', 'asae-cae-roster' ) );
		ASAE_CAE_DB::truncate_staging();

		$client = new ASAE_CAE_Wicket_Client();

		self::set_progress( 0, 0, __( 'Fetching CAEs from Wicket…', 'asae-cae-roster' ) );

		try {
			$response = $client->get_all(
				'people',
				array(
					'filter[tag_eq]'    => 'CAE',
					'filter[status_eq]' => 'active',
					'include'           => 'primary_organization,addresses',
				),
				25
			);
		} catch ( ASAE_CAE_Wicket_Exception $e ) {
			$msg = $e->getMessage();
			ASAE_CAE_Logger::update( $log_id, array( 'requests_made' => $client->requests_made() ) );
			ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $msg );
			ASAE_CAE_DB::truncate_staging();
			self::clear_progress();
			return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
		}

		$included     = isset( $response['included'] ) ? (array) $response['included'] : array();
		$orgs_index   = self::index_included( $included, 'organizations' );
		$addrs_index  = self::index_included( $included, 'addresses' );

		$processed = 0;
		$skipped   = 0;
		$today     = current_time( 'Y-m-d' );

		$people  = isset( $response['data'] ) ? (array) $response['data'] : array();
		$total   = count( $people );
		$stopped = false;

		self::set_progress( 0, $total, __( 'Processing records…', 'asae-cae-roster' ) );

		$idx = 0;
		foreach ( $people as $person ) {
			$idx++;

			// Cooperative stop check — set by the "Stop All Active Jobs" admin
			// action. Read from the DB (not a transient) so external object
			// caches can't mask the signal mid-run.
			if ( '1' === self::read_stop_flag_uncached() ) {
				$stopped = true;
				break;
			}

			$record = self::normalize_person( $person, $orgs_index, $addrs_index, $today );
			if ( null === $record ) {
				$skipped++;
			} else {
				// Photos are no longer downloaded — we just store the URL and
				// the shortcode lazy-loads it at view time, falling back to
				// the admin-configured default if it 404s. See ASAE_CAE_Photos.
				$record['photo_attachment_id'] = 0;
				$ok = self::insert_staging( $record );
				if ( $ok ) {
					$processed++;
				} else {
					$skipped++;
				}
			}

			// Persist progress every N records so the admin poll has fresh
			// data without us paying a DB write per row.
			if ( 0 === ( $idx % self::PROGRESS_TICK_EVERY ) || $idx === $total ) {
				$detail = ( null !== $record && '' !== $record['full_name'] )
					? $record['full_name']
					: '';
				self::set_progress( $processed, $total, __( 'Processing records…', 'asae-cae-roster' ), $detail );
				ASAE_CAE_Logger::update(
					$log_id,
					array(
						'requests_made'     => $client->requests_made(),
						'records_processed' => $processed,
					)
				);
			}
		}

		// Stopped mid-loop — finalize cleanly and leave live data alone.
		if ( $stopped ) {
			$msg = __( 'Stopped manually. Live roster unchanged; staging discarded.', 'asae-cae-roster' );
			ASAE_CAE_Logger::update(
				$log_id,
				array(
					'requests_made'     => $client->requests_made(),
					'records_processed' => $processed,
				)
			);
			ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_ABORTED, $msg );
			ASAE_CAE_DB::truncate_staging();
			delete_option( self::STOP_FLAG );
			self::clear_progress();
			return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
		}

		// Update progress on the log row before promoting (so a crash during
		// promotion still leaves a useful trail).
		ASAE_CAE_Logger::update(
			$log_id,
			array(
				'requests_made'     => $client->requests_made(),
				'records_processed' => $processed,
				'notes'             => $skipped > 0
					? sprintf( /* translators: %d: count of skipped records */ __( '%d record(s) skipped (inactive or unparseable).', 'asae-cae-roster' ), $skipped )
					: '',
			)
		);

		self::set_progress( $processed, $total, __( 'Promoting staging to live…', 'asae-cae-roster' ) );

		if ( ! ASAE_CAE_DB::promote_staging_to_live() ) {
			$msg = __( 'Staging promotion failed (RENAME TABLE returned false). Live data unchanged.', 'asae-cae-roster' );
			ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $msg );
			ASAE_CAE_DB::truncate_staging();
			self::clear_progress();
			return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
		}

		ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_SUCCESS );
		self::clear_progress();
		return array(
			'ok'      => true,
			'message' => sprintf( /* translators: %d: number of CAE records */ __( 'Synced %d CAE record(s).', 'asae-cae-roster' ), $processed ),
			'log_id'  => $log_id,
		);
	}

	/**
	 * Is there a 'running' log row right now? Used to refuse concurrent
	 * sync starts.
	 *
	 * @return bool
	 */
	public static function is_sync_in_progress() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . ASAE_CAE_DB::log_table() . " WHERE status = 'running'"
		) > 0;
	}

	/**
	 * Auto-recover any 'running' log row whose started_at is older than
	 * STALE_RUN_SECONDS — these represent crashed PHP processes that never
	 * got to call finish(). Marks them aborted so they don't permanently
	 * block new syncs.
	 *
	 * @return void
	 */
	private static function recover_stale_runs() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_RUN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . ASAE_CAE_DB::log_table() .
					" SET status = %s, ended_at = %s, error_message = %s" .
					" WHERE status = 'running' AND started_at < %s",
				ASAE_CAE_Logger::STATUS_ABORTED,
				current_time( 'mysql' ),
				__( 'Run abandoned: marked aborted automatically after exceeding the stale-run threshold.', 'asae-cae-roster' ),
				$cutoff
			)
		);
	}

	/**
	 * Stop every currently-running sync. Called from the "Stop All Active
	 * Jobs" admin button.
	 *
	 * Implementation:
	 *   1. Set the cooperative stop flag so any sync running in another PHP
	 *      process will exit cleanly at its next per-record check.
	 *   2. Mark every existing 'running' log row as 'aborted' immediately
	 *      (so the admin UI reflects the stop without waiting).
	 *   3. Truncate the staging table so a half-populated mirror can't be
	 *      promoted to live by anything else.
	 *
	 * Returns the number of log rows that were marked aborted.
	 *
	 * @return array{stopped:int, message:string}
	 */
	public static function stop_all_active() {
		// Step 1: raise the flag. autoload=no keeps it out of every page load.
		update_option( self::STOP_FLAG, '1', false );

		// Step 2: terminate any 'running' rows in the log so the UI updates
		// immediately. The actual PHP process may still be looping in another
		// request; when it next checks the flag it will see the stop and exit
		// cleanly without overwriting these rows (because finish() will run
		// on the same id with status=aborted, identical to what we set here).
		global $wpdb;
		$running_now = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . ASAE_CAE_DB::log_table() . " WHERE status = 'running'"
		);

		$stopped = (int) $wpdb->update(
			ASAE_CAE_DB::log_table(),
			array(
				'ended_at'      => current_time( 'mysql' ),
				'status'        => ASAE_CAE_Logger::STATUS_ABORTED,
				'error_message' => __( 'Stopped manually via admin action.', 'asae-cae-roster' ),
			),
			array( 'status' => 'running' ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $stopped ) {
			$stopped = 0;
		}

		// Step 3: discard any half-populated staging data.
		ASAE_CAE_DB::truncate_staging();

		if ( $running_now > 0 ) {
			$msg = sprintf(
				/* translators: %d: number of stopped runs */
				_n(
					'%d active sync stopped.',
					'%d active syncs stopped.',
					$running_now,
					'asae-cae-roster'
				),
				$running_now
			);
		} else {
			$msg = __( 'No active syncs were running. Staging cleared.', 'asae-cae-roster' );
		}

		return array(
			'stopped' => $stopped,
			'message' => $msg,
		);
	}

	/**
	 * Read the stop flag bypassing any object cache (transients/options can
	 * be cached in long-running requests, which would mask a fresh signal).
	 *
	 * @return string '1' when stop is active, '' otherwise.
	 */
	private static function read_stop_flag_uncached() {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::STOP_FLAG
			)
		);
		return null === $val ? '' : (string) $val;
	}

	// ── Progress snapshot (used by the Roster tab progress meter) ───────────

	/**
	 * Persist a progress snapshot the admin polls for. Called at every phase
	 * boundary and every PROGRESS_TICK_EVERY records inside the main loop.
	 *
	 * @param int    $current Records done.
	 * @param int    $total   Total records (0 when not yet known).
	 * @param string $phase   Headline status (e.g. "Processing records…").
	 * @param string $detail  Optional sub-status (e.g. the current name).
	 * @return void
	 */
	public static function set_progress( $current, $total, $phase = '', $detail = '' ) {
		update_option(
			self::PROGRESS_KEY,
			array(
				'current'    => max( 0, (int) $current ),
				'total'      => max( 0, (int) $total ),
				'phase'      => (string) $phase,
				'detail'     => (string) $detail,
				'updated_at' => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * Update only the `detail` field of the progress snapshot — used during
	 * the inner loop so we can show the current name without overwriting
	 * the headline phase. No-op if no snapshot exists yet.
	 *
	 * @param string $detail
	 * @return void
	 */
	public static function set_progress_detail( $detail ) {
		$existing = self::get_progress();
		if ( ! is_array( $existing ) ) {
			return;
		}
		$existing['detail']     = (string) $detail;
		$existing['updated_at'] = current_time( 'mysql' );
		update_option( self::PROGRESS_KEY, $existing, false );
	}

	/**
	 * Latest progress snapshot, or null if no sync has set one.
	 *
	 * @return array|null
	 */
	public static function get_progress() {
		$val = get_option( self::PROGRESS_KEY, null );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * Drop the progress snapshot (sync is no longer running, or was reset).
	 *
	 * @return void
	 */
	public static function clear_progress() {
		delete_option( self::PROGRESS_KEY );
	}

	// ── normalization ────────────────────────────────────────────────────────

	/**
	 * Build an associative index { id → resource } from a JSON:API `included`
	 * array, filtered to a specific resource type.
	 *
	 * @param array  $included
	 * @param string $type
	 * @return array<string,array>
	 */
	private static function index_included( array $included, $type ) {
		$out = array();
		foreach ( $included as $row ) {
			if ( isset( $row['type'], $row['id'] ) && $row['type'] === $type ) {
				$out[ (string) $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Convert a raw Wicket person resource into a row ready for the staging
	 * table. Returns null when the person is not a currently-active CAE.
	 *
	 * @param array  $person
	 * @param array  $orgs_index
	 * @param array  $addrs_index
	 * @param string $today  Y-m-d in WP local time.
	 * @return array|null
	 */
	private static function normalize_person( array $person, array $orgs_index, array $addrs_index, $today ) {
		$attrs = isset( $person['attributes'] ) ? (array) $person['attributes'] : array();

		// Hard filter: status must be active and not soft-deleted/anonymized.
		if ( ( $attrs['status'] ?? '' ) !== 'active' ) {
			return null;
		}
		if ( ! empty( $attrs['deleted_at'] ) ) {
			return null;
		}
		if ( ! empty( $attrs['anonymized_at'] ) ) {
			return null;
		}

		// Confirm the CAE designation is currently active. Wicket sometimes
		// keeps the CAE tag on people whose certification has lapsed, so the
		// designations data_field is the source of truth.
		$cae_field = self::find_data_field( $attrs['data_fields'] ?? array(), 'designations' );
		if ( null === $cae_field ) {
			return null;
		}
		$value = isset( $cae_field['value'] ) && is_array( $cae_field['value'] ) ? $cae_field['value'] : array();
		if ( empty( $value['cae'] ) ) {
			return null;
		}
		$end_date = isset( $value['end_date'] ) ? (string) $value['end_date'] : '';
		if ( '' !== $end_date && $end_date < $today ) {
			return null;
		}

		$family_name      = (string) ( $attrs['family_name'] ?? '' );
		$given_name       = (string) ( $attrs['given_name'] ?? '' );
		$full_name        = (string) ( $attrs['full_name'] ?? trim( $given_name . ' ' . $family_name ) );
		$honorific_suffix = (string) ( $attrs['honorific_suffix'] ?? '' );
		$honorific_suffix = self::ensure_cae_in_suffix( $honorific_suffix );

		// Photo lives inside data_fields → personal_info → photo_link.
		$photo_url = '';
		$pinfo     = self::find_data_field( $attrs['data_fields'] ?? array(), 'personal_info' );
		if ( null !== $pinfo && isset( $pinfo['value']['photo_link'] ) ) {
			$photo_url = (string) $pinfo['value']['photo_link'];
		}

		// Organization (via relationships.primary_organization → orgs_index).
		$org_name = '';
		$org_rel  = $person['relationships']['primary_organization']['data'] ?? null;
		if ( is_array( $org_rel ) && ! empty( $org_rel['id'] ) ) {
			$org_id = (string) $org_rel['id'];
			if ( isset( $orgs_index[ $org_id ] ) ) {
				$org_attrs = $orgs_index[ $org_id ]['attributes'] ?? array();
				$org_name  = (string) ( $org_attrs['legal_name'] ?? $org_attrs['name'] ?? '' );
			}
		}

		// City / state from the person's first usable address.
		list( $city, $state ) = self::pick_city_state( $person, $addrs_index );

		// First letter for the A-Z navigation. Capitalize and fall back to '#'
		// for names that don't start with a letter (rare but possible).
		$initial = '';
		if ( '' !== $family_name ) {
			$first = mb_substr( $family_name, 0, 1, 'UTF-8' );
			$initial = ctype_alpha( $first ) ? strtoupper( $first ) : '#';
		}

		return array(
			'wicket_uuid'         => (string) ( $person['id'] ?? '' ),
			'family_name'         => $family_name,
			'family_name_initial' => $initial,
			'given_name'          => $given_name,
			'full_name'           => $full_name,
			'honorific_suffix'    => $honorific_suffix,
			'job_title'           => (string) ( $attrs['job_title'] ?? '' ),
			'organization_name'   => $org_name,
			'city'                => $city,
			'state'               => $state,
			'photo_url'           => $photo_url,
			'cae_start_date'      => self::sanitize_date( $value['start_date'] ?? null ),
			'cae_end_date'        => self::sanitize_date( $end_date ),
			'last_synced_at'      => current_time( 'mysql' ),
		);
	}

	/**
	 * Insert a normalized record into the staging table.
	 *
	 * @param array $row
	 * @return bool
	 */
	private static function insert_staging( array $row ) {
		if ( '' === $row['wicket_uuid'] ) {
			return false;
		}

		global $wpdb;
		$ok = $wpdb->insert(
			ASAE_CAE_DB::staging_table(),
			array(
				'wicket_uuid'         => $row['wicket_uuid'],
				'family_name'         => $row['family_name'],
				'family_name_initial' => $row['family_name_initial'],
				'given_name'          => $row['given_name'],
				'full_name'           => $row['full_name'],
				'honorific_suffix'    => $row['honorific_suffix'],
				'job_title'           => $row['job_title'],
				'organization_name'   => $row['organization_name'],
				'city'                => $row['city'],
				'state'               => $row['state'],
				'photo_url'           => $row['photo_url'],
				'photo_attachment_id' => (int) $row['photo_attachment_id'],
				'cae_start_date'      => $row['cae_start_date'],
				'cae_end_date'        => $row['cae_end_date'],
				'last_synced_at'      => $row['last_synced_at'],
			),
			array(
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%d', '%s', '%s', '%s',
			)
		);
		return false !== $ok;
	}

	/**
	 * Find one entry in attributes.data_fields by its `key`. Returns null when
	 * the field isn't present or the array isn't shaped as expected.
	 *
	 * @param mixed  $data_fields Raw value from the API.
	 * @param string $key
	 * @return array|null
	 */
	private static function find_data_field( $data_fields, $key ) {
		if ( ! is_array( $data_fields ) ) {
			return null;
		}
		foreach ( $data_fields as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) && $field['key'] === $key ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Pick the most appropriate city/state pair from a person's addresses.
	 * Prefers a "work" address; falls back to the first address that has
	 * either a city or a state.
	 *
	 * @param array $person
	 * @param array $addrs_index
	 * @return array{0:string,1:string} [city, state]
	 */
	private static function pick_city_state( array $person, array $addrs_index ) {
		$rels = $person['relationships']['addresses']['data'] ?? array();
		if ( ! is_array( $rels ) ) {
			return array( '', '' );
		}

		$preferred = null;
		$first_any = null;

		foreach ( $rels as $ref ) {
			$id = isset( $ref['id'] ) ? (string) $ref['id'] : '';
			if ( '' === $id || ! isset( $addrs_index[ $id ] ) ) {
				continue;
			}
			$addr_attrs = $addrs_index[ $id ]['attributes'] ?? array();
			$city       = (string) ( $addr_attrs['city'] ?? '' );
			$state      = (string) ( $addr_attrs['state'] ?? $addr_attrs['region'] ?? '' );
			if ( '' === $city && '' === $state ) {
				continue;
			}
			$kind = strtolower( (string) ( $addr_attrs['kind'] ?? $addr_attrs['address_type'] ?? '' ) );
			if ( null === $first_any ) {
				$first_any = array( $city, $state );
			}
			if ( null === $preferred && in_array( $kind, array( 'work', 'business', 'office' ), true ) ) {
				$preferred = array( $city, $state );
				break; // Work address found — stop looking.
			}
		}

		return $preferred ?? ( $first_any ?? array( '', '' ) );
	}

	/**
	 * Make sure "CAE" is present in the displayed honorific suffix. Wicket
	 * sometimes returns suffixes with other credentials (e.g. CPACC) but no
	 * CAE even when the person has a valid CAE designation, so we append.
	 *
	 * @param string $suffix
	 * @return string
	 */
	private static function ensure_cae_in_suffix( $suffix ) {
		$suffix = trim( (string) $suffix );
		if ( '' === $suffix ) {
			return 'CAE';
		}
		// Tokenize on commas/whitespace and look for a case-insensitive CAE.
		$has_cae = false;
		foreach ( preg_split( '/[\s,]+/', $suffix ) as $token ) {
			if ( strcasecmp( $token, 'CAE' ) === 0 ) {
				$has_cae = true;
				break;
			}
		}
		return $has_cae ? $suffix : ( $suffix . ', CAE' );
	}

	/**
	 * Validate / coerce a date-ish input to Y-m-d, returning null on garbage.
	 *
	 * @param mixed $raw
	 * @return string|null
	 */
	private static function sanitize_date( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		// Accept either YYYY-MM-DD or full ISO 8601.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $m ) ) {
			return $m[1];
		}
		return null;
	}
}

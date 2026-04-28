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

	/**
	 * wp_options key holding the in-progress multi-chunk run state. Present
	 * only while a chunked sync is active; cleared when the run finalizes
	 * (success, failure, or stop). Schema:
	 *   log_id        — sync_log row that owns this run
	 *   next_page     — Wicket page number to fetch next (1-based)
	 *   total_pages   — discovered from response meta (0 if unknown)
	 *   total_items   — discovered from response meta (0 if unknown)
	 *   processed     — records inserted into staging so far
	 *   skipped       — records that failed normalization or insert
	 *   requests_made — total Wicket calls across all chunks of this run
	 *   started_at    — mysql ts when run() first initialized state
	 *   updated_at    — mysql ts of the most recent chunk completion
	 */
	const CHUNK_STATE_KEY = 'asae_cae_chunk_state';

	/** Wicket JSON:API page size. 25 is the platform default. */
	const PAGE_SIZE = 25;

	/** A 'running' log row whose chunk state hasn't updated in this long is treated as wedged. */
	const STALE_RUN_SECONDS = 30 * MINUTE_IN_SECONDS;

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
	 * Each call processes ONE chunk (one or a few Wicket pages) and either
	 * promotes staging to live (when all pages are done) or schedules the
	 * next single-event chunk. Across many chunks, a full sync of 4–5k
	 * records takes ~15–30 minutes at default settings (1 page per chunk,
	 * 5-second delay between chunks) — gentle on Wicket and resumable
	 * across PHP-process boundaries.
	 *
	 * @param string $triggered_by ASAE_CAE_Logger::TRIGGER_*
	 * @return array{ok:bool, message:string, log_id:int}
	 */
	public static function run( $triggered_by = ASAE_CAE_Logger::TRIGGER_CRON ) {
		// Clear any leftover stop signal from a prior request — otherwise this
		// fresh run would abort on its first kill-switch check.
		delete_option( self::STOP_FLAG );

		// Recover wedged 'running' log rows (e.g. PHP crashed mid-chunk and
		// no further chunk events were ever scheduled). Always runs first.
		self::recover_stale_runs();

		$state = self::get_chunk_state();

		if ( null === $state ) {
			// Fresh start. Refuse if some other 'running' row exists (defensive —
			// recover_stale_runs would have cleared anything truly dead).
			if ( self::is_sync_in_progress() ) {
				return array(
					'ok'      => false,
					'message' => __( 'Another sync is already in progress. Wait for it to finish or click Stop All Active Jobs.', 'asae-cae-roster' ),
					'log_id'  => 0,
				);
			}

			if ( ! ASAE_CAE_Settings::is_wicket_configured() ) {
				$msg    = __( 'Wicket is not fully configured (base URL, secret, and person ID required).', 'asae-cae-roster' );
				$log_id = ASAE_CAE_Logger::start( $triggered_by );
				ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $msg );
				return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
			}

			$log_id = ASAE_CAE_Logger::start( $triggered_by );
			ASAE_CAE_DB::truncate_staging();
			$state = array(
				'log_id'        => (int) $log_id,
				'next_page'     => 1,
				'total_pages'   => 0,
				'total_items'   => 0,
				'processed'     => 0,
				'skipped'       => 0,
				'requests_made' => 0,
				'started_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			);
			self::save_chunk_state( $state );
			self::set_progress( 0, 0, __( 'Preparing chunked sync…', 'asae-cae-roster' ) );
		}

		return self::run_chunk( $state );
	}

	/**
	 * Process a single chunk (1–N Wicket pages) of the in-progress run, then
	 * either schedule the next chunk or finalize the sync.
	 *
	 * @param array $state The chunk_state row (already validated by run()).
	 * @return array{ok:bool, message:string, log_id:int}
	 */
	private static function run_chunk( array $state ) {
		$log_id = (int) $state['log_id'];

		// Honor an admin-issued stop before doing any Wicket work.
		if ( '1' === self::read_stop_flag_uncached() ) {
			return self::finalize_stopped( $log_id, $state );
		}

		$client          = new ASAE_CAE_Wicket_Client();
		$pages_per_chunk = max( 1, ASAE_CAE_Settings::get_pages_per_chunk() );
		$start_page      = (int) $state['next_page'];
		$end_page        = $start_page + $pages_per_chunk - 1;

		self::set_progress(
			(int) $state['processed'],
			(int) $state['total_items'],
			sprintf(
				/* translators: 1: starting page, 2: ending page */
				__( 'Fetching pages %1$d–%2$d…', 'asae-cae-roster' ),
				$start_page,
				$end_page
			)
		);

		$data_acc     = array();
		$included_acc = array();
		$hit_end      = false;

		$body = self::build_query_filter_body();

		for ( $i = 0; $i < $pages_per_chunk; $i++ ) {
			$page = (int) $state['next_page'];
			try {
				$resp = $client->request_post(
					'people/query',
					self::build_query_string( $page ),
					$body
				);
			} catch ( ASAE_CAE_Wicket_Exception $e ) {
				return self::finalize_failed( $log_id, $state, $client, $e->getMessage() );
			}

			$data     = isset( $resp['data'] ) ? (array) $resp['data'] : array();
			$included = isset( $resp['included'] ) ? (array) $resp['included'] : array();

			// Capture totals from JSON:API meta (may not be present on all responses).
			$meta_total_pages = isset( $resp['meta']['page']['total_pages'] ) ? (int) $resp['meta']['page']['total_pages'] : 0;
			$meta_total_items = isset( $resp['meta']['page']['total_items'] ) ? (int) $resp['meta']['page']['total_items'] : 0;
			if ( $meta_total_pages > $state['total_pages'] ) {
				$state['total_pages'] = $meta_total_pages;
			}
			if ( $meta_total_items > $state['total_items'] ) {
				$state['total_items'] = $meta_total_items;
			}

			foreach ( $data as $row ) {
				$data_acc[] = $row;
			}
			foreach ( $included as $row ) {
				$included_acc[] = $row;
			}

			$state['next_page'] = $page + 1;

			// Short page = end of dataset.
			if ( count( $data ) < self::PAGE_SIZE ) {
				$hit_end = true;
				break;
			}
		}

		// Process accumulated records into staging.
		$orgs_index  = self::index_included( $included_acc, 'organizations' );
		$addrs_index = self::index_included( $included_acc, 'addresses' );
		$today       = current_time( 'Y-m-d' );

		$last_name = '';
		foreach ( $data_acc as $person ) {
			if ( '1' === self::read_stop_flag_uncached() ) {
				$state['requests_made'] += $client->requests_made();
				return self::finalize_stopped( $log_id, $state );
			}

			$record = self::normalize_person( $person, $orgs_index, $addrs_index, $today );
			if ( '' !== (string) ( $record['_skip_reason'] ?? '' ) ) {
				// Lapsed CAE / deleted / anonymized — counted, not inserted.
				$state['skipped']++;
				continue;
			}
			$record['photo_attachment_id'] = 0; // photos are lazy-loaded; see ASAE_CAE_Photos.
			if ( self::insert_staging( $record ) ) {
				$state['processed']++;
				$last_name = (string) $record['full_name'];
			} else {
				$state['skipped']++;
			}
		}

		$state['requests_made'] += $client->requests_made();
		$state['updated_at']     = current_time( 'mysql' );

		// Flush counters to the log row so the Logs tab updates between chunks.
		ASAE_CAE_Logger::update(
			$log_id,
			array(
				'requests_made'     => (int) $state['requests_made'],
				'records_processed' => (int) $state['processed'],
			)
		);

		// Done?
		$exhausted = $hit_end
			|| ( $state['total_pages'] > 0 && (int) $state['next_page'] > (int) $state['total_pages'] );

		if ( $exhausted ) {
			return self::finalize_success( $log_id, $state );
		}

		// More chunks to go: persist and self-reschedule.
		self::save_chunk_state( $state );
		self::set_progress(
			(int) $state['processed'],
			(int) max( $state['total_items'], $state['processed'] ),
			__( 'Processing records…', 'asae-cae-roster' ),
			$last_name
		);

		$delay = max( 1, ASAE_CAE_Settings::get_chunk_delay_seconds() );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK );

		return array(
			'ok'      => true,
			'log_id'  => $log_id,
			'message' => sprintf(
				/* translators: 1: total processed so far, 2: delay seconds */
				__( 'Chunk done: %1$d records so far. Next chunk in %2$d seconds.', 'asae-cae-roster' ),
				(int) $state['processed'],
				$delay
			),
		);
	}

	/**
	 * Finalize a successful run: promote staging → live and clear all
	 * transient state.
	 */
	private static function finalize_success( $log_id, array $state ) {
		self::set_progress(
			(int) $state['processed'],
			(int) max( $state['total_items'], $state['processed'] ),
			__( 'Promoting staging to live…', 'asae-cae-roster' )
		);

		if ( ! ASAE_CAE_DB::promote_staging_to_live() ) {
			$msg = __( 'Staging promotion failed (RENAME TABLE returned false). Live data unchanged.', 'asae-cae-roster' );
			ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $msg );
			ASAE_CAE_DB::truncate_staging();
			self::clear_chunk_state();
			self::clear_progress();
			return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
		}

		$skipped = (int) $state['skipped'];
		ASAE_CAE_Logger::update(
			$log_id,
			array(
				'notes' => $skipped > 0
					? sprintf( /* translators: %d: count of skipped records */ __( '%d record(s) skipped (inactive or unparseable).', 'asae-cae-roster' ), $skipped )
					: '',
			)
		);
		ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_SUCCESS );
		self::clear_chunk_state();
		self::clear_progress();

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of CAE records */
				__( 'Synced %d CAE record(s).', 'asae-cae-roster' ),
				(int) $state['processed']
			),
			'log_id'  => $log_id,
		);
	}

	/**
	 * Finalize a sync that hit a Wicket error mid-chunk. Live data untouched.
	 */
	private static function finalize_failed( $log_id, array $state, $client, $error_message ) {
		ASAE_CAE_Logger::update(
			$log_id,
			array( 'requests_made' => (int) $state['requests_made'] + (int) $client->requests_made() )
		);
		ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_FAILED, $error_message );
		ASAE_CAE_DB::truncate_staging();
		self::clear_chunk_state();
		self::clear_progress();

		return array(
			'ok'      => false,
			'message' => $error_message,
			'log_id'  => $log_id,
		);
	}

	/**
	 * Finalize a sync that was stopped via the kill flag.
	 */
	private static function finalize_stopped( $log_id, array $state ) {
		$msg = __( 'Stopped manually. Live roster unchanged; staging discarded.', 'asae-cae-roster' );
		ASAE_CAE_Logger::update(
			$log_id,
			array(
				'requests_made'     => (int) $state['requests_made'],
				'records_processed' => (int) $state['processed'],
			)
		);
		ASAE_CAE_Logger::finish( $log_id, ASAE_CAE_Logger::STATUS_ABORTED, $msg );
		ASAE_CAE_DB::truncate_staging();
		delete_option( self::STOP_FLAG );
		self::clear_chunk_state();
		self::clear_progress();

		return array( 'ok' => false, 'message' => $msg, 'log_id' => $log_id );
	}

	/**
	 * Fetch the first $limit CAEs alphabetically by family_name, normalize
	 * them, and return the rows. NEVER touches the staging or live tables —
	 * pure preview for the admin "Dry Run" button.
	 *
	 * @param int $limit Max records to return (1–100).
	 * @return array{ok:bool, message:string, rows:array, requests_made:int}
	 */
	public static function dry_run( $limit = 50 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		if ( ! ASAE_CAE_Settings::is_wicket_configured() ) {
			return array(
				'ok'            => false,
				'message'       => __( 'Wicket is not fully configured.', 'asae-cae-roster' ),
				'rows'          => array(),
				'requests_made' => 0,
			);
		}

		// Tight per-call budget: enough to fetch ceil($limit / page_size) pages
		// plus generous retry headroom, but never enough to walk the whole roster.
		$pages_needed   = (int) ceil( $limit / self::PAGE_SIZE );
		$request_budget = max( 5, $pages_needed * 3 );
		$client         = new ASAE_CAE_Wicket_Client( null, null, null, $request_budget, ASAE_CAE_Settings::get_request_delay_ms() );

		$data_acc     = array();
		$included_acc = array();
		$today        = current_time( 'Y-m-d' );
		$body         = self::build_query_filter_body();
		$first_resp   = null;

		try {
			for ( $page = 1; $page <= $pages_needed && count( $data_acc ) < $limit; $page++ ) {
				$resp = $client->request_post(
					'people/query',
					self::build_query_string( $page ),
					$body
				);
				if ( null === $first_resp ) {
					$first_resp = $resp;
				}
				$data     = isset( $resp['data'] ) ? (array) $resp['data'] : array();
				$included = isset( $resp['included'] ) ? (array) $resp['included'] : array();
				foreach ( $data as $row ) {
					$data_acc[] = $row;
				}
				foreach ( $included as $row ) {
					$included_acc[] = $row;
				}
				if ( count( $data ) < self::PAGE_SIZE ) {
					break; // last page reached
				}
			}
		} catch ( ASAE_CAE_Wicket_Exception $e ) {
			return array(
				'ok'             => false,
				'message'        => $e->getMessage(),
				'rows'           => array(),
				'raw_count'      => 0,
				'accepted_count' => 0,
				'requests_made'  => $client->requests_made(),
				'endpoint'       => 'POST /people/query',
				'query_body'     => $body,
				'response_keys'  => array(),
				'response_meta'  => null,
				'baseline_count' => null,
			);
		}

		$raw_count = count( $data_acc );

		// When the primary filter returned 0 records, run a battery of
		// diagnostic probes so we can see what's actually going on without
		// another code-edit cycle.
		$baseline_count = null;
		$filter_probes  = null;
		if ( 0 === $raw_count ) {
			try {
				$baseline       = $client->request( 'people', array( 'page[size]' => 3 ) );
				$baseline_count = isset( $baseline['data'] ) ? count( (array) $baseline['data'] ) : 0;
			} catch ( ASAE_CAE_Wicket_Exception $e ) {
				$baseline_count = -1; // signals "baseline call also threw"
			}
			$filter_probes = self::run_filter_probes();
		}

		// Normalize ALL rows for dry-run display — including the ones that
		// would be skipped at sync time. The `_skip_reason` field tells the
		// UI which rows to mark hidden and why, so the user can see "Wicket
		// returned 50, 5 of them are hidden because they're expired CAEs"
		// rather than puzzling over an unexplained 50→45 discrepancy.
		$orgs_index  = self::index_included( $included_acc, 'organizations' );
		$addrs_index = self::index_included( $included_acc, 'addresses' );

		$rows = array();
		foreach ( $data_acc as $person ) {
			$rows[] = self::normalize_person( $person, $orgs_index, $addrs_index, $today );
		}

		// Defensive client-side sort by family_name then given_name — Wicket
		// should already have sorted, but if it ignored our sort param this
		// keeps the preview consistent with how the public roster orders rows.
		usort(
			$rows,
			function ( $a, $b ) {
				$fa = strcasecmp( (string) $a['family_name'], (string) $b['family_name'] );
				if ( 0 !== $fa ) {
					return $fa;
				}
				return strcasecmp( (string) $a['given_name'], (string) $b['given_name'] );
			}
		);

		$rows = array_slice( $rows, 0, $limit );

		// Compute accepted vs hidden counts for the diagnostic top line.
		$accepted_count = 0;
		$hidden_count   = 0;
		foreach ( $rows as $r ) {
			if ( '' === (string) ( $r['_skip_reason'] ?? '' ) ) {
				$accepted_count++;
			} else {
				$hidden_count++;
			}
		}

		return array(
			'ok'             => true,
			'message'        => sprintf(
				/* translators: 1: rows returned to UI, 2: count active, 3: count hidden, 4: raw rows from Wicket */
				__( 'Dry run: %1$d record(s) returned (%2$d active, %3$d hidden). Wicket page total: %4$d.', 'asae-cae-roster' ),
				count( $rows ),
				$accepted_count,
				$hidden_count,
				$raw_count
			),
			'rows'           => $rows,
			'raw_count'      => $raw_count,
			'accepted_count' => $accepted_count,
			'hidden_count'   => $hidden_count,
			'requests_made'  => $client->requests_made(),
			'endpoint'       => 'POST /people/query',
			'query_body'     => $body,
			'response_keys'  => is_array( $first_resp ) ? array_keys( $first_resp ) : array(),
			'response_meta'  => is_array( $first_resp ) && isset( $first_resp['meta'] ) ? $first_resp['meta'] : null,
			'baseline_count' => $baseline_count,
			'filter_probes'  => $filter_probes,
		);
	}

	// ── Diagnostic probes (called only when the primary filter returns 0) ──

	/**
	 * Try a battery of candidate filter shapes and report which (if any)
	 * matched any records on the tenant. Each probe uses page[size]=1 so the
	 * round-trip cost is minimal even on rosters with thousands of CAEs.
	 *
	 * @return array<int, array{label:string, total_items:int|null, error:string|null, body:array}>
	 */
	private static function run_filter_probes() {
		// Generous budget, no inter-request delay — diagnostic only.
		$client = new ASAE_CAE_Wicket_Client( null, null, null, 30, 0 );

		$today = current_time( 'Y-m-d' );

		$variants = array(
			array(
				'label' => 'POST /people/query, no filter (sanity check)',
				'body'  => array( 'filter' => new stdClass() ),
			),
			array(
				'label' => 'POST /people/query, filter.status_eq = "active" (top-level only)',
				'body'  => array( 'filter' => array( 'status_eq' => 'active' ) ),
			),
			array(
				'label' => 'POST /people/query, search_query data_fields.designations.value.cae = true',
				'body'  => array(
					'filter' => array(
						'search_query' => array(
							'_and' => array(
								array( 'data_fields.designations.value.cae' => true ),
							),
						),
					),
				),
			),
			array(
				'label' => 'POST /people/query, search_query data_fields.designations.value.cae_eq = true (explicit predicate)',
				'body'  => array(
					'filter' => array(
						'search_query' => array(
							'_and' => array(
								array( 'data_fields.designations.value.cae_eq' => true ),
							),
						),
					),
				),
			),
			array(
				'label' => 'POST /people/query, search_query data_fields.designations.cae = true (no .value segment)',
				'body'  => array(
					'filter' => array(
						'search_query' => array(
							'_and' => array(
								array( 'data_fields.designations.cae' => true ),
							),
						),
					),
				),
			),
			array(
				'label' => 'POST /people/query, search_query data_fields.designations.value.cae_type = "cae" (string equality)',
				'body'  => array(
					'filter' => array(
						'search_query' => array(
							'_and' => array(
								array( 'data_fields.designations.value.cae_type' => 'cae' ),
							),
						),
					),
				),
			),
		);

		$out = array();
		foreach ( $variants as $v ) {
			$res = array( 'label' => $v['label'], 'total_items' => null, 'error' => null, 'body' => $v['body'] );
			try {
				$resp = $client->request_post( 'people/query', array( 'page[size]' => 1 ), $v['body'] );
				$res['total_items'] = isset( $resp['meta']['page']['total_items'] )
					? (int) $resp['meta']['page']['total_items']
					: ( isset( $resp['data'] ) ? count( (array) $resp['data'] ) : 0 );
			} catch ( ASAE_CAE_Wicket_Exception $e ) {
				$res['error'] = $e->getMessage();
			}
			$out[] = $res;
		}
		return $out;
	}


	/**
	 * Canonical /people/query filter body. Selects:
	 *   - active ASAE members (status = 'active')
	 *   - whose designations data_field flags them as a CAE, validated via two
	 *     independent fields (cae = true AND cae_type = "cae"). On the v0.0.7
	 *     diagnostic probes both filters returned the same 4,736-record count,
	 *     so AND-ing them costs nothing today; if Wicket's data ever drifts
	 *     between the boolean flag and the categorical type, the AND catches it.
	 *
	 * NOTE: A server-side end_date_gteq filter on this nested path does NOT
	 * work — predicate suffixes like `_gteq` only apply to top-level filter
	 * keys, not paths inside search_query. The "currently active" date check
	 * is performed client-side in normalize_person() on each returned record
	 * by comparing designations.value.end_date to today.
	 *
	 * @return array
	 */
	private static function build_query_filter_body() {
		return array(
			'filter' => array(
				'status_eq'    => 'active',
				'search_query' => array(
					'_and' => array(
						array( 'data_fields.designations.value.cae'      => true ),
						array( 'data_fields.designations.value.cae_type' => 'cae' ),
					),
				),
			),
		);
	}

	/**
	 * Standard query-string args used alongside the POST body — pagination,
	 * sorting, and sideloads.
	 *
	 * @param int $page
	 * @param int $page_size
	 * @return array
	 */
	private static function build_query_string( $page, $page_size = self::PAGE_SIZE ) {
		return array(
			'include'      => 'primary_organization,addresses',
			'sort'         => 'family_name',
			'page[size]'   => $page_size,
			'page[number]' => (int) $page,
		);
	}

	// ── Chunk state storage ─────────────────────────────────────────────────

	/** @return array|null */
	public static function get_chunk_state() {
		$val = get_option( self::CHUNK_STATE_KEY, null );
		return is_array( $val ) ? $val : null;
	}

	private static function save_chunk_state( array $state ) {
		update_option( self::CHUNK_STATE_KEY, $state, false );
	}

	private static function clear_chunk_state() {
		delete_option( self::CHUNK_STATE_KEY );
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
	 * Auto-recover wedged 'running' rows whose chunk_state hasn't advanced in
	 * STALE_RUN_SECONDS. With chunked syncs, started_at is no longer a useful
	 * staleness signal — a healthy run can take 30+ minutes overall. Use the
	 * chunk_state's updated_at instead, falling back to started_at when no
	 * chunk_state exists at all (legacy / failed-pre-init runs).
	 *
	 * @return void
	 */
	private static function recover_stale_runs() {
		global $wpdb;
		$state     = self::get_chunk_state();
		$cutoff_ts = time() - self::STALE_RUN_SECONDS;

		// Case 1: chunk_state exists and is fresh → run is healthy, do nothing.
		// IMPORTANT: updated_at was written with current_time('mysql') which
		// returns LOCAL time (in WP's configured timezone). Parse it through
		// DateTime + wp_timezone() so we get the correct unix timestamp on
		// sites west of UTC. (Earlier code used strtotime($x . ' UTC') which
		// was correct only when local == UTC; on America/New_York it shifted
		// fresh state 4 hours into the past, tripping the staleness cutoff
		// and killing every chunk after the first.)
		if ( is_array( $state ) && ! empty( $state['updated_at'] ) ) {
			$last = self::mysql_local_to_timestamp( (string) $state['updated_at'] );
			if ( $last && $last >= $cutoff_ts ) {
				return;
			}
		}

		// Case 2: stale chunk_state OR no chunk_state but running rows exist.
		// The cutoff has to be in the same time-zone format as `started_at`
		// (which is local, via current_time('mysql')). wp_date() formats a
		// unix timestamp in WP's configured timezone — exactly what we need.
		$cutoff_mysql = wp_date( 'Y-m-d H:i:s', $cutoff_ts );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . ASAE_CAE_DB::log_table() .
					" SET status = %s, ended_at = %s, error_message = %s" .
					" WHERE status = 'running' AND started_at < %s",
				ASAE_CAE_Logger::STATUS_ABORTED,
				current_time( 'mysql' ),
				__( 'Run abandoned: chunk state stalled past the recovery threshold.', 'asae-cae-roster' ),
				$cutoff_mysql
			)
		);

		// Always clear the (possibly stale) state and any leftover scheduled chunks.
		self::clear_chunk_state();
		self::clear_progress();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// Restore the daily recurring schedule (clear nuked it along with one-shots).
		self::schedule();
	}

	/**
	 * Convert a `current_time('mysql')` value (LOCAL time in WP's configured
	 * timezone) back to a unix timestamp. Returns 0 on parse failure.
	 *
	 * Use this in place of `strtotime($x . ' UTC')` for any value originally
	 * produced by `current_time('mysql')` — that function returns local time,
	 * not UTC, so the UTC-parse pattern is wrong on every site east or west
	 * of UTC.
	 *
	 * @param string $mysql_local
	 * @return int
	 */
	public static function mysql_local_to_timestamp( $mysql_local ) {
		if ( '' === (string) $mysql_local ) {
			return 0;
		}
		$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $mysql_local, wp_timezone() );
		return ( $dt instanceof DateTime ) ? $dt->getTimestamp() : 0;
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

		// Step 3: discard any half-populated staging data, drop the in-progress
		// chunk state, and unschedule any pending single-event chunks so the
		// run doesn't resurrect itself on the next page load.
		ASAE_CAE_DB::truncate_staging();
		self::clear_chunk_state();
		self::clear_progress();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::schedule(); // restore the daily recurring schedule.

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
	 * table. Always returns an array; the `_skip_reason` field tells callers
	 * whether the record should be inserted (empty string = accept) or
	 * displayed-but-skipped (non-empty = reason).
	 *
	 * Skip reasons currently set:
	 *   - `deleted` — attributes.deleted_at present
	 *   - `anonymized` — attributes.anonymized_at present
	 *   - `expired (end_date YYYY-MM-DD)` — designations.value.end_date in the past
	 *
	 * Wicket's server-side filter (cae=true AND cae_type="cae") matches every
	 * person who has the CAE designation, current or lapsed; the end_date
	 * check has to be client-side because predicate suffixes (`_gteq`) don't
	 * work on nested data_fields paths inside search_query.
	 *
	 * @param array  $person
	 * @param array  $orgs_index
	 * @param array  $addrs_index
	 * @param string $today  Y-m-d in WP local time.
	 * @return array
	 */
	private static function normalize_person( array $person, array $orgs_index, array $addrs_index, $today ) {
		$attrs = isset( $person['attributes'] ) ? (array) $person['attributes'] : array();

		// Optional designations data_field — when present, capture the date
		// range for the cache; when absent, leave dates empty.
		$cae_value = array();
		$cae_field = self::find_data_field( $attrs['data_fields'] ?? array(), 'designations' );
		if ( null !== $cae_field && isset( $cae_field['value'] ) && is_array( $cae_field['value'] ) ) {
			$cae_value = $cae_field['value'];
		}
		$end_date = isset( $cae_value['end_date'] ) ? (string) $cae_value['end_date'] : '';

		// Determine skip reason (if any). Empty string = accept.
		$skip_reason = '';
		if ( ! empty( $attrs['deleted_at'] ) ) {
			$skip_reason = __( 'deleted', 'asae-cae-roster' );
		} elseif ( ! empty( $attrs['anonymized_at'] ) ) {
			$skip_reason = __( 'anonymized', 'asae-cae-roster' );
		} elseif ( '' !== $end_date && $end_date < $today ) {
			$skip_reason = sprintf( /* translators: %s: end_date YYYY-MM-DD */ __( 'expired (end_date %s)', 'asae-cae-roster' ), $end_date );
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
			'cae_start_date'      => self::sanitize_date( $cae_value['start_date'] ?? null ),
			'cae_end_date'        => self::sanitize_date( $cae_value['end_date'] ?? null ),
			'last_synced_at'      => current_time( 'mysql' ),
			'_skip_reason'        => $skip_reason,
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

=== ASAE CAE Roster ===
Contributors: keithmsoares
Tags: asae, cae, roster, wicket
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 0.0.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pull active CAEs from Wicket and render them as a paginated, searchable, alphabetically-organized public roster.

== Description ==

ASAE CAE Roster pulls the current list of Certified Association Executives (CAEs) from a Wicket member-data instance and caches them in the local WordPress database. A nightly sync (default 02:00 local time) keeps the cache current. The public roster is rendered with the `[asae_cae_roster]` shortcode and includes:

* A-Z letter navigation with inactive letters disabled
* First/last name search
* Pagination within each letter section (default 20 per page)
* Each entry shows photo, full name with credentials, job title, employing organization, and city/state

The plugin is built to be a low-priority Wicket consumer: failed syncs revert to the prior good snapshot, per-run request budgets cap impact on the upstream API, and an admin "Test Connection" button validates credentials without running a full sync.

== Installation ==

1. Upload the `asae-cae-roster` folder to `/wp-content/plugins/`.
2. Activate the plugin via the WordPress Plugins screen.
3. Go to **ASAE → CAE Roster → Settings** and enter the Wicket Base URL, HMAC Secret, and Person UUID.
4. Click **Test Connection** to confirm credentials, then **Save Settings**.
5. Click **Sync Now** on the Roster tab to pull initial data, or wait for the scheduled run.
6. Add `[asae_cae_roster]` to any public page or post.

== Changelog ==

= 0.0.12 =
* Fix: chunked sync was getting "Run abandoned: chunk state stalled past the recovery threshold" overnight on local dev environments where WP-Cron's loopback to wp-cron.php is unreliable (Herd's NGINX/PHP-FPM, etc.). Lengthened ASAE_CAE_Sync::STALE_RUN_SECONDS from 30 minutes to 12 hours. The 30-minute threshold was treating "no traffic for 30 minutes" as a wedged run and aborting it; on hosts where cron events only fire when a page request happens, that threshold was incompatible with closed-laptop / inactive-tab gaps. 12 hours still cleans up genuine PHP-crash wreckage by morning, but lets a quiet-but-legit run resume the next time someone opens the dashboard. (Production hosts with steady frontend traffic were unaffected — this only bit local dev.)
* Browser tab throttling defense: when the Roster tab regains visibility (visibilitychange event), the admin JS immediately fires one progress poll and auto-resumes the chunk loop if a sync is still in progress. Chrome clamps setTimeout/setInterval to once-per-minute in tabs hidden longer than 5 minutes, which had been slowing the JS-driven chunk loop to a crawl when the user switched away. Tab-back now snaps it out of the throttle within one HTTP round-trip.
* Auto-resume from progress polling: if the polling tick sees a sync running and no JS chunk loop is currently driving it (e.g. user closed the laptop, came back, opened the dashboard fresh), the poller kicks off runChunkUntilDone automatically. Previously you had to click Sync Now again. A new chunkLoopActive flag prevents double-invocation when the poller and a manual click race.

= 0.0.11 =
* Sync Now now drives the entire chunked sync from the browser tab via repeated AJAX calls instead of relying on WP-Cron to fire each successive chunk. WP-Cron's loopback POST to wp-cron.php is unreliable on a lot of local dev environments (including Herd's NGINX/PHP-FPM out of the box), which had been leaving syncs stuck after the first chunk on those hosts. The JS-driven loop calls ajax_run_sync, waits the configured chunk_delay_seconds, and calls again — until the server reports in_progress=false. Wicket sees the same gentle one-page-every-5-seconds traffic pattern as before. The daily 02:00 sync still uses WP-Cron (which works fine in production WP environments).
* Added a short-lived transient chunk lock in Sync::run() so a JS-driven call and a cron-driven event can't accidentally race on the same Wicket page if both fire at once. TTL is 90 seconds — generous enough for slow API responses, short enough that a crashed PHP process can't keep the lock forever.
* Sync result payloads now include an `in_progress` flag based on whether chunk_state still exists after the call. Used by the JS loop to know when to stop calling.

= 0.0.10 =
* Dry Run now shows every record Wicket returned (up to the 50-record limit), not just the ones that pass validation. Each row has a Status column: green "Active" for records that would be inserted by a real sync, red "Hidden — <reason>" for ones that are filtered out. Reasons currently surface: deleted, anonymized, and "expired (end_date YYYY-MM-DD)". Skipped rows are also styled with strikethrough on the # and Name columns so they're visually obvious.
* Diagnostic top-line breaks the count into "active" + "hidden" instead of the older "passed structural validation" wording: e.g. "Wicket returned 50 record(s) · 45 active · 5 hidden · 2 API call(s)".
* Refactor: normalize_person() now always returns an array with a _skip_reason field instead of returning null on filter. Same effective behavior in the chunked sync (records with a non-empty reason are counted as skipped, not inserted) but lets dry_run keep them around for display.

= 0.0.9 =
* Fix: chunked sync stalled after the first chunk on sites in any timezone west of UTC. The recover_stale_runs() routine was parsing chunk_state.updated_at (which current_time('mysql') writes in LOCAL time) as if it were UTC, then comparing it to a cutoff that was also in UTC format. On America/New_York the result was that fresh state appeared 4 hours old — past the 30-minute staleness threshold — so every chunk after the first got incorrectly marked aborted and its successor unscheduled. Two-line fix: parse updated_at via DateTime::createFromFormat($fmt, $str, wp_timezone()) and format the SQL cutoff via wp_date() instead of gmdate().
* Fix: same timezone bug was making the Roster tab's "Last sync" timestamp and every Logs tab row display 4–5 hours off. Both views now use the new ASAE_CAE_Sync::mysql_local_to_timestamp() helper.
* Removed the misleading self-record probe from Dry Run diagnostics (added in v0.0.7 on the false premise that the configured Wicket person UUID would be a representative CAE — it's an API auth account, not a content sample). Baseline GET and filter probes remain.

= 0.0.8 =
* Production sync filter now uses two independent fields AND'd together: data_fields.designations.value.cae = true AND data_fields.designations.value.cae_type = "cae". Both returned the same 4,736-record count in the v0.0.7 diagnostic probes; AND-ing them costs nothing and acts as cross-validation against either field drifting in the future.
* Removed the server-side end_date_gteq filter. Wicket's search_query syntax does NOT support predicate suffixes (_eq, _gteq, etc.) on nested data_fields paths — the v0.0.7 probes confirmed this (the variant with `_eq` suffix returned 0 while the otherwise-identical implicit-equality variant returned 4,736). The "currently active CAE" date check is now performed client-side in normalize_person against designations.value.end_date.
* Trimmed Check for Updates "no releases" message from a multi-line tutorial to "No releases found." Diagnostic detail removed from the routine UX path.

= 0.0.7 =
* Check for Updates now distinguishes "no GitHub releases tagged yet" (cure-able by `git tag vX.Y.Z && git push origin vX.Y.Z`) from "couldn't reach api.github.com." Settings tab message is actionable instead of misleading.
* Dry Run is now self-debugging when the primary filter returns zero. After the existing baseline GET probe, it also runs (a) a "self-record" probe that fetches the configured Wicket person UUID's own /people/{uuid} record and shows the actual data_fields keys + the full designations entry, and (b) six filter-variant probes (no filter, status_eq only, four different data_fields path/predicate combinations) and reports total_items for each. Whichever variant returns >0 is the syntax we should use. Net result: one click pinpoints exactly which path/predicate Wicket honors, no further code-iteration guessing.
* The probes are diagnostic only; they fire only when the primary filter returns zero, so a working dry run still costs one API call.

= 0.0.6 =
* Check for Updates Now now reports its findings inline (matches asae-content-ingestor's pattern). When a newer release is found on GitHub the Settings tab shows "Update available: vX.Y.Z — Go to Plugins page" in red with a working link; otherwise it shows "You are running the latest version (vX.Y.Z)" in green. The previous behavior — generic "Update check complete, refresh the Plugins page" — gave no signal whether anything was actually pending.
* Made ASAE_CAE_GitHub_Updater::get_latest_release() public so the Check for Updates handler can compare current to remote.
* Dry Run output now includes a "Diagnostic detail" disclosure with the exact JSON request body, response top-level keys, response meta, and a baseline GET /people probe that runs automatically when the primary filter returns zero. Lets us see whether the filter is rejecting matches or the tenant has no data, without further code iteration.

= 0.0.5 =
* Switched to Wicket's POST /people/query endpoint (per https://wicketapi.docs.apiary.io/) so we can filter on nested data_fields paths server-side. The new query selects on `data_fields.designations.value.cae = true` AND `data_fields.designations.value.end_date_gteq = today` — which is what "currently-active CAE" actually means. The old GET filter[tag_eq]=CAE never matched (tags is an array attribute, not a scalar) and `attributes.status === active` describes ASAE membership, not CAE certification.
* Added request_post() to the Wicket client. Shares JWT auth, request budget, courtesy delay, and exponential-backoff retry with the existing GET request() via a common dispatcher.
* Loosened normalize_person: hard rejects only on deleted_at / anonymized_at. The CAE-active validation that used to reject every record when the response trimmed data_fields is now satisfied upstream by the server-side filter.
* Dry Run output now shows a diagnostic line: how many records Wicket returned, how many passed structural validation, and how many API calls were made — so a future "0 records" issue is debuggable in one click instead of guesswork.

= 0.0.4 =
* Sync is now broken into many small WP-Cron-driven chunks instead of one long blocking call. Each chunk fetches a small number of Wicket pages (default 1 page = 25 records) and self-schedules the next chunk after a configurable delay (default 5s). Resumable across PHP-process boundaries; live data isn't promoted until every chunk is complete. Fixes the "Per-run request budget reached (500)" failure mode for rosters of ~5,000 CAEs.
* New "Dry Run (Preview First 50)" admin action — fetches the first 50 active CAEs alphabetically (sort=family_name) and renders them in a table on the Roster tab. No DB writes, no effect on the live or staging tables.
* Plugin version moved from the Roster tab's status table to a small badge next to the page title, visible on every tab (matches the convention used by other ASAE plugins).
* "Check for Updates Now" moved from the Roster tab to the Settings tab, again matching the convention used by other ASAE plugins.
* Added two new Settings fields under "Chunked sync": Pages per chunk (default 1) and Delay between chunks in seconds (default 5). The existing "Max requests per chunk" / "Delay between requests" labels were clarified to indicate they're now per-chunk caps.
* Stop All Active Jobs now also clears the in-progress chunk state and unschedules any pending single-event chunks — so a stopped run truly stops, even if a chunk was queued to fire seconds later.
* Stale-run recovery now uses chunk_state.updated_at instead of started_at, so a healthy chunked sync that runs longer than 30 minutes isn't mistakenly aborted.

= 0.0.3 =
* Photos are no longer sideloaded into the WordPress media library. Sync stores the remote Wicket photo URL only; the public shortcode renders each <img> with native lazy-loading and a data-fallback attribute. A small client-side handler swaps the src to the admin-configured default photo when a remote image returns 404 or otherwise fails to load. This eliminates ~5,000 image downloads per sync and ~5,000 attachment rows per snapshot.
* Added a live progress meter to the Roster tab, mirroring the Group Rosters plugin: progress bar, "X of Y — phase" headline, and a sub-status line for the current record. Powered by a polled AJAX endpoint that reads a snapshot wp_options row written by the sync at every phase boundary and every 10 records.
* Concurrency guard: Sync::run() refuses to start when another sync is already running, with auto-recovery for stale 'running' rows older than 30 minutes (e.g. PHP crashed mid-run).

= 0.0.2 =
* Added "Stop All Active Jobs" admin action on the Roster tab. Sets a cooperative kill flag the running sync polls per-record, then marks any in-progress log rows as aborted and discards the staging table. Live roster is never touched, so readers continue to see the last good snapshot.

= 0.0.1 =
* Initial release
* Wicket API client with HMAC JWT auth, request budget, and exponential backoff
* Stage-and-swap sync with automatic revert on failure
* Daily WP-Cron sync at configurable local time
* Tabbed admin UI (Roster status / Settings / Logs)
* Public `[asae_cae_roster]` shortcode with letter nav, search, pagination
* WCAG 2.2 AA accessibility audit pass
* Self-hosted GitHub Releases updater

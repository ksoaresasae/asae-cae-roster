=== ASAE CAE Roster ===
Contributors: keithmsoares
Tags: asae, cae, roster, wicket
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 0.0.6
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

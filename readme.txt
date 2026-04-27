=== ASAE CAE Roster ===
Contributors: keithmsoares
Tags: asae, cae, roster, wicket
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 0.0.2
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

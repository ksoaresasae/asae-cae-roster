<?php
/**
 * ASAE CAE Roster — Admin class.
 *
 * One submenu page with three tabs (Roster | Settings | Logs), per the
 * _start.md requirement that views live as tabs within a single admin nav
 * item rather than separate WP submenus.
 *
 * Submenu attaches to the shared "ASAE" top-level menu (parent slug `asae`)
 * created by ASAE Explore at admin_menu priority 10. We hook at 20 so the
 * parent is guaranteed to exist; if Explore isn't active, we register the
 * top-level menu ourselves.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Admin {

	/** Submenu slug; used in URLs and as the wp_localize_script handle. */
	const MENU_SLUG = 'asae-cae-roster';

	/** Capability required to use any of this plugin's admin UI. */
	const CAP = 'manage_options';

	/** Single nonce action used for every AJAX handler in this class. */
	const AJAX_NONCE = 'asae_cae_ajax';

	/** Valid tab keys. The default tab is the first entry. */
	const TABS = array( 'roster', 'settings', 'logs' );

	/**
	 * Wire admin hooks. Called from plugins_loaded when is_admin().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX handlers — admin only.
		add_action( 'wp_ajax_asae_cae_check_updates',  array( __CLASS__, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_asae_cae_save_settings',  array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_asae_cae_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_asae_cae_run_sync',       array( __CLASS__, 'ajax_run_sync' ) );
		add_action( 'wp_ajax_asae_cae_stop_jobs',      array( __CLASS__, 'ajax_stop_jobs' ) );
	}

	/**
	 * Register the single submenu page under the shared "ASAE" menu.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		// Fallback: if asae-explore isn't loaded, create the parent menu ourselves.
		global $admin_page_hooks;
		if ( empty( $admin_page_hooks['asae'] ) ) {
			add_menu_page(
				__( 'ASAE', 'asae-cae-roster' ),
				__( 'ASAE', 'asae-cae-roster' ),
				self::CAP,
				'asae',
				'__return_null',
				'dashicons-building',
				30
			);
		}

		add_submenu_page(
			'asae',
			__( 'CAE Roster', 'asae-cae-roster' ),
			__( 'CAE Roster', 'asae-cae-roster' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets only on this plugin's page.
	 *
	 * @param string $hook_suffix
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// 'asae_page_<slug>' is WP's hook-suffix for submenus under a custom
		// parent slug ('asae' in our case).
		if ( 'asae_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		// Media library JS — needed for the default-photo picker on Settings.
		wp_enqueue_media();

		wp_enqueue_style(
			'asae-cae-admin',
			ASAE_CAE_URL . 'assets/css/admin.css',
			array(),
			ASAE_CAE_VERSION
		);

		wp_enqueue_script(
			'asae-cae-admin',
			ASAE_CAE_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ASAE_CAE_VERSION,
			true
		);

		wp_localize_script(
			'asae-cae-admin',
			'asaeCaeAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'strings' => array(
					'saving'           => __( 'Saving…', 'asae-cae-roster' ),
					'saved'            => __( 'Settings saved.', 'asae-cae-roster' ),
					'saveError'        => __( 'Could not save settings.', 'asae-cae-roster' ),
					'testing'          => __( 'Testing connection…', 'asae-cae-roster' ),
					'testFailed'       => __( 'Connection test failed.', 'asae-cae-roster' ),
					'syncing'          => __( 'Sync running… this can take several minutes for thousands of records. You can leave this tab open.', 'asae-cae-roster' ),
					'syncError'        => __( 'Sync failed. See the Logs tab for details.', 'asae-cae-roster' ),
					'stopping'         => __( 'Stopping all active jobs…', 'asae-cae-roster' ),
					'stopError'        => __( 'Could not stop active jobs.', 'asae-cae-roster' ),
					'stopConfirm'      => __( 'Stop all active sync jobs? Any in-progress sync will abort cleanly and the live roster will remain unchanged.', 'asae-cae-roster' ),
					'checkingUpdates'  => __( 'Checking for updates…', 'asae-cae-roster' ),
					'updatesChecked'   => __( 'Update check complete. Refresh the Plugins page to see results.', 'asae-cae-roster' ),
					'updatesError'     => __( 'Update check failed. Please try again.', 'asae-cae-roster' ),
					'pickPhotoTitle'   => __( 'Select default photo', 'asae-cae-roster' ),
					'pickPhotoButton'  => __( 'Use this photo', 'asae-cae-roster' ),
				),
			)
		);
	}

	/**
	 * Render the active tab.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'asae-cae-roster' ) );
		}

		$active = self::current_tab();
		$view   = ASAE_CAE_PATH . 'admin/views/page-' . $active . '.php';
		if ( ! file_exists( $view ) ) {
			$view = ASAE_CAE_PATH . 'admin/views/page-roster.php';
		}

		// Make commonly-needed values available in the view scope.
		$current_tab = $active;
		$tabs        = self::tab_labels();
		$page_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		require $view;
	}

	/**
	 * Output the WP-standard `<h2 class="nav-tab-wrapper">` tab nav.
	 *
	 * @return void
	 */
	public static function render_tabs(): void {
		$active   = self::current_tab();
		$page_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$labels   = self::tab_labels();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Plugin sections', 'asae-cae-roster' ) . '">';
		foreach ( $labels as $slug => $label ) {
			$is_active = ( $slug === $active );
			$class     = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
			$href      = add_query_arg( 'tab', $slug, $page_url );
			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( $href ),
				esc_attr( $class ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Active tab from $_GET, defaulting to first valid entry.
	 *
	 * @return string
	 */
	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, no action.
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return in_array( $raw, self::TABS, true ) ? $raw : self::TABS[0];
	}

	/**
	 * Translatable labels for each tab.
	 *
	 * @return array<string,string>
	 */
	private static function tab_labels(): array {
		return array(
			'roster'   => __( 'Roster', 'asae-cae-roster' ),
			'settings' => __( 'Settings', 'asae-cae-roster' ),
			'logs'     => __( 'Logs', 'asae-cae-roster' ),
		);
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	/**
	 * Force a fresh GitHub-update check (clears the updater's transient).
	 *
	 * @return void
	 */
	public static function ajax_check_updates(): void {
		self::verify_ajax();
		delete_transient( 'asae_cae_github_release' );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_send_json_success(
			array(
				'message' => __( 'Update check complete. Refresh the Plugins page to see results.', 'asae-cae-roster' ),
			)
		);
	}

	/**
	 * Save the Settings form, then re-run scheduling so any change to the
	 * cron HH:MM takes effect immediately.
	 *
	 * @return void
	 */
	public static function ajax_save_settings(): void {
		self::verify_ajax();

		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			? wp_unslash( $_POST['settings'] )
			: array();

		$saved = ASAE_CAE_Settings::save( $input );

		// Cron time may have changed — reschedule from scratch.
		ASAE_CAE_Sync::reschedule();

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved.', 'asae-cae-roster' ),
				'next_run' => self::format_next_run(),
			)
		);
	}

	/**
	 * Test the configured Wicket credentials with a single throwaway request.
	 * Reads from $_POST so admins can test new values before saving.
	 *
	 * @return void
	 */
	public static function ajax_test_connection(): void {
		self::verify_ajax();

		$base_url  = isset( $_POST['base_url'] )  ? sanitize_text_field( wp_unslash( $_POST['base_url'] ) )  : '';
		$secret    = isset( $_POST['secret'] )    ? trim( wp_unslash( (string) $_POST['secret'] ) )           : '';
		$person_id = isset( $_POST['person_id'] ) ? sanitize_text_field( wp_unslash( $_POST['person_id'] ) ) : '';

		// Fall back to stored values for any field the form left blank — typical
		// when an admin tweaks one credential without touching the others.
		if ( '' === $base_url )  { $base_url  = ASAE_CAE_Settings::get_base_url(); }
		if ( '' === $secret )    { $secret    = ASAE_CAE_Settings::get_secret(); }
		if ( '' === $person_id ) { $person_id = ASAE_CAE_Settings::get_person_id(); }

		// Tiny budget for the test so we can't accidentally hammer Wicket.
		$client     = new ASAE_CAE_Wicket_Client( $base_url, $secret, $person_id, 3, 0 );
		list( $ok, $message ) = $client->test_connection();

		if ( $ok ) {
			wp_send_json_success( array( 'message' => $message ) );
		} else {
			wp_send_json_error( array( 'message' => $message ) );
		}
	}

	/**
	 * Run a manual sync. Returns the sync result so the UI can show a status
	 * line without forcing a full page reload.
	 *
	 * @return void
	 */
	public static function ajax_run_sync(): void {
		self::verify_ajax();

		// PHP can run for a while during a full sync (thousands of records).
		// Lift the time limit only for this request; the host's hard cap
		// (FastCGI / nginx) still applies.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$result = ASAE_CAE_Sync::run( ASAE_CAE_Logger::TRIGGER_MANUAL );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success(
				array(
					'message'  => $result['message'],
					'log_id'   => (int) $result['log_id'],
					'count'    => ASAE_CAE_DB::count_live(),
					'next_run' => self::format_next_run(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'log_id'  => (int) $result['log_id'],
				)
			);
		}
	}

	/**
	 * Stop every currently-running sync. Sets the cooperative kill flag,
	 * marks any 'running' log rows as 'aborted', and truncates staging.
	 *
	 * @return void
	 */
	public static function ajax_stop_jobs(): void {
		self::verify_ajax();
		$result = ASAE_CAE_Sync::stop_all_active();
		wp_send_json_success(
			array(
				'message' => $result['message'],
				'stopped' => (int) $result['stopped'],
			)
		);
	}

	/**
	 * Common nonce + capability gate for all AJAX handlers.
	 *
	 * @return void
	 */
	private static function verify_ajax(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'asae-cae-roster' ) ),
				403
			);
		}
	}

	/**
	 * Human-readable next-run time in the site's local timezone.
	 *
	 * @return string
	 */
	public static function format_next_run(): string {
		$ts = ASAE_CAE_Sync::next_run_timestamp();
		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$ts
		);
	}
}

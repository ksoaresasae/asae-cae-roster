<?php
/**
 * ASAE CAE Roster — Admin Class
 *
 * Registers admin menus, enqueues assets, and provides AJAX handlers.
 * Submenu attaches to the shared "ASAE" top-level menu created by ASAE Explore.
 * If Explore is not active, a fallback top-level menu is created so the plugin
 * remains accessible.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Admin {

	/** Nonce action used for plugin AJAX requests. */
	const AJAX_NONCE = 'asae_cae_ajax';

	/**
	 * Wire all admin hooks. Called from plugins_loaded when is_admin() is true.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX — admin only.
		add_action( 'wp_ajax_asae_cae_check_updates', [ __CLASS__, 'ajax_check_updates' ] );
	}

	/**
	 * Register the plugin's admin menu pages.
	 *
	 * Submenu attaches to the shared "ASAE" top-level menu (slug: asae) created
	 * by ASAE Explore at admin_menu priority 10. We hook at priority 20 so the
	 * parent is guaranteed to exist by the time we register. If Explore isn't
	 * active, fall back to creating our own top-level "ASAE" menu.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		global $admin_page_hooks;
		if ( empty( $admin_page_hooks['asae'] ) ) {
			add_menu_page(
				__( 'ASAE', 'asae-cae-roster' ),
				__( 'ASAE', 'asae-cae-roster' ),
				'manage_options',
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
			'manage_options',
			'asae-cae-roster',
			[ __CLASS__, 'render_main_page' ]
		);

		add_submenu_page(
			'asae',
			__( 'CAE Roster — Settings', 'asae-cae-roster' ),
			__( 'CAE Roster Settings', 'asae-cae-roster' ),
			'manage_options',
			'asae-cae-roster-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue admin styles/scripts on this plugin's pages only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$plugin_pages = [
			'asae_page_asae-cae-roster',
			'asae_page_asae-cae-roster-settings',
		];

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'asae-cae-admin',
			ASAE_CAE_URL . 'assets/css/admin.css',
			[],
			ASAE_CAE_VERSION
		);

		wp_enqueue_script(
			'asae-cae-admin',
			ASAE_CAE_URL . 'assets/js/admin.js',
			[],
			ASAE_CAE_VERSION,
			true
		);

		wp_localize_script( 'asae-cae-admin', 'asaeCae', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
			'strings' => [
				'checking' => __( 'Checking for updates…', 'asae-cae-roster' ),
				'checked'  => __( 'Update check complete. Refresh the Plugins page to see results.', 'asae-cae-roster' ),
				'error'    => __( 'Update check failed. Please try again.', 'asae-cae-roster' ),
			],
		] );
	}

	/**
	 * Render the main plugin page.
	 *
	 * @return void
	 */
	public static function render_main_page(): void {
		require ASAE_CAE_PATH . 'admin/views/page-main.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		require ASAE_CAE_PATH . 'admin/views/page-settings.php';
	}

	/**
	 * AJAX: Clear cached GitHub release data and trigger a fresh update check.
	 *
	 * @return void
	 */
	public static function ajax_check_updates(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Insufficient permissions.', 'asae-cae-roster' ) ],
				403
			);
		}

		// Clear cached GitHub release + WP's plugin update transient.
		delete_transient( 'asae_cae_github_release' );
		delete_site_transient( 'update_plugins' );

		// Trigger a fresh update check.
		wp_update_plugins();

		wp_send_json_success( [
			'message' => __( 'Update check complete. Refresh the Plugins page to see results.', 'asae-cae-roster' ),
		] );
	}
}

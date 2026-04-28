<?php
/**
 * ASAE CAE Roster — Settings storage.
 *
 * Thin wrapper around get_option/update_option for plugin configuration.
 * Centralizes option keys, defaults, and sanitization so the rest of the
 * plugin can read settings without hard-coding option names.
 *
 * Per the v0.0.1 plan: this plugin keeps its own copy of Wicket credentials
 * even though asae-group-rosters has the same values. No cross-plugin coupling.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Settings {

	/** Single option key holding an associative array of all settings. */
	const OPTION_KEY = 'asae_cae_settings';

	/**
	 * Default values for every setting. Any new field MUST be added here.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Wicket API.
			'wicket_base_url'             => '',
			'wicket_secret'               => '',
			'wicket_person_id'            => '',

			// Rate / retry behaviour (per _start.md: low priority, never block other systems).
			'request_budget'              => 500,   // Per-CHUNK ceiling, not per full sync. With pages_per_chunk=1 this is effectively unlimited; only matters as a safety cap if a single chunk goes wild.
			'request_delay_ms'            => 250,   // Courtesy delay between requests.

			// Chunked sync: a full sync is now made of many small WP-Cron
			// ticks instead of one big run. This keeps Wicket pressure low
			// and works around per-process timeouts.
			'pages_per_chunk'             => 1,     // How many Wicket pages each chunk fetches. 1 = 25 records.
			'chunk_delay_seconds'         => 5,     // Wait between chunks before the next single-event fires.

			// Scheduled sync (defaults to 2:00 local time per _start.md).
			'schedule_hour'               => 2,
			'schedule_minute'             => 0,

			// Public roster display.
			'items_per_page'              => 20,
			'default_photo_attachment_id' => 0,
		);
	}

	/**
	 * Read all settings, merged over defaults so missing keys are filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read a single setting by key.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist a sanitized settings array. Unknown keys are dropped, known keys
	 * are coerced to their expected types/ranges. Returns the saved array.
	 *
	 * @param array $input Untrusted input (e.g. from a settings form).
	 * @return array Sanitized settings actually written.
	 */
	public static function save( array $input ) {
		$current   = self::all();
		$sanitized = $current;

		if ( array_key_exists( 'wicket_base_url', $input ) ) {
			$sanitized['wicket_base_url'] = self::sanitize_base_url( $input['wicket_base_url'] );
		}
		if ( array_key_exists( 'wicket_secret', $input ) ) {
			// Secret is stored verbatim (after trim). It's already in wp_options,
			// which is no less secure than any other plugin's API key storage.
			$sanitized['wicket_secret'] = is_string( $input['wicket_secret'] ) ? trim( $input['wicket_secret'] ) : '';
		}
		if ( array_key_exists( 'wicket_person_id', $input ) ) {
			$sanitized['wicket_person_id'] = self::sanitize_uuid( $input['wicket_person_id'] );
		}

		if ( array_key_exists( 'request_budget', $input ) ) {
			$sanitized['request_budget'] = self::clamp_int( $input['request_budget'], 1, 10000, 500 );
		}
		if ( array_key_exists( 'request_delay_ms', $input ) ) {
			$sanitized['request_delay_ms'] = self::clamp_int( $input['request_delay_ms'], 0, 10000, 250 );
		}

		if ( array_key_exists( 'pages_per_chunk', $input ) ) {
			$sanitized['pages_per_chunk'] = self::clamp_int( $input['pages_per_chunk'], 1, 50, 1 );
		}
		if ( array_key_exists( 'chunk_delay_seconds', $input ) ) {
			$sanitized['chunk_delay_seconds'] = self::clamp_int( $input['chunk_delay_seconds'], 1, 600, 5 );
		}

		if ( array_key_exists( 'schedule_hour', $input ) ) {
			$sanitized['schedule_hour'] = self::clamp_int( $input['schedule_hour'], 0, 23, 2 );
		}
		if ( array_key_exists( 'schedule_minute', $input ) ) {
			$sanitized['schedule_minute'] = self::clamp_int( $input['schedule_minute'], 0, 59, 0 );
		}

		if ( array_key_exists( 'items_per_page', $input ) ) {
			$sanitized['items_per_page'] = self::clamp_int( $input['items_per_page'], 5, 100, 20 );
		}
		if ( array_key_exists( 'default_photo_attachment_id', $input ) ) {
			$sanitized['default_photo_attachment_id'] = max( 0, (int) $input['default_photo_attachment_id'] );
		}

		update_option( self::OPTION_KEY, $sanitized, false );
		return $sanitized;
	}

	// ── Convenience accessors used elsewhere in the plugin ───────────────────

	public static function get_base_url() { return (string) self::get( 'wicket_base_url' ); }
	public static function get_secret()   { return (string) self::get( 'wicket_secret' ); }
	public static function get_person_id() { return (string) self::get( 'wicket_person_id' ); }
	public static function get_request_budget()    { return (int) self::get( 'request_budget' ); }
	public static function get_request_delay_ms()  { return (int) self::get( 'request_delay_ms' ); }
	public static function get_pages_per_chunk()   { return (int) self::get( 'pages_per_chunk' ); }
	public static function get_chunk_delay_seconds() { return (int) self::get( 'chunk_delay_seconds' ); }
	public static function get_schedule_hour()     { return (int) self::get( 'schedule_hour' ); }
	public static function get_schedule_minute()  { return (int) self::get( 'schedule_minute' ); }
	public static function get_items_per_page()   { return (int) self::get( 'items_per_page' ); }
	public static function get_default_photo_id() { return (int) self::get( 'default_photo_attachment_id' ); }

	/**
	 * URL of the configured default photo, or '' if none set / attachment missing.
	 *
	 * @return string
	 */
	public static function get_default_photo_url() {
		$id = self::get_default_photo_id();
		if ( $id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		return $url ? (string) $url : '';
	}

	/**
	 * Returns true when the three Wicket fields are all populated. The client
	 * applies the same check at request time, but the admin UI uses this to
	 * decide whether to enable the "Test Connection" / "Sync Now" buttons.
	 *
	 * @return bool
	 */
	public static function is_wicket_configured() {
		return '' !== self::get_base_url()
			&& '' !== self::get_secret()
			&& '' !== self::get_person_id();
	}

	// ── Sanitizers ───────────────────────────────────────────────────────────

	private static function sanitize_base_url( $raw ) {
		$url = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $url ) {
			return '';
		}
		// esc_url_raw rejects anything that isn't http(s) and strips dangerous chars.
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		return rtrim( (string) $clean, '/' );
	}

	private static function sanitize_uuid( $raw ) {
		$id = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $id ) {
			return '';
		}
		// Standard UUID format: 8-4-4-4-12 hex. Anything else gets rejected.
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			return '';
		}
		return strtolower( $id );
	}

	private static function clamp_int( $raw, $min, $max, $default ) {
		if ( '' === $raw || null === $raw ) {
			return $default;
		}
		$v = (int) $raw;
		if ( $v < $min ) {
			return $min;
		}
		if ( $v > $max ) {
			return $max;
		}
		return $v;
	}
}

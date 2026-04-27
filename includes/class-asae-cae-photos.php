<?php
/**
 * ASAE CAE Roster — Photo handling.
 *
 * Maps a remote photo URL (from Wicket) to a local WP media-library
 * attachment, deduping by URL across sync runs so we don't re-download
 * unchanged photos. Falls back to the admin-configured default photo when
 * the remote image is missing, returns 404, or hasn't been downloaded yet.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Photos {

	/** Attachment meta key storing the original Wicket URL the file came from. */
	const SOURCE_URL_META = '_asae_cae_source_url';

	/**
	 * Get an attachment ID for the given remote URL. Reuses an existing
	 * attachment when one already exists with the same source URL. Downloads
	 * fresh otherwise. Returns 0 on any failure (caller falls back to default).
	 *
	 * Safe to call from cron — pulls in the wp-admin includes lazily.
	 *
	 * @param string $remote_url
	 * @return int Attachment ID, or 0.
	 */
	public static function ensure_attachment_for_url( $remote_url ) {
		$remote_url = is_string( $remote_url ) ? trim( $remote_url ) : '';
		if ( '' === $remote_url ) {
			return 0;
		}
		if ( ! preg_match( '#^https?://#i', $remote_url ) ) {
			return 0;
		}

		$existing = self::find_existing_by_source_url( $remote_url );
		if ( $existing > 0 ) {
			return $existing;
		}

		// media_handle_sideload + download_url live in wp-admin includes; load
		// them on demand so cron and AJAX contexts can call this.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $remote_url, 30 );
		if ( is_wp_error( $tmp ) ) {
			ASAE_CAE_Logger::debug( 'Photo download failed (' . $remote_url . '): ' . $tmp->get_error_message() );
			return 0;
		}

		$basename = self::derive_basename( $remote_url );
		$file     = array(
			'name'     => $basename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			// download_url leaves the temp file in place on failure; clean up.
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			ASAE_CAE_Logger::debug( 'Photo sideload failed (' . $remote_url . '): ' . $attachment_id->get_error_message() );
			return 0;
		}

		update_post_meta( $attachment_id, self::SOURCE_URL_META, $remote_url );

		return (int) $attachment_id;
	}

	/**
	 * Resolve an attachment ID to a usable URL, falling back to the configured
	 * default photo if the attachment is gone or wasn't set. Empty string when
	 * neither resolves (caller can render an `<img alt>`-only placeholder).
	 *
	 * @param int    $attachment_id
	 * @param string $size  Standard WP image size.
	 * @return string
	 */
	public static function resolve_url( $attachment_id, $size = 'medium' ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_image_url( $attachment_id, $size );
			if ( $url ) {
				return (string) $url;
			}
		}
		return self::default_url( $size );
	}

	/**
	 * URL of the default photo configured by the admin, or '' if unset.
	 *
	 * @param string $size
	 * @return string
	 */
	public static function default_url( $size = 'medium' ) {
		$id = ASAE_CAE_Settings::get_default_photo_id();
		if ( $id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, $size );
		return $url ? (string) $url : '';
	}

	// ── internals ────────────────────────────────────────────────────────────

	/**
	 * Find an attachment previously sideloaded from this exact URL. Direct SQL
	 * because get_posts/meta_query is slow when meta tables get large.
	 *
	 * @param string $url
	 * @return int Attachment ID or 0.
	 */
	private static function find_existing_by_source_url( $url ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::SOURCE_URL_META,
				$url
			)
		);
		return (int) $found;
	}

	/**
	 * Pick a sane filename for the sideloaded media item. Uses the URL's
	 * basename when it has a recognisable image extension; otherwise generates
	 * one from a hash of the URL with a .jpg fallback.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function derive_basename( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = $path !== '' ? basename( $path ) : '';

		if ( $base !== '' && preg_match( '/\.(jpe?g|png|gif|webp)$/i', $base ) ) {
			return sanitize_file_name( $base );
		}

		// Fall back to a hash so we don't collide on generic names like "profile.png".
		$ext = preg_match( '/\.(jpe?g|png|gif|webp)$/i', $base, $m ) ? strtolower( $m[1] ) : 'jpg';
		return 'asae-cae-' . substr( md5( $url ), 0, 12 ) . '.' . $ext;
	}
}

<?php
/**
 * ASAE CAE Roster — Photo helpers.
 *
 * Photos are NOT downloaded. With 4–5k records and Wicket-hosted CDN URLs,
 * sideloading every image was both expensive (one HTTP per record per sync)
 * and unnecessary — the source URLs are publicly addressable.
 *
 * Strategy:
 *   - Sync stores the remote photo URL in the people table only.
 *   - The shortcode renders <img src="$photo_url" data-fallback="$default">
 *     with native loading="lazy", so the browser only requests images that
 *     actually scroll into view.
 *   - When a remote URL 404s (or otherwise errors), a tiny client-side
 *     handler swaps the src to the admin-configured default photo. If that
 *     also fails, an empty-state placeholder takes over via CSS.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Photos {

	/**
	 * URL of the admin-configured default photo, or '' if unset / attachment
	 * missing. Returned at the size most useful for card display.
	 *
	 * @param string $size Standard WP image size.
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
}

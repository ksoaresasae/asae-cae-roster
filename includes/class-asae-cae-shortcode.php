<?php
/**
 * ASAE CAE Roster — public shortcode [asae_cae_roster].
 *
 * Renders the cached roster as:
 *   - a search box (filters first/last name),
 *   - an A-Z letter nav (only letters with results are linked),
 *   - a paginated list of CAE cards.
 *
 * State lives in URL query params (cae_letter, cae_page, cae_search) so
 * everything is bookmarkable, shareable, and works without JavaScript.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Shortcode {

	/** Shortcode tag — `[asae_cae_roster]`. */
	const TAG = 'asae_cae_roster';

	/** Query params that carry roster state through page reloads. */
	const QP_LETTER = 'cae_letter';
	const QP_PAGE   = 'cae_page';
	const QP_SEARCH = 'cae_search';

	/**
	 * Wire shortcode + asset enqueue.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue front-end CSS/JS only on pages that actually contain the
	 * shortcode, so the roster doesn't add weight to every page on the site.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, self::TAG ) ) {
				wp_enqueue_style(
					'asae-cae-roster',
					ASAE_CAE_URL . 'assets/css/roster.css',
					array(),
					ASAE_CAE_VERSION
				);
				wp_enqueue_script(
					'asae-cae-roster',
					ASAE_CAE_URL . 'assets/js/roster.js',
					array(),
					ASAE_CAE_VERSION,
					true
				);
			}
		}
	}

	/**
	 * Render the roster. Returns the HTML string per WordPress shortcode
	 * convention.
	 *
	 * @param array|string $atts Shortcode attributes (none recognized in v0.0.1).
	 * @return string
	 */
	public static function render( $atts = array() ) {
		// Read state from URL query string (sanitized).
		$state = self::read_state();

		// Active letters drive the letter nav (disabled-styling for empties).
		// In search mode the letter filter is ignored; otherwise an empty
		// letter is the "All" view (entire roster, paginated). Invalid
		// letters fall back to "All" rather than silently snapping to "A",
		// which had given undue weight to people whose surnames started
		// with that letter.
		$active_letters = self::get_active_letters();
		if ( '' !== $state['search'] ) {
			$state['letter'] = '';
		} elseif ( '' !== $state['letter'] && ! in_array( $state['letter'], $active_letters, true ) ) {
			$state['letter'] = '';
		}

		$per_page = max( 5, min( 100, ASAE_CAE_Settings::get_items_per_page() ) );
		$offset   = ( $state['page'] - 1 ) * $per_page;

		$total       = self::count_records( $state['letter'], $state['search'] );
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		// Clamp page if the user supplied something out of range.
		if ( $state['page'] > $total_pages ) {
			$state['page'] = $total_pages;
			$offset        = ( $state['page'] - 1 ) * $per_page;
		}

		$records = $total > 0
			? self::fetch_records( $state['letter'], $state['search'], $offset, $per_page )
			: array();

		// Build the HTML in an output buffer so view-level helpers can echo.
		ob_start();
		?>
		<div class="asae-cae-roster" role="region" aria-label="<?php echo esc_attr__( 'CAE Roster', 'asae-cae-roster' ); ?>">

			<form class="asae-cae-search" role="search"
				method="get" action="<?php echo esc_url( self::current_path() ); ?>">
				<?php self::print_preserved_get_inputs( array( self::QP_SEARCH, self::QP_PAGE, self::QP_LETTER ) ); ?>
				<label for="asae-cae-q"><?php echo esc_html__( 'Search by name', 'asae-cae-roster' ); ?></label>
				<input type="search" id="asae-cae-q" name="<?php echo esc_attr( self::QP_SEARCH ); ?>"
					value="<?php echo esc_attr( $state['search'] ); ?>"
					autocomplete="off"
					placeholder="<?php echo esc_attr__( 'First or last name', 'asae-cae-roster' ); ?>" />
				<button type="submit" class="asae-cae-search-submit">
					<?php echo esc_html__( 'Search', 'asae-cae-roster' ); ?>
				</button>
				<?php if ( '' !== $state['search'] ) : ?>
					<a class="asae-cae-search-clear"
						href="<?php echo esc_url( self::build_url( array( self::QP_SEARCH => null, self::QP_PAGE => null ) ) ); ?>"
						aria-label="<?php echo esc_attr__( 'Clear search and show entire roster', 'asae-cae-roster' ); ?>">
						<?php echo esc_html__( 'Clear', 'asae-cae-roster' ); ?>
					</a>
				<?php endif; ?>
			</form>

			<?php if ( '' === $state['search'] ) : ?>
				<?php self::render_letter_nav( $active_letters, $state['letter'] ); ?>
			<?php endif; ?>

			<?php if ( '' !== $state['search'] ) : ?>
				<p class="asae-cae-result-summary" role="status">
					<?php
					printf(
						/* translators: 1: total number of results, 2: search term */
						esc_html( _n( '%1$d match for "%2$s"', '%1$d matches for "%2$s"', $total, 'asae-cae-roster' ) ),
						(int) $total,
						esc_html( $state['search'] )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $records ) ) : ?>
				<p class="asae-cae-empty">
					<?php
					if ( '' !== $state['search'] ) {
						echo esc_html__( 'No CAEs match your search.', 'asae-cae-roster' );
					} else {
						echo esc_html__( 'No CAEs to display.', 'asae-cae-roster' );
					}
					?>
				</p>
			<?php else : ?>
				<ul class="asae-cae-list">
					<?php foreach ( $records as $rec ) : ?>
						<?php self::render_card( $rec ); ?>
					<?php endforeach; ?>
				</ul>

				<?php self::render_pagination( $state['page'], $total_pages ); ?>
			<?php endif; ?>

		</div>
		<?php

		return (string) ob_get_clean();
	}

	// ── State + URL helpers ──────────────────────────────────────────────────

	/**
	 * Pull and sanitize roster state from the request query string.
	 *
	 * @return array{ letter:string, page:int, search:string }
	 */
	private static function read_state(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only filter UI.
		$letter_raw = isset( $_GET[ self::QP_LETTER ] ) ? wp_unslash( (string) $_GET[ self::QP_LETTER ] ) : '';
		$page_raw   = isset( $_GET[ self::QP_PAGE ] )   ? wp_unslash( (string) $_GET[ self::QP_PAGE ] )   : '';
		$search_raw = isset( $_GET[ self::QP_SEARCH ] ) ? wp_unslash( (string) $_GET[ self::QP_SEARCH ] ) : '';
		// phpcs:enable

		$letter = strtoupper( substr( $letter_raw, 0, 1 ) );
		if ( 1 !== preg_match( '/^[A-Z#]$/', $letter ) ) {
			$letter = '';
		}

		$page = (int) $page_raw;
		if ( $page < 1 ) {
			$page = 1;
		}

		$search = trim( sanitize_text_field( $search_raw ) );
		// Cap the search term length defensively.
		if ( mb_strlen( $search ) > 80 ) {
			$search = mb_substr( $search, 0, 80 );
		}

		return array(
			'letter' => $letter,
			'page'   => $page,
			'search' => $search,
		);
	}

	/**
	 * Build a URL on the current page with the given query args set/removed.
	 * Pass `null` for any arg you want to drop.
	 *
	 * @param array<string,mixed> $args
	 * @return string
	 */
	private static function build_url( array $args ): string {
		$base = self::current_path();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = $_GET;
		foreach ( $args as $k => $v ) {
			if ( null === $v || '' === $v ) {
				unset( $current[ $k ] );
			} else {
				$current[ $k ] = $v;
			}
		}
		return $current ? add_query_arg( $current, $base ) : $base;
	}

	/**
	 * Path-only URL of the current request, suitable as a form action.
	 *
	 * @return string
	 */
	private static function current_path(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// Strip query string; we rebuild it explicitly.
		$qpos = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$path = substr( $path, 0, $qpos );
		}
		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * Echo hidden inputs for any GET args we want preserved across a form
	 * submit (e.g. don't lose `cae_letter` when submitting the search form).
	 * Skips the keys passed in $exclude.
	 *
	 * @param string[] $exclude
	 * @return void
	 */
	private static function print_preserved_get_inputs( array $exclude ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $_GET as $k => $v ) {
			if ( in_array( $k, $exclude, true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				continue; // not supported here
			}
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( (string) $k ),
				esc_attr( wp_unslash( (string) $v ) )
			);
		}
	}

	// ── DB queries ───────────────────────────────────────────────────────────

	/**
	 * Letters that have at least one record in the live table.
	 *
	 * @return string[]
	 */
	private static function get_active_letters(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			'SELECT DISTINCT family_name_initial FROM ' . ASAE_CAE_DB::people_table() .
			" WHERE family_name_initial != '' ORDER BY family_name_initial"
		);
		return $rows ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Total record count for the active letter / search filters.
	 *
	 * @param string $letter
	 * @param string $search
	 * @return int
	 */
	private static function count_records( string $letter, string $search ): int {
		global $wpdb;
		list( $where_sql, $where_args ) = self::build_where( $letter, $search );

		$sql = 'SELECT COUNT(*) FROM ' . ASAE_CAE_DB::people_table() . $where_sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $where_args ? $wpdb->prepare( $sql, $where_args ) : $sql );
	}

	/**
	 * Fetch records for the current page, ordered for natural alphabetical
	 * browsing (family then given name).
	 *
	 * @param string $letter
	 * @param string $search
	 * @param int    $offset
	 * @param int    $limit
	 * @return array<int,object>
	 */
	private static function fetch_records( string $letter, string $search, int $offset, int $limit ): array {
		global $wpdb;
		list( $where_sql, $where_args ) = self::build_where( $letter, $search );

		$sql  = 'SELECT * FROM ' . ASAE_CAE_DB::people_table() . $where_sql .
				' ORDER BY family_name ASC, given_name ASC, id ASC LIMIT %d OFFSET %d';
		$args = array_merge( $where_args, array( max( 1, $limit ), max( 0, $offset ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		return $rows ? $rows : array();
	}

	/**
	 * Build the parameterized WHERE clause and the corresponding $wpdb->prepare
	 * args list for letter/search filters.
	 *
	 * @param string $letter
	 * @param string $search
	 * @return array{0:string,1:array}
	 */
	private static function build_where( string $letter, string $search ): array {
		global $wpdb;
		$conds = array();
		$args  = array();

		if ( '' !== $letter ) {
			$conds[] = 'family_name_initial = %s';
			$args[]  = $letter;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$conds[] = '(family_name LIKE %s OR given_name LIKE %s OR full_name LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_sql = $conds ? ' WHERE ' . implode( ' AND ', $conds ) : '';
		return array( $where_sql, $args );
	}

	// ── Sub-renderers ────────────────────────────────────────────────────────

	/**
	 * Render the A-Z letter navigation, prefixed with an "All" item that
	 * shows the entire roster (paginated). "All" is the default when no
	 * letter is in the URL — see render() — so on first visit no single
	 * letter group gets undue weight from being the landing view. Letters
	 * with no records are rendered as spans with aria-disabled, not links,
	 * so they're announced as unavailable but still maintain layout.
	 *
	 * @param string[] $active_letters
	 * @param string   $current Empty string = "All" is current.
	 * @return void
	 */
	private static function render_letter_nav( array $active_letters, string $current ): void {
		$letters = range( 'A', 'Z' );
		$active  = array_flip( $active_letters );
		$is_all  = ( '' === $current );

		echo '<nav class="asae-cae-letters" aria-label="' . esc_attr__( 'Filter by last name', 'asae-cae-roster' ) . '"><ul>';

		// "All" — clearing the letter filter drops cae_letter from the URL.
		if ( $is_all ) {
			printf(
				'<li class="is-current"><span aria-current="page">%s</span></li>',
				esc_html__( 'All', 'asae-cae-roster' )
			);
		} else {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( self::build_url( array( self::QP_LETTER => null, self::QP_PAGE => null ) ) ),
				esc_html__( 'All', 'asae-cae-roster' )
			);
		}

		foreach ( $letters as $letter ) {
			$has        = isset( $active[ $letter ] );
			$is_current = ( $letter === $current );
			if ( $is_current ) {
				printf(
					'<li class="is-current"><span aria-current="page">%s</span></li>',
					esc_html( $letter )
				);
			} elseif ( $has ) {
				printf(
					'<li><a href="%1$s">%2$s</a></li>',
					esc_url( self::build_url( array( self::QP_LETTER => $letter, self::QP_PAGE => null ) ) ),
					esc_html( $letter )
				);
			} else {
				printf(
					'<li class="is-disabled"><span aria-disabled="true">%s</span></li>',
					esc_html( $letter )
				);
			}
		}
		echo '</ul></nav>';
	}

	/**
	 * Render a single CAE card. Photo alt is intentionally empty because the
	 * person's name is rendered as text immediately adjacent (per WCAG
	 * decorative-image guidance — repeating the name in alt would be
	 * redundant for screen readers).
	 *
	 * Photos are NOT downloaded at sync time. The src points at the remote
	 * Wicket URL with native loading="lazy", and a data-fallback attribute
	 * carries the admin-configured default URL. roster.js attaches an `error`
	 * handler that swaps src to data-fallback when the remote image 404s or
	 * fails to load — so a missing photo silently becomes the default rather
	 * than a broken-image icon.
	 *
	 * @param object $rec
	 * @return void
	 */
	private static function render_card( $rec ): void {
		$photo_url   = trim( (string) $rec->photo_url );
		$default_url = ASAE_CAE_Photos::default_url( 'medium' );

		// Pick the actual src. If the record has a photo URL, use it (the JS
		// will swap to default on error). Otherwise render the default
		// directly. If neither exists we render the empty-state placeholder.
		if ( '' !== $photo_url ) {
			$src = $photo_url;
		} elseif ( '' !== $default_url ) {
			$src = $default_url;
		} else {
			$src = '';
		}
		$is_empty = ( '' === $src );

		$display_suffix = trim( (string) $rec->honorific_suffix );
		$display_name   = trim( (string) $rec->full_name );
		if ( '' === $display_name ) {
			$display_name = trim( $rec->given_name . ' ' . $rec->family_name );
		}
		?>
		<li class="asae-cae-card">
			<div class="asae-cae-card-photo<?php echo $is_empty ? ' is-empty' : ''; ?>">
				<?php if ( ! $is_empty ) : ?>
					<img src="<?php echo esc_url( $src ); ?>"
						<?php if ( '' !== $photo_url && '' !== $default_url && $photo_url !== $default_url ) : ?>
							data-fallback="<?php echo esc_url( $default_url ); ?>"
						<?php endif; ?>
						alt="" loading="lazy" decoding="async" />
				<?php endif; ?>
			</div>
			<div class="asae-cae-card-body">
				<p class="asae-cae-card-name">
					<?php
					// Output strong + suffix without whitespace between them — HTML
					// whitespace between adjacent inline elements collapses to a
					// single space, which had been rendering "John Smith , CAE"
					// (extra space before the comma) instead of "John Smith, CAE".
					$name_html = '<strong>' . esc_html( $display_name ) . '</strong>';
					if ( '' !== $display_suffix ) {
						$name_html .= '<span class="asae-cae-card-suffix">, ' . esc_html( $display_suffix ) . '</span>';
					}
					echo $name_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both pieces escaped above.
					?>
				</p>
				<?php if ( '' !== $rec->job_title ) : ?>
					<p class="asae-cae-card-title"><?php echo esc_html( $rec->job_title ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $rec->organization_name ) : ?>
					<p class="asae-cae-card-org"><?php echo esc_html( $rec->organization_name ); ?></p>
				<?php endif; ?>
				<?php
				$loc_parts = array_values( array_filter( array( $rec->city, $rec->state ) ) );
				if ( ! empty( $loc_parts ) ) :
					?>
					<p class="asae-cae-card-loc"><?php echo esc_html( implode( ', ', $loc_parts ) ); ?></p>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * Render Prev / page-numbers / Next pagination. Page numbers within ±2 of
	 * the current page are shown directly; gaps are collapsed with an ellipsis.
	 *
	 * @param int $current
	 * @param int $total
	 * @return void
	 */
	private static function render_pagination( int $current, int $total ): void {
		if ( $total <= 1 ) {
			return;
		}

		echo '<nav class="asae-cae-pagination" aria-label="' . esc_attr__( 'Pagination', 'asae-cae-roster' ) . '"><ul>';

		// Prev.
		if ( $current > 1 ) {
			printf(
				'<li><a rel="prev" href="%1$s">%2$s</a></li>',
				esc_url( self::build_url( array( self::QP_PAGE => $current - 1 ) ) ),
				esc_html__( '« Previous', 'asae-cae-roster' )
			);
		} else {
			printf(
				'<li class="is-disabled"><span aria-disabled="true">%s</span></li>',
				esc_html__( '« Previous', 'asae-cae-roster' )
			);
		}

		// Page numbers with ellipsis collapse.
		$range_min = max( 1, $current - 2 );
		$range_max = min( $total, $current + 2 );

		if ( $range_min > 1 ) {
			self::pagination_link( 1, $current );
			if ( $range_min > 2 ) {
				echo '<li class="is-ellipsis"><span aria-hidden="true">…</span></li>';
			}
		}
		for ( $p = $range_min; $p <= $range_max; $p++ ) {
			self::pagination_link( $p, $current );
		}
		if ( $range_max < $total ) {
			if ( $range_max < $total - 1 ) {
				echo '<li class="is-ellipsis"><span aria-hidden="true">…</span></li>';
			}
			self::pagination_link( $total, $current );
		}

		// Next.
		if ( $current < $total ) {
			printf(
				'<li><a rel="next" href="%1$s">%2$s</a></li>',
				esc_url( self::build_url( array( self::QP_PAGE => $current + 1 ) ) ),
				esc_html__( 'Next »', 'asae-cae-roster' )
			);
		} else {
			printf(
				'<li class="is-disabled"><span aria-disabled="true">%s</span></li>',
				esc_html__( 'Next »', 'asae-cae-roster' )
			);
		}

		echo '</ul></nav>';
	}

	/**
	 * Pagination helper: emits one numeric page item. Adds aria-current on the
	 * active page so screen readers announce the user's position.
	 *
	 * @param int $page
	 * @param int $current
	 * @return void
	 */
	private static function pagination_link( int $page, int $current ): void {
		if ( $page === $current ) {
			printf(
				'<li class="is-current"><span aria-current="page">%s</span></li>',
				esc_html( number_format_i18n( $page ) )
			);
			return;
		}
		printf(
			'<li><a href="%1$s" aria-label="%2$s">%3$s</a></li>',
			esc_url( self::build_url( array( self::QP_PAGE => $page ) ) ),
			esc_attr( sprintf( /* translators: %d: page number */ __( 'Go to page %d', 'asae-cae-roster' ), $page ) ),
			esc_html( number_format_i18n( $page ) )
		);
	}
}

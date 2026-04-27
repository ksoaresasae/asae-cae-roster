<?php
/**
 * Roster tab — status of the cached CAE roster + manual actions.
 *
 * Variables in scope (from ASAE_CAE_Admin::render_page):
 *   string $current_tab
 *   array  $tabs
 *   string $page_url
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$count        = ASAE_CAE_DB::count_live();
$latest       = ASAE_CAE_DB::latest_sync();
$next_run     = ASAE_CAE_Admin::format_next_run();
$is_configured = ASAE_CAE_Settings::is_wicket_configured();

$status_label = '';
$status_class = '';
if ( $latest ) {
	switch ( $latest->status ) {
		case 'success':
			$status_label = __( 'Success', 'asae-cae-roster' );
			$status_class = 'asae-cae-status-success';
			break;
		case 'failed':
			$status_label = __( 'Failed', 'asae-cae-roster' );
			$status_class = 'asae-cae-status-failed';
			break;
		case 'aborted':
			$status_label = __( 'Aborted', 'asae-cae-roster' );
			$status_class = 'asae-cae-status-failed';
			break;
		case 'running':
			$status_label = __( 'Running', 'asae-cae-roster' );
			$status_class = 'asae-cae-status-running';
			break;
	}
}
?>
<div class="wrap asae-cae-wrap">
	<h1><?php echo esc_html__( 'ASAE CAE Roster', 'asae-cae-roster' ); ?></h1>
	<?php ASAE_CAE_Admin::render_tabs(); ?>

	<div class="asae-cae-tab-content" role="region" aria-labelledby="asae-cae-roster-heading">
		<h2 id="asae-cae-roster-heading"><?php echo esc_html__( 'Roster status', 'asae-cae-roster' ); ?></h2>

		<?php if ( ! $is_configured ) : ?>
			<div class="notice notice-warning inline" role="alert">
				<p>
					<?php
					printf(
						/* translators: %s: link to the Settings tab */
						esc_html__( 'Wicket is not yet configured. Open the %s tab to enter your base URL, secret, and person ID.', 'asae-cae-roster' ),
						'<a href="' . esc_url( add_query_arg( 'tab', 'settings', $page_url ) ) . '">' . esc_html__( 'Settings', 'asae-cae-roster' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<table class="widefat striped asae-cae-status-table">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Records currently published', 'asae-cae-roster' ); ?></th>
					<td><strong id="asae-cae-record-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last sync', 'asae-cae-roster' ); ?></th>
					<td>
						<?php if ( $latest ) : ?>
							<span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
							<?php if ( ! empty( $latest->started_at ) ) : ?>
								&middot;
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( $latest->started_at . ' UTC' )
									)
								);
								?>
							<?php endif; ?>
							<?php if ( ! empty( $latest->error_message ) ) : ?>
								<div class="asae-cae-error-detail"><?php echo esc_html( $latest->error_message ); ?></div>
							<?php endif; ?>
						<?php else : ?>
							<em><?php echo esc_html__( 'No sync has run yet.', 'asae-cae-roster' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Next scheduled sync', 'asae-cae-roster' ); ?></th>
					<td><?php echo esc_html( $next_run ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Plugin version', 'asae-cae-roster' ); ?></th>
					<td><code>v<?php echo esc_html( ASAE_CAE_VERSION ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<h2><?php echo esc_html__( 'Actions', 'asae-cae-roster' ); ?></h2>

		<p>
			<button type="button" class="button button-primary" id="asae-cae-sync-now"
					aria-describedby="asae-cae-sync-status"
					<?php disabled( ! $is_configured ); ?>>
				<?php echo esc_html__( 'Sync Now', 'asae-cae-roster' ); ?>
			</button>
			<span id="asae-cae-sync-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
		</p>

		<p>
			<button type="button" class="button" id="asae-cae-stop-jobs"
					aria-describedby="asae-cae-stop-status">
				<?php echo esc_html__( 'Stop All Active Jobs', 'asae-cae-roster' ); ?>
			</button>
			<span id="asae-cae-stop-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
		</p>

		<p>
			<button type="button" class="button" id="asae-cae-check-updates"
					aria-describedby="asae-cae-updates-status">
				<?php echo esc_html__( 'Check for Updates Now', 'asae-cae-roster' ); ?>
			</button>
			<span id="asae-cae-updates-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
		</p>

		<h2><?php echo esc_html__( 'How to display the roster', 'asae-cae-roster' ); ?></h2>
		<p><?php echo esc_html__( 'Add this shortcode to any page or post:', 'asae-cae-roster' ); ?></p>
		<p><code>[asae_cae_roster]</code></p>
	</div>
</div>

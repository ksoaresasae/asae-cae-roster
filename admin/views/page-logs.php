<?php
/**
 * Logs tab — recent sync events from wp_asae_cae_sync_log.
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

$rows           = ASAE_CAE_Logger::recent( 50 );
$datetime_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// Helper: format LOCAL-time mysql datetimes (current_time('mysql')) via
// wp_date for the site's configured timezone.
$fmt_dt = static function ( $mysql ) use ( $datetime_fmt ) {
	if ( empty( $mysql ) ) {
		return '';
	}
	$ts = ASAE_CAE_Sync::mysql_local_to_timestamp( $mysql );
	return $ts ? wp_date( $datetime_fmt, $ts ) : esc_html( $mysql );
};

// Helper: human duration between two LOCAL-time mysql strings.
$fmt_duration = static function ( $start, $end ) {
	if ( empty( $start ) || empty( $end ) ) {
		return '';
	}
	$s = ASAE_CAE_Sync::mysql_local_to_timestamp( $start );
	$e = ASAE_CAE_Sync::mysql_local_to_timestamp( $end );
	if ( ! $s || ! $e || $e < $s ) {
		return '';
	}
	$secs = $e - $s;
	if ( $secs < 60 ) {
		return sprintf( /* translators: %d: seconds */ _n( '%d second', '%d seconds', $secs, 'asae-cae-roster' ), $secs );
	}
	$mins = (int) floor( $secs / 60 );
	$rem  = $secs % 60;
	return sprintf(
		/* translators: 1: minutes, 2: seconds */
		__( '%1$d min %2$d sec', 'asae-cae-roster' ),
		$mins,
		$rem
	);
};
?>
<div class="wrap asae-cae-wrap">
	<h1>
		<?php echo esc_html__( 'ASAE CAE Roster', 'asae-cae-roster' ); ?>
		<?php ASAE_CAE_Admin::render_version_badge(); ?>
	</h1>
	<?php ASAE_CAE_Admin::render_tabs(); ?>

	<div class="asae-cae-tab-content" role="region" aria-labelledby="asae-cae-logs-heading">
		<h2 id="asae-cae-logs-heading"><?php echo esc_html__( 'Recent sync activity', 'asae-cae-roster' ); ?></h2>

		<?php if ( empty( $rows ) ) : ?>
			<p><em><?php echo esc_html__( 'No sync runs recorded yet.', 'asae-cae-roster' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" aria-describedby="asae-cae-logs-heading">
				<caption class="screen-reader-text">
					<?php echo esc_html__( 'Recent sync runs, most recent first.', 'asae-cae-roster' ); ?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Started', 'asae-cae-roster' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Duration', 'asae-cae-roster' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'asae-cae-roster' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Trigger', 'asae-cae-roster' ); ?></th>
						<th scope="col" class="num"><?php echo esc_html__( 'Requests', 'asae-cae-roster' ); ?></th>
						<th scope="col" class="num"><?php echo esc_html__( 'Records', 'asae-cae-roster' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Notes / error', 'asae-cae-roster' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$status_class = '';
					switch ( $row->status ) {
						case 'success': $status_class = 'asae-cae-status-success'; break;
						case 'failed':
						case 'aborted': $status_class = 'asae-cae-status-failed'; break;
						case 'running': $status_class = 'asae-cae-status-running'; break;
					}
					$detail = ! empty( $row->error_message ) ? $row->error_message : (string) $row->notes;
					?>
					<tr>
						<td><?php echo esc_html( $fmt_dt( $row->started_at ) ); ?></td>
						<td><?php echo esc_html( $fmt_duration( $row->started_at, $row->ended_at ) ); ?></td>
						<td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( (string) $row->status ) ); ?></span></td>
						<td><?php echo esc_html( ucfirst( (string) $row->triggered_by ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->requests_made ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->records_processed ) ); ?></td>
						<td><?php echo $detail ? esc_html( $detail ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No notes', 'asae-cae-roster' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

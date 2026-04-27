<?php
/**
 * Settings page for ASAE CAE Roster.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'asae-cae-roster' ) );
}
?>
<div class="wrap asae-cae-wrap">
	<h1><?php echo esc_html__( 'ASAE CAE Roster — Settings', 'asae-cae-roster' ); ?></h1>

	<h2><?php echo esc_html__( 'Plugin Updates', 'asae-cae-roster' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: current plugin version */
			esc_html__( 'Current version: %s', 'asae-cae-roster' ),
			'<strong>' . esc_html( ASAE_CAE_VERSION ) . '</strong>'
		);
		?>
	</p>
	<p>
		<button type="button" class="button button-primary" id="asae-cae-check-updates"
				aria-describedby="asae-cae-check-updates-status">
			<?php echo esc_html__( 'Check for Updates Now', 'asae-cae-roster' ); ?>
		</button>
		<span id="asae-cae-check-updates-status" role="status" aria-live="polite" style="margin-left: 1em;"></span>
	</p>
</div>

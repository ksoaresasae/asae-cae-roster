<?php
/**
 * Main admin page for ASAE CAE Roster.
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
	<h1><?php echo esc_html__( 'ASAE CAE Roster', 'asae-cae-roster' ); ?></h1>
	<p>
		<?php echo esc_html__( 'This plugin has been scaffolded. Implementation will follow once instructions/_start.md is provided.', 'asae-cae-roster' ); ?>
	</p>
</div>

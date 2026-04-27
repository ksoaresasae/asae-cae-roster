<?php
/**
 * Settings tab — Wicket auth, schedule, display, rate behavior.
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

$s = ASAE_CAE_Settings::all();

$default_photo_url = ASAE_CAE_Settings::get_default_photo_url();
$default_photo_id  = (int) $s['default_photo_attachment_id'];
?>
<div class="wrap asae-cae-wrap">
	<h1><?php echo esc_html__( 'ASAE CAE Roster', 'asae-cae-roster' ); ?></h1>
	<?php ASAE_CAE_Admin::render_tabs(); ?>

	<form id="asae-cae-settings-form" class="asae-cae-tab-content" role="region" aria-labelledby="asae-cae-settings-heading">
		<h2 id="asae-cae-settings-heading"><?php echo esc_html__( 'Settings', 'asae-cae-roster' ); ?></h2>

		<h3><?php echo esc_html__( 'Wicket API', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'Same credentials your other Wicket-backed plugins use. The base URL should not include a trailing slash.', 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="asae-cae-base-url"><?php echo esc_html__( 'Base URL', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="url" id="asae-cae-base-url" name="settings[wicket_base_url]"
							value="<?php echo esc_attr( $s['wicket_base_url'] ); ?>"
							class="regular-text"
							placeholder="https://api.example.org/v1"
							autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="asae-cae-secret"><?php echo esc_html__( 'HMAC Secret', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="password" id="asae-cae-secret" name="settings[wicket_secret]"
							value="<?php echo esc_attr( $s['wicket_secret'] ); ?>"
							class="regular-text"
							autocomplete="off"
							aria-describedby="asae-cae-secret-help" />
						<p id="asae-cae-secret-help" class="description">
							<?php echo esc_html__( 'Used to sign the JWT (HS256) sent on every Wicket request.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="asae-cae-person-id"><?php echo esc_html__( 'Person UUID', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="text" id="asae-cae-person-id" name="settings[wicket_person_id]"
							value="<?php echo esc_attr( $s['wicket_person_id'] ); ?>"
							class="regular-text code"
							pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"
							placeholder="00000000-0000-0000-0000-000000000000"
							autocomplete="off"
							aria-describedby="asae-cae-person-id-help" />
						<p id="asae-cae-person-id-help" class="description">
							<?php echo esc_html__( 'Wicket UUID of the person account on whose behalf requests are made (sub claim).', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Test connection', 'asae-cae-roster' ); ?></th>
					<td>
						<button type="button" class="button" id="asae-cae-test-connection"
								aria-describedby="asae-cae-test-status">
							<?php echo esc_html__( 'Test Connection', 'asae-cae-roster' ); ?>
						</button>
						<span id="asae-cae-test-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html__( 'Scheduled sync', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'A daily sync runs at the time below in the site\'s local timezone. Defaults to 02:00.', 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Time of day', 'asae-cae-roster' ); ?></th>
					<td>
						<label for="asae-cae-schedule-hour" class="screen-reader-text">
							<?php echo esc_html__( 'Hour (0-23)', 'asae-cae-roster' ); ?>
						</label>
						<input type="number" id="asae-cae-schedule-hour" name="settings[schedule_hour]"
							min="0" max="23" inputmode="numeric"
							value="<?php echo esc_attr( $s['schedule_hour'] ); ?>"
							class="small-text" />
						<span aria-hidden="true">:</span>
						<label for="asae-cae-schedule-minute" class="screen-reader-text">
							<?php echo esc_html__( 'Minute (0-59)', 'asae-cae-roster' ); ?>
						</label>
						<input type="number" id="asae-cae-schedule-minute" name="settings[schedule_minute]"
							min="0" max="59" inputmode="numeric"
							value="<?php echo esc_attr( $s['schedule_minute'] ); ?>"
							class="small-text" />
						<p class="description">
							<?php echo esc_html__( '24-hour format. Use 02:00 for the default 2 AM run.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html__( 'Public roster display', 'asae-cae-roster' ); ?></h3>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="asae-cae-items-per-page"><?php echo esc_html__( 'Items per page', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-items-per-page" name="settings[items_per_page]"
							min="5" max="100" inputmode="numeric"
							value="<?php echo esc_attr( $s['items_per_page'] ); ?>"
							class="small-text" />
						<p class="description">
							<?php echo esc_html__( 'Number of CAEs shown per page within each letter section. Default: 20.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Default photo', 'asae-cae-roster' ); ?></th>
					<td>
						<div class="asae-cae-photo-picker">
							<img id="asae-cae-photo-preview"
								src="<?php echo esc_url( $default_photo_url ); ?>"
								alt=""
								data-empty-src=""
								class="asae-cae-photo-preview <?php echo $default_photo_url ? '' : 'is-empty'; ?>" />
							<input type="hidden" id="asae-cae-photo-id" name="settings[default_photo_attachment_id]"
								value="<?php echo esc_attr( $default_photo_id ); ?>" />
							<p>
								<button type="button" class="button" id="asae-cae-photo-pick">
									<?php echo esc_html__( 'Choose Photo', 'asae-cae-roster' ); ?>
								</button>
								<button type="button" class="button-link" id="asae-cae-photo-clear"
									<?php echo $default_photo_id > 0 ? '' : 'hidden'; ?>>
									<?php echo esc_html__( 'Remove', 'asae-cae-roster' ); ?>
								</button>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Used when a CAE record has no photo or the photo cannot be downloaded.', 'asae-cae-roster' ); ?>
							</p>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html__( 'Rate limiting', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'These caps protect Wicket and other plugins from being starved of API capacity by this sync.', 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="asae-cae-request-budget"><?php echo esc_html__( 'Max requests per sync', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-request-budget" name="settings[request_budget]"
							min="1" max="10000" inputmode="numeric"
							value="<?php echo esc_attr( $s['request_budget'] ); ?>"
							class="small-text" />
						<p class="description">
							<?php echo esc_html__( 'Sync aborts cleanly when this is reached. Default: 500.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="asae-cae-request-delay"><?php echo esc_html__( 'Delay between requests (ms)', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-request-delay" name="settings[request_delay_ms]"
							min="0" max="10000" inputmode="numeric"
							value="<?php echo esc_attr( $s['request_delay_ms'] ); ?>"
							class="small-text" />
						<p class="description">
							<?php echo esc_html__( 'Default: 250 ms. Set to 0 only on isolated dev sites.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary" id="asae-cae-save-settings"
					aria-describedby="asae-cae-save-status">
				<?php echo esc_html__( 'Save Settings', 'asae-cae-roster' ); ?>
			</button>
			<span id="asae-cae-save-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
		</p>
	</form>
</div>

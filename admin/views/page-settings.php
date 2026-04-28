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
	<h1>
		<?php echo esc_html__( 'ASAE CAE Roster', 'asae-cae-roster' ); ?>
		<?php ASAE_CAE_Admin::render_version_badge(); ?>
	</h1>
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
			<?php echo esc_html__( "Pick the days of the week the sync should run, then the time of day in the site's local timezone. Defaults to every day at 02:00.", 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Run on these days', 'asae-cae-roster' ); ?></th>
					<td>
						<fieldset class="asae-cae-day-checkboxes" aria-describedby="asae-cae-days-help">
							<legend class="screen-reader-text">
								<?php echo esc_html__( 'Days of the week the scheduled sync should run', 'asae-cae-roster' ); ?>
							</legend>
							<?php
							$day_labels = array(
								1 => __( 'Mon', 'asae-cae-roster' ),
								2 => __( 'Tue', 'asae-cae-roster' ),
								3 => __( 'Wed', 'asae-cae-roster' ),
								4 => __( 'Thu', 'asae-cae-roster' ),
								5 => __( 'Fri', 'asae-cae-roster' ),
								6 => __( 'Sat', 'asae-cae-roster' ),
								0 => __( 'Sun', 'asae-cae-roster' ),
							);
							$checked_days = isset( $s['schedule_days'] ) && is_array( $s['schedule_days'] ) ? $s['schedule_days'] : array();
							foreach ( $day_labels as $num => $label ) :
								$id = 'asae-cae-schedule-day-' . (int) $num;
								?>
								<label for="<?php echo esc_attr( $id ); ?>" class="asae-cae-day-checkbox">
									<input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
										name="settings[schedule_days][]"
										value="<?php echo esc_attr( (string) $num ); ?>"
										<?php checked( in_array( (int) $num, array_map( 'intval', $checked_days ), true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<input type="hidden" name="settings[_schedule_form]" value="1" />
						</fieldset>
						<p id="asae-cae-days-help" class="description">
							<?php echo esc_html__( 'If no days are selected, the scheduled sync is effectively turned off (manual Sync Now still works).', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Time of day', 'asae-cae-roster' ); ?></th>
					<td>
						<label for="asae-cae-schedule-hour" class="screen-reader-text">
							<?php echo esc_html__( 'Hour (0-23)', 'asae-cae-roster' ); ?>
						</label>
						<input type="number" id="asae-cae-schedule-hour" name="settings[schedule_hour]"
							min="0" max="23" inputmode="numeric"
							value="<?php echo esc_attr( $s['schedule_hour'] ); ?>"
							class="small-text"
							aria-describedby="asae-cae-schedule-help" />
						<span aria-hidden="true">:</span>
						<label for="asae-cae-schedule-minute" class="screen-reader-text">
							<?php echo esc_html__( 'Minute (0-59)', 'asae-cae-roster' ); ?>
						</label>
						<input type="number" id="asae-cae-schedule-minute" name="settings[schedule_minute]"
							min="0" max="59" inputmode="numeric"
							value="<?php echo esc_attr( $s['schedule_minute'] ); ?>"
							class="small-text"
							aria-describedby="asae-cae-schedule-help" />
						<p id="asae-cae-schedule-help" class="description">
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
							class="small-text"
							aria-describedby="asae-cae-items-per-page-help" />
						<p id="asae-cae-items-per-page-help" class="description">
							<?php echo esc_html__( 'Number of CAEs shown per page in both the "All" view and individual letter sections. Default: 50.', 'asae-cae-roster' ); ?>
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

		<h3><?php echo esc_html__( 'Chunked sync', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'A full sync is broken into many small chunks scheduled via WP-Cron, instead of one long blocking call. Smaller chunks + longer delays = lighter load on Wicket.', 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="asae-cae-pages-per-chunk"><?php echo esc_html__( 'Pages per chunk', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-pages-per-chunk" name="settings[pages_per_chunk]"
							min="1" max="50" inputmode="numeric"
							value="<?php echo esc_attr( $s['pages_per_chunk'] ); ?>"
							class="small-text"
							aria-describedby="asae-cae-pages-per-chunk-help" />
						<p id="asae-cae-pages-per-chunk-help" class="description">
							<?php echo esc_html__( 'Each Wicket page = 25 records. Default 1 (= 25 records per chunk).', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="asae-cae-chunk-delay"><?php echo esc_html__( 'Delay between chunks (seconds)', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-chunk-delay" name="settings[chunk_delay_seconds]"
							min="1" max="600" inputmode="numeric"
							value="<?php echo esc_attr( $s['chunk_delay_seconds'] ); ?>"
							class="small-text"
							aria-describedby="asae-cae-chunk-delay-help" />
						<p id="asae-cae-chunk-delay-help" class="description">
							<?php echo esc_html__( 'How long the next chunk waits before WP-Cron schedules it. Default 5.', 'asae-cae-roster' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html__( 'Rate limiting (per chunk)', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'These caps apply within a single chunk, not across the whole sync. They protect Wicket and other plugins from being starved of API capacity.', 'asae-cae-roster' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="asae-cae-request-budget"><?php echo esc_html__( 'Max requests per chunk', 'asae-cae-roster' ); ?></label>
					</th>
					<td>
						<input type="number" id="asae-cae-request-budget" name="settings[request_budget]"
							min="1" max="10000" inputmode="numeric"
							value="<?php echo esc_attr( $s['request_budget'] ); ?>"
							class="small-text"
							aria-describedby="asae-cae-request-budget-help" />
						<p id="asae-cae-request-budget-help" class="description">
							<?php echo esc_html__( 'A single chunk that hits this cap aborts cleanly; the run resumes from the next page on its next scheduled chunk.', 'asae-cae-roster' ); ?>
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
							class="small-text"
							aria-describedby="asae-cae-request-delay-help" />
						<p id="asae-cae-request-delay-help" class="description">
							<?php echo esc_html__( 'Courtesy pause between Wicket calls inside a single chunk. Default: 250 ms.', 'asae-cae-roster' ); ?>
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

		<h3><?php echo esc_html__( 'Plugin updates', 'asae-cae-roster' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'Forces an immediate check against the GitHub Releases endpoint, bypassing the 6-hour transient cache.', 'asae-cae-roster' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="asae-cae-check-updates"
					aria-describedby="asae-cae-updates-status">
				<?php echo esc_html__( 'Check for Updates Now', 'asae-cae-roster' ); ?>
			</button>
			<span id="asae-cae-updates-status" role="status" aria-live="polite" class="asae-cae-status-msg"></span>
		</p>
	</form>
</div>

<?php

declare( strict_types=1 );

namespace DSN\Hawk\Settings;

use DSN\Hawk\Plugin;
use DSN\Hawk\Reports\GravityFormsReport;
use DSN\Hawk\Support\GravityEntriesState;
use DSN\Hawk\Support\Logger;
use DSN\Hawk\Sync\Scheduler;
use DSN\Hawk\Sync\Syncer;

final class SettingsPage {

	private const NONCE_ACTION = 'dsn_hawk_settings';
	private const SYNC_NONCE   = 'dsn_hawk_sync_now';
	private const RESET_NONCE  = 'dsn_hawk_gf_reset';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'handlePost' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function menu(): void {
		add_options_page(
			__( 'DSN Hawk', 'dsn-hawk' ),
			__( 'DSN Hawk', 'dsn-hawk' ),
			'manage_options',
			'dsn-hawk',
			[ $this, 'render' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== 'settings_page_dsn-hawk' ) {
			return;
		}
		wp_enqueue_style(
			'dsn-hawk-admin',
			DSN_HAWK_URL . 'assets/admin.css',
			[],
			DSN_HAWK_VERSION
		);
	}

	public function handlePost(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save settings
		if ( isset( $_POST['dsn_hawk_save'] ) ) {
			check_admin_referer( self::NONCE_ACTION );

			$settings              = Plugin::settings();
			$settings['endpoint']  = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( (string) $_POST['endpoint'] ) ) : '';
			$settings['token']     = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
			$settings['frequency'] = isset( $_POST['frequency'] ) ? sanitize_key( (string) $_POST['frequency'] ) : 'dsn_hawk_hourly';

			$allowed_freq = [ 'dsn_hawk_hourly', 'daily', 'manual' ];
			if ( ! in_array( $settings['frequency'], $allowed_freq, true ) ) {
				$settings['frequency'] = 'dsn_hawk_hourly';
			}

			$reports_post        = isset( $_POST['reports'] ) && is_array( $_POST['reports'] )
				? $_POST['reports']
				: [];
			$settings['reports'] = [
				'gravity_forms'             => ! empty( $reports_post['gravity_forms'] ),
				'gravity_forms_entries'     => ! empty( $reports_post['gravity_forms_entries'] ),
				'gravity_forms_entries_pii' => ! empty( $reports_post['gravity_forms_entries_pii'] ),
				'email_log'                 => ! empty( $reports_post['email_log'] ),
				'plugins'                   => ! empty( $reports_post['plugins'] ),
				'core_theme'                => ! empty( $reports_post['core_theme'] ),
				'file_integrity'            => ! empty( $reports_post['file_integrity'] ),
			];

			update_option( Plugin::OPTION_KEY, $settings );
			( new Scheduler() )->schedule();

			$this->redirectWithNotice( 'saved' );
		}

		// Reset GF entry cursors — forces a fresh full backfill on next sync.
		if ( isset( $_POST['dsn_hawk_gf_reset'] ) ) {
			check_admin_referer( self::RESET_NONCE );
			GravityEntriesState::reset();
			add_settings_error(
				'dsn_hawk',
				'gf_reset',
				__( 'Gravity Forms entry backfill state reset. All entries will resync starting next run.', 'dsn-hawk' ),
				'updated'
			);
		}

		// Sync now
		if ( isset( $_POST['dsn_hawk_sync_now'] ) ) {
			check_admin_referer( self::SYNC_NONCE );

			$result = ( new Syncer() )->run();
			$msg    = sprintf(
				/* translators: 1: status, 2: http code, 3: message */
				__( 'Sync %1$s (HTTP %2$d): %3$s', 'dsn-hawk' ),
				$result['status'],
				$result['code'],
				$result['message']
			);
			$this->redirectWithNotice( $result['ok'] ? 'sync_ok' : 'sync_error', $msg );
		}
	}

	private function renderGfBackfillStatus(): void {
		if ( ! class_exists( '\\GFAPI' ) ) {
			return;
		}

		$state = GravityEntriesState::all();
		$forms = (array) \GFAPI::get_forms( true );
		$forms = array_merge( $forms, (array) \GFAPI::get_forms( false ) );

		if ( empty( $forms ) ) {
			echo '<p>' . esc_html__( 'No Gravity Forms found.', 'dsn-hawk' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Form', 'dsn-hawk' ); ?></th>
					<th><?php esc_html_e( 'Total entries', 'dsn-hawk' ); ?></th>
					<th><?php esc_html_e( 'Last entry ID sent', 'dsn-hawk' ); ?></th>
					<th><?php esc_html_e( 'Backfilled?', 'dsn-hawk' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'dsn-hawk' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $forms as $form ) :
				$fid   = (string) ( $form['id'] ?? '' );
				$total = method_exists( '\\GFAPI', 'count_entries' ) ? (int) \GFAPI::count_entries( (int) $fid ) : 0;
				$row   = $state[ $fid ] ?? [ 'cursor' => 0, 'backfilled' => false, 'updated_at' => 0 ];
				$when  = ! empty( $row['updated_at'] ) ? wp_date( 'Y-m-d H:i:s T', (int) $row['updated_at'] ) : '—';
				?>
				<tr>
					<td>
						<?php echo esc_html( (string) ( $form['title'] ?? '' ) ); ?>
						<span class="description">#<?php echo esc_html( $fid ); ?></span>
					</td>
					<td><?php echo esc_html( (string) $total ); ?></td>
					<td><?php echo esc_html( (string) ( $row['cursor'] ?? 0 ) ); ?></td>
					<td><?php echo ! empty( $row['backfilled'] ) ? '✓' : '…'; ?></td>
					<td><?php echo esc_html( (string) $when ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Plugin::settings();
		$logs     = Logger::recent( 25 );
		$gf       = ( new GravityFormsReport() )->isAvailable();
		?>
		<div class="wrap dsn-hawk-wrap">
			<h1><?php esc_html_e( 'DSN Hawk', 'dsn-hawk' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Collects site health reports and pushes them to the DSN Skyline admin.', 'dsn-hawk' ); ?>
			</p>

			<?php settings_errors( 'dsn_hawk' ); ?>
			<?php $this->renderNotice(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dsn-hawk-endpoint"><?php esc_html_e( 'Skyline endpoint URL', 'dsn-hawk' ); ?></label></th>
						<td>
							<input
								type="url"
								id="dsn-hawk-endpoint"
								name="endpoint"
								class="regular-text code"
								value="<?php echo esc_attr( (string) $settings['endpoint'] ); ?>"
								placeholder="https://mvp.designstudio.com/api/v1/hawk/sync"
							/>
							<p class="description"><?php esc_html_e( 'Full URL to the Skyline sync endpoint. HTTPS required.', 'dsn-hawk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsn-hawk-token"><?php esc_html_e( 'Sync token', 'dsn-hawk' ); ?></label></th>
						<td>
							<input
								type="password"
								id="dsn-hawk-token"
								name="token"
								class="regular-text code"
								value="<?php echo esc_attr( (string) $settings['token'] ); ?>"
								autocomplete="new-password"
							/>
							<p class="description"><?php esc_html_e( 'Bearer token. Matches the Laravel WP_SYNC_TOKEN env var.', 'dsn-hawk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsn-hawk-frequency"><?php esc_html_e( 'Sync frequency', 'dsn-hawk' ); ?></label></th>
						<td>
							<select id="dsn-hawk-frequency" name="frequency">
								<option value="dsn_hawk_hourly" <?php selected( $settings['frequency'], 'dsn_hawk_hourly' ); ?>><?php esc_html_e( 'Hourly', 'dsn-hawk' ); ?></option>
								<option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'dsn-hawk' ); ?></option>
								<option value="manual" <?php selected( $settings['frequency'], 'manual' ); ?>><?php esc_html_e( 'Manual only', 'dsn-hawk' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled reports', 'dsn-hawk' ); ?></th>
						<td>
							<fieldset class="dsn-hawk-reports">
								<label>
									<input
										type="checkbox"
										name="reports[gravity_forms]"
										value="1"
										<?php checked( ! empty( $settings['reports']['gravity_forms'] ) ); ?>
									/>
									<?php esc_html_e( 'Gravity Forms', 'dsn-hawk' ); ?>
									<?php if ( ! $gf ) : ?>
										<span class="description"><?php esc_html_e( '(Gravity Forms plugin not detected — report will be skipped.)', 'dsn-hawk' ); ?></span>
									<?php endif; ?>
								</label>
								<div class="dsn-hawk-sub">
									<label>
										<input
											type="checkbox"
											name="reports[gravity_forms_entries]"
											value="1"
											<?php checked( ! empty( $settings['reports']['gravity_forms_entries'] ) ); ?>
										/>
										<?php esc_html_e( 'Include form entries (first sync backfills all history in batches of 250, then incremental)', 'dsn-hawk' ); ?>
									</label>
									<br/>
									<label>
										<input
											type="checkbox"
											name="reports[gravity_forms_entries_pii]"
											value="1"
											<?php checked( ! empty( $settings['reports']['gravity_forms_entries_pii'] ) ); ?>
										/>
										<?php esc_html_e( 'Send raw PII in entries (otherwise: IPs masked to /24 or /48, email fields hashed sha256)', 'dsn-hawk' ); ?>
									</label>
								</div>
								<br/>
								<label>
									<input
										type="checkbox"
										name="reports[email_log]"
										value="1"
										<?php checked( ! empty( $settings['reports']['email_log'] ) ); ?>
									/>
									<?php esc_html_e( 'Email Log (compromise detection)', 'dsn-hawk' ); ?>
								</label>
								<br/>
								<label>
									<input
										type="checkbox"
										name="reports[plugins]"
										value="1"
										<?php checked( ! empty( $settings['reports']['plugins'] ) ); ?>
									/>
									<?php esc_html_e( 'Plugin Inventory', 'dsn-hawk' ); ?>
								</label>
								<br/>
								<label>
									<input
										type="checkbox"
										name="reports[core_theme]"
										value="1"
										<?php checked( ! empty( $settings['reports']['core_theme'] ) ); ?>
									/>
									<?php esc_html_e( 'Core + Theme', 'dsn-hawk' ); ?>
								</label>
								<br/>
								<label>
									<input
										type="checkbox"
										name="reports[file_integrity]"
										value="1"
										<?php checked( ! empty( $settings['reports']['file_integrity'] ) ); ?>
									/>
									<?php esc_html_e( 'File Integrity (slow — scans core + uploads, cached 24h)', 'dsn-hawk' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="dsn_hawk_save" class="button button-primary">
						<?php esc_html_e( 'Save settings', 'dsn-hawk' ); ?>
					</button>
				</p>
			</form>

			<hr/>

			<h2><?php esc_html_e( 'Sync now', 'dsn-hawk' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::SYNC_NONCE ); ?>
				<p>
					<button type="submit" name="dsn_hawk_sync_now" class="button button-secondary">
						<?php esc_html_e( 'Sync now', 'dsn-hawk' ); ?>
					</button>
				</p>
			</form>

			<?php
			$last = $settings['last_sync'] ?? [];
			if ( ! empty( $last['timestamp'] ) ) :
				$when = wp_date( 'Y-m-d H:i:s T', (int) $last['timestamp'] );
				?>
				<p class="description">
					<strong><?php esc_html_e( 'Last sync:', 'dsn-hawk' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							'%s — %s (HTTP %d) %s',
							$when,
							(string) ( $last['status'] ?? '' ),
							(int) ( $last['http_code'] ?? 0 ),
							(string) ( $last['message'] ?? '' )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<hr/>

			<?php if ( $gf ) : ?>
				<h2><?php esc_html_e( 'Gravity Forms entry backfill', 'dsn-hawk' ); ?></h2>
				<?php $this->renderGfBackfillStatus(); ?>
				<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'Reset backfill state? All entries will resync from scratch on the next run.', 'dsn-hawk' ) ); ?>');">
					<?php wp_nonce_field( self::RESET_NONCE ); ?>
					<p>
						<button type="submit" name="dsn_hawk_gf_reset" class="button">
							<?php esc_html_e( 'Reset backfill state', 'dsn-hawk' ); ?>
						</button>
					</p>
				</form>
				<hr/>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent runs', 'dsn-hawk' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No runs yet.', 'dsn-hawk' ); ?></p>
			<?php else : ?>
				<table class="widefat striped dsn-hawk-log">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'dsn-hawk' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dsn-hawk' ); ?></th>
							<th><?php esc_html_e( 'HTTP', 'dsn-hawk' ); ?></th>
							<th><?php esc_html_e( 'Bytes', 'dsn-hawk' ); ?></th>
							<th><?php esc_html_e( 'Message', 'dsn-hawk' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $logs as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['http_code'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['payload_bytes'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function redirectWithNotice( string $notice, string $message = '' ): never {
		if ( $message !== '' ) {
			set_transient( $this->noticeTransientKey(), $message, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => 'dsn-hawk',
					'dsn_hawk_notice' => $notice,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	private function renderNotice(): void {
		$notice = isset( $_GET['dsn_hawk_notice'] )
			? sanitize_key( wp_unslash( (string) $_GET['dsn_hawk_notice'] ) )
			: '';

		if ( $notice !== 'saved' ) {
			if ( ! in_array( $notice, [ 'sync_ok', 'sync_error' ], true ) ) {
				return;
			}

			$message = get_transient( $this->noticeTransientKey() );
			delete_transient( $this->noticeTransientKey() );

			if ( ! is_string( $message ) || $message === '' ) {
				return;
			}

			$class = $notice === 'sync_ok' ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'dsn-hawk' ) . '</p></div>';
	}

	private function noticeTransientKey(): string {
		return 'dsn_hawk_notice_' . get_current_user_id();
	}
}

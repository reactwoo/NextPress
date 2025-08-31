<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_rwsb_build_all', [ $this, 'handle_build_all' ] );
		add_action( 'admin_post_rwsb_clear_queue', [ $this, 'handle_clear_queue' ] );
		add_action( 'admin_post_rwsb_clear_log', [ $this, 'handle_clear_log' ] );
		add_action( 'wp_ajax_rwsb_queue_status', [ $this, 'ajax_queue_status' ] );
	}

	public function menu(): void {
		add_menu_page(
			'Static Builder',
			'Static Builder',
			'manage_options',
			'rwsb',
			[ $this, 'render' ],
			'dashicons-performance',
			58
		);
	}

	public function register_settings(): void {
		register_setting( 'rwsb', 'rwsb_settings', [
			'type' => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	public function sanitize( $input ): array {
		$defaults = rwsb_get_settings();
		$clean = [
			'enabled'        => isset( $input['enabled'] ) ? 1 : 0,
			'serve_static'   => isset( $input['serve_static'] ) ? 1 : 0,
			'respect_logged' => isset( $input['respect_logged'] ) ? 1 : 0,
			'post_types'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) ($input['post_types'] ?? []) ) ) ),
			'ttl'            => max( 0, (int) ( $input['ttl'] ?? 0 ) ),
			'bypass_param'   => sanitize_key( $input['bypass_param'] ?? 'rwsb' ),
			'webhook_url'    => esc_url_raw( $input['webhook_url'] ?? '' ),
			'headers'        => is_array( $input['headers'] ?? [] ) ? array_map( 'sanitize_text_field', $input['headers'] ) : $defaults['headers'],
		];
		return wp_parse_args( $clean, $defaults );
	}

	public function assets( $hook ): void {
		if ( $hook !== 'toplevel_page_rwsb' ) return;
		wp_enqueue_style( 'rwsb-admin', RWSB_URL . 'assets/admin.css', [], RWSB_VERSION );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$opts = rwsb_get_settings();
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$queue_status = RWSB_Queue::get_status();
		?>
		<div class="wrap rwsb-wrap">
			<h1>ReactWoo Static Builder</h1>
			
			<?php if ( isset( $_GET['built'] ) ): ?>
				<div class="notice notice-success is-dismissible">
					<p>Static files rebuilt successfully!</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['queued'] ) ): ?>
				<div class="notice notice-success is-dismissible">
					<p>Full rebuild queued successfully! Check status below.</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['cleared'] ) ): ?>
				<div class="notice notice-success is-dismissible">
					<p>Build queue cleared successfully!</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['log_cleared'] ) ): ?>
				<div class="notice notice-success is-dismissible">
					<p>Build log cleared successfully!</p>
				</div>
			<?php endif; ?>

			<?php $this->render_queue_status( $queue_status ); ?>
			
			<?php $this->render_build_log(); ?>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'rwsb' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable</th>
						<td><label><input type="checkbox" name="rwsb_settings[enabled]" value="1" <?php checked( 1, (int) $opts['enabled'] ); ?>> Turn on static builds</label></td>
					</tr>
					<tr>
						<th scope="row">Serve Static</th>
						<td><label><input type="checkbox" name="rwsb_settings[serve_static]" value="1" <?php checked( 1, (int) $opts['serve_static'] ); ?>> Serve static files on front-end</label></td>
					</tr>
					<tr>
						<th scope="row">Respect Logged-in</th>
						<td><label><input type="checkbox" name="rwsb_settings[respect_logged]" value="1" <?php checked( 1, (int) $opts['respect_logged'] ); ?>> Always bypass static for logged-in users</label></td>
					</tr>
					<tr>
						<th scope="row">Post Types</th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:inline-block;margin-right:12px;">
									<input type="checkbox" name="rwsb_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, (array) $opts['post_types'], true ) ); ?>>
									<?php echo esc_html( $pt->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">TTL (seconds)</th>
						<td><input name="rwsb_settings[ttl]" type="number" min="0" step="1" value="<?php echo esc_attr( (int) $opts['ttl'] ); ?>"> <small>0 = never expire</small></td>
					</tr>
					<tr>
						<th scope="row">Bypass Param</th>
						<td><input name="rwsb_settings[bypass_param]" type="text" value="<?php echo esc_attr( $opts['bypass_param'] ); ?>"> <small>Append <code>?<?php echo esc_html( $opts['bypass_param'] ); ?>=miss</code> to force live render</small></td>
					</tr>
					<tr>
						<th scope="row">Deploy Webhook</th>
						<td><input style="width:480px" name="rwsb_settings[webhook_url]" type="url" value="<?php echo esc_attr( $opts['webhook_url'] ); ?>"> <small>Optional Netlify/Vercel build hook</small></td>
					</tr>
					<tr>
						<th scope="row">Response Headers</th>
						<td>
							<label>Cache-Control<br>
								<input style="width:480px" name="rwsb_settings[headers][Cache-Control]" type="text" value="<?php echo esc_attr( $opts['headers']['Cache-Control'] ?? '' ); ?>">
							</label><br><br>
							<label>X-Powered-By<br>
								<input style="width:480px" name="rwsb_settings[headers][X-Powered-By]" type="text" value="<?php echo esc_attr( $opts['headers']['X-Powered-By'] ?? '' ); ?>">
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<div class="rwsb-action-buttons">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
					<?php wp_nonce_field( 'rwsb_build_all' ); ?>
					<input type="hidden" name="action" value="rwsb_build_all">
					<?php submit_button( 'Queue Full Rebuild', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
					<?php wp_nonce_field( 'rwsb_clear_queue' ); ?>
					<input type="hidden" name="action" value="rwsb_clear_queue">
					<?php submit_button( 'Clear Queue', 'delete', 'submit', false ); ?>
				</form>
			</div>

			<p><small>Storage: <code><?php echo esc_html( RWSB_STORE_DIR ); ?></code></small></p>
		</div>
		<?php
	}

	/**
	 * Render queue status section.
	 */
	protected function render_queue_status( array $status ): void {
		?>
		<div class="rwsb-queue-status">
			<h2>Build Queue Status</h2>
			<div id="rwsb-status-content">
				<?php $this->render_status_content( $status ); ?>
			</div>
			<button type="button" id="rwsb-refresh-status" class="button button-small">Refresh Status</button>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#rwsb-refresh-status').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('Refreshing...');
				
				$.post(ajaxurl, {
					action: 'rwsb_queue_status',
					_ajax_nonce: '<?php echo wp_create_nonce( 'rwsb_queue_status' ); ?>'
				}).done(function(response) {
					if (response.success) {
						$('#rwsb-status-content').html(response.data.html);
					}
				}).always(function() {
					$btn.prop('disabled', false).text('Refresh Status');
				});
			});
			
			// Auto-refresh every 10 seconds if queue is not empty
			<?php if ( $status['total'] > 0 ): ?>
			setInterval(function() {
				$('#rwsb-refresh-status').click();
			}, 10000);
			<?php endif; ?>
		});
		</script>
		<?php
	}

	/**
	 * Render status content (used for both initial render and AJAX refresh).
	 */
	protected function render_status_content( array $status ): void {
		?>
		<div class="rwsb-status-grid">
			<div class="rwsb-status-item">
				<strong>Total Tasks:</strong><br>
				<span style="color: <?php echo $status['total'] > 0 ? '#d63638' : '#00a32a'; ?>; font-size: 18px; font-weight: bold;">
					<?php echo $status['total']; ?>
				</span>
			</div>
			<div class="rwsb-status-item">
				<strong>Status:</strong><br>
				<span style="color: <?php echo $status['processing'] ? '#d63638' : '#00a32a'; ?>; font-size: 18px; font-weight: bold;">
					<?php echo $status['processing'] ? 'Processing' : 'Idle'; ?>
				</span>
			</div>
			<div class="rwsb-status-item">
				<strong>Single Builds:</strong><br>
				<span style="font-size: 18px; font-weight: bold;"><?php echo $status['counts']['single']; ?></span>
			</div>
			<div class="rwsb-status-item">
				<strong>Archive Builds:</strong><br>
				<span style="font-size: 18px; font-weight: bold;"><?php echo $status['counts']['archives']; ?></span>
			</div>
			<div class="rwsb-status-item">
				<strong>Full Rebuilds:</strong><br>
				<span style="font-size: 18px; font-weight: bold;"><?php echo $status['counts']['full']; ?></span>
			</div>
			<div class="rwsb-status-item">
				<strong>Next Scheduled:</strong><br>
				<span style="font-size: 14px;">
				<?php 
				if ( $status['next_scheduled'] ) {
					echo human_time_diff( $status['next_scheduled'] ) . ' from now';
				} else {
					echo 'None';
				}
				?>
				</span>
			</div>
		</div>
		<?php
	}

	public function handle_build_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_build_all' );
		RWSB_Queue::add_full_rebuild( 1 ); // High priority
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&queued=1' ) );
		exit;
	}

	public function handle_clear_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_clear_queue' );
		RWSB_Queue::clear_queue();
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&cleared=1' ) );
		exit;
	}

	public function ajax_queue_status(): void {
		check_ajax_referer( 'rwsb_queue_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$status = RWSB_Queue::get_status();
		ob_start();
		$this->render_status_content( $status );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html, 'status' => $status ] );
	}

	/**
	 * Render build log section.
	 */
	protected function render_build_log(): void {
		$stats = RWSB_Logger::get_stats();
		$recent = RWSB_Logger::get_recent_entries( 5 );
		?>
		<div class="rwsb-queue-status">
			<h2>Build History & Statistics</h2>
			
			<div class="rwsb-status-grid">
				<div class="rwsb-status-item">
					<strong>Total Builds:</strong><br>
					<span style="font-size: 18px; font-weight: bold;"><?php echo $stats['total_builds']; ?></span>
				</div>
				<div class="rwsb-status-item">
					<strong>Successful:</strong><br>
					<span style="color: #00a32a; font-size: 18px; font-weight: bold;"><?php echo $stats['successful_builds']; ?></span>
				</div>
				<div class="rwsb-status-item">
					<strong>Failed:</strong><br>
					<span style="color: #d63638; font-size: 18px; font-weight: bold;"><?php echo $stats['failed_builds']; ?></span>
				</div>
				<div class="rwsb-status-item">
					<strong>Last Build:</strong><br>
					<span style="font-size: 14px;">
					<?php 
					if ( $stats['last_build'] ) {
						echo human_time_diff( $stats['last_build']['timestamp'] ) . ' ago';
					} else {
						echo 'None';
					}
					?>
					</span>
				</div>
			</div>

			<?php if ( ! empty( $recent ) ): ?>
			<h3>Recent Activity</h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Time</th>
						<th>Type</th>
						<th>URL</th>
						<th>Status</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $entry ): ?>
					<tr>
						<td><?php echo human_time_diff( $entry['timestamp'] ) . ' ago'; ?></td>
						<td>
							<span class="rwsb-build-type rwsb-type-<?php echo esc_attr( $entry['type'] ); ?>">
								<?php echo esc_html( ucfirst( $entry['type'] ) ); ?>
							</span>
						</td>
						<td>
							<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" title="<?php echo esc_attr( $entry['url'] ); ?>">
								<?php echo esc_html( wp_parse_url( $entry['url'], PHP_URL_PATH ) ?: '/' ); ?>
							</a>
						</td>
						<td>
							<span class="rwsb-status rwsb-status-<?php echo esc_attr( $entry['status'] ); ?>">
								<?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<div class="rwsb-action-buttons" style="margin-top: 15px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
					<?php wp_nonce_field( 'rwsb_clear_log' ); ?>
					<input type="hidden" name="action" value="rwsb_clear_log">
					<?php submit_button( 'Clear Log', 'delete small', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_clear_log' );
		RWSB_Logger::clear_log();
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&log_cleared=1' ) );
		exit;
	}
}

new RWSB_Admin();

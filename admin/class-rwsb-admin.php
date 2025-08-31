<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_rwsb_build_all', [ $this, 'handle_build_all' ] );
		add_action( 'admin_post_rwsb_deploy_cloud', [ $this, 'handle_deploy_cloud' ] );
		add_action( 'admin_post_rwsb_connect_hosting', [ $this, 'handle_connect_hosting' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'admin_post_rwsb_test_webhook', [ $this, 'handle_test_webhook' ] );
		add_action( 'admin_post_rwsb_export_zip', [ $this, 'handle_export_zip' ] );
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
			'hosting_provider'   => sanitize_key( $input['hosting_provider'] ?? '' ),
			'hosting_connected'  => (int) ( $input['hosting_connected'] ?? 0 ),
			'hosting_manage_url' => esc_url_raw( $input['hosting_manage_url'] ?? '' ),
			'pro_enabled'        => isset( $input['pro_enabled'] ) ? 1 : 0,
			'auto_deploy_on_build'=> isset( $input['auto_deploy_on_build'] ) ? 1 : 0,
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
		?>
		<div class="wrap rwsb-wrap">
			<h1>ReactWoo Static Builder</h1>
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
						<th scope="row">Pro Features</th>
						<td>
							<label><input type="checkbox" name="rwsb_settings[pro_enabled]" value="1" <?php checked( 1, (int) $opts['pro_enabled'] ); ?>> Enable Pro (license required)</label>
							<br>
							<label><input type="checkbox" name="rwsb_settings[auto_deploy_on_build]" value="1" <?php checked( 1, (int) $opts['auto_deploy_on_build'] ); ?> <?php disabled( (int) $opts['pro_enabled'], 0 ); ?>> Auto-deploy to Cloud after builds</label>
							<?php if ( (int) $opts['pro_enabled'] === 0 ) : ?>
								<p><small>Upgrade to Pro to use external deployments and auto-deploy.</small></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Cloud Hosting</th>
						<td>
							<select name="rwsb_settings[hosting_provider]">
								<option value="" <?php selected( $opts['hosting_provider'], '' ); ?>>Not connected</option>
								<option value="cloudflare" <?php selected( $opts['hosting_provider'], 'cloudflare' ); ?>>Cloudflare Pages/Workers</option>
								<option value="vercel" <?php selected( $opts['hosting_provider'], 'vercel' ); ?>>Vercel</option>
								<option value="netlify" <?php selected( $opts['hosting_provider'], 'netlify' ); ?>>Netlify</option>
							</select>
							<?php if ( (int) $opts['hosting_connected'] === 1 && ! empty( $opts['hosting_manage_url'] ) ) : ?>
								<p><span style="color:green">Connected</span> — <a target="_blank" rel="noopener" href="<?php echo esc_url( $opts['hosting_manage_url'] ); ?>">Manage</a></p>
							<?php else: ?>
								<p><small>Choose a provider and save, then Connect below.</small></p>
							<?php endif; ?>
						</td>
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'rwsb_test_webhook' ); ?>
				<input type="hidden" name="action" value="rwsb_test_webhook">
				<?php submit_button( 'Send Test Webhook', 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'rwsb_connect_hosting' ); ?>
				<input type="hidden" name="action" value="rwsb_connect_hosting">
				<?php if ( ! empty( $opts['hosting_provider'] ) ) : ?>
					<?php if ( (int) $opts['pro_enabled'] === 1 ) : ?>
						<?php submit_button( 'Connect to ' . ucfirst( $opts['hosting_provider'] ), 'primary', 'submit', false ); ?>
					<?php else: ?>
						<?php submit_button( 'Connect (Pro required)', 'secondary', 'submit', false, [ 'disabled' => 'disabled' ] ); ?>
					<?php endif; ?>
				<?php else: ?>
					<?php submit_button( 'Connect to Cloud Hosting', 'secondary', 'submit', false, [ 'disabled' => 'disabled' ] ); ?>
				<?php endif; ?>
			</form>

			<?php if ( (int) $opts['hosting_connected'] === 1 && ! empty( $opts['hosting_provider'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<?php wp_nonce_field( 'rwsb_deploy_cloud' ); ?>
					<input type="hidden" name="action" value="rwsb_deploy_cloud">
					<?php if ( (int) $opts['pro_enabled'] === 1 ) : ?>
						<?php submit_button( 'Deploy to ' . ucfirst( $opts['hosting_provider'] ), 'primary', 'submit', false ); ?>
					<?php else: ?>
						<?php submit_button( 'Deploy (Pro required)', 'secondary', 'submit', false, [ 'disabled' => 'disabled' ] ); ?>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rwsb_build_all' ); ?>
				<input type="hidden" name="action" value="rwsb_build_all">
				<?php submit_button( 'Rebuild Everything Now', 'secondary' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'rwsb_export_zip' ); ?>
				<input type="hidden" name="action" value="rwsb_export_zip">
				<?php submit_button( 'Export Static ZIP', 'secondary' ); ?>
			</form>

			<p><small>Storage: <code><?php echo esc_html( RWSB_STORE_DIR ); ?></code></small></p>
		</div>
		<?php
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'toplevel_page_rwsb' ) return;
		$opts = rwsb_get_settings();
		if ( (int) ( $opts['pro_enabled'] ?? 0 ) === 0 ) {
			echo '<div class="notice notice-info"><p><strong>Pro features</strong> are disabled. Enable Pro to use external deployments and auto-deploy.</p></div>';
		}
		if ( (int) ( $opts['pro_enabled'] ?? 0 ) === 1 && ! empty( $opts['hosting_provider'] ) && (int) ( $opts['hosting_connected'] ?? 0 ) !== 1 ) {
			echo '<div class="notice notice-warning"><p>Cloud Hosting provider selected but not connected. Click <em>Connect</em> to authorize.</p></div>';
		}
		if ( ! rwsb_is_store_writable() ) {
			echo '<div class="notice notice-error"><p><strong>Storage not writable:</strong> ' . esc_html( RWSB_STORE_DIR ) . ' — please check permissions.</p></div>';
		}
	}

	public function handle_connect_hosting(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_connect_hosting' );
		$opts = rwsb_get_settings();
		$provider = sanitize_key( $opts['hosting_provider'] ?? '' );
		if ( $provider === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&connect=missing_provider' ) );
			exit;
		}
		$url = rwsb_build_connect_url( $provider );
		if ( $url === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&connect=error' ) );
			exit;
		}
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_build_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_build_all' );
		RWSB_Builder::build_all();
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&built=1' ) );
		exit;
	}

	public function handle_deploy_cloud(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_deploy_cloud' );
		$opts = rwsb_get_settings();
		$provider = sanitize_key( $opts['hosting_provider'] ?? '' );
		if ( $provider === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&deploy=missing_provider' ) );
			exit;
		}
		// Use existing webhook mechanism if set; otherwise, call server.reactwoo.com deploy endpoint (placeholder link)
		$hook = trim( (string) $opts['webhook_url'] );
		if ( $hook !== '' ) {
			wp_remote_post( $hook, [
				'timeout' => 8,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode([ 'event' => 'rwsb.deploy', 'provider' => $provider, 'site' => home_url(), 'installId' => rwsb_get_install_id() ]),
			] );
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&deploy=hooked' ) );
			exit;
		}
		$deploy_url = 'https://server.reactwoo.com/api/v1/hosting/deploy?provider=' . rawurlencode( $provider ) . '&site=' . rawurlencode( home_url() ) . '&installId=' . rawurlencode( rwsb_get_install_id() );
		wp_safe_redirect( $deploy_url );
		exit;
	}

	public function handle_test_webhook(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_test_webhook' );
		$opts = rwsb_get_settings();
		$hook = trim( (string) $opts['webhook_url'] );
		if ( $hook === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&test=missing_webhook' ) );
			exit;
		}
		wp_remote_post( $hook, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode([ 'event' => 'rwsb.test', 'site' => home_url(), 'installId' => rwsb_get_install_id() ]),
		] );
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&test=sent' ) );
		exit;
	}

	public function handle_export_zip(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_export_zip' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&export=zip_unavailable' ) );
			exit;
		}
		$uploads = wp_upload_dir();
		$tmp = trailingslashit( $uploads['basedir'] ) . 'rwsb-static-' . time() . '.zip';
		if ( ! rwsb_zip_static_store( $tmp ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&export=failed' ) );
			exit;
		}
		// Force download
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="rwsb-static.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}
}

new RWSB_Admin();

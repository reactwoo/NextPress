<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_rwsb_build_all', [ $this, 'handle_build_all' ] );
		add_action( 'admin_post_rwsb_local_export', [ $this, 'handle_local_export' ] );
		add_action( 'admin_post_rwsb_cloud_export', [ $this, 'handle_cloud_export' ] );
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
			'provider'       => in_array( ($input['provider'] ?? 'cloudflare'), ['cloudflare','netlify','vercel','none'], true ) ? $input['provider'] : 'cloudflare',
			'cloud_api_url'  => esc_url_raw( $input['cloud_api_url'] ?? '' ),
			'license_token'  => sanitize_text_field( $input['license_token'] ?? '' ),
			'webhook_url'    => esc_url_raw( $input['webhook_url'] ?? '' ),
			'webhook_mode'   => in_array( ($input['webhook_mode'] ?? 'debounced'), ['off','per_build','debounced'], true ) ? $input['webhook_mode'] : 'debounced',
			'deploy_debounce_sec' => max( 0, (int) ( $input['deploy_debounce_sec'] ?? 60 ) ),
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
						<th scope="row">Provider</th>
						<td>
							<select name="rwsb_settings[provider]">
								<option value="cloudflare" <?php selected( $opts['provider'], 'cloudflare' ); ?>>Cloudflare Pages (default)</option>
								<option value="netlify" <?php selected( $opts['provider'], 'netlify' ); ?>>Netlify</option>
								<option value="vercel" <?php selected( $opts['provider'], 'vercel' ); ?>>Vercel</option>
								<option value="none" <?php selected( $opts['provider'], 'none' ); ?>>None / Custom</option>
							</select>
							<p class="description">Choose the deployment target for external builds. Cloudflare is the most cost-efficient default.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Cloud API URL</th>
						<td>
							<input style="width:480px" name="rwsb_settings[cloud_api_url]" type="url" value="<?php echo esc_attr( $opts['cloud_api_url'] ); ?>"> <small>e.g. https://api.reactwoo.com</small>
						</td>
					</tr>
					<tr>
						<th scope="row">License Token</th>
						<td>
							<input style="width:480px" name="rwsb_settings[license_token]" type="text" value="<?php echo esc_attr( $opts['license_token'] ); ?>"> <small>JWT from license.reactwoo.com</small>
						</td>
					</tr>
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
						<td>
							<input style="width:480px" name="rwsb_settings[webhook_url]" type="url" value="<?php echo esc_attr( $opts['webhook_url'] ); ?>"> <small>Optional build hook (Netlify/Vercel/Custom)</small>
							<p style="margin-top:8px">
								<label>Webhook Mode
									<select name="rwsb_settings[webhook_mode]">
										<option value="off" <?php selected( $opts['webhook_mode'], 'off' ); ?>>Off (no pings)</option>
										<option value="per_build" <?php selected( $opts['webhook_mode'], 'per_build' ); ?>>Per build (immediate)</option>
										<option value="debounced" <?php selected( $opts['webhook_mode'], 'debounced' ); ?>>Debounced (batch to reduce costs)</option>
									</select>
								</label>
							</p>
							<p>
								<label>Debounce Window (seconds)
									<input name="rwsb_settings[deploy_debounce_sec]" type="number" min="0" step="1" value="<?php echo esc_attr( (int) $opts['deploy_debounce_sec'] ); ?>">
								</label>
								<small>Combine multiple content updates into a single webhook to avoid over-triggering builds.</small>
							</p>
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rwsb_build_all' ); ?>
				<input type="hidden" name="action" value="rwsb_build_all">
				<?php submit_button( 'Rebuild Everything Now', 'secondary' ); ?>
			</form>

			<p><small>Storage: <code><?php echo esc_html( RWSB_STORE_DIR ); ?></code></small></p>

			<hr>

			<h2>Local Export (Next.js ZIP)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rwsb_local_export' ); ?>
				<input type="hidden" name="action" value="rwsb_local_export">
				<p>Select published pages to include:</p>
				<div style="max-height:180px;overflow:auto;border:1px solid #e5e5e5;padding:8px;width:480px;">
					<?php
						$pages = get_posts([ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ]);
						foreach ( $pages as $p ) {
							echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="rwsb_export_ids[]" value="' . (int) $p->ID . '"> ' . esc_html( get_the_title( $p ) ) . '</label>';
						}
					?>
				</div>
				<?php submit_button( 'Download Next.js ZIP', 'primary' ); ?>
			</form>

			<hr>

			<h2>Cloud Export</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rwsb_cloud_export' ); ?>
				<input type="hidden" name="action" value="rwsb_cloud_export">
				<p>Select published pages to include:</p>
				<div style="max-height:180px;overflow:auto;border:1px solid #e5e5e5;padding:8px;width:480px;">
					<?php
						$pages = get_posts([ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ]);
						foreach ( $pages as $p ) {
							echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="rwsb_export_ids[]" value="' . (int) $p->ID . '"> ' . esc_html( get_the_title( $p ) ) . '</label>';
						}
					?>
				</div>
				<p>
					<label>Build provider:
						<select name="rwsb_build_provider">
							<option value="vercel">Vercel</option>
							<option value="netlify">Netlify</option>
						</select>
					</label>
				</p>
				<?php submit_button( 'Send to Cloud Export', 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_build_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_build_all' );
		RWSB_Builder::build_all();
		wp_safe_redirect( admin_url( 'admin.php?page=rwsb&built=1' ) );
		exit;
	}

	public function handle_local_export(): void {
		RWSB_Exporter::handle_local_export();
	}

	public function handle_cloud_export(): void {
		RWSB_Exporter::handle_cloud_export();
	}
}

new RWSB_Admin();

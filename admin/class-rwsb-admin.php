<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_rwsb_build_all', [ $this, 'handle_build_all' ] );
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
}

new RWSB_Admin();

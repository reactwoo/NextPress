<?php
/**
 * Plugin Name: ReactWoo Static Builder
 * Description: Next.js-style static rendering for WordPress. Builds static HTML for pages/products and serves instantly. Auto rebuilds on content changes.
 * Version: 1.0.0
 * Author: ReactWoo
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: reactwoo-static-builder
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RWSB_VERSION', '1.0.0' );
define( 'RWSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'RWSB_URL', plugin_dir_url( __FILE__ ) );
define( 'RWSB_STORE_DIR', WP_CONTENT_DIR . '/rwsb-static' );
define( 'RWSB_STORE_URL', content_url( 'rwsb-static' ) );

require_once RWSB_DIR . 'includes/helpers.php';
require_once RWSB_DIR . 'includes/class-rwsb-logger.php';
require_once RWSB_DIR . 'includes/class-rwsb-optimizer.php';
require_once RWSB_DIR . 'includes/class-rwsb-renderer.php';
require_once RWSB_DIR . 'includes/class-rwsb-queue.php';
require_once RWSB_DIR . 'includes/class-rwsb-builder.php';
require_once RWSB_DIR . 'includes/class-rwsb-rewrites.php';
require_once RWSB_DIR . 'includes/class-rwsb-cron.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once RWSB_DIR . 'includes/class-rwsb-cli.php';
}

if ( is_admin() ) {
	require_once RWSB_DIR . 'admin/class-rwsb-admin.php';
}

register_activation_hook( __FILE__, function() {
	if ( ! file_exists( RWSB_STORE_DIR ) ) {
		wp_mkdir_p( RWSB_STORE_DIR );
	}
	// Prime options if missing.
	$defaults = [
		'enabled'        => 1,
		'post_types'     => ['page','product'],
		'ttl'            => 0, // 0 = infinite
		'serve_static'   => 1,
		'respect_logged' => 1,
		'bypass_param'   => 'rwsb',
		'webhook_url'    => '',
		'headers'        => [
			'Cache-Control' => 'public, max-age=31536000, stale-while-revalidate=30',
			'X-Powered-By'  => 'ReactWoo Static Builder'
		],
	];
	add_option( 'rwsb_settings', $defaults );
	RWSB_Cron::register_events();
} );

register_deactivation_hook( __FILE__, function() {
	RWSB_Cron::clear_events();
} );

add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( 'reactwoo-static-builder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// Serve static ASAP in front-end.
add_action( 'template_redirect', [ 'RWSB_Rewrites', 'maybe_serve_static' ], 0 );

// Hook content changes to rebuilds.
add_action( 'save_post', [ 'RWSB_Builder', 'queue_build_for_post' ], 20, 3 );
add_action( 'deleted_post', [ 'RWSB_Builder', 'purge_for_post_id' ], 10, 1 );
add_action( 'transition_post_status', [ 'RWSB_Builder', 'maybe_purge_on_status_change' ], 10, 3 );

// WooCommerce product stock/status changes.
add_action( 'woocommerce_product_set_stock_status', [ 'RWSB_Builder', 'queue_build_for_product_id' ], 10, 3 );
add_action( 'woocommerce_update_product', [ 'RWSB_Builder', 'queue_build_for_product_id' ], 10, 1 );

// Menus and terms can influence templates/routes.
add_action( 'wp_update_nav_menu', [ 'RWSB_Builder', 'queue_build_all' ], 10, 1 );
add_action( 'edited_terms', [ 'RWSB_Builder', 'queue_build_archives' ], 10, 2 );

// Cron: async builders.
add_action( 'rwsb_build_single', [ 'RWSB_Builder', 'build_single' ], 10, 2 );
add_action( 'rwsb_build_all', [ 'RWSB_Builder', 'build_all' ], 10, 0 );
add_action( 'rwsb_build_archives', [ 'RWSB_Builder', 'build_archives' ], 10, 0 );
add_action( 'rwsb_process_queue', [ 'RWSB_Queue', 'process_queue' ], 10, 0 );

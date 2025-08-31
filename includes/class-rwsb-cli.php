include<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_CLI {
	/**
	 * Rebuild all static pages/products and archives.
	 *
	 * ## EXAMPLES
	 *     wp rwsb build-all
	 */
	public function build_all( $args, $assoc_args ) {
		\WP_CLI::line( 'Building all static pages…' );
		RWSB_Builder::build_all();
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Rebuild one URL or post ID.
	 *
	 * ## OPTIONS
	 * [--id=<id>]
	 * : The post ID to rebuild.
	 *
	 * [--url=<url>]
	 * : The absolute URL to rebuild.
	 *
	 * ## EXAMPLES
	 *     wp rwsb build --id=123
	 *     wp rwsb build --url="https://example.com/sample-page/"
	 */
	public function build( $args, $assoc_args ) {
		if ( isset( $assoc_args['id'] ) ) {
			$id  = (int) $assoc_args['id'];
			$url = get_permalink( $id );
			if ( ! $url ) {
				\WP_CLI::error( 'Invalid post ID' );
			}
			RWSB_Builder::build_single( $id, $url );
			\WP_CLI::success( 'Built ' . $url );
			return;
		}
		if ( isset( $assoc_args['url'] ) ) {
			$url = esc_url_raw( $assoc_args['url'] );
			RWSB_Builder::build_single( 0, $url );
			\WP_CLI::success( 'Built ' . $url );
			return;
		}
		\WP_CLI::error( 'Provide --id or --url.' );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'rwsb', 'RWSB_CLI' );
}

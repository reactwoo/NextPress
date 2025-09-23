<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Builder {

	public static function queue_build_for_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! rwsb_is_included_post_type( $post->post_type ) ) return;
		if ( in_array( $post->post_status, ['draft','auto-draft','trash'], true ) ) return;

		$delay = 10; // Give time for meta/blocks/hooks to finish.
		wp_schedule_single_event( time() + $delay, 'rwsb_build_single', [ $post_id, get_permalink( $post_id ) ] );
	}

	public static function queue_build_for_product_id( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) return;
		$post_type = get_post_type( $product_id );
		if ( $post_type !== 'product' ) return;
		$url = get_permalink( $product_id );
		wp_schedule_single_event( time() + 10, 'rwsb_build_single', [ $product_id, $url ] );
	}

	public static function maybe_purge_on_status_change( $new, $old, $post ): void {
		if ( ! $post instanceof WP_Post ) return;
		if ( ! rwsb_is_included_post_type( $post->post_type ) ) return;
		if ( $new !== 'publish' ) {
			self::purge_for_post_id( $post->ID );
		}
	}

	public static function purge_for_post_id( int $post_id ): void {
		$url  = get_permalink( $post_id );
		if ( ! $url ) return;
		$file = rwsb_store_path_for_url( $url );
		self::delete_file( $file );
	}

	protected static function delete_file( string $file ): void {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
		// Clean empty directories up the tree.
		$dir = dirname( $file );
		while ( $dir && $dir !== RWSB_STORE_DIR && is_dir( $dir ) ) {
			$items = glob( $dir . '/*' );
			if ( empty( $items ) ) {
				@rmdir( $dir );
				$dir = dirname( $dir );
			} else {
				break;
			}
		}
	}

	public static function build_single( int $post_id, string $url ): void {
		$settings = rwsb_get_settings();
		if ( (int) $settings['enabled'] !== 1 ) return;

		// Fetch fully rendered HTML via HTTP to include theme/plugins output.
		// Bypass param ensures dynamic mode (not served static while building).
		$bypass = $settings['bypass_param'] ?: 'rwsb';
		$sep    = str_contains( $url, '?' ) ? '&' : '?';
		$build_url = $url . $sep . $bypass . '=miss';

		$result = rwsb_http_get( $build_url );
		if ( $result['code'] !== 200 || empty( $result['body'] ) ) {
			error_log( '[RWSB] Build failed for ' . $url . ' code=' . $result['code'] . ' error=' . $result['error'] );
			return;
		}

		$file = rwsb_store_path_for_url( $url );
		rwsb_mkdir_for_file( $file );

		// Inject marker and canonical.
		$html = self::inject_build_meta( $result['body'], $url );

		file_put_contents( $file, $html );

		self::maybe_ping_webhook( $url );
	}

	public static function build_all(): void {
		$settings = rwsb_get_settings();
		$types    = (array) $settings['post_types'];

		$query = new WP_Query([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		if ( $query->posts ) {
			foreach ( $query->posts as $pid ) {
				$url = get_permalink( $pid );
				if ( $url ) {
					self::build_single( (int) $pid, $url );
				}
			}
		}
		self::build_archives();
	}

	public static function queue_build_all(): void {
		wp_schedule_single_event( time() + 15, 'rwsb_build_all', [] );
	}

	public static function build_archives(): void {
		// Home/front page
		$home = home_url( '/' );
		self::build_url( $home );

		// Blog page (if set)
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			self::build_url( get_permalink( $posts_page_id ) );
		}

		// Term archives for included post types
		$taxes = get_taxonomies( [ 'public' => true ], 'names' );
		foreach ( $taxes as $tax ) {
			$terms = get_terms([ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ]);
			if ( is_wp_error( $terms ) ) continue;
			foreach ( $terms as $term_id ) {
				$term_link = get_term_link( (int) $term_id );
				if ( ! is_wp_error( $term_link ) ) {
					self::build_url( $term_link );
				}
			}
		}
	}

	protected static function build_url( string $url ): void {
		$settings = rwsb_get_settings();
		$bypass   = $settings['bypass_param'] ?: 'rwsb';
		$sep      = str_contains( $url, '?' ) ? '&' : '?';
		$result   = rwsb_http_get( $url . $sep . $bypass . '=miss' );
		if ( $result['code'] !== 200 || empty( $result['body'] ) ) return;
		$file = rwsb_store_path_for_url( $url );
		rwsb_mkdir_for_file( $file );
		$html = self::inject_build_meta( $result['body'], $url );
		file_put_contents( $file, $html );
	}

	protected static function inject_build_meta( string $html, string $canonical ): string {
		$marker = "\n<!-- Built by ReactWoo Static Builder " . esc_html( date( 'c' ) ) . " -->\n";
		$has_head = stripos( $html, '</head>' );
		if ( $has_head !== false ) {
			$tag = '<link rel="canonical" href="' . esc_url( $canonical ) . '"/>' . $marker;
			return substr_replace( $html, $tag, $has_head, 0 );
		}
		return $html . $marker;
	}

	protected static function maybe_ping_webhook( string $url ): void {
		$settings = rwsb_get_settings();
		$hook = trim( (string) $settings['webhook_url'] );
		$mode = (string) ( $settings['webhook_mode'] ?? 'debounced' );
		$debounce = (int) ( $settings['deploy_debounce_sec'] ?? 60 );
		if ( $hook === '' || $mode === 'off' ) return;
		if ( $mode === 'per_build' ) {
			wp_remote_post( $hook, [
				'timeout' => 8,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode([ 'event' => 'rwsb.build', 'url' => $url, 'site' => home_url() ]),
			] );
			return;
		}
		// Debounced: schedule a single webhook send within the window.
		if ( ! wp_next_scheduled( 'rwsb_send_webhook' ) ) {
			wp_schedule_single_event( time() + max( 1, $debounce ), 'rwsb_send_webhook', [] );
		}
	}

	public static function send_debounced_webhook(): void {
		$settings = rwsb_get_settings();
		$hook = trim( (string) $settings['webhook_url'] );
		if ( $hook === '' ) return;
		wp_remote_post( $hook, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode([ 'event' => 'rwsb.deploy', 'site' => home_url() ]),
		] );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Builder {

	public static function queue_build_for_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! rwsb_is_included_post_type( $post->post_type ) ) return;
		if ( in_array( $post->post_status, ['draft','auto-draft','trash'], true ) ) return;

		$url = get_permalink( $post_id );
		if ( $url ) {
			RWSB_Queue::add_single( $post_id, $url, 10 );
		}
	}

	public static function queue_build_for_product_id( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) return;
		$post_type = get_post_type( $product_id );
		if ( $post_type !== 'product' ) return;
		$url = get_permalink( $product_id );
		if ( $url ) {
			RWSB_Queue::add_single( $product_id, $url, 5 ); // Higher priority for products
		}
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

		RWSB_Logger::log( 'single', $url, 'started', 'Build started for post ID: ' . $post_id );

		// Fetch fully rendered HTML via HTTP to include theme/plugins output.
		// Bypass param ensures dynamic mode (not served static while building).
		$bypass = $settings['bypass_param'] ?: 'rwsb';
		$sep    = str_contains( $url, '?' ) ? '&' : '?';
		$build_url = $url . $sep . $bypass . '=miss';

		$result = rwsb_http_get( $build_url );
		if ( $result['code'] !== 200 || empty( $result['body'] ) ) {
			$error_msg = 'Build failed for ' . $url . ' code=' . $result['code'] . ' error=' . $result['error'];
			error_log( '[RWSB] ' . $error_msg );
			RWSB_Logger::log( 'single', $url, 'error', $error_msg, [ 'post_id' => $post_id, 'http_code' => $result['code'] ] );
			return;
		}

		$file = rwsb_store_path_for_url( $url );
		rwsb_mkdir_for_file( $file );

		// Inject marker and canonical.
		$html = self::inject_build_meta( $result['body'], $url );

		$bytes_written = file_put_contents( $file, $html );
		if ( $bytes_written === false ) {
			$error_msg = 'Failed to write static file for ' . $url;
			error_log( '[RWSB] ' . $error_msg );
			RWSB_Logger::log( 'single', $url, 'error', $error_msg, [ 'post_id' => $post_id, 'file_path' => $file ] );
			return;
		}

		RWSB_Logger::log( 'single', $url, 'success', 'Built successfully', [ 
			'post_id' => $post_id, 
			'file_size' => $bytes_written,
			'file_path' => $file 
		] );

		self::maybe_ping_webhook( $url );
	}

	public static function build_all(): void {
		$start_time = time();
		RWSB_Logger::log( 'full', home_url(), 'started', 'Full rebuild started' );

		$settings = rwsb_get_settings();
		$types    = (array) $settings['post_types'];

		$query = new WP_Query([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		$built_count = 0;
		if ( $query->posts ) {
			foreach ( $query->posts as $pid ) {
				$url = get_permalink( $pid );
				if ( $url ) {
					self::build_single( (int) $pid, $url );
					$built_count++;
				}
			}
		}
		
		self::build_archives();
		
		$duration = time() - $start_time;
		RWSB_Logger::log( 'full', home_url(), 'success', 'Full rebuild completed', [
			'posts_built' => $built_count,
			'duration' => $duration
		] );
	}

	public static function queue_build_all(): void {
		RWSB_Queue::add_full_rebuild( 30 );
	}

	public static function queue_build_archives(): void {
		RWSB_Queue::add_archives( 20 );
	}

	public static function build_archives(): void {
		$start_time = time();
		RWSB_Logger::log( 'archives', home_url(), 'started', 'Archive rebuild started' );

		$built_count = 0;

		// Home/front page
		$home = home_url( '/' );
		self::build_url( $home );
		$built_count++;

		// Blog page (if set)
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			self::build_url( get_permalink( $posts_page_id ) );
			$built_count++;
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
					$built_count++;
				}
			}
		}

		// Date archives (yearly, monthly)
		$built_count += self::build_date_archives();

		// Author archives
		$built_count += self::build_author_archives();

		// Custom post type archives
		$built_count += self::build_post_type_archives();

		$duration = time() - $start_time;
		RWSB_Logger::log( 'archives', home_url(), 'success', 'Archive rebuild completed', [
			'archives_built' => $built_count,
			'duration' => $duration
		] );
	}

	/**
	 * Build date archives (yearly and monthly).
	 */
	protected static function build_date_archives(): int {
		global $wpdb;
		
		// Get distinct years and months from published posts
		$settings = rwsb_get_settings();
		$post_types = (array) $settings['post_types'];
		$post_types_sql = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";
		
		$dates = $wpdb->get_results( "
			SELECT DISTINCT YEAR(post_date) as year, MONTH(post_date) as month 
			FROM {$wpdb->posts} 
			WHERE post_status = 'publish' 
			AND post_type IN ($post_types_sql)
			ORDER BY year DESC, month DESC
		" );

		$count = 0;
		foreach ( $dates as $date ) {
			// Yearly archive
			$year_link = get_year_link( $date->year );
			if ( $year_link ) {
				self::build_url( $year_link );
				$count++;
			}

			// Monthly archive
			$month_link = get_month_link( $date->year, $date->month );
			if ( $month_link ) {
				self::build_url( $month_link );
				$count++;
			}
		}
		
		return $count;
	}

	/**
	 * Build author archives for authors who have published posts.
	 */
	protected static function build_author_archives(): int {
		$settings = rwsb_get_settings();
		$post_types = (array) $settings['post_types'];
		
		$authors = get_users([
			'who' => 'authors',
			'has_published_posts' => $post_types,
			'fields' => 'ID',
		]);

		$count = 0;
		foreach ( $authors as $author_id ) {
			$author_link = get_author_posts_url( $author_id );
			if ( $author_link ) {
				self::build_url( $author_link );
				$count++;
			}
		}
		
		return $count;
	}

	/**
	 * Build custom post type archives.
	 */
	protected static function build_post_type_archives(): int {
		$settings = rwsb_get_settings();
		$post_types = (array) $settings['post_types'];
		
		$count = 0;
		foreach ( $post_types as $post_type ) {
			$post_type_obj = get_post_type_object( $post_type );
			if ( $post_type_obj && $post_type_obj->has_archive ) {
				$archive_link = get_post_type_archive_link( $post_type );
				if ( $archive_link ) {
					self::build_url( $archive_link );
					$count++;
				}
			}
		}
		
		return $count;
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
		if ( $hook === '' ) return;
		wp_remote_post( $hook, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode([ 'event' => 'rwsb.build', 'url' => $url, 'site' => home_url() ]),
		] );
	}
}

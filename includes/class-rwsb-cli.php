<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_CLI {
	/**
	 * Rebuild all static pages/products and archives.
	 *
	 * ## OPTIONS
	 * [--queue]
	 * : Add to build queue instead of building immediately.
	 *
	 * ## EXAMPLES
	 *     wp rwsb build-all
	 *     wp rwsb build-all --queue
	 */
	public function build_all( $args, $assoc_args ) {
		if ( isset( $assoc_args['queue'] ) ) {
			RWSB_Queue::add_full_rebuild( 1 );
			\WP_CLI::success( 'Full rebuild queued.' );
			return;
		}
		
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

	/**
	 * Show queue status and recent build log.
	 *
	 * ## EXAMPLES
	 *     wp rwsb status
	 */
	public function status( $args, $assoc_args ) {
		$queue_status = RWSB_Queue::get_status();
		$stats = RWSB_Logger::get_stats();
		
		\WP_CLI::line( 'Queue Status:' );
		\WP_CLI::line( '  Total Tasks: ' . $queue_status['total'] );
		\WP_CLI::line( '  Processing: ' . ( $queue_status['processing'] ? 'Yes' : 'No' ) );
		\WP_CLI::line( '  Single Builds: ' . $queue_status['counts']['single'] );
		\WP_CLI::line( '  Archive Builds: ' . $queue_status['counts']['archives'] );
		\WP_CLI::line( '  Full Rebuilds: ' . $queue_status['counts']['full'] );
		
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Build Statistics:' );
		\WP_CLI::line( '  Total Builds: ' . $stats['total_builds'] );
		\WP_CLI::line( '  Successful: ' . $stats['successful_builds'] );
		\WP_CLI::line( '  Failed: ' . $stats['failed_builds'] );
		
		if ( $stats['last_build'] ) {
			\WP_CLI::line( '  Last Build: ' . human_time_diff( $stats['last_build']['timestamp'] ) . ' ago (' . $stats['last_build']['status'] . ')' );
		}
	}

	/**
	 * Clear the build queue.
	 *
	 * ## EXAMPLES
	 *     wp rwsb clear-queue
	 */
	public function clear_queue( $args, $assoc_args ) {
		RWSB_Queue::clear_queue();
		\WP_CLI::success( 'Build queue cleared.' );
	}

	/**
	 * Process the build queue manually.
	 *
	 * ## EXAMPLES
	 *     wp rwsb process-queue
	 */
	public function process_queue( $args, $assoc_args ) {
		\WP_CLI::line( 'Processing build queue...' );
		RWSB_Queue::process_queue();
		\WP_CLI::success( 'Queue processing completed.' );
	}

	/**
	 * Build all items of a specific post type.
	 *
	 * ## OPTIONS
	 * <post_type>
	 * : The post type to rebuild (e.g., page, post, product).
	 *
	 * [--queue]
	 * : Add to build queue instead of building immediately.
	 *
	 * ## EXAMPLES
	 *     wp rwsb build-post-type product
	 *     wp rwsb build-post-type page --queue
	 */
	public function build_post_type( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a post type.' );
		}
		
		$post_type = sanitize_key( $args[0] );
		$post_type_obj = get_post_type_object( $post_type );
		
		if ( ! $post_type_obj ) {
			\WP_CLI::error( "Post type '{$post_type}' does not exist." );
		}
		
		if ( isset( $assoc_args['queue'] ) ) {
			RWSB_Queue::add_post_type( $post_type, 5 );
			\WP_CLI::success( "All {$post_type_obj->labels->name} queued for rebuild." );
			return;
		}
		
		\WP_CLI::line( "Building all {$post_type_obj->labels->name}..." );
		
		$query = new WP_Query([
			'post_type' => $post_type,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		]);

		$built = 0;
		$failed = 0;
		
		if ( $query->posts ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Building', count( $query->posts ) );
			
			foreach ( $query->posts as $post_id ) {
				$url = get_permalink( $post_id );
				if ( $url ) {
					$success = RWSB_Builder::build_single_with_result( (int) $post_id, $url );
					if ( $success ) {
						$built++;
					} else {
						$failed++;
					}
				}
				$progress->tick();
			}
			
			$progress->finish();
		}
		
		\WP_CLI::success( "Built {$built} {$post_type_obj->labels->name}" . ( $failed > 0 ? " ({$failed} failed)" : '' ) );
	}

	/**
	 * Retry failed builds for a specific post type.
	 *
	 * ## OPTIONS
	 * <post_type>
	 * : The post type to retry failures for.
	 *
	 * ## EXAMPLES
	 *     wp rwsb retry-failures product
	 */
	public function retry_failures( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a post type.' );
		}
		
		$post_type = sanitize_key( $args[0] );
		$retried = RWSB_Queue::retry_post_type_failures( $post_type );
		
		if ( $retried > 0 ) {
			\WP_CLI::success( "Retried {$retried} failed {$post_type} builds." );
		} else {
			\WP_CLI::line( "No failed {$post_type} builds to retry." );
		}
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'rwsb', 'RWSB_CLI' );
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Build logging and status tracking system.
 */
class RWSB_Logger {
	const LOG_OPTION = 'rwsb_build_log';
	const MAX_LOG_ENTRIES = 100;

	/**
	 * Log a build event.
	 */
	public static function log( string $type, string $url, string $status, string $message = '', array $meta = [] ): void {
		$log = self::get_log();
		
		$entry = [
			'timestamp' => time(),
			'type' => $type, // 'single', 'archives', 'full'
			'url' => $url,
			'status' => $status, // 'success', 'error', 'started'
			'message' => $message,
			'meta' => $meta,
		];
		
		// Add to beginning of log
		array_unshift( $log, $entry );
		
		// Keep only recent entries
		$log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );
		
		update_option( self::LOG_OPTION, $log );
	}

	/**
	 * Log a post-type specific build event.
	 */
	public static function log_post_type_build( string $post_type, string $url, string $status, string $message = '', array $meta = [] ): void {
		// Log to main log
		self::log( 'single', $url, $status, $message, array_merge( $meta, [ 'post_type' => $post_type ] ) );
		
		// Log to post-type specific log
		$post_type_log = self::get_post_type_log( $post_type );
		
		$entry = [
			'timestamp' => time(),
			'url' => $url,
			'status' => $status,
			'message' => $message,
			'meta' => $meta,
		];
		
		array_unshift( $post_type_log, $entry );
		$post_type_log = array_slice( $post_type_log, 0, 50 ); // Smaller limit per post type
		
		update_option( 'rwsb_log_' . $post_type, $post_type_log );
	}

	/**
	 * Get post-type specific log.
	 */
	public static function get_post_type_log( string $post_type ): array {
		return get_option( 'rwsb_log_' . $post_type, [] );
	}

	/**
	 * Get post-type statistics.
	 */
	public static function get_post_type_stats( string $post_type ): array {
		$log = self::get_post_type_log( $post_type );
		$stats = [
			'total_builds' => 0,
			'successful_builds' => 0,
			'failed_builds' => 0,
			'last_build' => null,
			'last_success' => null,
			'last_error' => null,
			'current_failures' => 0,
		];

		foreach ( $log as $entry ) {
			if ( $entry['status'] === 'success' ) {
				$stats['successful_builds']++;
				if ( ! $stats['last_success'] ) {
					$stats['last_success'] = $entry;
				}
			} elseif ( $entry['status'] === 'error' ) {
				$stats['failed_builds']++;
				if ( ! $stats['last_error'] ) {
					$stats['last_error'] = $entry;
				}
			}
			
			if ( ! $stats['last_build'] ) {
				$stats['last_build'] = $entry;
			}
			
			$stats['total_builds']++;
		}

		// Count current failures (recent failed builds without subsequent success)
		$recent_failures = array_slice( $log, 0, 10 );
		foreach ( $recent_failures as $entry ) {
			if ( $entry['status'] === 'error' ) {
				$stats['current_failures']++;
			} elseif ( $entry['status'] === 'success' ) {
				break; // Stop at first success
			}
		}

		return $stats;
	}

	/**
	 * Get overview statistics for all post types.
	 */
	public static function get_post_type_overview(): array {
		$settings = rwsb_get_settings();
		$post_types = (array) $settings['post_types'];
		$overview = [];
		
		foreach ( $post_types as $post_type ) {
			$overview[$post_type] = self::get_post_type_stats( $post_type );
		}
		
		return $overview;
	}

	/**
	 * Get build log.
	 */
	public static function get_log(): array {
		return get_option( self::LOG_OPTION, [] );
	}

	/**
	 * Clear build log.
	 */
	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Get build statistics.
	 */
	public static function get_stats(): array {
		$log = self::get_log();
		$stats = [
			'total_builds' => 0,
			'successful_builds' => 0,
			'failed_builds' => 0,
			'last_build' => null,
			'last_success' => null,
			'last_error' => null,
		];

		foreach ( $log as $entry ) {
			if ( $entry['status'] === 'success' ) {
				$stats['successful_builds']++;
				if ( ! $stats['last_success'] ) {
					$stats['last_success'] = $entry;
				}
			} elseif ( $entry['status'] === 'error' ) {
				$stats['failed_builds']++;
				if ( ! $stats['last_error'] ) {
					$stats['last_error'] = $entry;
				}
			}
			
			if ( ! $stats['last_build'] ) {
				$stats['last_build'] = $entry;
			}
			
			$stats['total_builds']++;
		}

		return $stats;
	}

	/**
	 * Get recent log entries for admin display.
	 */
	public static function get_recent_entries( int $limit = 10 ): array {
		$log = self::get_log();
		return array_slice( $log, 0, $limit );
	}
}
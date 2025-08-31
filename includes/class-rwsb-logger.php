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
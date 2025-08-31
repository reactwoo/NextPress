<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Analytics and insights for build performance and failures.
 */
class RWSB_Analytics {

	/**
	 * Analyze failure patterns across post types.
	 */
	public static function analyze_failure_patterns(): array {
		$settings = rwsb_get_settings();
		$post_types = (array) $settings['post_types'];
		$analysis = [];
		
		foreach ( $post_types as $post_type ) {
			$log = RWSB_Logger::get_post_type_log( $post_type );
			$analysis[$post_type] = self::analyze_post_type_failures( $log, $post_type );
		}
		
		return $analysis;
	}

	/**
	 * Analyze failures for a specific post type.
	 */
	protected static function analyze_post_type_failures( array $log, string $post_type ): array {
		$failures = array_filter( $log, function( $entry ) {
			return $entry['status'] === 'error';
		} );
		
		$patterns = [
			'http_errors' => 0,
			'timeout_errors' => 0,
			'file_write_errors' => 0,
			'memory_errors' => 0,
			'plugin_conflicts' => 0,
			'common_errors' => [],
		];
		
		foreach ( $failures as $failure ) {
			$message = strtolower( $failure['message'] ?? '' );
			$meta = $failure['meta'] ?? [];
			
			// Categorize error types
			if ( strpos( $message, 'http' ) !== false || ! empty( $meta['http_code'] ) ) {
				$patterns['http_errors']++;
			} elseif ( strpos( $message, 'timeout' ) !== false ) {
				$patterns['timeout_errors']++;
			} elseif ( strpos( $message, 'write' ) !== false || strpos( $message, 'file' ) !== false ) {
				$patterns['file_write_errors']++;
			} elseif ( strpos( $message, 'memory' ) !== false || strpos( $message, 'fatal' ) !== false ) {
				$patterns['memory_errors']++;
			} elseif ( strpos( $message, 'plugin' ) !== false ) {
				$patterns['plugin_conflicts']++;
			}
			
			// Track common error messages
			$error_key = substr( $message, 0, 50 );
			$patterns['common_errors'][$error_key] = ( $patterns['common_errors'][$error_key] ?? 0 ) + 1;
		}
		
		// Sort common errors by frequency
		arsort( $patterns['common_errors'] );
		$patterns['common_errors'] = array_slice( $patterns['common_errors'], 0, 5, true );
		
		return [
			'total_failures' => count( $failures ),
			'patterns' => $patterns,
			'failure_rate' => count( $log ) > 0 ? round( ( count( $failures ) / count( $log ) ) * 100, 1 ) : 0,
			'recent_failures' => array_slice( $failures, 0, 3 ),
		];
	}

	/**
	 * Get performance insights.
	 */
	public static function get_performance_insights(): array {
		$main_log = RWSB_Logger::get_log();
		$insights = [
			'avg_build_time' => 0,
			'slowest_builds' => [],
			'fastest_builds' => [],
			'optimization_impact' => [],
		];
		
		$build_times = [];
		$optimization_data = [];
		
		foreach ( $main_log as $entry ) {
			if ( $entry['status'] === 'success' && ! empty( $entry['meta']['duration'] ) ) {
				$build_times[] = $entry['meta']['duration'];
				
				// Track optimization impact
				if ( ! empty( $entry['meta']['initial_size'] ) && ! empty( $entry['meta']['final_size'] ) ) {
					$reduction = $entry['meta']['initial_size'] - $entry['meta']['final_size'];
					$optimization_data[] = [
						'url' => $entry['url'],
						'reduction' => $reduction,
						'percentage' => round( ( $reduction / $entry['meta']['initial_size'] ) * 100, 1 ),
					];
				}
			}
		}
		
		if ( ! empty( $build_times ) ) {
			$insights['avg_build_time'] = round( array_sum( $build_times ) / count( $build_times ), 2 );
		}
		
		$insights['optimization_impact'] = array_slice( $optimization_data, 0, 10 );
		
		return $insights;
	}

	/**
	 * Generate recommendations based on failure analysis.
	 */
	public static function get_recommendations(): array {
		$failures = self::analyze_failure_patterns();
		$recommendations = [];
		
		foreach ( $failures as $post_type => $analysis ) {
			$patterns = $analysis['patterns'];
			$post_type_recommendations = [];
			
			if ( $patterns['http_errors'] > 2 ) {
				$post_type_recommendations[] = [
					'type' => 'warning',
					'message' => 'High HTTP error rate - check for broken links or server issues',
					'action' => 'Review server logs and verify URLs are accessible'
				];
			}
			
			if ( $patterns['timeout_errors'] > 1 ) {
				$post_type_recommendations[] = [
					'type' => 'warning',
					'message' => 'Timeout errors detected - pages may be too slow to render',
					'action' => 'Consider increasing timeout or optimizing page performance'
				];
			}
			
			if ( $patterns['memory_errors'] > 0 ) {
				$post_type_recommendations[] = [
					'type' => 'error',
					'message' => 'Memory errors detected - insufficient server resources',
					'action' => 'Increase PHP memory limit or reduce batch size'
				];
			}
			
			if ( $patterns['plugin_conflicts'] > 0 ) {
				$post_type_recommendations[] = [
					'type' => 'error',
					'message' => 'Plugin conflicts detected',
					'action' => 'Disable conflicting optimization plugins'
				];
			}
			
			if ( $analysis['failure_rate'] > 20 ) {
				$post_type_recommendations[] = [
					'type' => 'warning',
					'message' => 'High failure rate (' . $analysis['failure_rate'] . '%)',
					'action' => 'Review common error patterns and consider excluding problematic content'
				];
			}
			
			if ( ! empty( $post_type_recommendations ) ) {
				$recommendations[$post_type] = $post_type_recommendations;
			}
		}
		
		return $recommendations;
	}

	/**
	 * Get build health score for a post type.
	 */
	public static function get_health_score( string $post_type ): array {
		$stats = RWSB_Logger::get_post_type_stats( $post_type );
		$queue_status = RWSB_Queue::get_status();
		
		$score = 100;
		$factors = [];
		
		// Failure rate impact
		$total = $stats['total_builds'] ?? 0;
		if ( $total > 0 ) {
			$failure_rate = ( ( $stats['failed_builds'] ?? 0 ) / $total ) * 100;
			if ( $failure_rate > 10 ) {
				$penalty = min( 40, $failure_rate * 2 );
				$score -= $penalty;
				$factors[] = "High failure rate: -{$penalty} points";
			}
		}
		
		// Current failures impact
		$current_failures = count( $queue_status['failed_tasks'][$post_type] ?? [] );
		if ( $current_failures > 0 ) {
			$penalty = min( 30, $current_failures * 10 );
			$score -= $penalty;
			$factors[] = "Current failures: -{$penalty} points";
		}
		
		// Queue backlog impact
		$queued = $queue_status['post_type_counts'][$post_type] ?? 0;
		if ( $queued > 10 ) {
			$penalty = min( 20, ( $queued - 10 ) * 2 );
			$score -= $penalty;
			$factors[] = "Queue backlog: -{$penalty} points";
		}
		
		$score = max( 0, $score );
		
		return [
			'score' => $score,
			'grade' => self::score_to_grade( $score ),
			'factors' => $factors,
		];
	}

	/**
	 * Convert numeric score to letter grade.
	 */
	protected static function score_to_grade( int $score ): string {
		if ( $score >= 90 ) return 'A';
		if ( $score >= 80 ) return 'B';
		if ( $score >= 70 ) return 'C';
		if ( $score >= 60 ) return 'D';
		return 'F';
	}
}
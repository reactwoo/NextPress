<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Smart build strategies for different scenarios and content types.
 */
class RWSB_Strategy {

	/**
	 * Determine optimal build strategy for a post type.
	 */
	public static function get_build_strategy( string $post_type ): array {
		$post_type_obj = get_post_type_object( $post_type );
		$health = RWSB_Analytics::get_health_score( $post_type );
		
		$strategy = [
			'priority' => RWSB_Queue::get_post_type_priority( $post_type ),
			'batch_size' => 10,
			'retry_attempts' => 3,
			'timeout' => 30,
			'optimization_level' => 'standard',
			'second_pass' => true,
		];
		
		// Adjust strategy based on post type
		switch ( $post_type ) {
			case 'product':
				$strategy['priority'] = 1;
				$strategy['batch_size'] = 5; // Smaller batches for complex product pages
				$strategy['timeout'] = 45; // Longer timeout for WooCommerce
				$strategy['optimization_level'] = 'conservative'; // Preserve e-commerce functionality
				break;
				
			case 'page':
				$strategy['priority'] = 5;
				$strategy['optimization_level'] = 'aggressive'; // Pages are typically simpler
				break;
				
			case 'post':
				$strategy['priority'] = 10;
				$strategy['batch_size'] = 15; // Larger batches for simpler blog posts
				break;
				
			case 'attachment':
				$strategy['priority'] = 20;
				$strategy['second_pass'] = false; // Media pages don't need late-loading analysis
				$strategy['optimization_level'] = 'minimal';
				break;
		}
		
		// Adjust based on health score
		if ( $health['score'] < 70 ) {
			$strategy['batch_size'] = max( 3, $strategy['batch_size'] / 2 ); // Smaller batches for problematic types
			$strategy['timeout'] += 15; // More time for struggling content
			$strategy['optimization_level'] = 'conservative'; // Less aggressive optimization
		}
		
		return $strategy;
	}

	/**
	 * Get recommended action for a failed post type.
	 */
	public static function get_failure_action( string $post_type, array $failed_tasks ): string {
		if ( empty( $failed_tasks ) ) {
			return 'none';
		}
		
		$failure_count = count( $failed_tasks );
		$recent_failures = array_slice( $failed_tasks, 0, 3 );
		
		// Analyze recent failure patterns
		$error_types = [];
		foreach ( $recent_failures as $task ) {
			$error = strtolower( $task['last_error'] ?? '' );
			if ( strpos( $error, 'timeout' ) !== false ) {
				$error_types['timeout'] = ( $error_types['timeout'] ?? 0 ) + 1;
			} elseif ( strpos( $error, 'memory' ) !== false ) {
				$error_types['memory'] = ( $error_types['memory'] ?? 0 ) + 1;
			} elseif ( strpos( $error, 'http' ) !== false ) {
				$error_types['http'] = ( $error_types['http'] ?? 0 ) + 1;
			} else {
				$error_types['other'] = ( $error_types['other'] ?? 0 ) + 1;
			}
		}
		
		// Determine best action
		if ( $failure_count > 10 ) {
			return 'investigate'; // Too many failures, needs investigation
		}
		
		if ( ! empty( $error_types['memory'] ) ) {
			return 'reduce_batch'; // Memory issues need smaller batches
		}
		
		if ( ! empty( $error_types['timeout'] ) ) {
			return 'increase_timeout'; // Timeout issues need more time
		}
		
		if ( $failure_count <= 3 ) {
			return 'retry'; // Few failures, safe to retry
		}
		
		return 'selective_retry'; // Moderate failures, retry selectively
	}

	/**
	 * Execute recommended action for failed post type.
	 */
	public static function execute_failure_action( string $post_type, string $action ): array {
		$result = [ 'success' => false, 'message' => '', 'data' => [] ];
		
		switch ( $action ) {
			case 'retry':
				$retried = RWSB_Queue::retry_post_type_failures( $post_type );
				$result = [
					'success' => true,
					'message' => "Retried {$retried} failed {$post_type} builds",
					'data' => [ 'retried' => $retried ]
				];
				break;
				
			case 'selective_retry':
				// Retry only the most recent failures
				$retried = self::selective_retry( $post_type, 5 );
				$result = [
					'success' => true,
					'message' => "Selectively retried {$retried} recent {$post_type} failures",
					'data' => [ 'retried' => $retried ]
				];
				break;
				
			case 'reduce_batch':
				// This would adjust queue processing settings
				$result = [
					'success' => true,
					'message' => "Recommended: Reduce batch size for {$post_type} builds",
					'data' => [ 'recommendation' => 'reduce_batch_size' ]
				];
				break;
				
			case 'increase_timeout':
				$result = [
					'success' => true,
					'message' => "Recommended: Increase timeout for {$post_type} builds",
					'data' => [ 'recommendation' => 'increase_timeout' ]
				];
				break;
				
			case 'investigate':
				$result = [
					'success' => false,
					'message' => "Too many failures for {$post_type} - manual investigation required",
					'data' => [ 'requires_investigation' => true ]
				];
				break;
		}
		
		return $result;
	}

	/**
	 * Selectively retry recent failures.
	 */
	protected static function selective_retry( string $post_type, int $limit ): int {
		$queue = RWSB_Queue::get_queue();
		$retried = 0;
		$count = 0;
		
		foreach ( $queue as $task_key => $task ) {
			if ( $count >= $limit ) break;
			
			if ( $task['type'] === 'single' && 
				 ( $task['post_type'] ?? '' ) === $post_type && 
				 ! empty( $task['last_error'] ) ) {
				
				// Reset for retry
				$task['attempts'] = 0;
				$task['last_error'] = null;
				$task['retry_after'] = null;
				$task['priority'] = 1; // High priority
				
				$queue[$task_key] = $task;
				$retried++;
				$count++;
			}
		}
		
		if ( $retried > 0 ) {
			update_option( 'rwsb_build_queue', $queue );
			RWSB_Queue::maybe_schedule_processor();
		}
		
		return $retried;
	}
}
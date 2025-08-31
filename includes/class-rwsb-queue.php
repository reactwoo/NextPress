<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Queue management system for build tasks.
 * Handles batching, deduplication, and priority ordering of build requests.
 */
class RWSB_Queue {
	const QUEUE_OPTION = 'rwsb_build_queue';
	const PROCESSING_OPTION = 'rwsb_queue_processing';
	const MAX_BATCH_SIZE = 10;
	const LOCK_TIMEOUT = 300; // 5 minutes

	/**
	 * Add a single build task to the queue with post-type categorization.
	 */
	public static function add_single( int $post_id, string $url, int $priority = 10 ): void {
		$queue = self::get_queue();
		$post_type = get_post_type( $post_id ) ?: 'unknown';
		$task_key = $post_type . '_' . $post_id;
		
		// Determine priority based on post type
		$priority = self::get_post_type_priority( $post_type, $priority );
		
		// Update or add task (deduplication by key)
		$queue[$task_key] = [
			'type' => 'single',
			'post_id' => $post_id,
			'post_type' => $post_type,
			'url' => $url,
			'priority' => $priority,
			'added' => time(),
			'attempts' => 0,
			'last_error' => null,
		];
		
		self::save_queue( $queue );
		self::maybe_schedule_processor();
	}

	/**
	 * Get priority based on post type.
	 */
	protected static function get_post_type_priority( string $post_type, int $default_priority = 10 ): int {
		$priorities = [
			'product' => 1,      // Highest priority - e-commerce critical
			'page' => 5,         // High priority - main content
			'post' => 10,        // Medium priority - blog content
			'attachment' => 15,  // Lower priority - media
		];
		
		return $priorities[$post_type] ?? $default_priority;
	}

	/**
	 * Add an archive build task to the queue.
	 */
	public static function add_archives( int $priority = 20 ): void {
		$queue = self::get_queue();
		$task_key = 'archives_all';
		
		$queue[$task_key] = [
			'type' => 'archives',
			'priority' => $priority,
			'added' => time(),
		];
		
		self::save_queue( $queue );
		self::maybe_schedule_processor();
	}

	/**
	 * Add a full rebuild task to the queue.
	 */
	public static function add_full_rebuild( int $priority = 30 ): void {
		$queue = self::get_queue();
		$task_key = 'full_rebuild';
		
		$queue[$task_key] = [
			'type' => 'full',
			'priority' => $priority,
			'added' => time(),
		];
		
		self::save_queue( $queue );
		self::maybe_schedule_processor();
	}

	/**
	 * Process queued build tasks in batches.
	 */
	public static function process_queue(): void {
		// Prevent concurrent processing
		if ( self::is_processing() ) {
			return;
		}

		self::set_processing_lock();
		
		try {
			$queue = self::get_queue();
			if ( empty( $queue ) ) {
				return;
			}

			// Sort by priority (lower numbers = higher priority)
			uasort( $queue, function( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			});

			$processed = 0;
			$available_tasks = [];
			
			// Filter out tasks that are waiting for retry
			foreach ( $queue as $task_key => $task ) {
				if ( empty( $task['retry_after'] ) || $task['retry_after'] <= time() ) {
					$available_tasks[$task_key] = $task;
				}
			}
			
			$batch = array_slice( $available_tasks, 0, self::MAX_BATCH_SIZE, true );
			
			foreach ( $batch as $task_key => $task ) {
				$success = self::process_task( $task );
				if ( $success ) {
					unset( $queue[$task_key] ); // Remove successful tasks
				}
				$processed++;
			}

			self::save_queue( $queue );
			
			// If more tasks remain, schedule another batch
			if ( count( $queue ) > 0 ) {
				self::schedule_processor( 5 ); // 5 second delay between batches
			}

			error_log( "[RWSB Queue] Processed {$processed} tasks, " . count( $queue ) . " remaining" );
			
		} finally {
			self::clear_processing_lock();
		}
	}

	/**
	 * Process a single task with failure handling and retries.
	 */
	protected static function process_task( array $task ): bool {
		$task['attempts'] = ( $task['attempts'] ?? 0 ) + 1;
		$max_attempts = 3;
		
		try {
			switch ( $task['type'] ) {
				case 'single':
					$success = RWSB_Builder::build_single_with_result( $task['post_id'], $task['url'] );
					break;
				case 'archives':
					$success = RWSB_Builder::build_archives_with_result();
					break;
				case 'full':
					$success = RWSB_Builder::build_all_with_result();
					break;
				default:
					$success = false;
			}
			
			if ( $success ) {
				// Log success with post type info
				$post_type = $task['post_type'] ?? 'unknown';
				RWSB_Logger::log_post_type_build( $post_type, $task['url'] ?? home_url(), 'success', 'Build completed', [
					'post_id' => $task['post_id'] ?? 0,
					'attempts' => $task['attempts'],
					'task_type' => $task['type']
				] );
				return true;
			} else {
				throw new Exception( 'Build method returned false' );
			}
			
		} catch ( Exception $e ) {
			$url = $task['url'] ?? home_url();
			$post_type = $task['post_type'] ?? 'unknown';
			$error_msg = 'Queue processing failed: ' . $e->getMessage();
			
			// Log failure with post type info
			RWSB_Logger::log_post_type_build( $post_type, $url, 'error', $error_msg, [
				'task' => $task,
				'attempts' => $task['attempts'],
				'max_attempts' => $max_attempts,
				'exception' => $e->getTraceAsString()
			] );
			
			error_log( '[RWSB Queue] Task failed (attempt ' . $task['attempts'] . '/' . $max_attempts . '): ' . $e->getMessage() );
			
			// Retry if under max attempts
			if ( $task['attempts'] < $max_attempts ) {
				$task['last_error'] = $error_msg;
				self::requeue_task( $task );
			}
			
			return false;
		}
	}

	/**
	 * Requeue a failed task with exponential backoff.
	 */
	protected static function requeue_task( array $task ): void {
		$queue = self::get_queue();
		$task_key = ( $task['post_type'] ?? 'unknown' ) . '_' . ( $task['post_id'] ?? 0 );
		
		// Exponential backoff: 30s, 2min, 5min
		$delays = [ 30, 120, 300 ];
		$delay = $delays[ min( $task['attempts'] - 1, count( $delays ) - 1 ) ];
		
		$task['retry_after'] = time() + $delay;
		$queue[$task_key] = $task;
		
		self::save_queue( $queue );
	}

	/**
	 * Get current queue.
	 */
	protected static function get_queue(): array {
		return get_option( self::QUEUE_OPTION, [] );
	}

	/**
	 * Save queue to database.
	 */
	protected static function save_queue( array $queue ): void {
		update_option( self::QUEUE_OPTION, $queue );
	}

	/**
	 * Check if queue processor is currently running.
	 */
	protected static function is_processing(): bool {
		$lock_time = get_option( self::PROCESSING_OPTION, 0 );
		return ( time() - $lock_time ) < self::LOCK_TIMEOUT;
	}

	/**
	 * Set processing lock.
	 */
	protected static function set_processing_lock(): void {
		update_option( self::PROCESSING_OPTION, time() );
	}

	/**
	 * Clear processing lock.
	 */
	protected static function clear_processing_lock(): void {
		delete_option( self::PROCESSING_OPTION );
	}

	/**
	 * Schedule queue processor if not already scheduled.
	 */
	protected static function maybe_schedule_processor(): void {
		if ( ! wp_next_scheduled( 'rwsb_process_queue' ) ) {
			self::schedule_processor();
		}
	}

	/**
	 * Schedule queue processor.
	 */
	protected static function schedule_processor( int $delay = 2 ): void {
		wp_schedule_single_event( time() + $delay, 'rwsb_process_queue' );
	}

	/**
	 * Get queue status for admin display with post-type breakdowns.
	 */
	public static function get_status(): array {
		$queue = self::get_queue();
		$processing = self::is_processing();
		
		$counts = [
			'single' => 0,
			'archives' => 0,
			'full' => 0,
		];
		
		$post_type_counts = [];
		$failed_tasks = [];
		$retry_tasks = [];
		
		foreach ( $queue as $task_key => $task ) {
			if ( isset( $counts[$task['type']] ) ) {
				$counts[$task['type']]++;
			}
			
			// Count by post type
			if ( $task['type'] === 'single' ) {
				$post_type = $task['post_type'] ?? 'unknown';
				$post_type_counts[$post_type] = ( $post_type_counts[$post_type] ?? 0 ) + 1;
				
				// Track failed and retry tasks
				if ( ! empty( $task['last_error'] ) ) {
					$failed_tasks[$post_type][] = $task;
				}
				
				if ( ! empty( $task['retry_after'] ) && $task['retry_after'] > time() ) {
					$retry_tasks[$post_type][] = $task;
				}
			}
		}
		
		return [
			'total' => count( $queue ),
			'processing' => $processing,
			'counts' => $counts,
			'post_type_counts' => $post_type_counts,
			'failed_tasks' => $failed_tasks,
			'retry_tasks' => $retry_tasks,
			'next_scheduled' => wp_next_scheduled( 'rwsb_process_queue' ),
		];
	}

	/**
	 * Add specific post type to queue.
	 */
	public static function add_post_type( string $post_type, int $priority = 10 ): void {
		$query = new WP_Query([
			'post_type' => $post_type,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		]);

		if ( $query->posts ) {
			foreach ( $query->posts as $post_id ) {
				$url = get_permalink( $post_id );
				if ( $url ) {
					self::add_single( (int) $post_id, $url, $priority );
				}
			}
		}
	}

	/**
	 * Retry failed tasks for a specific post type.
	 */
	public static function retry_post_type_failures( string $post_type ): int {
		$queue = self::get_queue();
		$retried = 0;
		
		foreach ( $queue as $task_key => $task ) {
			if ( $task['type'] === 'single' && 
				 ( $task['post_type'] ?? '' ) === $post_type && 
				 ! empty( $task['last_error'] ) ) {
				
				// Reset attempts and error
				$task['attempts'] = 0;
				$task['last_error'] = null;
				$task['retry_after'] = null;
				$task['priority'] = self::get_post_type_priority( $post_type, 1 ); // High priority retry
				
				$queue[$task_key] = $task;
				$retried++;
			}
		}
		
		if ( $retried > 0 ) {
			self::save_queue( $queue );
			self::maybe_schedule_processor();
		}
		
		return $retried;
	}

	/**
	 * Clear failed tasks for a specific post type.
	 */
	public static function clear_post_type_failures( string $post_type ): int {
		$queue = self::get_queue();
		$cleared = 0;
		
		foreach ( $queue as $task_key => $task ) {
			if ( $task['type'] === 'single' && 
				 ( $task['post_type'] ?? '' ) === $post_type && 
				 ! empty( $task['last_error'] ) ) {
				
				unset( $queue[$task_key] );
				$cleared++;
			}
		}
		
		if ( $cleared > 0 ) {
			self::save_queue( $queue );
		}
		
		return $cleared;
	}

	/**
	 * Clear all queued tasks.
	 */
	public static function clear_queue(): void {
		delete_option( self::QUEUE_OPTION );
		self::clear_processing_lock();
		
		// Clear any scheduled processors
		$timestamp = wp_next_scheduled( 'rwsb_process_queue' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rwsb_process_queue' );
		}
	}
}
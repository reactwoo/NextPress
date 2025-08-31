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
	 * Add a single build task to the queue.
	 */
	public static function add_single( int $post_id, string $url, int $priority = 10 ): void {
		$queue = self::get_queue();
		$task_key = 'single_' . $post_id;
		
		// Update or add task (deduplication by key)
		$queue[$task_key] = [
			'type' => 'single',
			'post_id' => $post_id,
			'url' => $url,
			'priority' => $priority,
			'added' => time(),
		];
		
		self::save_queue( $queue );
		self::maybe_schedule_processor();
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
			$batch = array_slice( $queue, 0, self::MAX_BATCH_SIZE, true );
			
			foreach ( $batch as $task_key => $task ) {
				self::process_task( $task );
				unset( $queue[$task_key] );
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
	 * Process a single task.
	 */
	protected static function process_task( array $task ): void {
		try {
			switch ( $task['type'] ) {
				case 'single':
					RWSB_Builder::build_single( $task['post_id'], $task['url'] );
					break;
				case 'archives':
					RWSB_Builder::build_archives();
					break;
				case 'full':
					RWSB_Builder::build_all();
					break;
			}
		} catch ( Exception $e ) {
			$url = $task['url'] ?? home_url();
			RWSB_Logger::log( $task['type'], $url, 'error', 'Queue processing failed: ' . $e->getMessage(), [
				'task' => $task,
				'exception' => $e->getTraceAsString()
			] );
			error_log( '[RWSB Queue] Task failed: ' . $e->getMessage() );
		}
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
	 * Get queue status for admin display.
	 */
	public static function get_status(): array {
		$queue = self::get_queue();
		$processing = self::is_processing();
		
		$counts = [
			'single' => 0,
			'archives' => 0,
			'full' => 0,
		];
		
		foreach ( $queue as $task ) {
			if ( isset( $counts[$task['type']] ) ) {
				$counts[$task['type']]++;
			}
		}
		
		return [
			'total' => count( $queue ),
			'processing' => $processing,
			'counts' => $counts,
			'next_scheduled' => wp_next_scheduled( 'rwsb_process_queue' ),
		];
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
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Cron {
	public static function register_events(): void {
		if ( ! wp_next_scheduled( 'rwsb_build_archives' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'rwsb_build_archives' );
		}
	}

	public static function clear_events(): void {
		$timestamp = wp_next_scheduled( 'rwsb_build_archives' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rwsb_build_archives' );
		}
	}
}

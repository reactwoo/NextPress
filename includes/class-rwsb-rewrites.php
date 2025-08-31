<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Rewrites {
	public static function maybe_serve_static(): void {
		$settings = rwsb_get_settings();
		if ( (int) $settings['enabled'] !== 1 ) return;
		if ( (int) $settings['serve_static'] !== 1 ) return;
		if ( rwsb_should_bypass() ) return;

		// Only front-end GET requests.
		if ( is_admin() || ( defined('REST_REQUEST') && REST_REQUEST ) ) return;
		if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) return;

		// Only serve for canonical URLs with a post ID or front page, archives, terms if built.
		$requested_url = self::current_url();
		$static_file   = rwsb_store_path_for_url( $requested_url );

		// Respect TTL if set.
		$ttl = (int) $settings['ttl'];
		if ( file_exists( $static_file ) ) {
			if ( $ttl > 0 ) {
				$age = time() - filemtime( $static_file );
				if ( $age > $ttl ) {
					// Stale — let WP render and a build will refresh async on shutdown via cron.
					return;
				}
			}

			// Serve it.
			status_header( 200 );
			rwsb_send_headers( (array) $settings['headers'] );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-RWSB-Cache: HIT' );
			readfile( $static_file );
			exit;
		}
		// If no static file, allow normal WP render; build may be queued by other hooks.
	}

	protected static function current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';
		return $scheme . '://' . $host . $uri;
	}
}

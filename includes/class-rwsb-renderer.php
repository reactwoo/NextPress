<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enhanced rendering system with late-loading content support.
 * Addresses the challenge of capturing dynamically loaded assets and content.
 */
class RWSB_Renderer {

	/**
	 * Render page with enhanced asset detection and late-loading support.
	 */
	public static function render_page( string $url, int $post_id = 0 ): array {
		$settings = rwsb_get_settings();
		$optimization = $settings['optimization'] ?? [];
		
		// Phase 1: Initial render
		$initial_result = self::initial_render( $url );
		if ( $initial_result['code'] !== 200 ) {
			return $initial_result;
		}
		
		$html = $initial_result['body'];
		$assets = RWSB_Optimizer::extract_assets( $html );
		
		// Phase 2: Late-loading analysis if enabled
		if ( ! empty( $optimization['second_pass_analysis'] ) ) {
			$late_loading_result = self::analyze_late_loading( $url, $html );
			if ( $late_loading_result['enhanced'] ) {
				$html = $late_loading_result['body'];
			}
		}
		
		// Phase 3: Asset optimization
		if ( ! empty( $optimization['enabled'] ) ) {
			$html = RWSB_Optimizer::optimize_html( $html, $url );
		}
		
		return [
			'code' => 200,
			'body' => $html,
			'error' => '',
			'metadata' => [
				'initial_size' => strlen( $initial_result['body'] ),
				'final_size' => strlen( $html ),
				'assets_detected' => count( $assets['css'] ?? [] ) + count( $assets['js'] ?? [] ),
				'late_loading_enhanced' => $late_loading_result['enhanced'] ?? false,
			]
		];
	}

	/**
	 * Initial page render with asset detection hooks.
	 */
	protected static function initial_render( string $url ): array {
		$settings = rwsb_get_settings();
		$bypass = $settings['bypass_param'] ?: 'rwsb';
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		
		// Add special query params to signal our rendering mode
		$build_url = $url . $sep . $bypass . '=miss&rwsb_render=1';
		
		// Hook into WordPress to capture asset information during render
		add_action( 'wp_head', [ self::class, 'capture_head_assets' ], 999 );
		add_action( 'wp_footer', [ self::class, 'capture_footer_assets' ], 1 );
		
		return rwsb_http_get( $build_url );
	}

	/**
	 * Analyze and capture late-loading content.
	 */
	protected static function analyze_late_loading( string $url, string $html ): array {
		// Check if page has late-loading indicators
		$has_late_loading = self::detect_late_loading_patterns( $html );
		
		if ( ! $has_late_loading ) {
			return [ 'enhanced' => false, 'body' => $html ];
		}
		
		// Wait for potential late-loading content
		$settings = rwsb_get_settings();
		$bypass = $settings['bypass_param'] ?: 'rwsb';
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		$late_url = $url . $sep . $bypass . '=miss&rwsb_late=1';
		
		// Add a delay to allow late-loading content to execute
		sleep( 3 );
		
		$late_result = rwsb_http_get( $late_url );
		if ( $late_result['code'] === 200 && ! empty( $late_result['body'] ) ) {
			// Compare content to see if anything changed
			$size_diff = abs( strlen( $late_result['body'] ) - strlen( $html ) );
			$content_changed = $size_diff > 100; // Significant change threshold
			
			if ( $content_changed ) {
				return [ 'enhanced' => true, 'body' => $late_result['body'] ];
			}
		}
		
		return [ 'enhanced' => false, 'body' => $html ];
	}

	/**
	 * Detect patterns that indicate late-loading content.
	 */
	protected static function detect_late_loading_patterns( string $html ): bool {
		$patterns = [
			// AJAX patterns
			'\.ajax\(', '\.get\(', '\.post\(', 'fetch\(',
			'XMLHttpRequest', 'axios\.',
			
			// Event-based loading
			'addEventListener\(', 'on\(.*?load', 'on\(.*?click',
			'on\(.*?scroll', 'on\(.*?resize',
			
			// Intersection Observer (lazy loading)
			'IntersectionObserver', 'lazy', 'lazyload',
			
			// Dynamic imports
			'import\(', 'System\.import', 'require\(',
			
			// Timers
			'setTimeout', 'setInterval', 'requestAnimationFrame',
			
			// WordPress specific
			'wp\.ajax', 'wp_localize_script.*ajax',
			
			// Common late-loading libraries
			'swiper', 'slick', 'owl-carousel', 'lightbox',
			'masonry', 'isotope', 'infinite-scroll',
		];
		
		foreach ( $patterns as $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $html ) ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Capture assets loaded in wp_head.
	 */
	public static function capture_head_assets(): void {
		// This would be called during the actual WordPress render
		// to capture what assets are being loaded
		if ( ! isset( $_GET['rwsb_render'] ) ) return;
		
		global $wp_scripts, $wp_styles;
		
		// Store asset information for later analysis
		update_option( 'rwsb_temp_head_assets', [
			'scripts' => $wp_scripts->done ?? [],
			'styles' => $wp_styles->done ?? [],
			'timestamp' => time(),
		], false );
	}

	/**
	 * Capture assets loaded in wp_footer.
	 */
	public static function capture_footer_assets(): void {
		if ( ! isset( $_GET['rwsb_render'] ) ) return;
		
		global $wp_scripts, $wp_styles;
		
		update_option( 'rwsb_temp_footer_assets', [
			'scripts' => $wp_scripts->done ?? [],
			'styles' => $wp_styles->done ?? [],
			'timestamp' => time(),
		], false );
	}

	/**
	 * Get captured asset information.
	 */
	public static function get_captured_assets(): array {
		$head_assets = get_option( 'rwsb_temp_head_assets', [] );
		$footer_assets = get_option( 'rwsb_temp_footer_assets', [] );
		
		// Clean up temporary options
		delete_option( 'rwsb_temp_head_assets' );
		delete_option( 'rwsb_temp_footer_assets' );
		
		return [
			'head' => $head_assets,
			'footer' => $footer_assets,
		];
	}

	/**
	 * Enhanced HTTP request with user agent simulation.
	 */
	public static function enhanced_http_get( string $url, array $options = [] ): array {
		$args = [
			'timeout' => 45, // Longer timeout for complex pages
			'redirection' => 5,
			'headers' => [
				'User-Agent' => $options['user_agent'] ?? 'Mozilla/5.0 (compatible; ReactWooStaticBuilder/' . RWSB_VERSION . '; +' . home_url() . ')',
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.5',
				'Accept-Encoding' => 'gzip, deflate',
				'Connection' => 'keep-alive',
				'Upgrade-Insecure-Requests' => '1',
			],
		];
		
		// Add custom headers if in optimization mode
		if ( ! empty( $options['optimization_mode'] ) ) {
			$args['headers']['X-RWSB-Optimization'] = '1';
			$args['headers']['X-RWSB-Phase'] = $options['phase'] ?? 'initial';
		}
		
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'code' => 0, 'body' => '', 'error' => $response->get_error_message() ];
		}
		
		return [ 
			'code' => wp_remote_retrieve_response_code( $response ), 
			'body' => wp_remote_retrieve_body( $response ), 
			'error' => '',
			'headers' => wp_remote_retrieve_headers( $response )->getAll()
		];
	}
}
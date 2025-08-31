<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Asset optimization system for static builds.
 * Handles CSS/JS minification, critical resource detection, and plugin conflict management.
 */
class RWSB_Optimizer {
	
	const CRITICAL_JS_PATTERNS = [
		'jquery', 'wp-embed', 'wp-emoji', 'wp-polyfill',
		'woocommerce', 'wc-', 'cart', 'checkout',
		'critical', 'above-fold', 'inline'
	];

	const LATE_LOADING_PATTERNS = [
		'lazy', 'defer', 'async', 'intersection-observer',
		'scroll', 'click', 'hover', 'load-more'
	];

	const CONFLICTING_PLUGINS = [
		'autoptimize/autoptimize.php',
		'wp-rocket/wp-rocket.php',
		'w3-total-cache/w3-total-cache.php',
		'wp-super-cache/wp-cache.php',
		'litespeed-cache/litespeed-cache.php',
		'wp-optimize/wp-optimize.php',
		'sg-cachepress/sg-cachepress.php',
		'hummingbird-performance/wp-hummingbird.php',
		'swift-performance-lite/performance.php',
		'breeze/breeze.php',
	];

	/**
	 * Check for conflicting optimization plugins.
	 */
	public static function check_plugin_conflicts(): array {
		$conflicts = [];
		$active_plugins = get_option( 'active_plugins', [] );
		
		foreach ( self::CONFLICTING_PLUGINS as $plugin ) {
			if ( in_array( $plugin, $active_plugins, true ) ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
				$conflicts[] = [
					'plugin' => $plugin,
					'name' => $plugin_data['Name'] ?? basename( $plugin ),
					'version' => $plugin_data['Version'] ?? 'Unknown',
				];
			}
		}
		
		return $conflicts;
	}

	/**
	 * Optimize HTML output before writing to static file.
	 */
	public static function optimize_html( string $html, string $url, array $options = [] ): string {
		$settings = rwsb_get_settings();
		$optimization = $settings['optimization'] ?? [];
		
		if ( empty( $optimization['enabled'] ) ) {
			return $html;
		}

		$start_time = microtime( true );
		
		// 1. Extract and analyze assets
		$assets = self::extract_assets( $html );
		
		// 2. Detect critical and late-loading resources
		$critical_assets = self::detect_critical_assets( $assets, $html );
		$late_loading_assets = self::detect_late_loading_assets( $assets, $html );
		
		// 3. Optimize based on settings
		if ( ! empty( $optimization['minify_html'] ) ) {
			$html = self::minify_html( $html );
		}
		
		if ( ! empty( $optimization['optimize_css'] ) ) {
			$html = self::optimize_css_in_html( $html, $critical_assets );
		}
		
		if ( ! empty( $optimization['optimize_js'] ) ) {
			$html = self::optimize_js_in_html( $html, $critical_assets, $late_loading_assets );
		}
		
		if ( ! empty( $optimization['remove_unused'] ) ) {
			$html = self::remove_unused_assets( $html, $url );
		}
		
		// 4. Add performance optimizations
		$html = self::add_performance_hints( $html, $critical_assets );
		
		$duration = microtime( true ) - $start_time;
		RWSB_Logger::log( 'optimization', $url, 'success', 'Assets optimized', [
			'duration' => round( $duration * 1000, 2 ) . 'ms',
			'original_size' => strlen( $html ),
			'critical_css' => count( $critical_assets['css'] ?? [] ),
			'critical_js' => count( $critical_assets['js'] ?? [] ),
			'late_loading_js' => count( $late_loading_assets ),
		] );
		
		return $html;
	}

	/**
	 * Extract all CSS and JS assets from HTML.
	 */
	protected static function extract_assets( string $html ): array {
		$assets = [ 'css' => [], 'js' => [] ];
		
		// Extract CSS links
		preg_match_all( '/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html, $css_matches );
		foreach ( $css_matches[0] as $css_tag ) {
			if ( preg_match( '/href=["\']([^"\']+)["\']/', $css_tag, $href_match ) ) {
				$assets['css'][] = [
					'tag' => $css_tag,
					'url' => $href_match[1],
					'media' => self::extract_attribute( $css_tag, 'media' ) ?: 'all',
				];
			}
		}
		
		// Extract JS scripts
		preg_match_all( '/<script[^>]*src=[^>]*>.*?<\/script>/is', $html, $js_matches );
		foreach ( $js_matches[0] as $js_tag ) {
			if ( preg_match( '/src=["\']([^"\']+)["\']/', $js_tag, $src_match ) ) {
				$assets['js'][] = [
					'tag' => $js_tag,
					'url' => $src_match[1],
					'async' => strpos( $js_tag, 'async' ) !== false,
					'defer' => strpos( $js_tag, 'defer' ) !== false,
				];
			}
		}
		
		// Extract inline scripts
		preg_match_all( '/<script[^>]*>(?!.*src=)(.*?)<\/script>/is', $html, $inline_js_matches );
		foreach ( $inline_js_matches[0] as $index => $js_tag ) {
			$assets['js'][] = [
				'tag' => $js_tag,
				'url' => 'inline-' . $index,
				'inline' => true,
				'content' => $inline_js_matches[1][$index] ?? '',
			];
		}
		
		return $assets;
	}

	/**
	 * Detect critical assets needed for initial render.
	 */
	protected static function detect_critical_assets( array $assets, string $html ): array {
		$critical = [ 'css' => [], 'js' => [] ];
		
		// CSS: All non-print stylesheets are considered critical
		foreach ( $assets['css'] as $css ) {
			if ( $css['media'] !== 'print' ) {
				$critical['css'][] = $css;
			}
		}
		
		// JS: Identify critical scripts
		foreach ( $assets['js'] as $js ) {
			$url = $js['url'];
			$is_critical = false;
			
			// Check against critical patterns
			foreach ( self::CRITICAL_JS_PATTERNS as $pattern ) {
				if ( stripos( $url, $pattern ) !== false ) {
					$is_critical = true;
					break;
				}
			}
			
			// Inline scripts are often critical
			if ( ! empty( $js['inline'] ) ) {
				$content = $js['content'] ?? '';
				// Check if it's setting up critical functionality
				if ( stripos( $content, 'document.ready' ) !== false ||
					 stripos( $content, 'DOMContentLoaded' ) !== false ||
					 stripos( $content, 'wp_localize' ) !== false ) {
					$is_critical = true;
				}
			}
			
			// Scripts without async/defer are typically critical
			if ( ! $js['async'] && ! $js['defer'] && empty( $js['inline'] ) ) {
				$is_critical = true;
			}
			
			if ( $is_critical ) {
				$critical['js'][] = $js;
			}
		}
		
		return $critical;
	}

	/**
	 * Detect late-loading assets that might break if optimized.
	 */
	protected static function detect_late_loading_assets( array $assets, string $html ): array {
		$late_loading = [];
		
		foreach ( $assets['js'] as $js ) {
			$url = $js['url'];
			$content = $js['content'] ?? '';
			
			// Check for late-loading patterns
			foreach ( self::LATE_LOADING_PATTERNS as $pattern ) {
				if ( stripos( $url, $pattern ) !== false || stripos( $content, $pattern ) !== false ) {
					$late_loading[] = $js;
					break;
				}
			}
			
			// Check for event-based loading
			if ( stripos( $content, 'addEventListener' ) !== false ||
				 stripos( $content, 'setTimeout' ) !== false ||
				 stripos( $content, 'setInterval' ) !== false ||
				 stripos( $content, '.on(' ) !== false ) {
				$late_loading[] = $js;
			}
		}
		
		return $late_loading;
	}

	/**
	 * Enhanced page rendering with asset analysis.
	 */
	public static function render_with_analysis( string $url ): array {
		// First pass: Get initial HTML
		$settings = rwsb_get_settings();
		$bypass = $settings['bypass_param'] ?: 'rwsb';
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		$build_url = $url . $sep . $bypass . '=miss&rwsb_analyze=1';
		
		// Add custom headers to signal optimization mode
		add_filter( 'wp_headers', function( $headers ) {
			$headers['X-RWSB-Optimization'] = '1';
			return $headers;
		} );
		
		$result = rwsb_http_get( $build_url );
		
		if ( $result['code'] !== 200 || empty( $result['body'] ) ) {
			return $result;
		}
		
		// Second pass: Wait for late-loading assets if needed
		$html = $result['body'];
		$needs_second_pass = self::needs_late_loading_analysis( $html );
		
		if ( $needs_second_pass ) {
			// Wait a bit for async assets to load, then fetch again
			sleep( 2 );
			$second_result = rwsb_http_get( $build_url . '&rwsb_second_pass=1' );
			if ( $second_result['code'] === 200 && ! empty( $second_result['body'] ) ) {
				$html = $second_result['body'];
			}
		}
		
		return [
			'code' => $result['code'],
			'body' => $html,
			'error' => $result['error'],
			'analysis' => [
				'second_pass' => $needs_second_pass,
				'assets' => self::extract_assets( $html ),
			]
		];
	}

	/**
	 * Check if page needs late-loading analysis.
	 */
	protected static function needs_late_loading_analysis( string $html ): bool {
		// Look for indicators of late-loading content
		$patterns = [
			'setTimeout', 'setInterval', 'requestAnimationFrame',
			'IntersectionObserver', 'MutationObserver',
			'fetch(', '$.ajax', '$.get', '$.post',
			'dynamic import', 'import(', 'System.import'
		];
		
		foreach ( $patterns as $pattern ) {
			if ( stripos( $html, $pattern ) !== false ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Minify HTML output.
	 */
	protected static function minify_html( string $html ): string {
		// Preserve critical whitespace in <pre>, <code>, <textarea>, <script>
		$preserved = [];
		$preserve_tags = [ 'pre', 'code', 'textarea', 'script' ];
		
		foreach ( $preserve_tags as $tag ) {
			preg_match_all( "/<{$tag}[^>]*>.*?<\/{$tag}>/is", $html, $matches );
			foreach ( $matches[0] as $i => $match ) {
				$placeholder = "<!--RWSB_PRESERVE_{$tag}_{$i}-->";
				$preserved[$placeholder] = $match;
				$html = str_replace( $match, $placeholder, $html );
			}
		}
		
		// Minify
		$html = preg_replace( '/\s+/', ' ', $html ); // Multiple spaces to single
		$html = preg_replace( '/>\s+</', '><', $html ); // Remove spaces between tags
		$html = preg_replace( '/<!--(?!RWSB_PRESERVE).*?-->/s', '', $html ); // Remove comments except preserved
		
		// Restore preserved content
		foreach ( $preserved as $placeholder => $content ) {
			$html = str_replace( $placeholder, $content, $html );
		}
		
		return trim( $html );
	}

	/**
	 * Optimize CSS in HTML.
	 */
	protected static function optimize_css_in_html( string $html, array $critical_assets ): string {
		$settings = rwsb_get_settings();
		$optimization = $settings['optimization'] ?? [];
		
		if ( empty( $optimization['combine_css'] ) ) {
			return $html;
		}
		
		// Extract all CSS links
		$css_assets = $critical_assets['css'] ?? [];
		$combined_css = '';
		$removed_tags = [];
		
		foreach ( $css_assets as $css ) {
			if ( self::is_local_asset( $css['url'] ) ) {
				$css_content = self::fetch_and_minify_css( $css['url'] );
				if ( $css_content ) {
					$combined_css .= "/* {$css['url']} */\n" . $css_content . "\n";
					$removed_tags[] = $css['tag'];
				}
			}
		}
		
		if ( ! empty( $combined_css ) ) {
			// Remove original CSS tags
			foreach ( $removed_tags as $tag ) {
				$html = str_replace( $tag, '', $html );
			}
			
			// Add combined CSS
			$combined_tag = '<style id="rwsb-combined-css">' . $combined_css . '</style>';
			$html = str_replace( '</head>', $combined_tag . '</head>', $html );
		}
		
		return $html;
	}

	/**
	 * Optimize JavaScript in HTML with late-loading consideration.
	 */
	protected static function optimize_js_in_html( string $html, array $critical_assets, array $late_loading_assets ): string {
		$settings = rwsb_get_settings();
		$optimization = $settings['optimization'] ?? [];
		
		if ( empty( $optimization['optimize_js'] ) ) {
			return $html;
		}
		
		// Separate critical from non-critical JS
		$critical_js = $critical_assets['js'] ?? [];
		$late_loading_urls = array_column( $late_loading_assets, 'url' );
		
		$combined_critical = '';
		$deferred_scripts = [];
		$removed_tags = [];
		
		foreach ( $critical_js as $js ) {
			if ( in_array( $js['url'], $late_loading_urls, true ) ) {
				// Don't optimize late-loading scripts
				continue;
			}
			
			if ( ! empty( $js['inline'] ) ) {
				$combined_critical .= "/* Inline script */\n" . $js['content'] . "\n";
				$removed_tags[] = $js['tag'];
			} elseif ( self::is_local_asset( $js['url'] ) && empty( $optimization['preserve_external'] ) ) {
				$js_content = self::fetch_and_minify_js( $js['url'] );
				if ( $js_content ) {
					if ( $js['async'] || $js['defer'] ) {
						$deferred_scripts[] = $js_content;
					} else {
						$combined_critical .= "/* {$js['url']} */\n" . $js_content . "\n";
					}
					$removed_tags[] = $js['tag'];
				}
			}
		}
		
		// Remove optimized script tags
		foreach ( $removed_tags as $tag ) {
			$html = str_replace( $tag, '', $html );
		}
		
		// Add combined critical JS
		if ( ! empty( $combined_critical ) ) {
			$critical_tag = '<script id="rwsb-combined-critical">' . $combined_critical . '</script>';
			$html = str_replace( '</head>', $critical_tag . '</head>', $html );
		}
		
		// Add deferred JS before closing body
		if ( ! empty( $deferred_scripts ) ) {
			$deferred_content = implode( "\n", $deferred_scripts );
			$deferred_tag = '<script defer id="rwsb-combined-deferred">' . $deferred_content . '</script>';
			$html = str_replace( '</body>', $deferred_tag . '</body>', $html );
		}
		
		return $html;
	}

	/**
	 * Remove unused assets based on actual page content analysis.
	 */
	protected static function remove_unused_assets( string $html, string $url ): string {
		// Analyze HTML content to determine what's actually used
		$used_classes = self::extract_used_css_classes( $html );
		$used_ids = self::extract_used_ids( $html );
		
		// This is a complex feature that would need careful implementation
		// For now, we'll focus on removing obviously unused assets
		
		// Remove emoji scripts if no emojis detected
		if ( ! preg_match( '/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]/u', $html ) ) {
			$html = preg_replace( '/<script[^>]*wp-emoji[^>]*>.*?<\/script>/is', '', $html );
			$html = preg_replace( '/<link[^>]*wp-emoji[^>]*>/i', '', $html );
		}
		
		// Remove embed scripts if no embeds detected
		if ( ! preg_match( '/<iframe|<embed|<object/', $html ) ) {
			$html = preg_replace( '/<script[^>]*wp-embed[^>]*>.*?<\/script>/is', '', $html );
		}
		
		return $html;
	}

	/**
	 * Add performance hints and optimizations.
	 */
	protected static function add_performance_hints( string $html, array $critical_assets ): string {
		$hints = [];
		
		// Add preload hints for critical assets
		foreach ( $critical_assets['css'] ?? [] as $css ) {
			if ( self::is_local_asset( $css['url'] ) ) {
				$hints[] = '<link rel="preload" href="' . esc_url( $css['url'] ) . '" as="style">';
			}
		}
		
		// Add DNS prefetch for external domains
		$external_domains = self::extract_external_domains( $critical_assets );
		foreach ( $external_domains as $domain ) {
			$hints[] = '<link rel="dns-prefetch" href="//' . esc_attr( $domain ) . '">';
		}
		
		if ( ! empty( $hints ) ) {
			$hints_html = implode( "\n", $hints ) . "\n";
			$html = str_replace( '</head>', $hints_html . '</head>', $html );
		}
		
		return $html;
	}

	/**
	 * Helper methods for asset analysis.
	 */
	protected static function extract_attribute( string $tag, string $attr ): ?string {
		if ( preg_match( "/{$attr}=[\"']([^\"']+)[\"']/i", $tag, $match ) ) {
			return $match[1];
		}
		return null;
	}

	protected static function is_local_asset( string $url ): bool {
		$site_url = home_url();
		return strpos( $url, $site_url ) === 0 || strpos( $url, '/' ) === 0;
	}

	protected static function fetch_and_minify_css( string $url ): string {
		// Implementation would fetch CSS and minify it
		// This is a placeholder for the actual implementation
		return '';
	}

	protected static function fetch_and_minify_js( string $url ): string {
		// Implementation would fetch JS and minify it
		// This is a placeholder for the actual implementation
		return '';
	}

	protected static function extract_used_css_classes( string $html ): array {
		preg_match_all( '/class=["\']([^"\']+)["\']/', $html, $matches );
		$classes = [];
		foreach ( $matches[1] as $class_string ) {
			$classes = array_merge( $classes, explode( ' ', $class_string ) );
		}
		return array_unique( array_filter( $classes ) );
	}

	protected static function extract_used_ids( string $html ): array {
		preg_match_all( '/id=["\']([^"\']+)["\']/', $html, $matches );
		return array_unique( $matches[1] ?? [] );
	}

	protected static function extract_external_domains( array $assets ): array {
		$domains = [];
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		
		foreach ( $assets as $type => $items ) {
			foreach ( $items as $asset ) {
				if ( ! empty( $asset['url'] ) && ! self::is_local_asset( $asset['url'] ) ) {
					$domain = wp_parse_url( $asset['url'], PHP_URL_HOST );
					if ( $domain && $domain !== $site_host ) {
						$domains[] = $domain;
					}
				}
			}
		}
		
		return array_unique( $domains );
	}
}
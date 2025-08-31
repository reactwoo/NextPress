<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function rwsb_get_settings(): array {
	$settings = get_option( 'rwsb_settings', [] );
	return wp_parse_args( $settings, [
		'enabled'        => 1,
		'post_types'     => ['page','product'],
		'ttl'            => 0,
		'serve_static'   => 1,
		'respect_logged' => 1,
		'bypass_param'   => 'rwsb',
		'webhook_url'    => '',
		'headers'        => [
			'Cache-Control' => 'public, max-age=31536000, stale-while-revalidate=30',
			'X-Powered-By'  => 'ReactWoo Static Builder'
		],
	] );
}

function rwsb_store_path_for_url( string $url ): string {
	$parts = wp_parse_url( $url );
	$host  = $parts['host'] ?? 'site';
	$path  = rtrim( $parts['path'] ?? '/', '/' );
	if ( $path === '' ) $path = '/';
	$full  = RWSB_STORE_DIR . '/' . $host . $path;
	if ( str_ends_with( $full, '/' ) ) $full .= 'index.html';
	else $full .= '/index.html';
	return $full;
}

function rwsb_url_for_path( string $url ): string {
	$parts = wp_parse_url( $url );
	$host  = $parts['host'] ?? 'site';
	$path  = rtrim( $parts['path'] ?? '/', '/' );
	if ( $path === '' ) $path = '/';
	$full  = RWSB_STORE_URL . '/' . $host . $path;
	if ( str_ends_with( $full, '/' ) ) $full .= 'index.html';
	else $full .= '/index.html';
	return $full;
}

function rwsb_mkdir_for_file( string $file ): void {
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
}

function rwsb_should_bypass(): bool {
	$settings = rwsb_get_settings();
	$param    = $settings['bypass_param'] ?: 'rwsb';
	if ( isset( $_GET[ $param ] ) ) return true;
	if ( is_user_logged_in() && (int) $settings['respect_logged'] === 1 ) return true;
	return false;
}

function rwsb_send_headers( array $headers ): void {
	foreach ( $headers as $k => $v ) {
		header( $k . ': ' . $v );
	}
}

function rwsb_http_get( string $url ): array {
	$args = [
		'timeout' => 30,
		'redirection' => 5,
		'headers' => [
			'User-Agent' => 'ReactWooStaticBuilder/' . RWSB_VERSION,
			'Accept' => 'text/html,application/xhtml+xml',
		],
	];
	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return [ 'code' => 0, 'body' => '', 'error' => $response->get_error_message() ];
	}
	return [ 'code' => wp_remote_retrieve_response_code( $response ), 'body' => wp_remote_retrieve_body( $response ), 'error' => '' ];
}

function rwsb_is_included_post_type( string $post_type ): bool {
	$settings = rwsb_get_settings();
	return in_array( $post_type, (array) $settings['post_types'], true );
}

function rwsb_clean_url_to_path( string $url ): string {
	return rwsb_store_path_for_url( $url );
}

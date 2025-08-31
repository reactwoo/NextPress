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
		// Cloud hosting (connect and deploy)
		'hosting_provider'   => '', // '', 'cloudflare', 'vercel', 'netlify'
		'hosting_connected'  => 0,
		'hosting_manage_url' => '',
		'pro_enabled'        => 0,
		'auto_deploy_on_build'=> 0,
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

function rwsb_zip_static_store( string $zip_path ): bool {
	$root = rtrim( RWSB_STORE_DIR, '/' );
	if ( ! class_exists( 'ZipArchive' ) ) return false;
	$zip = new ZipArchive();
	if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) return false;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	$root_len = strlen( $root ) + 1;
	foreach ( $iterator as $file ) {
		$path = (string) $file;
		$local = substr( $path, $root_len );
		if ( is_dir( $path ) ) {
			$zip->addEmptyDir( $local );
		} else {
			$zip->addFile( $path, $local );
		}
	}
	$zip->close();
	return file_exists( $zip_path );
}

function rwsb_mkdir_for_file( string $file ): void {
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
}

function rwsb_is_store_writable(): bool {
	$dir = RWSB_STORE_DIR;
	if ( ! file_exists( $dir ) ) {
		return is_writable( dirname( $dir ) );
	}
	return is_writable( $dir );
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

function rwsb_get_install_id(): string {
	$install_id = get_option( 'rwsb_install_id', '' );
	if ( ! is_string( $install_id ) || $install_id === '' ) {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$install_id = wp_generate_uuid4();
		} else {
			$install_id = bin2hex( random_bytes( 16 ) );
		}
		update_option( 'rwsb_install_id', $install_id, false );
	}
	return $install_id;
}

function rwsb_build_connect_url( string $provider ): string {
	$provider = sanitize_key( $provider );
	if ( $provider === '' ) return '';
	$site = rawurlencode( home_url() );
	$install = rawurlencode( rwsb_get_install_id() );
	$return = rawurlencode( admin_url( 'admin.php?page=rwsb' ) );
	return 'https://server.reactwoo.com/connect?provider=' . $provider . '&site=' . $site . '&installId=' . $install . '&return=' . $return;
}

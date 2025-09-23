<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RWSB_Exporter {

	public static function handle_local_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 403 );
		check_admin_referer( 'rwsb_local_export' );
		$selected = array_map( 'intval', (array) ($_POST['rwsb_export_ids'] ?? []) );
		if ( empty( $selected ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&export_error=none_selected' ) );
			exit;
		}

		$zip_path = self::build_zip_for_posts( $selected );
		if ( ! $zip_path || ! file_exists( $zip_path ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rwsb&export_error=failed' ) );
			exit;
		}

		self::stream_zip_and_cleanup( $zip_path );
	}

	protected static function build_zip_for_posts( array $post_ids ): string {
		$upload_dir = wp_upload_dir();
		$work_dir = trailingslashit( $upload_dir['basedir'] ) . 'rwsb-export-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $work_dir );

		self::copy_template( $work_dir );
		self::write_export_data( $work_dir, $post_ids );

		$zip_path = $work_dir . 'nextjs-export.zip';
		self::zip_directory( $work_dir . 'app-root', $zip_path );
		return $zip_path;
	}

	protected static function copy_template( string $work_dir ): void {
		$src = RWSB_DIR . 'templates/nextjs-template';
		$dst = $work_dir . 'app-root';
		self::recurse_copy( $src, $dst );
		// Replace .tmpl files to .tsx to avoid type lints in this context
		$tmpl_files = [
			$dst . '/app/layout.tsx.tmpl' => $dst . '/app/layout.tsx',
			$dst . '/app/page.tsx.tmpl' => $dst . '/app/page.tsx',
			$dst . '/app/[slug]/page.tsx.tmpl' => $dst . '/app/[slug]/page.tsx',
		];
		foreach ( $tmpl_files as $from => $to ) {
			if ( file_exists( $from ) ) {
				@rename( $from, $to );
			}
		}
	}

	protected static function write_export_data( string $work_dir, array $post_ids ): void {
		$data = [ 'site' => [ 'url' => home_url() ], 'pages' => [], 'globals' => [] ];
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || $post->post_status !== 'publish' ) continue;
			$elementor_raw = get_post_meta( $pid, '_elementor_data', true );
			$elementor_json = self::maybe_json_decode( $elementor_raw );
			$elementor_css  = self::read_elementor_css_for_post( $pid );
			$data['pages'][] = [
				'id' => $pid,
				'slug' => trim( get_post_field( 'post_name', $pid ) ),
				'title' => get_the_title( $pid ),
				'content' => apply_filters( 'the_content', $post->post_content ),
				'elementor_data' => $elementor_json,
				'elementor_css'  => $elementor_css,
			];
		}
		// Elementor globals (best-effort)
		$global_css = self::read_elementor_global_css();
		$global_settings = get_option( 'elementor_global_settings', [] );
		if ( ! empty( $global_settings ) ) {
			$data['globals']['elementor_global_settings'] = $global_settings;
			$tw_colors = self::extract_elementor_colors( (array) $global_settings );
			if ( ! empty( $tw_colors ) ) {
				$data['globals']['colors'] = [];
				foreach ( $tw_colors as $k => $v ) {
					$data['globals']['colors'][] = [ 'name' => $k, 'value' => $v ];
				}
			}
		}

		$export_dir = trailingslashit( $work_dir . 'app-root/app' );
		wp_mkdir_p( $export_dir );
		file_put_contents( $export_dir . 'exportData.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		// Write CSS into app/globals.css to be imported by layout
		$css_dir = $export_dir;
		$existing = '';
		$globals_css_path = $css_dir . 'globals.css';
		if ( file_exists( $globals_css_path ) ) {
			$existing = (string) file_get_contents( $globals_css_path );
		}
		$css = "\n/* RWSB export: appended styles */\n";
		if ( $global_css ) {
			$css .= "\n/* Elementor global.css */\n" . $global_css . "\n";
		}
		// Append per-page CSS as comments; real Next.js project should scope per route
		foreach ( $data['pages'] as $page ) {
			if ( ! empty( $page['elementor_css'] ) ) {
				$css .= "\n/* Elementor post-" . $page['id'] . ".css */\n" . $page['elementor_css'] . "\n";
			}
		}
		file_put_contents( $globals_css_path, $existing . $css );

		// Inject Tailwind color palette if available
		if ( ! empty( $global_settings ) ) {
			$palette = self::extract_elementor_colors( (array) $global_settings );
			if ( ! empty( $palette ) ) {
				self::inject_tailwind_colors( trailingslashit( $work_dir . 'app-root' ) . 'tailwind.config.js', $palette );
			}
		}
	}

	/**
	 * Build Tailwind color palette from Elementor global settings structure.
	 */
	protected static function extract_elementor_colors( array $globals ): array {
		$palette = [];
		$possible_sets = [];
		if ( isset( $globals['system_colors']['colors'] ) && is_array( $globals['system_colors']['colors'] ) ) {
			$possible_sets[] = $globals['system_colors']['colors'];
		}
		if ( isset( $globals['system_colors'] ) && is_array( $globals['system_colors'] ) ) {
			$possible_sets[] = $globals['system_colors'];
		}
		if ( isset( $globals['theme_colors'] ) && is_array( $globals['theme_colors'] ) ) {
			$possible_sets[] = $globals['theme_colors'];
		}
		if ( isset( $globals['custom_colors'] ) && is_array( $globals['custom_colors'] ) ) {
			$possible_sets[] = $globals['custom_colors'];
		}

		foreach ( $possible_sets as $set ) {
			foreach ( (array) $set as $idx => $item ) {
				if ( ! is_array( $item ) ) continue;
				$raw = $item['color'] ?? $item['value'] ?? '';
				if ( ! is_string( $raw ) || $raw === '' ) continue;
				$name_raw = $item['title'] ?? $item['name'] ?? $item['_id'] ?? ( 'color_' . (int) $idx );
				$name = sanitize_title( (string) $name_raw );
				// Basic validation for color values (#, rgb, hsl)
				if ( preg_match( '/^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgb[a]?\(|hsl[a]?\()/', $raw ) ) ) {
					$palette[ $name ] = $raw;
				}
			}
		}

		return $palette;
	}

	/**
	 * Inject colors into tailwind.config.js under theme.extend.colors
	 */
	protected static function inject_tailwind_colors( string $config_path, array $palette ): void {
		if ( ! file_exists( $config_path ) ) return;
		$contents = (string) file_get_contents( $config_path );

		$colors_js = [];
		foreach ( $palette as $k => $v ) {
			// Ensure valid JS identifiers or quoted keys
			$key = preg_match( '/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $k ) ? $k : ( '"' . $k . '"' );
			$val = '"' . str_replace( '"', '\"', $v ) . '"';
			$colors_js[] = $key . ': ' . $val;
		}
		$colors_block = 'colors: { ' . implode( ', ', $colors_js ) . ' }';

		if ( strpos( $contents, 'extend: {}' ) !== false ) {
			$contents = str_replace( 'extend: {}', 'extend: { ' . $colors_block . ' }', $contents );
		} else if ( preg_match( '/extend:\s*\{/', $contents ) ) {
			$contents = preg_replace( '/extend:\s*\{/', 'extend: { ' . $colors_block . ', ', $contents, 1 );
		} else if ( preg_match( '/theme:\s*\{/', $contents ) ) {
			$contents = preg_replace( '/theme:\s*\{/', 'theme: { extend: { ' . $colors_block . ' }, ', $contents, 1 );
		} else {
			// Fallback: append a minimal theme.extend block
			$contents = rtrim( $contents );
			$contents .= "\n// RWSB injected colors\nmodule.exports.theme = { extend: { " . $colors_block . " } };\n";
		}

		file_put_contents( $config_path, $contents );
	}

	protected static function zip_directory( string $src_dir, string $zip_path ): void {
		if ( ! class_exists( 'ZipArchive' ) ) return;
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) return;
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		$src_dir_len = strlen( rtrim( $src_dir, '/' ) . '/' );
		foreach ( $files as $file ) {
			$path = (string) $file;
			$local = substr( $path, $src_dir_len );
			if ( is_dir( $path ) ) {
				$zip->addEmptyDir( $local );
			} else {
				$zip->addFile( $path, $local );
			}
		}
		$zip->close();
	}

	protected static function recurse_copy( string $src, string $dst ): void {
		wp_mkdir_p( $dst );
		$dir = opendir( $src );
		if ( ! $dir ) return;
		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( $file === '.' || $file === '.' ) continue;
			$from = $src . '/' . $file;
			$to   = $dst . '/' . $file;
			if ( is_dir( $from ) ) {
				self::recurse_copy( $from, $to );
			} else {
				copy( $from, $to );
			}
		}
		closedir( $dir );
	}

	protected static function maybe_json_decode( $raw ) {
		if ( is_array( $raw ) || is_object( $raw ) ) return $raw;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
		}
		return null;
	}

	protected static function read_elementor_css_for_post( int $post_id ): string {
		$upload = wp_upload_dir();
		$path = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $path ) ) {
			return (string) file_get_contents( $path );
		}
		$meta = get_post_meta( $post_id, '_elementor_css', true );
		if ( is_string( $meta ) ) return $meta;
		if ( is_array( $meta ) && isset( $meta['css'] ) ) return (string) $meta['css'];
		return '';
	}

	protected static function read_elementor_global_css(): string {
		$upload = wp_upload_dir();
		$path = trailingslashit( $upload['basedir'] ) . 'elementor/css/global.css';
		if ( file_exists( $path ) ) {
			return (string) file_get_contents( $path );
		}
		return '';
	}

	protected static function stream_zip_and_cleanup( string $zip_path ): void {
		if ( headers_sent() ) return;
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="nextjs-export.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path );
		@unlink( $zip_path );
		exit;
	}
}


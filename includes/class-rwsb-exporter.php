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
	}

	protected static function write_export_data( string $work_dir, array $post_ids ): void {
		$data = [ 'site' => [ 'url' => home_url() ], 'pages' => [] ];
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || $post->post_status !== 'publish' ) continue;
			$data['pages'][] = [
				'id' => $pid,
				'slug' => trim( get_post_field( 'post_name', $pid ) ),
				'title' => get_the_title( $pid ),
				'content' => apply_filters( 'the_content', $post->post_content ),
			];
		}
		$export_dir = trailingslashit( $work_dir . 'app-root/app' );
		wp_mkdir_p( $export_dir );
		file_put_contents( $export_dir . 'exportData.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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


<?php
/**
 * Local package creation and sending to the paired site.
 *
 * @package One_Click_Site_Push
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OCSP_Sender {
	/**
	 * Build and send a deployment package to the configured receiver.
	 *
	 * @return void Build and send a deployment package to the configured receiver.
	 */
	public static function push_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to publish this site.', 'one-click-site-push' ) );
		}
		check_admin_referer( 'ocsp_push_site' );
		$settings = self::settings();
		if ( ! $settings['destination_url'] || ! $settings['destination_token'] ) {
			self::redirect_with_result( 'Save the live URL and receiver secret before publishing.' );
		}
		if ( untrailingslashit( home_url() ) === untrailingslashit( $settings['destination_url'] ) ) {
			self::redirect_with_result( 'The live URL matches this site. No deployment was sent.' );
		}
		$mode = isset( $_POST['deployment_mode'] ) ? sanitize_key( wp_unslash( $_POST['deployment_mode'] ) ) : 'merge';
		if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
			$mode = 'merge';
		}
		if ( 'replace' === $mode ) {
			$receiver_check = self::check_full_clone_receiver( $settings );
			if ( is_wp_error( $receiver_check ) ) {
				self::redirect_with_result( $receiver_check->get_error_message() );
			}
		}
		$package = self::build_package( $mode );
		if ( is_wp_error( $package ) ) {
			self::redirect_with_result( $package->get_error_message() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the generated binary ZIP package for HTTP transfer.
		$body = file_get_contents( $package );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary ZIP after reading it.
		unlink( $package );
		if ( false === $body || strlen( $body ) > self::MAX_PACKAGE_BYTES ) {
			self::redirect_with_result( 'The deployment package is larger than the 250 MB limit or could not be read.' );
		}
		$endpoint = trailingslashit( $settings['destination_url'] ) . 'wp-json/' . self::REST_NAMESPACE . self::REST_ROUTE;
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 240,
				'headers'     => array(
					'Content-Type'                 => 'application/zip',
					'X-One-Click-Site-Push-Token'  => $settings['destination_token'],
					'X-One-Click-Site-Push-Source' => home_url(),
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			self::redirect_with_result( 'Could not reach the live receiver: ' . $response->get_error_message() );
		}
		$status        = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : '';
			if ( '' === $message && is_array( $data ) ) {
				$message = wp_json_encode( $data );
			}
			if ( '' === $message && is_string( $response_body ) ) {
				$message = preg_replace( '/\\s+/', ' ', wp_strip_all_tags( $response_body ) );
			}
			$message = $message ? substr( $message, 0, 500 ) : 'Receiver returned HTTP ' . $status . ' with no diagnostic message.';
			self::redirect_with_result( 'Deployment was not completed: ' . $message );
		}
		$summary = isset( $data['summary'] ) ? sanitize_text_field( $data['summary'] ) : 'Deployment completed.';
		self::redirect_with_result( $summary );
	}

	/**
	 * Confirm that the paired live receiver supports full-site replacement.
	 *
	 * @param array $settings Sender configuration.
	 * @return true|WP_Error True when the receiver advertises full-clone support.
	 */
	private static function check_full_clone_receiver( $settings ) {
		$endpoint = trailingslashit( $settings['destination_url'] ) . 'wp-json/' . self::REST_NAMESPACE . self::REST_ROUTE;
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'X-One-Click-Site-Push-Token' => $settings['destination_token'],
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ocsp_receiver_check', 'Could not verify full-replacement support on the live site: ' . $response->get_error_message() );
		}
		$features = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $features['full_clone'] ) || ! empty( $features['multisite'] ) ) {
			return new WP_Error( 'ocsp_receiver_outdated', 'The live site does not advertise full-site replacement support. Update One Click Site Push on the live site and enable its receiver before trying again.' );
		}
		return true;
	}

	/**
	 * Create a ZIP containing eligible content, media, and the active theme.
	 *
	 * @param mixed $mode Description of mode.
	 * @return string|WP_Error Create a ZIP containing eligible content, media, and the active theme.
	 */
	private static function build_package( $mode = 'merge' ) {
		if ( 'replace' === $mode && is_multisite() ) {
			return new WP_Error( 'ocsp_multisite_unsupported', 'Full-site replacement is not supported on WordPress Multisite.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ocsp_zip_missing', 'PHP ZipArchive is required on the local site.' );
		}
		$stylesheet       = get_stylesheet();
		$template         = get_template();
		$theme_dir        = get_stylesheet_directory();
		$parent_theme_dir = trailingslashit( get_theme_root( $template ) ) . $template;
		$includes_parent  = $template !== $stylesheet;
		$upload_dir       = wp_upload_dir();
		if ( ! is_dir( $theme_dir ) || ! is_dir( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'ocsp_source_missing', 'The active theme or uploads directory could not be found.' );
		}
		if ( $includes_parent && ! is_dir( $parent_theme_dir ) ) {
			return new WP_Error( 'ocsp_parent_theme_missing', 'The active child theme’s parent theme could not be found on the sending site.' );
		}
		$post_data       = array();
		$attachment_data = array();
		if ( 'merge' === $mode ) {
			$posts = get_posts(
				array(
					'post_type'   => array( 'page', 'post' ),
					'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'numberposts' => -1,
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			);
			foreach ( $posts as $post ) {
				$post_data[] = array(
					'id'             => (int) $post->ID,
					'post_type'      => $post->post_type,
					'post_status'    => $post->post_status,
					'post_title'     => $post->post_title,
					'post_name'      => $post->post_name,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_date'      => $post->post_date,
					'post_date_gmt'  => $post->post_date_gmt,
					'post_parent'    => (int) $post->post_parent,
					'menu_order'     => (int) $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				);
			}
			$attachments = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => -1,
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			);
			foreach ( $attachments as $attachment ) {
				$path = get_attached_file( $attachment->ID );
				$real = $path ? realpath( $path ) : false;
				$base = realpath( $upload_dir['basedir'] );
				if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
					continue;
				}
				$attachment_data[] = array(
					'id'          => (int) $attachment->ID,
					'relative'    => ltrim( str_replace( '\\', '/', substr( $real, strlen( $base ) ) ), '/' ),
					'title'       => $attachment->post_title,
					'caption'     => $attachment->post_excerpt,
					'description' => $attachment->post_content,
					'mime'        => $attachment->post_mime_type,
					'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
					'metadata'    => wp_get_attachment_metadata( $attachment->ID ),
				);
			}
		}
		$manifest = array(
			'version'         => 1,
			'deployment_mode' => 'replace' === $mode ? 'replace' : 'merge',
			'full_clone'      => 'replace' === $mode,
			'created'         => gmdate( 'c' ),
			'source_url'      => untrailingslashit( home_url() ),
			'source_siteurl'  => untrailingslashit( site_url() ),
			'source_upload'   => untrailingslashit( $upload_dir['baseurl'] ),
			'source_theme'    => untrailingslashit( get_stylesheet_directory_uri() ),
			'stylesheet'      => $stylesheet,
			'template'        => $template,
			'parent_included' => $includes_parent,
			'posts'           => $post_data,
			'attachments'     => $attachment_data,
			'front_page'      => (int) get_option( 'page_on_front' ),
			'posts_page'      => (int) get_option( 'page_for_posts' ),
			'show_on_front'   => get_option( 'show_on_front' ),
		);
		$tmp      = self::create_temp_file( 'ocsp-deploy-' );
		if ( ! $tmp ) {
			return new WP_Error( 'ocsp_temp_failed', 'Could not create a temporary deployment package.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the incomplete temporary ZIP.
			unlink( $tmp );
			return new WP_Error( 'ocsp_zip_failed', 'Could not create the deployment archive.' );
		}
		if ( 'replace' === $mode ) {
			if ( ! self::add_site_files_to_zip( $zip ) ) {
				$zip->close();
				wp_delete_file( $tmp );
				return new WP_Error( 'ocsp_site_files_zip', 'A plugin, theme, upload, or language file could not be added to the package.' );
			}
			$database = self::export_database( $zip, home_url(), site_url() );
			if ( is_wp_error( $database ) ) {
				$zip->close();
				self::cleanup_database_temp_files();
				wp_delete_file( $tmp );
				return $database;
			}
			$manifest['database'] = $database;
		} else {
			self::add_directory_to_zip( $zip, $theme_dir, 'theme/' );
			if ( $includes_parent ) {
				self::add_directory_to_zip( $zip, $parent_theme_dir, 'parent-theme/' );
			}
			self::add_directory_to_zip( $zip, $upload_dir['basedir'], 'uploads/' );
		}
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		$zip->close();
		self::cleanup_database_temp_files();
		return $tmp;
	}

	/**
	 * Add regular files from a directory to a ZIP archive.
	 *
	 * @param ZipArchive $zip       Archive receiving the files.
	 * @param mixed      $directory Directory whose files are added.
	 * @param mixed      $prefix    Archive entry prefix.
	 * @return bool Whether all regular files were added to the archive.
	 */
	private static function add_directory_to_zip( ZipArchive $zip, $directory, $prefix ) {
		$root = realpath( $directory );
		if ( ! $root ) {
			return false;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$path     = str_replace( '\\', '/', $file->getPathname() );
			$relative = ltrim( substr( $path, strlen( str_replace( '\\', '/', $root ) ) ), '/' );
			if ( false !== strpos( $prefix, 'uploads/' ) && ( 0 === strpos( $relative, 'one-click-site-push-backups/' ) || 0 === strpos( $relative, 'one-click-site-push/' ) ) ) {
				continue;
			}
			if ( ! $zip->addFile( $file->getPathname(), $prefix . $relative ) ) {
				return false;
			}
		}
		return true;
	}
}

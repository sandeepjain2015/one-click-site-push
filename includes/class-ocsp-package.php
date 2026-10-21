<?php
/**
 * Archive, media, path, and content remapping helpers.
 *
 * @package One_Click_Site_Push
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OCSP_Package {
	/**
	 * Return the WordPress content directories included in a full-site snapshot.
	 *
	 * @return array Directory names mapped to their local paths.
	 */
	private static function site_directories() {
		$upload_dir = wp_upload_dir();
		return array(
			'plugins'    => WP_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'mu-plugins' => WPMU_PLUGIN_DIR,
			'uploads'    => $upload_dir['basedir'],
			'languages'  => WP_LANG_DIR,
		);
	}

	/**
	 * Add all transferable WordPress content directories to an archive.
	 *
	 * @param ZipArchive $zip Archive receiving the site files.
	 * @return bool Whether all directories were added successfully.
	 */
	private static function add_site_files_to_zip( ZipArchive $zip ) {
		foreach ( self::site_directories() as $name => $directory ) {
			if ( ! $zip->addEmptyDir( 'site-files/' . $name . '/' ) ) {
				return false;
			}
			if ( is_dir( $directory ) && ! self::add_directory_to_zip( $zip, $directory, 'site-files/' . $name . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace the receiver's plugin, theme, must-use plugin, upload, and language files.
	 *
	 * @param ZipArchive $zip          Archive containing the sender's files.
	 * @param array      $manifest     Deployment manifest.
	 * @return true|WP_Error True or a filesystem error.
	 */
	private static function install_site_files_from_zip( ZipArchive $zip, $manifest ) {
		$temporary = self::create_temp_file( 'ocsp-files-' );
		if ( ! $temporary ) {
			return new WP_Error( 'ocsp_files_temp', 'A temporary folder for site files could not be created.', array( 'status' => 500 ) );
		}
		wp_delete_file( $temporary );
		$stage = $temporary . '-dir';
		if ( ! wp_mkdir_p( $stage ) || ! self::extract_prefix( $zip, 'site-files/', $stage ) ) {
			self::remove_tree( $stage );
			return new WP_Error( 'ocsp_files_extract', 'The sender site files could not be safely extracted.', array( 'status' => 500 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$filesystem     = new WP_Filesystem_Direct( null );
		$directory_mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
		$file_mode      = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		foreach ( self::site_directories() as $name => $destination ) {
			$source = trailingslashit( $stage ) . $name;
			if ( ! is_dir( $source ) ) {
				self::remove_tree( $stage );
				return new WP_Error( 'ocsp_files_missing', 'The package is missing the ' . $name . ' directory.', array( 'status' => 400 ) );
			}
			if ( file_exists( $destination ) && ! $filesystem->delete( $destination, true ) ) {
				self::remove_tree( $stage );
				return new WP_Error( 'ocsp_files_delete', 'The live ' . $name . ' directory could not be replaced.', array( 'status' => 500 ) );
			}
			if ( ! self::copy_directory( $source, $destination, $filesystem, $directory_mode, $file_mode ) ) {
				self::remove_tree( $stage );
				return new WP_Error( 'ocsp_files_copy', 'The sender ' . $name . ' directory could not be installed.', array( 'status' => 500 ) );
			}
		}
		self::remove_tree( $stage );
		self::rewrite_site_references( $manifest );
		return true;
	}

	/**
	 * Copy a directory tree with WordPress filesystem methods.
	 *
	 * @param string               $source Source directory.
	 * @param string               $destination Destination directory.
	 * @param WP_Filesystem_Direct $filesystem Direct filesystem instance.
	 * @param int                  $directory_mode Permissions for created directories.
	 * @param int                  $file_mode Permissions for copied files.
	 * @return bool Whether the directory tree was copied.
	 */
	private static function copy_directory( $source, $destination, WP_Filesystem_Direct $filesystem, $directory_mode, $file_mode ) {
		$root = realpath( $source );
		if ( ! $root || ! $filesystem->mkdir( $destination, $directory_mode ) ) {
			return false;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				continue;
			}
			$relative = ltrim( substr( $item->getPathname(), strlen( $root ) ), DIRECTORY_SEPARATOR );
			$target   = trailingslashit( $destination ) . $relative;
			if ( $item->isDir() ) {
				if ( ! $filesystem->mkdir( $target, $directory_mode ) && ! $filesystem->is_dir( $target ) ) {
					return false;
				}
			} elseif ( ! $filesystem->copy( $item->getPathname(), $target, true, $file_mode ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Rewrite sender URLs in copied plugin, theme, media, and language text files.
	 *
	 * @param array $manifest Deployment manifest.
	 * @return void Rewrite sender URLs in copied site files.
	 */
	private static function rewrite_site_references( $manifest ) {
		$upload_dir   = wp_upload_dir();
		$theme_slug   = sanitize_key( $manifest['stylesheet'] ?? '' );
		$replacements = array(
			untrailingslashit( $manifest['source_url'] ?? '' )     => untrailingslashit( home_url() ),
			untrailingslashit( $manifest['source_siteurl'] ?? '' ) => untrailingslashit( site_url() ),
			untrailingslashit( $manifest['source_upload'] ?? '' )  => untrailingslashit( $upload_dir['baseurl'] ),
		);
		if ( ! empty( $manifest['source_theme'] ) && $theme_slug ) {
			$replacements[ untrailingslashit( $manifest['source_theme'] ) ] = untrailingslashit( trailingslashit( get_theme_root_uri() ) . $theme_slug );
		}
		$replacements = array_filter(
			$replacements,
			static function ( $source ) {
				return '' !== $source;
			}
		);
		foreach ( self::site_directories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || $file->isLink() || ! preg_match( '/\.(php|html|css|js|json|txt|xml|svg)$/i', $file->getFilename() ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read copied site files to update sender URLs.
				$content = file_get_contents( $file->getPathname() );
				if ( false === $content ) {
					continue;
				}
				$updated = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
				if ( $updated !== $content ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Save rewritten URLs to the copied site files.
					file_put_contents( $file->getPathname(), $updated, LOCK_EX );
				}
			}
		}
	}

	/**
	 * Restrict an imported post status to supported WordPress statuses.
	 *
	 * @param mixed $status Description of status.
	 * @return string Restrict an imported post status to supported WordPress statuses.
	 */
	private static function safe_status( $status ) {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Create a temporary file using the configured system temp directory.
	 *
	 * @param mixed $prefix      Archive entry prefix.
	 * @return string|false Create a temporary file using the configured system temp directory.
	 */
	private static function create_temp_file( $prefix ) {
		$directory = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$directory = trailingslashit( $directory );
		$prefix    = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $prefix );
		return tempnam( $directory, substr( $prefix ? $prefix : 'ocsp-', 0, 20 ) );
	}

	/**
	 * Safely extract archive entries under a named prefix.
	 *
	 * @param ZipArchive $zip         Archive being extracted.
	 * @param mixed      $prefix      Archive entry prefix.
	 * @param mixed      $destination Extraction destination.
	 * @return bool Safely extract archive entries under a named prefix.
	 */
	private static function extract_prefix( ZipArchive $zip, $prefix, $destination ) {
		$prefix = rtrim( $prefix, '/' ) . '/';
		$root   = realpath( $destination );
		if ( ! $root ) {
			return false;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this PHP extension property.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$relative = substr( $name, strlen( $prefix ) );
			if ( '' === $relative || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '\\' ) || preg_match( '#(^/|(^|/)\.\.?(/|$))#', $relative ) ) {
				return false;
			}
			$target = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( substr( $name, -1 ) === '/' ) {
				if ( ! wp_mkdir_p( $target ) ) {
					return false;
				}
				continue;
			}
			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				return false;
			}
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- Stream extraction is required for large ZIP entries.
			$out = fopen( $target, 'wb' );
			if ( ! $out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close archive streams opened with ZipArchive::getStream().
				fclose( $stream );
				return false;
			}
			stream_copy_to_stream( $stream, $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close archive streams opened with ZipArchive::getStream().
			fclose( $stream );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the extraction output stream.
			fclose( $out );
		}
		return true;
	}

	/**
	 * Register extracted files as WordPress media library attachments.
	 *
	 * @param mixed $attachments Description of attachments.
	 * @param mixed $upload_root Description of upload root.
	 * @param mixed $upload_dir Description of upload dir.
	 * @return array|WP_Error Register extracted files as WordPress media library attachments.
	 */
	private static function import_attachments( $attachments, $upload_root, $upload_dir ) {
		$map            = array();
		$deployment_rel = 'one-click-site-push/' . basename( $upload_root );
		foreach ( $attachments as $attachment ) {
			$relative = self::safe_relative_path( $attachment['relative'] ?? '' );
			if ( ! $relative ) {
				continue;
			}
			$path = trailingslashit( $upload_root ) . $relative;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$attached_file = $deployment_rel . '/' . $relative;
			$file_url      = trailingslashit( $upload_dir['baseurl'] ) . $attached_file;
			$id            = wp_insert_attachment(
				array(
					'post_mime_type' => sanitize_mime_type( $attachment['mime'] ?? 'application/octet-stream' ),
					'post_title'     => sanitize_text_field( $attachment['title'] ?? '' ),
					'post_excerpt'   => sanitize_text_field( $attachment['caption'] ?? '' ),
					'post_content'   => sanitize_textarea_field( $attachment['description'] ?? '' ),
					'post_status'    => 'inherit',
					'guid'           => esc_url_raw( $file_url ),
				),
				$path,
				0,
				true
			);
			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'ocsp_media_import', 'A media item could not be registered: ' . $id->get_error_message(), array( 'status' => 500 ) );
			}
			update_attached_file( $id, $path );
			if ( ! empty( $attachment['metadata'] ) && is_array( $attachment['metadata'] ) ) {
				$metadata         = $attachment['metadata'];
				$metadata['file'] = $attached_file;
				wp_update_attachment_metadata( $id, $metadata );
			}
			if ( ! empty( $attachment['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $attachment['alt'] ) );
			}
			$map[ (int) $attachment['id'] ] = (int) $id;
		}
		return $map;
	}

	/**
	 * Validate and normalize an archive-relative file path.
	 *
	 * @param mixed $path Description of path.
	 * @return string|false Validate and normalize an archive-relative file path.
	 */
	private static function safe_relative_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, ':' ) || false !== strpos( $path, '\\' ) || preg_match( '#(^/|(^|/)\.\.?(/|$))#', $path ) ) {
			return false;
		}
		return ltrim( $path, '/' );
	}

	/**
	 * Rewrite imported content URLs and references for the receiving site.
	 *
	 * @param mixed $content Description of content.
	 * @param mixed $manifest Description of manifest.
	 * @param mixed $upload_dir Description of upload dir.
	 * @param mixed $deployment_id Description of deployment id.
	 * @param mixed $attachment_map Description of attachment map.
	 * @param mixed $post_map Description of post map.
	 * @return string Rewrite imported content URLs and references for the receiving site.
	 */
	private static function remap_content( $content, $manifest, $upload_dir, $deployment_id, $attachment_map, $post_map ) {
		$blocks  = parse_blocks( $content );
		$blocks  = self::remap_blocks( $blocks, $attachment_map );
		$content = serialize_blocks( $blocks );
		$content = str_replace( untrailingslashit( $manifest['source_url'] ), untrailingslashit( home_url() ), $content );
		$content = str_replace( untrailingslashit( $manifest['source_upload'] ), trailingslashit( $upload_dir['baseurl'] ) . 'one-click-site-push/' . $deployment_id, $content );
		$content = preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $match ) use ( $attachment_map ) {
				$old = (int) $match[1];
				return 'wp-image-' . ( $attachment_map[ $old ] ?? $old );
			},
			$content
		);
		$content = preg_replace_callback(
			'/([?&]page_id=)(\d+)/',
			function ( $match ) use ( $post_map ) {
				$old = (int) $match[2];
				return $match[1] . ( $post_map[ $old ] ?? $old );
			},
			$content
		);
		return $content;
	}

	/**
	 * Remap image block attachment IDs recursively.
	 *
	 * @param mixed $blocks Description of blocks.
	 * @param mixed $attachment_map Description of attachment map.
	 * @return array Remap image block attachment IDs recursively.
	 */
	private static function remap_blocks( $blocks, $attachment_map ) {
		foreach ( $blocks as &$block ) {
			if ( 'core/image' === ( $block['blockName'] ?? '' ) && isset( $block['attrs']['id'] ) ) {
				$source_id = (int) $block['attrs']['id'];
				if ( isset( $attachment_map[ $source_id ] ) ) {
					$block['attrs']['id'] = $attachment_map[ $source_id ];
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::remap_blocks( $block['innerBlocks'], $attachment_map );
			}
		}
		return $blocks;
	}

	/**
	 * Rewrite source URLs in extracted theme text files.
	 *
	 * @param mixed $theme_path Description of theme path.
	 * @param mixed $manifest Description of manifest.
	 * @param mixed $upload_dir Description of upload dir.
	 * @param mixed $deployment_id Description of deployment id.
	 * @param mixed $post_map Description of post map.
	 * @return void Rewrite source URLs in extracted theme text files.
	 */
	private static function rewrite_theme_references( $theme_path, $manifest, $upload_dir, $deployment_id, $post_map ) {
		$iterator         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_path, FilesystemIterator::SKIP_DOTS ) );
		$theme_url        = trailingslashit( get_theme_root_uri() ) . basename( $theme_path );
		$source_theme_url = untrailingslashit( $manifest['source_theme'] ?? ( trailingslashit( get_theme_root_uri() ) . sanitize_key( $manifest['stylesheet'] ) ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || ! preg_match( '/\.(php|html|css|js|json|txt|xml)$/i', $file->getFilename() ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local theme files for URL remapping.
			$data = file_get_contents( $file->getPathname() );
			if ( false === $data ) {
				continue;
			}
			$data = str_replace( untrailingslashit( $manifest['source_url'] ), untrailingslashit( home_url() ), $data );
			$data = str_replace( untrailingslashit( $manifest['source_upload'] ), trailingslashit( $upload_dir['baseurl'] ) . 'one-click-site-push/' . $deployment_id, $data );
			$data = str_replace( untrailingslashit( $source_theme_url ), untrailingslashit( $theme_url ), $data );
			$data = preg_replace_callback(
				'/([?&]page_id=)(\d+)/',
				function ( $match ) use ( $post_map ) {
					$old = (int) $match[2];
					return $match[1] . ( $post_map[ $old ] ?? $old );
				},
				$data
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Write the remapped local theme file.
			file_put_contents( $file->getPathname(), $data, LOCK_EX );
		}
	}
}

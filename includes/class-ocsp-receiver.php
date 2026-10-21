<?php
/**
 * Authenticated REST receiver and live-site deployment routines.
 *
 * @package One_Click_Site_Push
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OCSP_Receiver {
	/**
	 * Register the authenticated deployment REST route.
	 *
	 * @return void Register the authenticated deployment REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'receiver_info' ),
					'permission_callback' => array( __CLASS__, 'authorize_receiver' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'receive_deployment' ),
					'permission_callback' => array( __CLASS__, 'authorize_receiver' ),
				),
			)
		);
	}

	/**
	 * Report receiver features to an authenticated sender before packaging a full clone.
	 *
	 * @return WP_REST_Response Receiver feature information.
	 */
	public static function receiver_info() {
		return rest_ensure_response(
			array(
				'version'    => self::VERSION,
				'full_clone' => true,
				'multisite'  => is_multisite(),
			)
		);
	}

	/**
	 * Authorize a deployment request using the shared secret.
	 *
	 * @param WP_REST_Request $request Description of request.
	 * @return true|WP_Error Authorize a deployment request using the shared secret.
	 */
	public static function authorize_receiver( WP_REST_Request $request ) {
		$settings = self::settings();
		$token    = (string) $request->get_header( 'x-one-click-site-push-token' );
		if ( ! $settings['receiver_enabled'] || ! $settings['receiver_secret'] || ! $token || ! hash_equals( (string) $settings['receiver_secret'], $token ) ) {
			return new WP_Error( 'ocsp_unauthorized', 'Deployment receiver is disabled or the shared secret is invalid.', array( 'status' => 401 ) );
		}
		return true;
	}


	/**
	 * Handle a deployment request and report receiver failures.
	 *
	 * @param WP_REST_Request $request Description of request.
	 * @return WP_REST_Response|WP_Error Handle a deployment request and report receiver failures.
	 */
	public static function receive_deployment( WP_REST_Request $request ) {
		self::$receiver_stage = 'initializing';
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		try {
			return self::receive_deployment_impl( $request );
		} catch ( Throwable $error ) {
			$detail = sprintf( 'Receiver failed during %s: %s', self::$receiver_stage, $error->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Record actionable receiver diagnostics for hosting error logs.
			error_log( '[One Click Site Push] ' . $detail . ' in ' . $error->getFile() . ':' . $error->getLine() );
			return new WP_Error( 'ocsp_receiver_failure', $detail, array( 'status' => 500 ) );
		}
	}

	/**
	 * Validate and apply a deployment package on the receiving site.
	 *
	 * @param WP_REST_Request $request Description of request.
	 * @return WP_REST_Response|WP_Error Validate and apply a deployment package on the receiving site.
	 */
	private static function receive_deployment_impl( WP_REST_Request $request ) {
		self::$receiver_stage = 'reading package';
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ocsp_zip_missing', 'PHP ZipArchive is required on the live site.', array( 'status' => 500 ) );
		}
		$body = $request->get_body();
		if ( ! $body || strlen( $body ) > self::MAX_PACKAGE_BYTES ) {
			return new WP_Error( 'ocsp_package_size', 'The deployment package is empty or exceeds 250 MB.', array( 'status' => 413 ) );
		}
		$tmp = self::create_temp_file( 'ocsp-incoming-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Stage the raw ZIP request body as a binary temporary file.
		if ( ! $tmp || false === file_put_contents( $tmp, $body, LOCK_EX ) ) {
			return new WP_Error( 'ocsp_temp_failed', 'Could not stage the received deployment package.', array( 'status' => 500 ) );
		}
		unset( $body );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
			unlink( $tmp );
			return new WP_Error( 'ocsp_bad_zip', 'The received deployment package is not a valid ZIP archive.', array( 'status' => 400 ) );
		}
		$manifest_json        = $zip->getFromName( 'manifest.json' );
		self::$receiver_stage = 'validating package';
		$manifest             = $manifest_json ? json_decode( $manifest_json, true ) : null;
		if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['version'] ?? 0 ) || ( empty( $manifest['full_clone'] ) && empty( $manifest['posts'] ) ) || empty( $manifest['stylesheet'] ) || empty( $manifest['template'] ) ) {
			$zip->close();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
			unlink( $tmp );
			return new WP_Error( 'ocsp_bad_manifest', 'The deployment manifest is missing required site data.', array( 'status' => 400 ) );
		}
		if ( ! empty( $manifest['full_clone'] ) ) {
			return self::receive_full_clone( $zip, $manifest, $tmp );
		}
		$template_slug    = sanitize_key( $manifest['template'] );
		$stylesheet_slug  = sanitize_key( $manifest['stylesheet'] );
		$has_parent_theme = $template_slug !== $stylesheet_slug;
		if ( $has_parent_theme && ! wp_get_theme( $template_slug )->exists() ) {
			if ( empty( $manifest['parent_included'] ) || ! $template_slug ) {
				$zip->close();
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
				unlink( $tmp );
				return new WP_Error( 'ocsp_parent_theme_missing', 'The deployment package does not include the missing parent theme.', array( 'status' => 409 ) );
			}
			$parent_theme_path    = trailingslashit( get_theme_root() ) . $template_slug;
			self::$receiver_stage = 'installing parent theme';
			if ( ! wp_mkdir_p( $parent_theme_path ) || ! self::extract_prefix( $zip, 'parent-theme/', $parent_theme_path ) ) {
				$zip->close();
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
				unlink( $tmp );
				return new WP_Error( 'ocsp_parent_theme_install', 'The bundled parent theme could not be installed on the live site.', array( 'status' => 500 ) );
			}
			wp_clean_themes_cache( true );
			if ( ! wp_get_theme( $template_slug )->exists() ) {
				$zip->close();
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
				unlink( $tmp );
				return new WP_Error( 'ocsp_parent_theme_invalid', 'The bundled parent theme files were incomplete or invalid.', array( 'status' => 500 ) );
			}
		}
		$deployment_id        = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 5, false, false ) );
		$upload_dir           = wp_upload_dir();
		$upload_root          = trailingslashit( $upload_dir['basedir'] ) . 'one-click-site-push/' . $deployment_id;
		$theme_root           = trailingslashit( get_theme_root() );
		$source_theme_slug    = sanitize_key( $manifest['stylesheet'] );
		$theme_slug           = sanitize_key( $source_theme_slug . '-release-' . str_replace( '-', '', $deployment_id ) );
		$theme_path           = $theme_root . $theme_slug;
		$dirs_ok              = wp_mkdir_p( $upload_root ) && wp_mkdir_p( $theme_path );
		self::$receiver_stage = 'extracting theme and media';
		if ( ! $dirs_ok || ! self::extract_prefix( $zip, 'uploads/', $upload_root ) || ! self::extract_prefix( $zip, 'theme/', $theme_path ) ) {
			$zip->close();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove the temporary archive after processing.
			unlink( $tmp );
			return new WP_Error( 'ocsp_extract_failed', 'Could not safely unpack the deployment files.', array( 'status' => 500 ) );
		}
		$zip->close();
		unlink( $tmp );

		$attachment_map       = self::import_attachments( $manifest['attachments'] ?? array(), $upload_root, $upload_dir );
		self::$receiver_stage = 'importing attachments';
		if ( is_wp_error( $attachment_map ) ) {
			return $attachment_map;
		}
		$post_map             = array();
		self::$receiver_stage = 'importing pages and posts';
		$source_posts         = $manifest['posts'];
		foreach ( $source_posts as $source_post ) {
			if ( empty( $source_post['post_type'] ) || ! in_array( $source_post['post_type'], array( 'page', 'post' ), true ) ) {
				continue;
			}
			$slug        = sanitize_title( $source_post['post_name'] ?? $source_post['post_title'] ?? '' );
			$type        = $source_post['post_type'];
			$matches     = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => $type,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$existing_id = $matches ? (int) $matches[0] : 0;
			$postarr     = array(
				'post_type'      => $type,
				'post_status'    => self::safe_status( $source_post['post_status'] ?? 'draft' ),
				'post_title'     => sanitize_text_field( $source_post['post_title'] ?? '' ),
				'post_name'      => $slug,
				'post_content'   => '',
				'post_excerpt'   => sanitize_textarea_field( $source_post['post_excerpt'] ?? '' ),
				'post_date'      => sanitize_text_field( $source_post['post_date'] ?? current_time( 'mysql' ) ),
				'post_date_gmt'  => sanitize_text_field( $source_post['post_date_gmt'] ?? current_time( 'mysql', true ) ),
				'post_parent'    => 0,
				'menu_order'     => absint( $source_post['menu_order'] ?? 0 ),
				'comment_status' => 'open' === ( $source_post['comment_status'] ?? '' ) ? 'open' : 'closed',
				'ping_status'    => 'open' === ( $source_post['ping_status'] ?? '' ) ? 'open' : 'closed',
			);
			if ( $existing_id ) {
				$postarr['ID'] = $existing_id;
				wp_save_post_revision( $existing_id );
			}
			$target_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $target_id ) ) {
				return new WP_Error( 'ocsp_post_import', 'A page or post could not be imported: ' . $target_id->get_error_message(), array( 'status' => 500 ) );
			}
			$post_map[ (int) $source_post['id'] ] = (int) $target_id;
		}
		self::$receiver_stage = 'remapping page content and links';
		foreach ( $source_posts as $source_post ) {
			$source_id = (int) ( $source_post['id'] ?? 0 );
			if ( empty( $post_map[ $source_id ] ) ) {
				continue;
			}
			$content   = self::remap_content( $source_post['post_content'] ?? '', $manifest, $upload_dir, $deployment_id, $attachment_map, $post_map );
			$parent_id = (int) ( $source_post['post_parent'] ?? 0 );
			wp_update_post(
				array(
					'ID'           => $post_map[ $source_id ],
					'post_content' => $content,
					'post_parent'  => $post_map[ $parent_id ] ?? 0,
				),
				true
			);
		}
		self::rewrite_theme_references( $theme_path, $manifest, $upload_dir, $deployment_id, $post_map );
		self::$receiver_stage = 'updating front page and theme';
		update_option( 'show_on_front', 'posts' === ( $manifest['show_on_front'] ?? '' ) ? 'posts' : 'page' );
		$front_id = (int) ( $manifest['front_page'] ?? 0 );
		$posts_id = (int) ( $manifest['posts_page'] ?? 0 );
		update_option( 'page_on_front', $post_map[ $front_id ] ?? 0 );
		update_option( 'page_for_posts', $post_map[ $posts_id ] ?? 0 );
		wp_clean_themes_cache( true );
		$new_theme = wp_get_theme( $theme_slug );
		if ( ! $new_theme->exists() ) {
			return new WP_Error( 'ocsp_theme_invalid', 'The theme files were copied, but WordPress could not load the new theme.', array( 'status' => 500 ) );
		}
		switch_theme( $theme_slug );
		$replace_mode = 'replace' === ( $manifest['deployment_mode'] ?? 'merge' );
		if ( $replace_mode ) {
			self::$receiver_stage = 'replacing managed live data';
			self::delete_unmatched_content( $post_map );
			self::delete_unmatched_attachments( $attachment_map );
			self::delete_previous_deployments( $upload_dir['basedir'], $deployment_id );
			self::delete_previous_theme_releases( $theme_root, $source_theme_slug, $theme_slug );
		}
		self::$receiver_stage = 'complete';
		return rest_ensure_response(
			array(
				'summary' => sprintf( 'Deployment completed in %s mode: %d pages/posts and %d media items published.%s', $replace_mode ? 'replace' : 'keep live-only data', count( $post_map ), count( $attachment_map ), $replace_mode ? ' Unmatched managed live content and previous generated deployment files were removed.' : ' Live-only content and previous theme files were kept.' ),
				'theme'   => $theme_slug,
			)
		);
	}

	/**
	 * Replace a single-site WordPress installation from a complete sender snapshot.
	 *
	 * @param ZipArchive $zip      Incoming deployment archive.
	 * @param array      $manifest Deployment manifest.
	 * @param string     $package  Temporary path to the incoming archive.
	 * @return WP_REST_Response|WP_Error Deployment result.
	 */
	private static function receive_full_clone( ZipArchive $zip, $manifest, $package ) {
		if ( is_multisite() ) {
			$zip->close();
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_multisite_unsupported', 'Full-site replacement is not supported on WordPress Multisite.', array( 'status' => 409 ) );
		}
		if ( empty( $manifest['database']['tables'] ) || empty( $manifest['source_url'] ) || empty( $manifest['source_siteurl'] ) ) {
			$zip->close();
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_full_clone_manifest', 'The full-site package is missing its database snapshot or source URLs.', array( 'status' => 400 ) );
		}
		$backup_path = self::create_temp_file( 'ocsp-rollback-' );
		if ( ! $backup_path ) {
			$zip->close();
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_rollback_failed', 'A rollback snapshot could not be prepared, so the live site was not changed.', array( 'status' => 500 ) );
		}
		$temp_root = realpath( dirname( $backup_path ) );
		$web_root  = realpath( ABSPATH );
		if ( $temp_root && $web_root && 0 === strpos( trailingslashit( $temp_root ), trailingslashit( $web_root ) ) ) {
			wp_delete_file( $backup_path );
			$zip->close();
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_rollback_private_path', 'The server temporary directory is inside the public WordPress directory. Full replacement was stopped to avoid creating a public database backup.', array( 'status' => 500 ) );
		}
		wp_delete_file( $backup_path );
		$backup_zip = new ZipArchive();
		if ( true !== $backup_zip->open( $backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$zip->close();
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_rollback_failed', 'A rollback archive could not be opened, so the live site was not changed.', array( 'status' => 500 ) );
		}
		$backup_database = self::export_database( $backup_zip, home_url(), site_url() );
		if ( is_wp_error( $backup_database ) || ! self::add_site_files_to_zip( $backup_zip ) ) {
			$backup_zip->close();
			self::cleanup_database_temp_files();
			$zip->close();
			wp_delete_file( $backup_path );
			wp_delete_file( $package );
			return is_wp_error( $backup_database ) ? $backup_database : new WP_Error( 'ocsp_rollback_failed', 'The live site rollback files could not be saved.', array( 'status' => 500 ) );
		}
		$backup_manifest = array(
			'version'        => 1,
			'full_clone'     => true,
			'source_url'     => untrailingslashit( home_url() ),
			'source_siteurl' => untrailingslashit( site_url() ),
			'source_upload'  => untrailingslashit( wp_upload_dir()['baseurl'] ),
			'source_theme'   => untrailingslashit( get_stylesheet_directory_uri() ),
			'stylesheet'     => get_stylesheet(),
			'database'       => $backup_database,
		);
		$backup_zip->addFromString( 'manifest.json', wp_json_encode( $backup_manifest ) );
		$backup_zip->close();
		self::cleanup_database_temp_files();
		$zip->close();
		$target_home     = untrailingslashit( home_url() );
		$target_siteurl  = untrailingslashit( site_url() );
		$target_settings = self::settings();
		if ( true !== $zip->open( $package ) ) {
			wp_delete_file( $backup_path );
			wp_delete_file( $package );
			return new WP_Error( 'ocsp_package_reopen', 'The incoming deployment package could not be reopened. The live site was not changed.', array( 'status' => 500 ) );
		}
		self::$receiver_stage = 'installing sender site files';
		$result               = self::install_site_files_from_zip( $zip, $manifest );
		if ( ! is_wp_error( $result ) ) {
			self::$receiver_stage = 'replacing the live database';
			$result               = self::import_database( $zip, $manifest['database'], $manifest['source_url'], $manifest['source_siteurl'], $target_home, $target_siteurl );
		}
		if ( ! is_wp_error( $result ) ) {
			self::$receiver_stage = 'restoring destination URL and deployment access';
			$result               = self::restore_destination_options( $target_home, $target_siteurl, $target_settings );
		}
		$zip->close();
		if ( is_wp_error( $result ) ) {
			$backup         = new ZipArchive();
			$rollback_error = false;
			if ( true === $backup->open( $backup_path ) ) {
				$backup_manifest_json = $backup->getFromName( 'manifest.json' );
				$backup_data          = $backup_manifest_json ? json_decode( $backup_manifest_json, true ) : null;
				if ( is_array( $backup_data ) ) {
					$files_result   = self::install_site_files_from_zip( $backup, $backup_data );
					$db_result      = is_wp_error( $files_result ) ? $files_result : self::import_database( $backup, $backup_data['database'], $backup_data['source_url'], $backup_data['source_siteurl'], $target_home, $target_siteurl );
					$rollback_error = is_wp_error( $db_result );
				} else {
					$rollback_error = true;
				}
				$backup->close();
			} else {
				$rollback_error = true;
			}
			wp_delete_file( $backup_path );
			wp_delete_file( $package );
			if ( $rollback_error ) {
				return new WP_Error( 'ocsp_rollback_failed', 'Deployment failed and automatic rollback also failed. Restore the live site backup immediately. Original error: ' . $result->get_error_message(), array( 'status' => 500 ) );
			}
			return new WP_Error( 'ocsp_full_clone_failed', 'Deployment failed. The live site was restored from its pre-deployment snapshot. ' . $result->get_error_message(), array( 'status' => 500 ) );
		}
		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		wp_delete_file( $backup_path );
		wp_delete_file( $package );
		self::$receiver_stage = 'complete';
		return rest_ensure_response(
			array(
				'summary' => 'Full-site replacement completed: the sender database, users, plugins, themes, media, and WordPress settings were copied. The receiving site URL, wp-config.php, and deployment receiver secret were retained.',
			)
		);
	}

	/**
	 * Restore receiver connection settings without running unrelated option hooks.
	 *
	 * The direct database updates avoid third-party hooks firing during a full-site
	 * replacement when the sender's plugin files have replaced those integrations.
	 *
	 * @param string $home_url Receiver home URL.
	 * @param string $site_url Receiver WordPress URL.
	 * @param array  $settings Receiver settings saved before import.
	 * @return true|WP_Error Whether all receiver-specific options were restored.
	 */
	private static function restore_destination_options( $home_url, $site_url, $settings ) {
		global $wpdb;
		wp_cache_flush();
		$source_settings                     = get_option( self::OPTION, array() );
		$source_settings                     = is_array( $source_settings ) ? $source_settings : array();
		$source_settings['receiver_enabled'] = $settings['receiver_enabled'];
		$source_settings['receiver_secret']  = $settings['receiver_secret'];
		$options                             = array(
			'home'       => $home_url,
			'siteurl'    => $site_url,
			self::OPTION => maybe_serialize( $source_settings ),
		);
		foreach ( $options as $name => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypass third-party option hooks during plugin replacement; the object cache is flushed below.
			$updated = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => $value ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'ocsp_receiver_settings_restore', 'The receiving site URL or deployment access settings could not be restored.', array( 'status' => 500 ) );
			}
		}
		wp_cache_flush();
		return true;
	}

	/**
	 * Delete pages and posts not present in the incoming deployment.
	 *
	 * @param mixed $post_map Description of post map.
	 * @return void Delete pages and posts not present in the incoming deployment.
	 */
	private static function delete_unmatched_content( $post_map ) {
		$keep = array_map( 'intval', array_values( $post_map ) );
		$ids  = get_posts(
			array(
				'post_type'   => array( 'page', 'post' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			if ( ! in_array( (int) $id, $keep, true ) ) {
				wp_delete_post( (int) $id, true );
			}
		}
	}

	/**
	 * Delete media items not present in the incoming deployment.
	 *
	 * @param mixed $attachment_map Description of attachment map.
	 * @return void Delete media items not present in the incoming deployment.
	 */
	private static function delete_unmatched_attachments( $attachment_map ) {
		$keep = array_map( 'intval', array_values( $attachment_map ) );
		$ids  = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			if ( ! in_array( (int) $id, $keep, true ) ) {
				wp_delete_attachment( (int) $id, true );
			}
		}
	}

	/**
	 * Remove older plugin-managed upload deployment folders.
	 *
	 * @param mixed $basedir Description of basedir.
	 * @param mixed $current_id Description of current id.
	 * @return void Remove older plugin-managed upload deployment folders.
	 */
	private static function delete_previous_deployments( $basedir, $current_id ) {
		$root = trailingslashit( $basedir ) . 'one-click-site-push';
		if ( ! is_dir( $root ) ) {
			return;
		}
		foreach ( glob( trailingslashit( $root ) . '*' ) as $path ) {
			if ( is_dir( $path ) && basename( $path ) !== $current_id ) {
				self::remove_tree( $path );
			}
		}
	}

	/**
	 * Remove older generated releases for the source theme.
	 *
	 * @param mixed $theme_root Description of theme root.
	 * @param mixed $source_slug Description of source slug.
	 * @param mixed $current_slug Description of current slug.
	 * @return void Remove older generated releases for the source theme.
	 */
	private static function delete_previous_theme_releases( $theme_root, $source_slug, $current_slug ) {
		$prefix = sanitize_key( $source_slug . '-release-' );
		foreach ( glob( trailingslashit( $theme_root ) . $prefix . '*' ) as $path ) {
			if ( is_dir( $path ) && basename( $path ) !== $current_slug ) {
				self::remove_tree( $path );
			}
		}
	}

	/**
	 * Recursively remove a file or directory without following symbolic links.
	 *
	 * @param mixed $path Description of path.
	 * @return bool Recursively remove a file or directory without following symbolic links.
	 */
	private static function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup should attempt removal without surfacing filesystem warnings.
			return unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			self::remove_tree( $item->getPathname() );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup should attempt removal without surfacing filesystem warnings.
		return rmdir( $path );
	}
}

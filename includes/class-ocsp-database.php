<?php
/**
 * Database export and import helpers for full-site replacement deployments.
 *
 * @package One_Click_Site_Push
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OCSP_Database {
	/**
	 * Temporarily disable foreign key checks while replacing site tables.
	 *
	 * @param bool $enabled Whether foreign key checks should be enabled.
	 * @return void Update the current database connection setting.
	 */
	private static function set_foreign_key_checks( $enabled ) {
		global $wpdb;
		$value = $enabled ? 1 : 0;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=' . $value ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Value is strictly limited to integer 0 or 1 for table replacement.
	}

	/**
	 * Drop a validated receiver table before importing its sender equivalent.
	 *
	 * @param string $table Validated receiver table name.
	 * @return void Drop the receiver table.
	 */
	private static function drop_site_table( $table ) {
		global $wpdb;
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $quoted ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Table is obtained from the database and escaped as a quoted identifier; replacement follows a rollback snapshot.
	}

	/**
	 * Remove temporary files used to archive database table rows.
	 *
	 * @return void Remove database staging files.
	 */
	private static function cleanup_database_temp_files() {
		foreach ( self::$database_temp_files as $path ) {
			wp_delete_file( $path );
		}
		self::$database_temp_files = array();
	}

	/**
	 * Add the sender site's prefixed database tables and rows to the archive.
	 *
	 * @param ZipArchive $zip          Archive receiving the database snapshot.
	 * @param string     $source_url Sender's public home URL.
	 * @param string     $source_siteurl Sender's WordPress installation URL.
	 * @return array|WP_Error Database table manifest or an export error.
	 */
	private static function export_database( ZipArchive $zip, $source_url, $source_siteurl ) {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full migration must enumerate all WordPress-prefixed tables.
		if ( ! is_array( $tables ) ) {
			return new WP_Error( 'ocsp_database_read', 'The sender database tables could not be listed.' );
		}
		$prefix = (string) $wpdb->prefix;
		$tables = array_values(
			array_filter(
				$tables,
				static function ( $table ) use ( $prefix ) {
					return 0 === strpos( $table, $prefix );
				}
			)
		);
		sort( $tables, SORT_STRING );
		$result = array();
		foreach ( $tables as $index => $table ) {
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come only from SHOW TABLES and are quoted as SQL identifiers.
			if ( empty( $create[1] ) ) {
				return new WP_Error( 'ocsp_database_schema', 'A sender database table schema could not be read.' );
			}
			$schema = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `{{TABLE}}`', $create[1], 1 );
			$file   = sprintf( 'database/table-%05d.jsonl', $index );
			$tmp    = self::create_temp_file( 'ocsp-table-' );
			if ( ! $tmp ) {
				return new WP_Error( 'ocsp_database_temp', 'A temporary database export file could not be created.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- Stream a database table in bounded batches to avoid loading it into memory.
			$stream = fopen( $tmp, 'wb' );
			if ( ! $stream ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ocsp_database_temp', 'A temporary database export stream could not be opened.' );
			}
			$offset = 0;
			do {
				$rows = $wpdb->get_results( "SELECT * FROM {$quoted} LIMIT 100 OFFSET " . absint( $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a database-listed, escaped identifier and offset is an integer.
				if ( ! is_array( $rows ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
					fclose( $stream );
					wp_delete_file( $tmp );
					return new WP_Error( 'ocsp_database_read', 'A sender database table could not be exported.' );
				}
				foreach ( $rows as $row ) {
					$encoded = array();
					foreach ( $row as $column => $value ) {
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode database bytes for a lossless JSON-lines archive; this is data transport, not code obfuscation.
						$encoded[ $column ] = null === $value ? null : base64_encode( (string) $value );
					}
					$line = wp_json_encode( $encoded );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite -- Stream bounded batches to avoid memory proportional to table size.
					if ( false === $line || false === fwrite( $stream, $line . "\n" ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
						fclose( $stream );
						wp_delete_file( $tmp );
						return new WP_Error( 'ocsp_database_write', 'A sender database table could not be written to the deployment package.' );
					}
				}
				$count   = count( $rows );
				$offset += $count;
			} while ( 100 === $count );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
			fclose( $stream );
			if ( ! $zip->addFile( $tmp, $file ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ocsp_database_zip', 'A database table could not be added to the deployment package.' );
			}
			self::$database_temp_files[] = $tmp;
			$result[]                    = array(
				'name'   => $table,
				'schema' => $schema,
				'file'   => $file,
			);
		}
		return array(
			'prefix'   => $prefix,
			'home_url' => untrailingslashit( $source_url ),
			'site_url' => untrailingslashit( $source_siteurl ),
			'tables'   => $result,
		);
	}

	/**
	 * Replace the receiving site's prefixed tables with the packaged snapshot.
	 *
	 * @param ZipArchive $zip      Archive containing database table data.
	 * @param array      $database Sender database manifest.
	 * @param string     $old_home Sender home URL.
	 * @param string     $old_site Sender WordPress installation URL.
	 * @param string     $new_home Receiver home URL.
	 * @param string     $new_site Receiver WordPress installation URL.
	 * @return true|WP_Error True or a database restore error.
	 */
	private static function import_database( ZipArchive $zip, $database, $old_home, $old_site, $new_home, $new_site ) {
		global $wpdb;
		if ( empty( $database['prefix'] ) || empty( $database['tables'] ) || ! is_array( $database['tables'] ) ) {
			return new WP_Error( 'ocsp_database_manifest', 'The full-site package has no valid database snapshot.', array( 'status' => 400 ) );
		}
		$source_prefix = (string) $database['prefix'];
		$target_prefix = (string) $wpdb->prefix;
		$target_tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full replacement must enumerate all receiver tables.
		if ( ! is_array( $target_tables ) ) {
			return new WP_Error( 'ocsp_database_read', 'The receiving database tables could not be listed.', array( 'status' => 500 ) );
		}
		self::set_foreign_key_checks( false );
		foreach ( $target_tables as $table ) {
			if ( 0 === strpos( $table, $target_prefix ) ) {
				self::drop_site_table( $table );
			}
		}
		foreach ( $database['tables'] as $table_info ) {
			$source_name = (string) ( $table_info['name'] ?? '' );
			$file        = (string) ( $table_info['file'] ?? '' );
			$schema      = (string) ( $table_info['schema'] ?? '' );
			if ( 0 !== strpos( $source_name, $source_prefix ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $source_name ) || ! preg_match( '#^database/table-[0-9]{5}\.jsonl$#', $file ) || false === strpos( $schema, '{{TABLE}}' ) ) {
				self::set_foreign_key_checks( true );
				return new WP_Error( 'ocsp_database_manifest', 'The full-site package contains an invalid database table entry.', array( 'status' => 400 ) );
			}
			$target_name = $target_prefix . substr( $source_name, strlen( $source_prefix ) );
			$create_sql  = str_replace( '{{TABLE}}', str_replace( '`', '``', $target_name ), $schema );
			if ( false === $wpdb->query( $create_sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- This DDL uses a validated sender schema and internally derived table name.
				self::set_foreign_key_checks( true );
				return new WP_Error( 'ocsp_database_schema', 'A receiving database table could not be created: ' . $wpdb->last_error, array( 'status' => 500 ) );
			}
			$tmp = self::create_temp_file( 'ocsp-table-in-' );
			if ( ! $tmp ) {
				self::set_foreign_key_checks( true );
				return new WP_Error( 'ocsp_database_extract', 'A private temporary folder could not be prepared for database import.', array( 'status' => 500 ) );
			}
			wp_delete_file( $tmp );
			$stage = $tmp . '-dir';
			if ( ! wp_mkdir_p( $stage ) || false === $zip->extractTo( $stage, $file ) ) {
				self::set_foreign_key_checks( true );
				return new WP_Error( 'ocsp_database_extract', 'Database data could not be extracted from the package.', array( 'status' => 500 ) );
			}
			$extracted = trailingslashit( $stage ) . $file;
			if ( ! is_file( $extracted ) ) {
				self::set_foreign_key_checks( true );
				self::remove_tree( $stage );
				return new WP_Error( 'ocsp_database_extract', 'A database table data file is missing.', array( 'status' => 500 ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- Stream database rows from the extracted package.
			$stream = fopen( $extracted, 'rb' );
			if ( ! $stream ) {
				self::set_foreign_key_checks( true );
				self::remove_tree( $stage );
				return new WP_Error( 'ocsp_database_read', 'A database table data file could not be read.', array( 'status' => 500 ) );
			}
			while ( ! feof( $stream ) ) {
				$line = fgets( $stream );
				if ( false === $line ) {
					break;
				}
				$encoded = json_decode( $line, true );
				if ( ! is_array( $encoded ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
					fclose( $stream );
					self::set_foreign_key_checks( true );
					self::remove_tree( $stage );
					return new WP_Error( 'ocsp_database_data', 'A database row in the package is invalid.', array( 'status' => 400 ) );
				}
				$row = array();
				foreach ( $encoded as $column => $value ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode database bytes from this plugin's lossless transport format.
					$decoded = null === $value ? null : base64_decode( (string) $value, true );
					if ( null !== $value && false === $decoded ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
						fclose( $stream );
						self::set_foreign_key_checks( true );
						self::remove_tree( $stage );
						return new WP_Error( 'ocsp_database_data', 'A database field in the package is invalid.', array( 'status' => 400 ) );
					}
					if ( is_string( $decoded ) ) {
						$decoded = self::remap_database_value( $decoded, $old_home, $old_site, $new_home, $new_site );
					}
					if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', (string) $column ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
						fclose( $stream );
						self::set_foreign_key_checks( true );
						self::remove_tree( $stage );
						return new WP_Error( 'ocsp_database_data', 'A database column in the package is invalid.', array( 'status' => 400 ) );
					}
					if ( is_string( $decoded ) && 'option_name' === $column && 'options' === substr( $source_name, -7 ) ) {
						$decoded = str_replace( $source_prefix, $target_prefix, $decoded );
					}
					if ( is_string( $decoded ) && 'meta_key' === $column && 'usermeta' === substr( $source_name, -8 ) ) {
						$decoded = str_replace( $source_prefix, $target_prefix, $decoded );
					}
					$row[ $column ] = $decoded;
				}
				$formats = array_map(
					static function ( $value ) {
						return null === $value ? null : '%s';
					},
					array_values( $row )
				);
				if ( false === $wpdb->insert( $target_name, $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Restore source rows into the replaced destination table.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
					fclose( $stream );
					self::set_foreign_key_checks( true );
					self::remove_tree( $stage );
					return new WP_Error( 'ocsp_database_insert', 'A receiving database row could not be imported: ' . $wpdb->last_error, array( 'status' => 500 ) );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Close the temporary database stream.
			fclose( $stream );
			self::remove_tree( $stage );
		}
		self::set_foreign_key_checks( true );
		return true;
	}

	/**
	 * Rewrite site URLs in a database value while preserving serialized data.
	 *
	 * @param string $value    Database value.
	 * @param string $old_home Sender home URL.
	 * @param string $old_site Sender WordPress installation URL.
	 * @param string $new_home Receiver home URL.
	 * @param string $new_site Receiver WordPress installation URL.
	 * @return string Rewritten database value.
	 */
	private static function remap_database_value( $value, $old_home, $old_site, $new_home, $new_site ) {
		$rewrites = array_filter(
			array(
				untrailingslashit( $old_site ) => untrailingslashit( $new_site ),
				untrailingslashit( $old_home ) => untrailingslashit( $new_home ),
			),
			static function ( $old ) {
				return '' !== $old;
			}
		);
		if ( is_serialized( $value ) && ! preg_match( '/(?:^|[;{])(?:O|C):[0-9]+:/', $value ) ) {
			foreach ( $rewrites as $old => $new ) {
				$value = self::rewrite_serialized_value( $value, $old, $new );
			}
			return $value;
		}
		return str_replace( array_keys( $rewrites ), array_values( $rewrites ), $value );
	}

	/**
	 * Recursively rewrite strings in a serialized PHP array/object value.
	 *
	 * @param string $serialized Serialized input.
	 * @param string $search     Sender URL.
	 * @param string $replace    Receiver URL.
	 * @return string Rewritten serialized input.
	 */
	private static function rewrite_serialized_value( $serialized, $search, $replace ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Decode a validated serialized database value without loading classes; suppress malformed-data notices.
		$value = @unserialize( $serialized, array( 'allowed_classes' => false ) );
		if ( false === $value && 'b:0;' !== $serialized ) {
			return str_replace( $search, $replace, $serialized );
		}
		$value = self::rewrite_nested_value( $value, $search, $replace );
		return maybe_serialize( $value );
	}

	/**
	 * Update URLs recursively in arrays and object properties.
	 *
	 * @param mixed  $value   Value to rewrite.
	 * @param string $search  Sender URL.
	 * @param string $replace Receiver URL.
	 * @return mixed Rewritten value.
	 */
	private static function rewrite_nested_value( $value, $search, $replace ) {
		if ( is_string( $value ) ) {
			return str_replace( $search, $replace, $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_nested_value( $item, $search, $replace );
			}
		}
		return $value;
	}
}

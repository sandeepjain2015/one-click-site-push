<?php
/**
 * Plugin Name: One Click Site Push
 * Description: Publish WordPress pages, posts, media, and the active theme to a paired WordPress site.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Sandeep Jain
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: one-click-site-push
 *
 * @package One_Click_Site_Push
 */
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName -- The entry point follows the required plugin slug filename.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-ocsp-admin.php';
require_once __DIR__ . '/includes/class-ocsp-sender.php';
require_once __DIR__ . '/includes/class-ocsp-receiver.php';
require_once __DIR__ . '/includes/class-ocsp-package.php';
require_once __DIR__ . '/includes/class-ocsp-database.php';

/**
 * Main plugin bootstrap and shared deployment configuration.
 *
 * @package One_Click_Site_Push
 */
final class OCSP_One_Click_Site_Push {
	const OPTION            = 'ocsp_settings';
	const VERSION           = '1.0.0';
	const REST_NAMESPACE    = 'one-click-site-push/v1';
	const REST_ROUTE        = '/deploy';
	const MAX_PACKAGE_BYTES = 250000000;

	/**
	 * Current deployment stage, used to provide receiver failure context.
	 *
	 * @var string
	 */
	private static $receiver_stage = 'starting';

	/**
	 * Temporary files staged into the current database archive.
	 *
	 * @var array
	 */
	private static $database_temp_files = array();

	use OCSP_Admin;
	use OCSP_Sender;
	use OCSP_Receiver;
	use OCSP_Package;
	use OCSP_Database;

	/**
	 * Attach plugin admin and REST handlers to WordPress hooks.
	 *
	 * @return void Attach plugin admin and REST handlers to WordPress hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_ocsp_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_ocsp_push', array( __CLASS__, 'push_site' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}
}

OCSP_One_Click_Site_Push::init();

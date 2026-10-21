<?php
/**
 * Administrative screens and settings handlers.
 *
 * @package One_Click_Site_Push
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OCSP_Admin {
	/**
	 * Retrieve plugin settings merged with safe defaults.
	 *
	 * @return array Retrieve plugin settings merged with safe defaults.
	 */
	private static function settings() {
		$defaults = array(
			'destination_url'   => '',
			'destination_token' => '',
			'receiver_enabled'  => 0,
			'receiver_secret'   => '',
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Register the plugin settings screen under Tools.
	 *
	 * @return void Register the plugin settings screen under Tools.
	 */
	public static function admin_menu() {
		add_management_page( 'One Click Site Push', 'One Click Site Push', 'manage_options', 'one-click-site-push', array( __CLASS__, 'render_admin' ) );
	}

	/**
	 * Render the sender, receiver, and deployment settings screen.
	 *
	 * @return void Render the sender, receiver, and deployment settings screen.
	 */
	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized query value only displays a status notice.
		$result = isset( $_GET['ocsp_result'] ) ? sanitize_text_field( wp_unslash( $_GET['ocsp_result'] ) ) : '';
		?>
			<div class="wrap">
				<h1>One Click Site Push</h1>
				<p>Publish selected content or replace a paired WordPress site with a full copy of this installation. The receiving site must have this plugin installed and its receiver enabled.</p>
			<?php
			if ( $result ) :
				?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $result ); ?></p></div><?php endif; ?>
				<h2>Sender setup</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ocsp_save" />
				<?php wp_nonce_field( 'ocsp_save_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="ocsp-destination-url">Receiving site URL</label></th><td><input id="ocsp-destination-url" class="regular-text" type="url" name="destination_url" value="<?php echo esc_attr( $settings['destination_url'] ); ?>" /><p class="description">Use the HTTPS address of the receiving WordPress site. No destination is prefilled.</p></td></tr>
						<tr><th scope="row"><label for="ocsp-destination-token">Receiver secret</label></th><td><input id="ocsp-destination-token" class="regular-text" type="password" name="destination_token" value="" autocomplete="new-password" /><p class="description">Paste the shared secret shown on the receiving site. Leave blank to keep the saved value.</p></td></tr>
					</table>
				<?php submit_button( 'Save sender settings' ); ?>
				</form>
				<hr />
				<h2>Receiver setup</h2>
				<p>Enable this on the receiving site. Copy its receiver secret into the sender settings above.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ocsp_save" />
					<input type="hidden" name="receiver_form" value="1" />
				<?php wp_nonce_field( 'ocsp_save_settings' ); ?>
					<label><input type="checkbox" name="receiver_enabled" value="1" <?php checked( $settings['receiver_enabled'] ); ?> /> Enable secure deployment receiver</label>
					<p><label for="ocsp-receiver-secret"><strong>Shared receiver secret</strong></label><br />
					<input id="ocsp-receiver-secret" class="regular-text" type="password" name="receiver_secret" value="" autocomplete="new-password" />
				<?php
				if ( $settings['receiver_secret'] ) :
					?>
					<br /><span class="description">A secret is already saved. Leave blank to keep it. To copy or rotate it, enter a new secret and save.</span>
					<?php
else :
	?>
					<br /><span class="description">Set the same long random secret on the sender and receiver.</span><?php endif; ?></p>
				<?php submit_button( 'Save receiver settings' ); ?>
				</form>
			<?php if ( $settings['receiver_enabled'] ) : ?>
					<p><strong>Receiver secret (copy this into Sender setup on the sending site):</strong><br /><code style="user-select:all;word-break:break-all;"><?php echo esc_html( $settings['receiver_secret'] ); ?></code></p>
					<p><strong>Receiver endpoint:</strong> <code><?php echo esc_html( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ); ?></code></p>
				<?php endif; ?>
				<hr />
				<h2>Push to another site</h2>
				<p>Choose whether to keep receiver-only content or make the receiving WordPress site match this sender. Full replacement transfers the sender database and site files.</p>
				<form id="ocsp-push-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="var mode=this.elements.deployment_mode.value;if(mode==='replace'&&!confirm('FULL SITE REPLACEMENT: this overwrites the receiving site database, users, plugins, themes, media, and settings with the sender copy. Existing receiver accounts and content will be replaced. The receiving URL, wp-config.php, and One Click Site Push receiver secret are retained. A temporary rollback snapshot is made and used if deployment fails. Continue only if this is the intended receiving site.'))return false;return confirm('Push this site to the configured receiving site now?');">
					<input type="hidden" name="action" value="ocsp_push" />
				<?php wp_nonce_field( 'ocsp_push_site' ); ?>
					<fieldset style="margin:16px 0;padding:12px 16px;border:1px solid #c3c4c7;max-width:760px"><legend><strong>Existing receiver data</strong></legend>
						<p><label><input type="radio" name="deployment_mode" value="merge" checked /> Keep receiver-only data: update matching pages and posts; keep unmatched receiver pages, posts, and media.</label></p>
						<p><label><input type="radio" name="deployment_mode" value="replace" /> Full site replacement: copy the sender's database, users, plugins, themes, media, menus, custom post types, and WordPress settings over the receiving site.</label></p>
						<p class="description">The destination URL, wp-config.php, and this plugin's receiver secret are retained. Users and passwords become the sender's; you may need to log in with a sender account. Full replacement is not supported on Multisite. A temporary rollback snapshot is used if deployment fails; create a separate hosting backup before starting.</p>
					</fieldset>
				<?php submit_button( 'Push this site to another site', 'primary large' ); ?>
				</form>
			</div>
			<?php
	}

	/**
	 * Validate and save sender or receiver settings.
	 *
	 * @return void Validate and save sender or receiver settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'one-click-site-push' ) );
		}
		check_admin_referer( 'ocsp_save_settings' );
		$settings = self::settings();
		if ( isset( $_POST['receiver_form'] ) ) {
			$settings['receiver_enabled'] = ! empty( $_POST['receiver_enabled'] ) ? 1 : 0;
			$new_secret                   = isset( $_POST['receiver_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['receiver_secret'] ) ) ) : '';
			if ( '' !== $new_secret ) {
				$settings['receiver_secret'] = $new_secret;
			}
			if ( $settings['receiver_enabled'] && '' === $settings['receiver_secret'] ) {
				$settings['receiver_secret'] = wp_generate_password( 64, false, false );
			}
		} else {
			$url = isset( $_POST['destination_url'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['destination_url'] ) ) ) ) : '';
			if ( $url && 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				self::redirect_with_result( 'The live site URL must use HTTPS.' );
			}
			$settings['destination_url'] = untrailingslashit( $url );
			$new_token                   = isset( $_POST['destination_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['destination_token'] ) ) ) : '';
			if ( '' !== $new_token ) {
				$settings['destination_token'] = $new_token;
			}
		}
		update_option( self::OPTION, $settings, false );
		self::redirect_with_result( 'Settings saved.' );
	}

	/**
	 * Return to the settings screen with a status message.
	 *
	 * @param mixed $message Description of message.
	 * @return void Return to the settings screen with a status message.
	 */
	private static function redirect_with_result( $message ) {
		$url = add_query_arg(
			array(
				'page'        => 'one-click-site-push',
				'ocsp_result' => rawurlencode( $message ),
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

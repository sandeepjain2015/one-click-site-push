=== One Click Site Push ===
Contributors: sandeepjainlive
Tags: deployment, migration, publishing
Requires at least: 6.4
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Publish selected WordPress content or fully replace a paired single-site WordPress installation from a sender site.

== Setup ==

1. Install and activate this plugin on both the sending and receiving WordPress sites.
2. On the receiving site, open Tools > One Click Site Push, enable the receiver, save a long random shared secret, and copy it.
3. On the sending site, enter the receiving site's HTTPS URL and paste the shared secret into Sender setup.
4. Choose whether to keep receiver-only content or replace the receiving site's database and transferable site files, then select Push this site to another site when a deployment is ready.

The receiver requires PHP ZipArchive. Both sites must be reachable over HTTPS and allow a request body up to 250 MB. Full replacement is not supported on WordPress Multisite.

The sender does not have a prefilled destination. Confirm the HTTPS receiver URL and shared secret before publishing.

== Data transfer ==

When an administrator submits a deployment, the sender transmits the selected data to the administrator-configured HTTPS receiver. Full replacement includes the sender's WordPress-prefixed database tables, users, plugin and theme files, must-use plugins, uploads, and language files. Site URLs are remapped to the receiving installation. The receiving `wp-config.php`, destination URL, and receiver secret are retained. The shared secret is sent in a request header for receiver authentication. The plugin does not contact a vendor-operated service or send telemetry.

== What gets published ==

In keep-live-only mode, the plugin upserts pages and posts by type and slug, transfers media files, copies the active theme into a new release directory, updates front-page assignments, and activates that theme release. Gutenberg image IDs, page links, and local site URLs are remapped for the live site. Existing posts receive a WordPress revision before update. Unmatched live content and old generated deployment files are kept.

In full replacement mode, the receiver first creates a temporary rollback snapshot. It then replaces all tables using the WordPress table prefix and the plugins, themes, must-use plugins, uploads, and language directories with the sender's copies. Users, passwords, custom post types, menus, comments, plugin data, and general WordPress settings are therefore replaced. The receiving site URL, `wp-config.php`, and this plugin's receiver secret are retained. If the deployment reports a failure, the plugin attempts to restore the temporary snapshot. The temporary snapshot is removed after a successful deployment; maintain a separate host backup before starting. Existing live accounts are replaced by sender accounts, so log in with a sender account afterward. This mode does not copy WordPress core files or database credentials and does not support Multisite.

The receiver secret is stored in the WordPress options table and must be protected like an administrator credential. Rotate it from the receiver settings when needed. Only administrators can use the deployment screen.

== Changelog ==

= 1.0.0 =
* Initial public release of the paired sender and receiver.
* Publish pages, posts, media, active themes, and child-theme parents to a configured WordPress site.
* Offer keep-live-only and full-site replacement modes.
* Require an administrator-configured HTTPS destination and shared receiver secret.
* Include modular plugin code and receiver diagnostics.

# One Click Site Push

Deploy selected WordPress content or fully replace a paired single-site WordPress installation from a sender site.

## Requirements

- WordPress 6.4 or later
- PHP 8.0 or later
- PHP `ZipArchive` enabled on both sites
- HTTPS access to the receiving site

## Code structure

The plugin entry point, `one-click-site-push.php`, keeps the plugin metadata, constants, hooks, and module loading. Implementation is organized under `includes/`:

- `class-ocsp-admin.php` — settings screen, forms, and settings handlers.
- `class-ocsp-sender.php` — deployment package creation and HTTP sender.
- `class-ocsp-receiver.php` — authenticated REST endpoint and deployment orchestration.
- `class-ocsp-package.php` — archive extraction, media import, and URL/content remapping helpers.
- `class-ocsp-database.php` — streamed database table export/import and URL remapping for full replacement.

The modules use traits composed into `OCSP_One_Click_Site_Push`, preserving the plugin's existing hooks, option name, and REST route.

## Setup

1. Install and activate the plugin on both sites.
2. On the receiving site, open **Tools → One Click Site Push**, enable the receiver, save a long random shared secret, and copy it.
3. On the sending site, enter the receiving site's HTTPS URL and the shared secret.
4. Choose **Keep receiver-only data** to preserve unmatched receiver pages, posts, and media, or **Full site replacement** to replace the receiving site's database and transferable files with the sender's copy.
5. Review the scope and make a separate host backup before using full replacement. Then select **Push this site to another site**.

No destination URL is preconfigured. The receiver is disabled until an administrator enables it.

## What is transferred

When an administrator initiates a deployment, the sender transmits the selected site's page and post content, media files, active theme files, theme metadata, and front-page assignments to the HTTPS receiver URL configured by that administrator. The shared secret is sent in an HTTP header for receiver authentication. The plugin does not contact a vendor-operated service.

Full replacement transfers the sender's WordPress-prefixed database tables, including users, passwords, comments, custom post types, menus, plugin data, and general WordPress settings. It also transfers the plugins, themes, must-use plugins, uploads, and language files. The receiver remaps the home and site URLs. It retains the destination `wp-config.php`, URL, and One Click Site Push receiver secret. WordPress core files and database credentials are not copied. Full replacement is not supported on Multisite.

Full replacement first creates a temporary snapshot for rollback if deployment fails. It replaces the receiving site's WordPress-prefixed database tables and transferable site directories. The temporary snapshot is removed after success, so maintain an independent backup. Users and passwords become the sender's, and you may need to log in with a sender account after deployment. The HTTP package is limited to 250 MB; larger sites require another migration method.

## WordPress.org note

Full replacement transfers executable PHP files for plugins and themes and activates the sender's plugin configuration. Review the current WordPress.org Plugin Directory guidelines for software that transfers or installs executable code before submitting this feature for directory review.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

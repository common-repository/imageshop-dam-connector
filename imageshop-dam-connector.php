<?php
/**
 * Plugin Name: Imageshop DAM Connector
 * Plugin URI:
 * Description: Use the Imageshop media library as your companys one source for all media.
 * Version: 1.1.0
 * Author: Imageshop
 * Author URI: https://imageshop.org
 * License: MIT
 * Text Domain: imageshop-dam-connector
 * Domain Path: /languages
 * Requires PHP: 5.6
 * Requires at least: 5.6
 */

declare( strict_types = 1 );

namespace Imageshop\WordPress;

\defined( 'ABSPATH' ) || exit;

\define( 'IMAGESHOP_ABSPATH', __DIR__ );
\define( 'IMAGESHOP_PLUGIN_BASE_NAME', __FILE__ );

require_once __DIR__ . '/includes/class-imageshop.php';
require_once __DIR__ . '/includes/class-attachment.php';
require_once __DIR__ . '/includes/class-helpers.php';
require_once __DIR__ . '/includes/class-library.php';
require_once __DIR__ . '/includes/class-onboarding.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-sync.php';

function imageshop_incompatibile( $msg ) {
	require_once ABSPATH . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php';
	\deactivate_plugins( __FILE__ );
	\wp_die( $msg );
}

// Validate that the plugin is compatible when being activated.
\register_activation_hook(
	__FILE__,
	function() {
		if ( \is_admin() && ( ! \defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			if ( \version_compare( PHP_VERSION, '5.6', '<' ) ) {
				imageshop_incompatibile(
					\sprintf(
						// translators: %s is the PHP version.
						__(
							'The Imageshop Media Library plugin requires PHP version 5.6 or higher. This site uses PHP version %s, which has caused the plugin to be automatically deactivated.',
							'imageshop-dam-connector'
						),
						PHP_VERSION
					)
				);
			}
		}
	}
);

if ( \class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/includes/CLI/class-meta.php';
	require_once __DIR__ . '/includes/CLI/class-media.php';
	require_once __DIR__ . '/includes/CLI/class-duplicates.php';
}

$isml = Imageshop::get_instance();
$isml->start();

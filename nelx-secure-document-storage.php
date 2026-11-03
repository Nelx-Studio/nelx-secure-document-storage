<?php
/**
 * Plugin Name: Nelx Secure Document Storage
 * Plugin URI: https://nelxstudio.com/nelx-secure-document-storage/
 * Description: Secure file storage, native uploads, protected downloads and universal shortcodes, with optional JetFormBuilder, JetEngine and Elementor integrations.
 * Version: 1.3.17
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Astariko Ambuka
 * Author URI: https://nelxstudio.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nelx-secure-document-storage
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

define('NELXSDST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NELXSDST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NELXSDST_PLUGIN_VERSION', '1.3.17');

if ( ! defined('NELXSDST_SECURE_DIR_NAME') ) {
	define('NELXSDST_SECURE_DIR_NAME', 'nelx-secure-storage');
}

/*
 * Register the bundled Nelx Studio Hub core candidate before plugins_loaded.
 * If several Nelx plugins bundle the Hub, the shared loader selects the newest
 * candidate. A separately installed Hub remains compatible and takes precedence.
 */
require_once NELXSDST_PLUGIN_DIR . 'includes/nelx-studio-hub/loader.php';
if ( function_exists( 'nelx_studio_hub_register_core_candidate' ) ) {
	nelx_studio_hub_register_core_candidate(
		'0.3.5',
		NELXSDST_PLUGIN_DIR . 'includes/nelx-studio-hub/core.php',
		NELXSDST_PLUGIN_DIR . 'includes/nelx-studio-hub/',
		NELXSDST_PLUGIN_URL . 'includes/nelx-studio-hub/'
	);
}

// Include required files
require_once NELXSDST_PLUGIN_DIR . 'includes/hooks.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/access.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/storage-service.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/field-renderer.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/shortcode-presets.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/renderer.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/custom-callback.php';
require_once NELXSDST_PLUGIN_DIR . 'includes/settings.php';

// Initialize core components
add_action('init', function () {
	if (class_exists('NELXSDST_SecureStorage')) {
		NELXSDST_SecureStorage::get_instance();
	}

	if (class_exists('NELXSDST_Settings')) {
		new NELXSDST_Settings();
	}

	if (class_exists('NELXSDST_File_Access')) {
		new NELXSDST_File_Access();
	}
}, 0);

register_activation_hook(__FILE__, 'nelxsdst_setup_secure_storage');
function nelxsdst_setup_secure_storage() {
	require_once NELXSDST_PLUGIN_DIR . 'includes/hooks.php';

	if (class_exists('NELXSDST_SecureStorage')) {
		$secure_storage = NELXSDST_SecureStorage::get_instance();
		$secure_storage->ensure_secure_dir();
	}
}

// Deactivation hook - cleanup if needed
register_deactivation_hook(__FILE__, 'nelxsdst_deactivate_cleanup');
function nelxsdst_deactivate_cleanup() {
	// Intentionally no destructive cleanup on deactivate.
}

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'nelxsdst_add_plugin_settings_link');
function nelxsdst_add_plugin_settings_link($links) {
	$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=nelx-secure-document-storage')) . '">' . esc_html__('Settings', 'nelx-secure-document-storage') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}

// Get plugin version
function nelxsdst_get_plugin_version() {
	return NELXSDST_PLUGIN_VERSION;
}

/**
 * Register Secure Document Storage with the shared Nelx Studio Hub.
 *
 * The Hub is optional at runtime: when another Nelx product or the standalone
 * Hub provides the core, this plugin registers with that running instance.
 */
function nelxsdst_register_with_nelx_studio_hub() {
	if ( ! function_exists( 'nelx_studio_register_product' ) ) {
		return;
	}

	nelx_studio_register_product(
		array(
			'slug'        => 'nelx-secure-document-storage',
			'name'        => 'Secure Documents Storage',
			'version'     => NELXSDST_PLUGIN_VERSION,
			'description' => 'Protect and manage important documents with controlled access.',
			'plugin_file' => plugin_basename( __FILE__ ),
			'menu_slug'   => 'nelx-secure-document-storage',
			'manage_url'  => admin_url( 'admin.php?page=nelx-secure-document-storage' ),
			'docs_url'    => 'https://nelxstudio.com/nelx-secure-document-storage-user-guide/#overview',
			'learn_url'   => 'https://nelxstudio.com/nelx-secure-document-storage/',
			'icon'        => 'dashicons-media-document',
			'accent'      => 'blue',
			'plan'        => 'free',
			'tags'        => array( 'Documents', 'Access Control', 'Secure Storage' ),
		)
	);
}
add_action( 'plugins_loaded', 'nelxsdst_register_with_nelx_studio_hub', 20 );


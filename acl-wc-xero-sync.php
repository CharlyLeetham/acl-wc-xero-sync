<?php
/**
 * Plugin Name: ACL WooCommerce Xero Sync
 * Plugin URI:  https://askcharlyleetham.com
 * Description: Multifunction sync WooCommerce with Xero.
 * Version: 1.0.0
 * Author: Charly Leetham
 * Author URI:  https://askcharlyleetham.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: acl-wc-xero-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access.
}

// Prevent activation if WooCommerce is not active
register_activation_hook( __FILE__, 'acl_wc_xero_sync_check_dependencies' );

/**
 * Checks if WooCommerce is active during plugin activation.
 */
function acl_wc_xero_sync_check_dependencies() {
    if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        // Deactivate the plugin
        deactivate_plugins( plugin_basename( __FILE__ ) );

        // Show an admin error message
        wp_die(
            '<p><strong>ACL WooCommerce Xero Sync:</strong> WooCommerce is not active. This plugin requires WooCommerce to be installed and activated.</p>' .
            '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&laquo; Return to Plugins</a></p>'
        );
    }
}

// Include third-party libraries
require_once __DIR__ . '/lib/xero-php/vendor/autoload.php';

// Include custom class files
require_once __DIR__ . '/src/admin/product-sync-page.php';
require_once __DIR__ . '/src/services/wc-service.php';
require_once __DIR__ . '/src/services/sync-service.php';
require_once __DIR__ . '/src/helpers/helpers.php';
require_once __DIR__ . '/src/helpers/logger.php';
require_once __DIR__ . '/src/bootstrap.php';

// Initialize the plugin
try {
    \ACLWcXeroSync\ACLBootstrap::init();
} catch ( Exception $e ) {
    error_log( 'ACL WooCommerce Xero Sync failed to initialize: ' . $e->getMessage() );
}

// Define the plugin base URL
if ( ! defined( 'ACL_XERO_PLUGIN_URL' ) ) {
    define( 'ACL_XERO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Define the plugin base path
if ( ! defined( 'ACL_XERO_PLUGIN_PATH' ) ) {
    define( 'ACL_XERO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

<?php
/**
 * Bootstrap the plugin by initializing all components.
 */

namespace ACLWcXeroSync;

class ACLBootstrap {
    /**
     * Initializes the plugin by setting up necessary components.
     */
    public static function init() {
        // Check if WooCommerce is active
        if ( ! self::is_woocommerce_active() ) {
            add_action( 'admin_notices', [ __CLASS__, 'woocommerce_inactive_notice' ] );
            return;
        }

        // Check if the ACLProductSyncPage class exists
        if ( ! class_exists( 'ACLWcXeroSync\Admin\ACLProductSyncPage' ) ) {
            //error_log( 'Class ACLProductSyncPage not found' );
            return; // Exit initialization to avoid further errors
        }
        
        // Enqueue styles for admin area
        add_action('admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles']);

        Admin\ACLProductSyncPage::init();
    }

    /**
     * Checks if WooCommerce is active.
     *
     * @return bool True if WooCommerce is active, false otherwise.
     */
    private static function is_woocommerce_active() {
        return in_array( 
            'woocommerce/woocommerce.php', 
            apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) 
        );
    }

    /**
     * Displays an admin notice if WooCommerce is not active.
     */
    public static function woocommerce_inactive_notice() {
        ?>
        <div class="error">
            <p><strong>ACL WooCommerce Xero Sync:</strong> WooCommerce is not active. Please activate WooCommerce to use this plugin.</p>
        </div>
        <?php
    }

    public static function enqueue_styles() {
        $stylesheet_path = plugin_dir_path(__FILE__) . 'assets/css/admin-style.css';
        $version = filemtime( $stylesheet_path );
        wp_enqueue_style('acl-wc-xero-sync-admin', plugins_url('assets/css/admin-style.css', __FILE__), array(), $version, 'all');
    }    
}

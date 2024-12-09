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

        /*spl_autoload_register(function ($class) {
            error_log("Attempting to autoload: $class");
        });

        // Check if the ACLProductSyncPage class exists
        if ( ! class_exists( 'ACLWcXeroSync\Admin\ACLProductSyncPage' ) ) {
            error_log( 'Class ACLProductSyncPage not found' );
            return; // Exit initialization to avoid further errors
        }        
        */

        require_once __DIR__ . '/admin/product-sync-page.php';

        if ( ! class_exists( 'ACLWcXeroSync\Admin\ACLProductSyncPage' ) ) {
            error_log( 'Class ACLProductSyncPage still not found after manual inclusion' );
            return;
        } else {
            error_log( 'Class ACLProductSyncPage found after manual inclusion' );
        }        

        // Initialize the admin sync page
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
}

namespace ACLWcXeroSync\Admin;

class ACLProductSyncPage {
    /**
     * Registers the admin menu pages.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
    }

    /**
     * Adds ACL Xero Sync and its submenus under WooCommerce.
     */
    public static function add_admin_pages() {
        // Parent Menu: ACL Xero Sync
        add_submenu_page(
            'woocommerce',             // Parent slug (WooCommerce menu)
            'ACL Xero Sync',           // Page title
            'ACL Xero Sync',           // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync',           // Menu slug
            [ __CLASS__, 'render_placeholder_page' ] // Callback (placeholder for parent)
        );

        // Submenu: Product Sync
        add_submenu_page(
            'acl-xero-sync',           // Parent slug (ACL Xero Sync menu)
            'Product Sync',            // Page title
            'Product Sync',            // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync-products',  // Menu slug
            [ __CLASS__, 'render_sync_page' ] // Callback
        );

        // Submenu: Settings
        add_submenu_page(
            'acl-xero-sync',           // Parent slug (ACL Xero Sync menu)
            'Settings',                // Page title
            'Settings',                // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync-settings',  // Menu slug
            [ __CLASS__, 'render_settings_page' ] // Callback
        );
    }

    /**
     * Renders a placeholder page for the parent menu.
     */
    public static function render_placeholder_page() {
        echo '<div class="wrap"><h1>ACL Xero Sync</h1><p>Choose an option from the menu.</p></div>';
    }

    /**
     * Renders the Product Sync Page.
     */
    public static function render_sync_page() {
        if ( isset( $_POST['sync_xero_products'] ) ) {
            \ACLWcXeroSync\Services\ACLSyncService::sync_products();
            echo '<div class="updated"><p>Products synced to Xero!</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Sync Products to Xero</h1>
            <form method="post">
                <input type="hidden" name="sync_xero_products" value="1">
                <button type="submit" class="button button-primary">Start Sync</button>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the Settings Page.
     */
    public static function render_settings_page() {
        if ( isset( $_POST['acl_xero_settings'] ) ) {
            update_option( 'acl_xero_consumer_key', sanitize_text_field( $_POST['acl_xero_consumer_key'] ) );
            update_option( 'acl_xero_consumer_secret', sanitize_text_field( $_POST['acl_xero_consumer_secret'] ) );
            echo '<div class="updated"><p>Settings updated successfully!</p></div>';
        }

        $consumer_key = get_option( 'acl_xero_consumer_key', '' );
        $consumer_secret = get_option( 'acl_xero_consumer_secret', '' );

        ?>
        <div class="wrap">
            <h1>Xero Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="acl_xero_consumer_key">Xero Consumer Key</label></th>
                        <td><input type="text" id="acl_xero_consumer_key" name="acl_xero_consumer_key" value="<?php echo esc_attr( $consumer_key ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acl_xero_consumer_secret">Xero Consumer Secret</label></th>
                        <td><input type="text" id="acl_xero_consumer_secret" name="acl_xero_consumer_secret" value="<?php echo esc_attr( $consumer_secret ); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="acl_xero_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }
}

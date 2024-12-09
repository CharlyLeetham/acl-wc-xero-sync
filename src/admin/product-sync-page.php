<?php
namespace ACLWcXeroSync\Admin;

class ACLProductSyncPage {
    /**
     * Initializes the admin menu pages and callback URL handler.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
        add_action( 'admin_post_acl_xero_sync_callback', [ __CLASS__, 'handle_xero_callback' ] );
    }

    /**
     * Adds ACL Xero Sync and its submenus under WooCommerce.
     */
    public static function add_admin_pages() {
        add_menu_page(
            'ACL Xero Sync',           // Page title
            'ACL Xero Sync',           // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync',           // Menu slug
            [ __CLASS__, 'render_placeholder_page' ], // Callback for the parent menu
            'dashicons-update',        // Icon
            56                         // Position
        );

        add_submenu_page(
            'acl-xero-sync',           // Parent slug (ACL Xero Sync menu)
            'Product Sync',            // Page title
            'Product Sync',            // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync-products',  // Menu slug
            [ __CLASS__, 'render_sync_page' ] // Callback for Product Sync
        );

        add_submenu_page(
            'acl-xero-sync',           // Parent slug (ACL Xero Sync menu)
            'Settings',                // Page title
            'Settings',                // Menu title
            'manage_woocommerce',      // Capability
            'acl-xero-sync-settings',  // Menu slug
            [ __CLASS__, 'render_settings_page' ] // Callback for Settings
        );
    }

    /**
     * Renders a placeholder page for the parent menu.
     */
    public static function render_placeholder_page() {
        echo '<div class="wrap">';
        echo '<h1>ACL Xero Sync</h1>';
        echo '<p>Select an option from the submenu.</p>';
        echo '</div>';
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
        $redirect_uri = admin_url( 'admin-post.php?action=acl_xero_sync_callback' );

        ?>
        <div class="wrap">
            <h1>Xero Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th><label for="acl_xero_consumer_key">Xero Consumer Key</label></th>
                        <td><input type="text" id="acl_xero_consumer_key" name="acl_xero_consumer_key" value="<?php echo esc_attr( $consumer_key ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="acl_xero_consumer_secret">Xero Consumer Secret</label></th>
                        <td><input type="text" id="acl_xero_consumer_secret" name="acl_xero_consumer_secret" value="<?php echo esc_attr( $consumer_secret ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="acl_xero_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>

            <h2>Callback URL</h2>
            <p>This URL is required when creating your app in Xero:</p>
            <code><?php echo esc_url( $redirect_uri ); ?></code>

            <h2>Connect to Xero</h2>
            <?php if ( $consumer_key && $consumer_secret ): ?>
                <a href="<?php echo esc_url( self::get_xero_auth_url( $consumer_key, $redirect_uri ) ); ?>" class="button button-primary">Authorize with Xero</a>
            <?php else: ?>
                <p>Please save your Consumer Key and Secret to enable authorization.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handles the OAuth callback from Xero.
     */
    public static function handle_xero_callback() {
        if ( isset( $_GET['code'] ) ) {
            $auth_code = sanitize_text_field( $_GET['code'] );
            $tokens = self::exchange_auth_code_for_token( $auth_code );

            if ( $tokens ) {
                update_option( 'xero_access_token', $tokens['access_token'] );
                update_option( 'xero_refresh_token', $tokens['refresh_token'] );
                update_option( 'xero_token_expires', time() + $tokens['expires_in'] );
                wp_redirect( admin_url( 'admin.php?page=acl-xero-sync-settings&auth=success' ) );
                exit;
            } else {
                wp_redirect( admin_url( 'admin.php?page=acl-xero-sync-settings&auth=error' ) );
                exit;
            }
        } else {
            wp_redirect( admin_url( 'admin.php?page=acl-xero-sync-settings&auth=error' ) );
            exit;
        }
    }

    /**
     * Exchanges the authorization code for tokens.
     *
     * @param string $auth_code The authorization code from Xero.
     * @return array|false The tokens or false on failure.
     */
    private static function exchange_auth_code_for_token( $auth_code ) {
        $client_id = get_option( 'acl_xero_consumer_key' );
        $client_secret = get_option( 'acl_xero_consumer_secret' );
        $redirect_uri = admin_url( 'admin-post.php?action=acl_xero_sync_callback' );

        $response = wp_remote_post( 'https://identity.xero.com/connect/token', [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $auth_code,
                'redirect_uri'  => $redirect_uri,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'Xero Token Exchange Error: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            return $body;
        }

        error_log( 'Xero Token Exchange Response: ' . print_r( $body, true ) );
        return false;
    }

    /**
     * Generates the Xero OAuth URL.
     */
    private static function get_xero_auth_url( $client_id, $redirect_uri ) {
        return 'https://login.xero.com/identity/connect/authorize?' . http_build_query( [
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => 'accounting.transactions accounting.settings offline_access',
        ] );
    }
}

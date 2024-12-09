<?php
namespace ACLWcXeroSync\Admin;

class ACLProductSyncPage {
    /**
     * Initializes the admin menu pages and callback URL handler.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
        add_action( 'admin_post_acl_xero_sync_callback', [ __CLASS__, 'handle_xero_callback' ] );
        add_action( 'admin_post_acl_xero_reset_authorization', [ __CLASS__, 'reset_authorization' ] );
    }

    /**
     * Adds ACL Xero Sync and its submenus under WooCommerce.
     */
    public static function add_admin_pages() {
        add_menu_page(
            'ACL Xero Sync',
            'ACL Xero Sync',
            'manage_woocommerce',
            'acl-xero-sync',
            [ __CLASS__, 'render_placeholder_page' ],
            'dashicons-update',
            56
        );

        add_submenu_page(
            'acl-xero-sync',
            'Product Sync',
            'Product Sync',
            'manage_woocommerce',
            'acl-xero-sync-products',
            [ __CLASS__, 'render_sync_page' ]
        );

        add_submenu_page(
            'acl-xero-sync',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'acl-xero-sync-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /**
     * Resets the authorization by clearing stored tokens.
     */
    public static function reset_authorization() {
        // Delete options related to Xero tokens
        delete_option( 'xero_access_token' );
        delete_option( 'xero_refresh_token' );
        delete_option( 'xero_token_expires' );

        // Redirect back to the settings page with a success message
        wp_redirect( admin_url( 'admin.php?page=acl-xero-sync-settings&reset=success' ) );
        exit;
    }    

    /**
     * Generates the Xero OAuth URL.
     *
     * @param string $client_id The Client ID from Xero.
     * @param string $redirect_uri The callback URL for the app.
     * @return string The authorization URL.
     */
    private static function get_xero_auth_url( $client_id, $redirect_uri ) {
        return 'https://login.xero.com/identity/connect/authorize?' . http_build_query( [
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => 'accounting.transactions accounting.settings offline_access',
        ] );
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

        // Check Authorization Status
        $access_token = get_option( 'xero_access_token', '' );
        $auth_success = isset( $_GET['auth'] ) && $_GET['auth'] === 'success';
        $reset_success = isset( $_GET['reset'] ) && $_GET['reset'] === 'success';
        $is_authorized = ! empty( $access_token ) || $auth_success;
        $status = $is_authorized ? 'Authorised' : 'Unauthorised';

        ?>
        <div class="wrap">
            <h1>Xero Settings</h1>

            <!-- Step 1: Callback URL -->
            <h2>Step 1: Enter Your API Details</h2>
            <p>This Callback URL is required when creating your app in Xero:</p>
            <code id="acl-xero-callback-url"><?php echo esc_url( $redirect_uri ); ?></code>
            <button type="button" id="acl-xero-copy-callback-url" class="button">Copy URL</button>

            <!-- JavaScript to Copy the Callback URL -->
            <script>
            document.getElementById('acl-xero-copy-callback-url').addEventListener('click', function () {
                const url = document.getElementById('acl-xero-callback-url').innerText;
                navigator.clipboard.writeText(url).then(() => {
                    alert('Callback URL copied to clipboard!');
                }).catch(err => {
                    alert('Failed to copy URL: ' + err);
                });
            });
            </script>

            <!-- API Details Form -->
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

            <!-- Step 2: Sync with Xero -->
            <h2>Step 2: Sync with Xero</h2>
            <p>Status: <strong><?php echo esc_html( $status ); ?></strong></p>
            <?php if ( $reset_success ): ?>
                <p style="color: green;">Authorization has been reset successfully.</p>
            <?php endif; ?>
            <a href="<?php echo esc_url( self::get_xero_auth_url( $consumer_key, $redirect_uri ) ); ?>" 
               class="button button-primary"
               <?php echo empty( $consumer_key ) || empty( $consumer_secret ) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
               Authorise with Xero
            </a>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=acl_xero_reset_authorization' ) ); ?>" style="margin-top: 10px;">
                <button type="submit" class="button">Reset Authorization</button>
            </form>
            <?php if ( ! $is_authorized ): ?>
                <p style="color: red;">Please authorise the app with Xero to enable syncing.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // Other methods: handle_xero_callback, reset_authorization, and helper functions...
}

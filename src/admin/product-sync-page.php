<?php
namespace ACLWcXeroSync\Admin;
use ACLWcXeroSync\Services\ACLSyncService;

class ACLProductSyncPage {
    /**
     * Initializes the admin menu pages and callback URL handler.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
        add_action( 'admin_post_acl_xero_sync_callback', [ __CLASS__, 'handle_xero_callback' ] );
        add_action( 'admin_post_acl_xero_reset_authorization', [ __CLASS__, 'reset_authorization' ] );
        add_action( 'wp_ajax_acl_xero_test_connection_ajax', [ __CLASS__, 'handle_test_connection' ] );
        add_action( 'wp_ajax_acl_xero_sync_products_ajax', [ __CLASS__, 'handle_sync_ajax' ] );
    
        // Enqueue scripts and localize AJAX URL
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }       

    /**
     * Enqueues scripts for admin area.
     */
    public static function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'ajax_object', array('ajaxurl' => admin_url('admin-ajax.php')));
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

                // Fetch tenants
                $tenants = self::get_xero_tenants($tokens['access_token']);

                if ($tenants && !empty($tenants)) {
                    // Assuming you want to use the first tenant; adjust if needed for multi-tenant support
                    $tenant_id = $tenants[0]['tenantId'];
                    update_option('xero_tenant_id', $tenant_id);
                } else {
                    error_log('No tenants found or error fetching tenants.');
                    wp_redirect( admin_url( 'admin.php?page=acl-xero-sync-settings&auth=error' ) );
                    exit;
                }  

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
     * Gets the list of tenants associated with the access token.
     *
     * @param string $access_token The access token from Xero.
     * @return array|null Array of tenant data or null if unable to fetch.
     */
    private static function get_xero_tenants($access_token) {
        $client_id = get_option( 'acl_xero_consumer_key' );
        $response = wp_remote_get( 'https://api.xero.com/connections', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            error_log( 'Xero Tenant Fetch Error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body; // This should be an array of tenant objects
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
            // Handle logging settings
            $logging_levels = [
                'xero_auth' => 'Xero Authorisation',
                'xero_connection' => 'Xero Connection for Sync',
                'product_sync' => 'Product Sync',
                'test_xero' => 'Test Xero'
            ];
            foreach ($logging_levels as $key => $label) {
                update_option('acl_xero_log_' . $key, isset($_POST['acl_xero_log_' . $key]) ? '1' : '0');
            }            
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

                <!-- Logging Options -->
                <h2>Logging Options</h2>
                    <table class="form-table">
                        <?php 
                        $logging_levels = [
                            'xero_auth' => 'Xero Authorisation',
                            'xero_connection' => 'Xero Connection for Sync',
                            'product_sync' => 'Product Sync',
                            'test_xero' => 'Test Xero'
                        ];
                        foreach ($logging_levels as $key => $label):
                            $checked = get_option('acl_xero_log_' . $key) ? 'checked' : '';
                            ?>
                            <tr>
                                <th scope="row"><label for="acl_xero_log_<?php echo $key; ?>"><?php echo esc_html($label); ?> Logging</label></th>
                                <td>
                                    <input type="checkbox" id="acl_xero_log_<?php echo $key; ?>" name="acl_xero_log_<?php echo $key; ?>" <?php echo $checked; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
            <button type="button" id="test-xero-connection" class="button button-secondary" style="margin-left: 10px;">
                Test Xero Connection
            </button>
            <div id="xero-test-connection-result" style="margin-top: 10px;"></div>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    console.log ('1');
                    $('#test-xero-connection').on('click', function () {
                        console.log ('2');
                        $('#xero-test-connection-result').html('<p>Testing Connection...</p>');
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: { action: 'acl_xero_test_connection_ajax' },
                            beforeSend: function() {
                                console.log('3'); // Before sending AJAX request
                            },                            
                            success: function (response) {
                                console.log('4'); // Logging successful AJAX response
                                console.log('Response:', response);                                
                                $('#xero-test-connection-result').html(response);
                            },
                            error: function(xhr, status, error) {
                                console.log('5'); // Logging that an error occurred in AJAX request
                                console.error('Error details:', xhr, status, error);                                
                                var errorMessage = xhr.status + ' ' + xhr.statusText + ': ' + error;
                                $('xero-test-connection-result').html('<p>An error occurred: ' + errorMessage + '</p>');
                            },
                        });
                    });
                });
            </script>           
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=acl_xero_reset_authorization' ) ); ?>" style="margin-top: 10px;">
                <button type="submit" class="button">Reset Authorization</button>
            </form>
            <?php if ( ! $is_authorized ): ?>
                <p style="color: red;">Please authorise the app with Xero to enable syncing.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the Product Sync Page.
     */
    public static function render_sync_page() {
        ?>
        <div class="wrap">
            <h1>Sync Products to Xero</h1>
            <form method="post" id="sync-products-form">
                <input type="hidden" name="sync_xero_products" value="1">
                <button type="button" class="button button-primary" id="start-sync">Start Sync</button>
            </form>
            <div id="sync-results"></div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#start-sync').on('click', function(e) {
                    e.preventDefault();
                    $('#sync-results').html('<p>Syncing...</p>');
                    console.log('AJAX call initiated');
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            'action': 'acl_xero_sync_products_ajax',
                            'sync_xero_products': '1'
                        },
                        beforeSend: function() {
                        },                        
                        success: function(response) {                           
                            $('#sync-results').html(response);
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = xhr.status + ' ' + xhr.statusText + ': ' + error;
                            $('#sync-results').html('<p>An error occurred: ' + errorMessage + '</p>');
                        },
                        complete: function() {
                        }                        
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Handles the AJAX request for testing the Xero connection.
     */
    public static function handle_test_connection() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
    
        //ob_start();
        $xero = ACLSyncService::initialize_xero_client();

        // Check for errors
        if (is_wp_error($xero)) {
            echo "<div class='notice notice-error'>".$xero->get_error_message()."</div>"; // Display the error message
            wp_die(); // Stop further execution
        }
        
        if (!empty($xero)) {
            echo "<div class='notice notice-error'>".$xero."</div>"; // Echo the captured output
        } else {
            echo "No output from sync process.";
        }
        wp_die(); // This is required to end the AJAX call properly
    }
    
    public static function handle_sync_ajax() {
        self::log_message("Entering sync ajax", 'test_xero');
       
        // Check if the user has permission to perform this action
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
    
        ob_start(); // Start output buffering
        ACLSyncService::sync_products();
        $output = ob_get_clean(); // Capture the output
        
        if (!empty($output)) {
            echo $output; // Echo the captured output
        } else {
            echo "No output from sync process.";
        }
        wp_die(); // This is required to end the AJAX call properly
    }
}

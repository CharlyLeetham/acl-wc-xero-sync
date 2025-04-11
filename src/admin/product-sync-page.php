<?php
namespace ACLWcXeroSync\Admin;
use ACLWcXeroSync\Services\ACLSyncService;
use ACLWcXeroSync\Helpers\ACLXeroHelper;
use ACLWcXeroSync\Helpers\ACLXeroLogger;

class ACLProductSyncPage {
    /**
     * Initializes the admin menu pages and callback URL handler.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_pages' ] );
        add_action( 'admin_post_acl_xero_sync_callback', [ __CLASS__, 'handle_xero_callback' ] );
        add_action( 'admin_post_acl_xero_reset_authorization', [ __CLASS__, 'reset_authorization' ] );
        add_action( 'wp_ajax_acl_xero_test_connection_ajax', [ ACLXeroHelper::class, 'handle_test_connection' ] );
        add_action( 'wp_ajax_acl_xero_sync_products_ajax', [ ACLXeroHelper::class, 'handle_sync_ajax' ] );
        add_action( 'wp_ajax_acl_download_file', [ ACLXeroHelper::class, 'handle_file_download' ] );
        add_action( 'wp_ajax_acl_delete_csv', [ACLXeroHelper::class, 'handle_delete_csv'] ); 
        add_action( 'wp_ajax_acl_delete_csv_multiple', [ACLXeroHelper::class, 'handle_delete_csv_multiple'] );
        add_action( 'wp_ajax_acl_update_csv_display', [ACLXeroHelper::class, 'update_csv_display'] );
        add_action( 'wp_ajax_acl_get_log_content', [ACLXeroHelper::class, 'get_log_content' ] );           
        add_action( 'acl_xero_log_rotation_event', [ACLXeroLogger::class, 'acl_xero_log_rotation'] ); 
        add_action( 'wp_ajax_acl_xero_sync_status_ajax', [ACLXeroHelper::class, 'handle_sync_status'] );             
                    

        // Enqueue scripts and localize AJAX URL
        add_action( 'admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts'] );
        add_action( 'admin_enqueue_scripts', [__CLASS__, 'acl_xero_display_files'] );
    }       

    /**
     * Enqueues scripts for admin area.
     */
    public static function enqueue_scripts() {
        // Enqueue jQuery if you need a specific version or for some other reason
        wp_enqueue_script('jquery');      
        // If you need an AJAX object for another purpose, you could do it here, but it's not necessary with the above setup
        wp_localize_script('jquery', 'ajax_object', array('ajaxurl' => admin_url('admin-ajax.php')));
    } 

    public static function acl_xero_display_files($file_type = 'log' ) {
        // Enqueue your custom script
        $sp = ACL_XERO_PLUGIN_PATH . 'src/assets/js/wc-xero-sync.js';
        $version = filemtime($sp);
        wp_enqueue_script('acl-wc-xero-sync', ACL_XERO_PLUGIN_URL . 'src/assets/js/wc-xero-sync.js', array('jquery'), $version , true);    
    
        // Localize the script with all necessary data
        wp_localize_script('acl-wc-xero-sync', 'aclWcXeroSyncAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_get_log_content' => wp_create_nonce('get_log_content'),
            'nonce_update_csv_display' => wp_create_nonce('update_csv_display'),
            'nonce_download_file' => wp_create_nonce('download_file'),
            'nonce_delete_csv' => wp_create_nonce('delete_csv'),
            'nonce_delete_csv_multiple' => wp_create_nonce('delete_csv_multiple'),
            'nonce_xero_sync_products_ajax' => wp_create_nonce('xero_sync_products_ajax'),
            'defaultLog' => $defaultLog ? $defaultLog : null,
        ));
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
            'acl-xero-sync', // Parent slug (under WooCommerce)
            'Xero Invoice Sync', // Page title
            'Xero Invoice Sync', // Menu title
            'manage_woocommerce', // Capability
            'acl-xero-invoice-sync', // Menu slug
            [ __CLASS__, 'render_xero_invoice_sync' ]
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
                    update_option( 'xero_tenant_id', $tenant_id );
                } else {
                    ACLXeroLogger::log_message( 'No tenants found or error fetching tenants.', 'xero_auth' );
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
            ACLXeroLogger::log_message( 'Xero Tenant Fetch Error: ' . $response->get_error_message(), 'xero_auth' );
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
            ACLXeroLogger::log_message( 'Xero Token Exchange Error: ' . $response->get_error_message(), 'xero_auth' );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            return $body;
        }

        ACLXeroLogger::log_message( 'Xero Token Exchange Response: ' . print_r( $body, true ), 'xero_auth' );
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
            'scope'         => 'accounting.transactions accounting.settings accounting.contacts offline_access',
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
                'xero_logging' => 'Xero Logging',
                'invoice_sync_test' => 'Invoice Sync Test',
                'invoice_sync' => 'Invoice Sync'
            ];
            foreach ($logging_levels as $key => $label) {
                update_option( 'acl_xero_log_' . $key, isset( $_POST['acl_xero_log_' . $key] ) ? '1' : '0' );
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
                        <td>
                            <input type="password" id="acl_xero_consumer_key" name="acl_xero_consumer_key" value="<?php echo esc_attr( $consumer_key ); ?>" class="regular-text showable-password" autocomplete="off" />
                            <span class="password-toggle-icon" data-target="acl_xero_consumer_key" title="Toggle visibility">
                                <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acl_xero_consumer_secret">Xero Consumer Secret</label></th>
                        <td>
                            <input type="password" id="acl_xero_consumer_secret" name="acl_xero_consumer_secret" value="<?php echo esc_attr( $consumer_secret ); ?>" class="regular-text showable-password" autocomplete="off" />
                            <span class="password-toggle-icon" data-target="acl_xero_consumer_secret" title="Toggle visibility">
                                <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                            </span>
                        </td>
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
                            'xero_logging' => 'Xero Logging',
                            'invoice_sync_test' => 'Invoice Sync Test',
                            'invoice_sync' => 'Invoice Sync'                            
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


                <!-- Log Files -->
                <h2>Log Files</h2>
                <div id="log-file-container">
                    <table class="form-table">
                        <tr>
                            <td colspan="2">
                                <?php 
                                $filetype = 'log';
                                $defaultLog = ACLXeroHelper::display_files( $filetype );                               
                                ?>
                                    <script>
                                        var defaultLog = "<?php echo esc_js( $defaultLog ); ?>";
                                    </script>
                            </td>
                        </tr>
                    </table>
                </div>                                   
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

        $xero = ACLXeroHelper::initialize_xero_client();

        if ( is_wp_error( $xero ) ) {
            echo "<div class='notice notice-error'><p>" . $xero->get_error_message() . "</p></div>"; // Display the error message
            flush();
            // Set to empty arrays so that the form can still be rendered
            $accounts = [];
            $taxTypes = [];

        }  else {
            $accounts = ACLXeroHelper::getXeroAccounts( $xero ); 
            $taxTypes = ACLXeroHelper::getXeroTaxTypes( $xero ); 
        }
        ?>
        <div class="wrap">
            <h1>Sync Products to Xero</h1>
            <form method="post" id="sync-products-form">
                <div class="syncrow">
                    <input type="hidden" name="sync_xero_products" value="1">
                    <input type="checkbox" id="dry-run" name="dry_run">
                    <label for="dry-run">Dry Run</label>
                </div>
                <div class="syncrow">                
                    <select name="category_id" id="category-select">
                        <option value="">Select Category</option>
                        <?php
                        $categories = get_terms('product_cat', array('hide_empty' => false));
                        foreach ($categories as $category) {
                            echo '<option value="' . $category->term_id . '">' . $category->name . '</option>';
                        }
                        ?>
                    </select> 
                </div>
                <div class="syncrow">
                    <h3>For New Products provide:</h3>
                </div>

                <div class="syncrow">
                    <select name="cogs" id="cogs">
                        <option value="">Select COGS Account</option>
                        <?php
                        if ( empty( $accounts ) ) {
                            echo '<option value="">Authenticate with Xero</option>';    
                        } else {
                            foreach ( $accounts as $account ) {
                                if ( $account['Type'] == 'EXPENSE' ) { // Filter for expense accounts which would generally include COGS
                                    echo '<option value="' . $account['Code'] . '">(' . $account['Code']. ') ' . $account['Name'] . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <label for="cogs">Cost of Goods Sold Account</label>
                </div>                
                <div class="syncrow">
                    <select name="salesacct" id="salesacct">
                        <option value="">Select Sales Account</option>
                        <?php
                        if ( empty( $accounts) ) {
                            echo '<option value="">Authenticate with Xero</option>';
                        } else {
                            foreach ( $accounts as $account ) {
                                if ( $account['Type'] == 'REVENUE' ) { // Filter for revenue accounts
                                    echo '<option value="' . $account['Code'] . '">(' . $account['Code']. ') ' . $account['Name'] . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <label for="salesacct">Sales Account</label>
                </div>
                <div class="syncrow">
                    <select name="cogs_tax_type" id="cogs-tax-type">
                        <option value="">Select COGS Tax Type</option>
                        <?php
                        if ( empty( $taxTypes ) ) {
                            echo '<option value="">Authenticate with Xero</option>';
                        } else {
                            foreach ($taxTypes as $taxType) {
                                if ( $taxType['Expenses'] ) {
                                    echo '<option value="' . $taxType['TaxType'] . '">' . $taxType['Name'] . '</option>';
                                } 
                            }
                        }
                        ?>
                    </select>
                    <label for="cogs-tax-type">COGS Tax Type</label>
                </div>
                <div class="syncrow">
                    <select name="sales_tax_type" id="sales-tax-type">
                        <option value="">Select Sales Tax Type</option>
                        <?php
                        if ( empty( $taxTypes ) ) {
                            echo '<option value="">Authenticate with Xero</option>';
                        } else {

                            foreach ( $taxTypes as $taxType ) {
                                if ( $taxType['Revenue'] ) {
                                    echo '<option value="' . $taxType['TaxType'] . '">' . $taxType['Name'] . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <label for="sales-tax-type">Sales Tax Type</label>
                </div>                
                <div class="syncrow">                                                   
                    <button type="button" class="button button-primary" id="start-sync">Start Sync</button>
                </div>        
            </form>
            <div id="sync-results"></div>
            <div id="csv-file-updates"></div> <!-- Placeholder for updates -->                      
            

            <h2>CSV Files</h2>
            <div id="csv-file-container">
                <table class="form-table">
                    <tr>
                        <td colspan="2">
                            <?php 
                            $filetype = 'csv';
                            $defaultLog = ACLXeroHelper::display_files( $filetype, 'pricechange|newproducts' );                               
                            ?>
                                <script>
                                    var defaultLog = "<?php echo esc_js($defaultLog); ?>";
                                </script>
                        </td>                        
                    </tr>
                </table>
            </div>             
        </div>
        <?php
    }
    
    public static function render_xero_invoice_sync() {
        // Save option if submitted
        if ( isset( $_POST['xero_sync_options_nonce'] ) && wp_verify_nonce( $_POST['xero_sync_options_nonce'], 'xero_sync_options_action' ) ) {
            $unpaid_status = isset( $_POST['unpaid_invoice_status'] ) && in_array( $_POST['unpaid_invoice_status'], 
                [ \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT, \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED ] ) 
                ? $_POST['unpaid_invoice_status'] 
                : \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED;
            update_option( 'acl_xero_unpaid_invoice_status', $unpaid_status );

            $bank_account = isset($_POST['bankaccount']) ? sanitize_text_field($_POST['bankaccount']) : '';
            update_option('acl_xero_default_bank_account', $bank_account);

            echo "<div class='notice notice-success'><p>Settings saved.</p></div>";
        }
       
        $args = array(
            'status' => array( 'completed', 'processing', 'pending' ),
            'limit' => -1,
            'return' => 'ids',
        );
        $order_ids = wc_get_orders( $args );
    
        if ( empty( $order_ids ) ) {
            echo "<div class='notice notice-warning'><p>No orders found in the system.</p></div>";
        }
    
        if ( isset( $_POST['xero_test_sync_nonce'] ) && wp_verify_nonce( $_POST['xero_test_sync_nonce'], 'xero_test_sync_action' ) ) {
            $dry_run = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
            if ( isset( $_POST['sync_all'] ) ) {
                ACLXeroHelper::xero_invoice_sync( $dry_run ); // Reuse existing logic for now
            } elseif ( isset( $_POST['sync_order'] ) && ! empty( $_POST['order_id'] ) ) {
                $order_id = intval( $_POST['order_id'] );
                ACLXeroHelper::xero_invoice_sync( $dry_run, array( $order_id ) );
            }
        }

        // Add test for get_or_create_xero_contact
        $test_result = '';
        if (isset($_POST['test_contact_nonce']) && wp_verify_nonce($_POST['test_contact_nonce'], 'test_contact_action') && !empty($order_ids)) {
            $xero = ACLXeroHelper::initialize_xero_client();
            if (!is_wp_error($xero)) {
                try {
                    $order = wc_get_order($order_ids[0]); // Use first order
                    ACLSyncService::get_or_create_xero_contact($xero, $order);
                    $test_result = "<div class='notice notice-success'><p>Contact retrieval test succeeded for order {$order_ids[0]}</p></div>";
                } catch (\Exception $e) {
                    $test_result = "<div class='notice notice-error'><p>Contact retrieval test failed: " . esc_html($e->getMessage()) . ". See logs.</p></div>";
                }
            } else {
                $test_result = "<div class='notice notice-error'><p>Xero client init failed: " . $xero->get_error_message() . "</p></div>";
            }
        }        
    
        $current_status = get_option( 'acl_xero_unpaid_invoice_status', \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED );

        $xero = ACLXeroHelper::initialize_xero_client();

        if ( is_wp_error( $xero ) ) {
            echo "<div class='notice notice-error'><p>" . $xero->get_error_message() . "</p></div>"; // Display the error message
            flush();
            // Set to empty arrays so that the form can still be rendered
            $accounts = [];
            $taxTypes = [];

        }  else {
            $accounts = ACLXeroHelper::getXeroAccounts( $xero ); 
        }
        ?>
        <div class="wrap">
            <h1>Xero Invoice Sync</h1>
            <!-- Options Panel -->
            <h2>Sync Settings</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'xero_sync_options_action', 'xero_sync_options_nonce' ); ?>
                <div class="syncrow">
                        <select name="unpaid_invoice_status" id="unpaid_invoice_status">
                            <option value="<?php echo \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED; ?>" 
                                <?php selected( $current_status, \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED ); ?>>Authorised</option>
                            <option value="<?php echo \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT; ?>" 
                                <?php selected( $current_status, \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT ); ?>>Draft</option>
                        </select>    
                        <label for="unpaid_invoice_status">Unpaid Invoice Status</label>                  
                        <p class="description">Choose how unpaid orders are posted to Xero. 'Authorised' posts to accounts immediately; 'Draft' requires manual approval.</p>
                </div>

                <div class="syncrow">
                    <select name="bankaccount" id="bankaccount">
                        <option value="">Select Bank Account</option>
                        <?php
                         $selected_bank_account = get_option('acl_xero_default_bank_account', ''); // Get the saved bank account code
                        if ( empty( $accounts ) ) {
                            echo '<option value="">Authenticate with Xero</option>';    
                        } else {
                            foreach ( $accounts as $account ) {
                                if ( $account['Type'] == 'BANK' ) { // Filter for expense accounts which would generally include COGS
                                    $code = esc_attr($account['Code']);
                                    $name = esc_html($account['Name']);
                                    $is_selected = selected($selected_bank_account, $code, false); // Compare saved value with current code
                                    echo "<option value='$code' $is_selected>($code) $name</option>";
                                }
                            }
                        }
                        ?>
                    </select>
                    <label for="bankaccount">Default Bank Account For Paid Orders</label>
                </div>                    
                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="Save Settings">
                </p>
            </form>
    
            <!-- Existing Sync UI -->
            <h2>Manual Sync</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'xero_test_sync_action', 'xero_test_sync_nonce' ); ?>
                <p>
                    <input type="submit" name="sync_all" class="button button-primary" value="Sync All Orders (Live)">
                    <input type="submit" name="sync_all" class="button" value="Sync All Orders (Dry Run)" onclick="document.getElementById('dry_run_input').value='1';">
                    <input type="hidden" name="dry_run" id="dry_run_input" value="0">
                </p>
            </form>
    
            <?php if ( ! empty( $order_ids ) ) : ?>
                <h2>Orders List (<?php echo count( $order_ids ); ?> found)</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Payment Status</th>
                            <th>Xero Sync Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $order_ids as $order_id ) {
                            $order = wc_get_order( $order_id );
                            $existing_invoice = ACLXeroHelper::check_existing_xero_invoice( $xero, $order_id );
                            $sync_status = $existing_invoice ? 
                                "Synced (Invoice ID: " . $existing_invoice->getInvoiceID() . ")" : 
                                "Not Synced";
                            ?>
                            <tr>
                                <td><?php echo $order_id; ?></td>
                                <td><?php echo $order->get_date_created()->format( 'Y-m-d H:i:s' ); ?></td>
                                <td><?php echo $order->get_status(); ?></td>
                                <td><?php echo wc_price( $order->get_total() ); ?></td>
                                <td><?php echo $order->is_paid() ? 'Paid' : 'Unpaid'; ?></td>
                                <td><?php echo $sync_status; ?></td>
                                <td>
                                    <form method="post" action="" style="display:inline;">
                                        <?php wp_nonce_field( 'xero_test_sync_action', 'xero_test_sync_nonce' ); ?>
                                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                        <input type="submit" name="sync_order" class="button button-small" value="Sync Live">
                                        <input type="submit" name="sync_order" class="button button-small" value="Dry Run" onclick="this.form.dry_run.value='1';">
                                        <input type="hidden" name="dry_run" value="0">
                                    </form>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Test Contact Retrieval</h2>
            <form method="post" action="">
                <?php wp_nonce_field('test_contact_action', 'test_contact_nonce'); ?>
                <p>
                    <input type="submit" name="test_contact" class="button button-primary" value="Test Contact Retrieval">
                </p>
            </form>
            <?php echo $test_result; ?>            
    
            <h2>Invoice Sync CSV Log Files</h2>
            <div id="csv-file-container">
                <table class="form-table">
                    <tr>
                        <td colspan="2">
                            <?php 
                            $filetype = 'csv';
                            $defaultLog = ACLXeroHelper::display_files( $filetype, 'invoice_sync' );                               
                            ?>
                            <script>
                                var defaultLog = "<?php echo esc_js( $defaultLog ); ?>";
                            </script>
                        </td>                        
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }  
}

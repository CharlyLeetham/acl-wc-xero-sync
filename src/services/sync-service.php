<?php
/**
 * Handles syncing WooCommerce products with Xero.
 */

namespace ACLWcXeroSync\Services;

class ACLSyncService {
    /**
     * Syncs WooCommerce products with Xero by checking their existence.
     */
    public static function sync_products() {
        try {
            // Step 1: Fetch WooCommerce Products
            $products = self::get_wc_products();
            if ( empty( $products ) ) {
                self::log_message('No products found in WooCommerce.', 'product_sync');
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";
                return;
            }

            self::log_message(count($products) . ' products fetched from WooCommerce.', 'product_sync');

            // Step 2: Initialize Xero Client
            $client_id = get_option( 'acl_xero_consumer_key' );
            $client_secret = get_option( 'acl_xero_consumer_secret' );

            if ( empty( $client_id ) || empty( $client_secret ) ) {
                throw new \Exception( 'Missing Xero Consumer Key or Secret. Please update the settings.' );
            }

            $xero = self::initialize_xero_client( $client_id, $client_secret );

            // Step 3: Process Each Product
            foreach ( $products as $product ) {
                self::process_product( $xero, $product );
            }
        } catch ( \Exception $e ) {
            self::log_message('Fatal error in sync process: ' . $e->getMessage(), 'product_sync');
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";
        }
    }

    /**
     * Initializes the Xero client using Consumer Key and Secret.
     *
     * @param string $client_id The Consumer Key.
     * @param string $client_secret The Consumer Secret.
     * @return \XeroPHP\Application
     */
    private static function initialize_xero_client() {
        try {
            $accessToken = get_option('xero_access_token');
            $refreshToken = get_option('xero_refresh_token');
            $tenantId = get_option('xero_tenant_id');
        
            if (empty($accessToken) || empty($refreshToken) || empty($tenantId)) {
                throw new \Exception("Xero Access Token, Refresh Token, or Tenant ID missing. Please authorize.");
            }
        
            // Check if the token is expired (assuming token expiration is stored)
            $token_expires = get_option('xero_token_expires', 0);
            if (time() > $token_expires) {
                $client_id = get_option('acl_xero_consumer_key');
                $client_secret = get_option('acl_xero_consumer_secret');
        
                // Use the provider to refresh the token
                $provider = new \Calcinai\OAuth2\Client\Provider\Xero([
                    'clientId' => $client_id,
                    'clientSecret' => $client_secret,
                ]);

                // Directly use the refresh token string instead of creating an object
                $newAccessToken = $provider->getAccessToken('refresh_token', [
                    'refresh_token' => $refreshToken
                ]);

                if ($newAccessToken) {
                    $accessToken = $newAccessToken->getToken();
                    update_option('xero_access_token', $accessToken);
                    update_option('xero_refresh_token', $newAccessToken->getRefreshToken());
                    update_option('xero_token_expires', time() + $newAccessToken->getExpires());
                } else {
                    throw new \Exception("Failed to refresh the Xero access token.");
                }
            }
        
            // Now initialize with the (potentially new) access token
            $xero = new \XeroPHP\Application($accessToken, $tenantId);
        
            self::log_message("Xero initialized correctly. Tenant ID: " . $tenantId, 'xero_auth');
            self::log_message("Token Expires " . $newAccessToken, 'xero_auth');
            return $xero;
        } catch (\Exception $e) {
            self::log_message('Error initializing Xero client: ' . $e->getMessage(), 'xero_auth');
            throw $e;
        }
    }


    // Class property to store messages
    private static $messages = [];    

    /**
     * Processes a single product, checking its existence in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param array $product
     */
    private static function process_product( $xero, $product ) {
        if ( empty( $product['sku'] ) ) {
            self::log_message("Product [ID: {$product['id']}] skipped: Missing SKU.", 'product_sync');
            echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
            return;
        }

        $sku = $product['sku'];

        try {

            self::test_xero_connection($xero);            
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            if ( $exists ) {
                self::log_message("Product [SKU: {$sku}] exists in Xero.", 'product_sync');
                self::add_message("Product SKU <strong>{$sku}</strong> exists in Xero.", 'info');
            } else {
                self::log_message("Product [SKU: {$sku}] does not exist in Xero.", 'product_sync');
                self::add_message("Product SKU <strong>{$sku}</strong> does not exist in Xero.", 'warning');
            }
        } catch ( \Exception $e ) {
            self::log_message("Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync');
            self::add_message("Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Checks if a product SKU exists in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param string $sku
     * @return bool
     */
    private static function check_if_sku_exists( $xero, $sku ) {
        try {
            $query = $xero->load( 'Accounting\\Item' )
                                   ->where( 'Code', $sku );
          
            self::log_message(" SKU: " . $sku, 'product_sync');
            
            $existing_items = $query->execute();                                   
            return ! empty( $existing_items );
        } catch ( \Exception $e ) {
            self::log_message("Error querying Xero for SKU {$sku}: {$e->getMessage()}", 'product_sync');;
            throw $e;
        }
    }

    /**
     * Fetches products from WooCommerce using a direct database query.
     *
     * @return array List of WooCommerce products.
     */
    private static function get_wc_products() {
        global $wpdb;

        $query = "
            SELECT p.ID as id, pm.meta_value as sku
            FROM {$wpdb->prefix}posts p
            JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_key = '_sku'
        ";

        return $wpdb->get_results( $query, ARRAY_A ) ?: [];
    }

    /**
     * Logs a message to a custom log file.
     *
     * @param string $message The message to log.
     */
    private static function log_message($message, $level = 'none') {
        $log_file = WP_CONTENT_DIR . '/uploads/acl-xero-sync.log';
        $log_enabled = get_option('acl_xero_log_' . $level, '0') == '1'; // Default to disabled if not set
    
        if ($log_enabled) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($log_file, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND);
        }
    }

    // Add this new method to test the Xero connection
    private static function test_xero_connection($xero) {
        try {
            // Attempt to get the organization details, which should be a basic, low-impact query
            $orgs = $xero->load('Accounting\\Organisation')
                        ->execute();
            
            if (empty($orgs)) {
                throw new \Exception("No organizations found, Xero connection might be invalid.");
            }
            
            self::log_message("Xero connection test passed. Organization name: " . $orgs[0]->getName(), 'xero_connection');
        } catch (\Exception $e) {
            self::log_message("Xero connection test failed: " . $e->getMessage(), 'xero_connection');
            throw new \Exception("Xero connection test failed: " . $e->getMessage());
        }
    } 

    /**
     * Adds a message to be displayed on the Sync Products page.
     *
     * @param string $message The message to display.
     * @param string $type The type of message (error, warning, info).
     */
    private static function add_message($message, $type = 'info') {
        self::$messages[] = ['message' => $message, 'type' => $type];
    }    

   /**
     * Displays all collected messages on the Sync Products page.
     */
    public static function display_messages() {
        self::log_message("Displaying Messages " . $e->getMessage(), 'xero_connection');
        foreach (self::$messages as $message) {
            self::log_message("foreach " . $e->getMessage(), 'xero_connection');
            ?>
            <div class="notice notice-<?php echo esc_attr($message['type']); ?>">
                <p><?php echo esc_html($message['message']); ?></p>
            </div>
            <?php
        }
        // Clear messages after displaying
        self::log_message("after loop" . $e->getMessage(), 'xero_connection');
        self::$messages = [];
        self::log_message("last " . $e->getMessage(), 'xero_connection');
    }    

  
}

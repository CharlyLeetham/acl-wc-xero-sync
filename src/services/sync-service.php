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

            echo "Syncing " . count($products) . " products...<br>";
            self::log_message(count($products) . ' products fetched from WooCommerce.', 'product_sync');

            // Step 2: Initialize Xero Client
            $client_id = get_option( 'acl_xero_consumer_key' );
            $client_secret = get_option( 'acl_xero_consumer_secret' );

            if ( empty( $client_id ) || empty( $client_secret ) ) {
                throw new \Exception( 'Missing Xero Consumer Key or Secret. Please update the settings.' );
            }

            $xero = self::initialize_xero_client();

            // Step 3: Process Each Product
            foreach ( $products as $product ) {
                try {
                    self::process_product($xero, $product);
                } catch (\Exception $e) {
                    $sku = $product['sku'] ?? 'No SKU';
                    self::log_message("Error processing product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync');
                    echo "<div class='notice notice-error'><p>Error processing product SKU: <strong>{$sku}</strong> - {$e->getMessage()}</p></div>";
                }
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

    
    public static function initialize_xero_client() {
        self::log_message('Initializing Xero client.', 'xero_auth');

        try {
            // Retrieve necessary credentials
            $accessToken = get_option('xero_access_token');
            $refreshToken = get_option('xero_refresh_token');
            $tenantId = get_option('xero_tenant_id');
            $tokenExpires = get_option('xero_token_expires', 0);

            if (empty($accessToken) || empty($refreshToken) || empty($tenantId)) {
                self::log_message('Missing Xero credentials. Please authorize.', 'xero_auth');
                throw new \Exception("Xero Access Token, Refresh Token, or Tenant ID missing. Please authorize.");
            }

            // Check token expiration and refresh if necessary
            if (time() > $tokenExpires) {
                self::log_message('Access token expired. Refreshing...', 'xero_auth');

                $clientId = get_option('acl_xero_consumer_key');
                $clientSecret = get_option('acl_xero_consumer_secret');

                $provider = new \Calcinai\OAuth2\Client\Provider\Xero([
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                ]);

                $newAccessToken = $provider->getAccessToken('refresh_token', [
                    'refresh_token' => $refreshToken,
                ]);

                $accessToken = $newAccessToken->getToken();
                update_option('xero_access_token', $accessToken);
                update_option('xero_refresh_token', $newAccessToken->getRefreshToken());
                update_option('xero_token_expires', time() + $newAccessToken->getExpires());

                self::log_message('Tokens refreshed successfully.', 'xero_auth');
            }

            // Initialize Xero client
            $xero = new \XeroPHP\Application($accessToken, $tenantId);
            self::log_message("Xero client initialized successfully with Tenant ID: $tenantId", 'xero_auth');

            return $xero;

        } catch (\Exception $e) {
            self::log_message("Error initializing Xero client: " . $e->getMessage(), 'xero_auth');
            throw new \Exception("Failed to initialize Xero client: " . $e->getMessage());
        }
    }
    



    public static function old_initialize_xero_client() {
        self::log_message('Initialising Xero.', 'xero_auth');
        try {
            $accessToken = get_option('xero_access_token');
            $refreshToken = get_option('xero_refresh_token');
            $tenantId = get_option('xero_tenant_id');
        
            if (empty($accessToken) || empty($refreshToken) || empty($tenantId)) {
                throw new \Exception("Xero Access Token, Refresh Token, or Tenant ID missing. Please authorize.");
                self::log_message("Xero Access Token, Refresh Token, or Tenant ID missing. Please authorize.", 'xero_auth');
            }
        
            // Check if the token is expired (assuming token expiration is stored)
            $token_expires = get_option('xero_token_expires', 0);
            self::log_message("Current time: " . date('Y-m-d H:i:s', time()), 'xero_auth');
            self::log_message("Token expires at: " . date('Y-m-d H:i:s', $token_expires), 'xero_auth');
            if (time() > $token_expires) {
                self::log_message('Token has exired.', 'xero_auth');
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

                try {
                    $newAccessToken = $provider->getAccessToken('refresh_token', [
                        'refresh_token' => $refreshToken
                    ]);
    
                    if ($newAccessToken) {
                        $accessToken = $newAccessToken->getToken();
                        update_option('xero_access_token', $accessToken);
                        update_option('xero_refresh_token', $newAccessToken->getRefreshToken());
                        $newExpiration = time() + $newAccessToken->getExpires();
                        update_option('xero_token_expires', $newExpiration);
                        self::log_message('Tokens refreshed.', 'xero_auth');
                        self::log_message("New token expires at: " . date('Y-m-d H:i:s', $newExpiration), 'xero_auth');
                    } else {
                        throw new \Exception("Failed to refresh the Xero access token.");
                    }
                } catch (\Exception $e) {
                    self::log_message('Token refresh failed: ' . $e->getMessage(), 'xero_auth');
                    throw new \Exception("Failed to refresh Xero token. Please re-authorize the connection.");
                }
            }

                // Initialize with the (potentially new) access token
                $xero = new \XeroPHP\Application($accessToken, $tenantId);
            
                self::log_message("Xero initialized correctly. Tenant ID: " . $tenantId, 'xero_auth');
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
            self::log_message("Product [ID: {$product['id']}] - $sku skipped: Missing SKU.", 'product_sync');
            echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] - {$sku} skipped: Missing SKU.</p></div>";
            return;
        }

        $sku = $product['sku'];

        try {
         
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            if ( $exists ) {

                // Fetch item details from Xero
                $item = self::get_xero_item($xero, $sku);
                
                // Assuming 'UnitPrice' is the field for sale price in Xero
                $xeroPrice = $item->getSalesDetails()->getUnitPrice();
                
                // Get WooCommerce price
                $wcPrice = get_post_meta($product['id'], '_price', true);  

                // Compare prices
                if ((float)$xeroPrice !== (float)$wcPrice) {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Price differs. Xero sale price: ${$xeroPrice}. WooCommerce price: ${$wcPrice} </p></div>";
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Price is the same.</p></div>";
                }
                self::log_message("Product SKU <strong>{$sku}</strong> exists in Xero. Xero Price: ${$xeroPrice}, WooCommerce Price: ${$wcPrice}", 'product_sync');
            } else {
                echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] does not exist in Xero.</p></div>";                
                self::log_message("Product SKU <strong>{$sku}</strong> does not exist in Xero.", 'product_sync');
            }
        } catch ( \Exception $e ) {
            self::log_message("Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync');
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
            $query = $xero->load('Accounting\\Item')
                          ->where('Code', $sku);
    
            self::log_message(" SKU: " . $sku, 'product_sync');
            
            $existing_items = $query->execute();                                   
            return !empty($existing_items);
        } catch (\Exception $e) {
            $errorDetails = json_decode($e->getMessage(), true);
            if ($errorDetails && isset($errorDetails['Detail']) && strpos($errorDetails['Detail'], 'TokenExpired') !== false) {
                self::log_message("Token expired during SKU check for " . $sku, 'product_sync');
                
                try {
                    // Attempt to refresh the token
                    $xero = self::initialize_xero_client(); // This should handle token refresh
                    self::log_message("Attempting to refresh token for SKU check.", 'xero_auth');
                    
                    // Retry the query with the potentially refreshed token
                    $query = $xero->load('Accounting\\Item')
                                  ->where('Code', $sku);
                    
                    $existing_items = $query->execute();
                    self::log_message("Token refresh and query retry successful for SKU " . $sku, 'xero_auth');
                    return !empty($existing_items);
                } catch (\Exception $refreshException) {
                    // If refresh fails, notify user to reauthorize
                    self::log_message("Failed to refresh token for SKU " . $sku . ": " . $refreshException->getMessage(), 'xero_auth');
                    echo "<div class='notice notice-error'><p>Token expired and could not be refreshed. Please reauthorize to sync product SKU: <strong>{$sku}</strong>.</p></div>";
                    return false; // Return false since we couldn't check the SKU
                }
            } else {
                // Log and display other types of errors
                self::log_message("Error querying Xero for SKU {$sku}: {$e->getMessage()}", 'product_sync');
                echo "<div class='notice notice-error'><p>Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}</p></div>";
                throw $e; // Re-throw to let the calling function know there was an error
            }
        }
    }

    /**
     * Retrieves Xero item by SKU.
     *
     * @param \XeroPHP\Application $xero
     * @param string $sku
     * @return \XeroPHP\Models\Accounting\Item
     * @throws \Exception
     */
    private static function get_xero_item($xero, $sku) {
        try {
            $query = $xero->load('Accounting\\Item')
                        ->where('Code', $sku);

            $items = $query->execute();
            
            if (empty($items)) {
                throw new \Exception("Item with SKU {$sku} not found in Xero.");
            }

            return $items[0]; // Assuming there's only one item with this SKU
        } catch (\Exception $e) {
            self::log_message("Error fetching item [SKU: {$sku}] from Xero: {$e->getMessage()}", 'product_sync');
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
    public static function test_xero_connection($xero) {
        self::log_message("Testing Xero Connection", 'test_xero');
        try {
            // Attempt to get the organization details, which should be a basic, low-impact query
            $orgs = $xero->load('Accounting\\Organisation')
                        ->execute();
            
            if (empty($orgs)) {
                throw new \Exception("No organizations found, Xero connection might be invalid.");
                echo "<div class='notice notice-info'><p>No organizations found, Xero connection might be invalid.</p></div>";
            }
            
            self::log_message("Xero connection test passed. Organization name: " . $orgs[0]->getName(), 'test_xero');
            echo "<div class='notice notice-info'><p>Xero connection test passed. Organization name: " . $orgs[0]->getName()."</p></div>";
        } catch (\Exception $e) {
            $parts = explode(':', $e->getMessage(), 2); // Split into 2 parts: everything before the first colon, and everything after            

            if (count($parts) > 1) {
                $detailPart = trim($parts[1]); // Trim to remove any leading/trailing whitespace
                if (strpos($detailPart, 'TokenExpired') !== false) {
                    self::log_message("Xero Connection Test Failed. Token Expired", 'test_xero');
                    try {
                        // Reinitialize with a potentially refreshed token
                        $xero = self::initialize_xero_client();
                        
                        // Try the query again with the new token
                        $orgs = $xero->load('Accounting\\Organisation')
                                    ->execute();
                        
                        if (empty($orgs)) {
                            throw new \Exception("No organizations found after token refresh, connection still invalid.");
                        }
                        
                        self::log_message("Token refreshed successfully. Organization name: " . $orgs[0]->getName(), 'test_xero');
                    } catch (\Exception $refreshException) {
                        self::log_message("Failed to refresh token or connection still invalid: " . $refreshException->getMessage(), 'test_xero');
                        throw $refreshException;
                    }
                } else {
                    self::log_message("Xero connection test failed: " . $e->getMessage(), 'test_xero');
                    throw $e;
                }
            } else {
                self::log_message("Xero connection test failed: " . $e->getMessage(), 'test_xero');
                throw $e;
            }
        }
    }    
}

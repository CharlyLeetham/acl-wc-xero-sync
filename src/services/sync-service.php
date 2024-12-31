<?php
/**
 * Handles syncing WooCommerce products with Xero.
 */

namespace ACLWcXeroSync\Services;
use ACLWcXeroSync\Helpers\ACLXeroHelper;
use ACLWcXeroSync\Helpers\ACLXeroLogger;

class ACLSyncService {

    /**
     * Syncs WooCommerce products with Xero by checking their existence.
     */
    public static function sync_products( $dry_run = false ) {
        try {
            // Step 1: Fetch WooCommerce Products
            $products = ACLWCService::get_products();
            if ( empty( $products ) ) {
                ACLXeroLogger::log_message( 'No products found in WooCommerce.', 'product_sync' );
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";                
                return;
            }

            echo "<div class='notice notice-info'><p>Syncing " . count( $products ) . " products...</p></div>";
            ACLXeroLogger::log_message( count( $products ) . ' products fetched from WooCommerce.', 'product_sync' );

            // Step 2: Initialize Xero Client
            $xero = ACLXeroHelper::initialize_xero_client();
            if ( is_wp_error( $xero ) ) {
                echo "<div class='notice notice-error'><p>" . $xero->get_error_message() . "</p></div>"; // Display the error message
                wp_die(); // Stop further execution
            }  
            
            if ( !empty( $xero ) ) {
                echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: " . get_option( 'xero_tenant_id' ) . "</p></div>"; // Echo the captured output
                echo "<div class='notice notice-info'><p>Now syncing products</p></div>"; // Echo the captured output
            } 

            // Setup the CSV files
            $nopricechange_csv = "nopricechange_" . current_time( "Y-m-d-H-i-s" ) . ".csv"; // Captures sync'd products that have no changes
            $pricechange_csv = "pricechange_" . current_time( "Y-m-d-H-i-s" ) . ".csv"; // Captures sync'd products that have changes.

            // Step 3: Process Each Product
            $itemsToUpdate = array();
            $count = 0;
            foreach ( $products as $product ) {
                if ( empty( $product['sku'] ) ) {
                    ACLXeroLogger::log_message( "Product [ID: {$product['id']}] skipped: Missing SKU.", 'product_sync' );
                    echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
                    continue;
                }

                $sku = $product['sku'];
                try {
                    $itemDetails = self::process_product( $xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run );
                    if ( !empty( $itemDetails ) ) {
                        $itemsToUpdate[] = $itemDetails;
                    }
                    $count++;
                } catch ( \Exception $e ) {
                    ACLXeroLogger::log_message( "Error processing product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
                    echo "<div class='notice notice-error'><p>Error processing product SKU: <strong>{$sku}</strong> - {$e->getMessage()}</p></div>";
                }
            }

            // Perform batch update
            if ( !$dry_run && !empty( $itemsToUpdate ) ) {
                self::batch_update_xero_items( $itemsToUpdate );
            }

            // Echo the number of successfully synced products
            echo "<div class='notice notice-success'><p>{$count} Products Processed</p></div>";        
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( 'Fatal error in sync process: ' . $e->getMessage(), 'product_sync' );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";            
        }
    }      

    /**
     * Processes a single product, deciding if it needs an update.
     *
     * @param \XeroPHP\Application $xero
     * @param array $product
     * @param string $pricechange_csv
     * @param string $nopricechange_csv
     * @return array|null Returns item details if an update is needed, null otherwise
     */
    private static function process_product( $xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run ) {
        $sku = $product['sku'];

        try {
         
            ACLXeroLogger::log_message("Dry Run: {$dry_run}", 'product_sync');
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            if ( $exists ) {

                // Fetch item details from Xero
                $item = self::get_xero_item( $xero, $sku );

                
                // Assuming 'UnitPrice' is the field for sale price in Xero
                $xeroPrice = $item->SalesDetails->UnitPrice;
                echo '<pre>';
                echo var_dump($xeroPrice);
                echo '</pre>';
                return;
                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true );  

                // Compare prices
                if ( (float)$xeroPrice !== (float)$wcPrice ) {
                    $salesDetails = $item->getSalesDetails();
                    ACLXeroHelper::csv_file( $pricechange_csv, "{$sku},{$xeroPrice},{$wcPrice}" );
                    return [
                        'Code' => $sku,
                        'SalesDetails' => [
                            'UnitPrice' => (float)$wcPrice,
                            'AccountCode' => $salesDetails->getAccountCode(),
                            'TaxType' => $salesDetails->getTaxType()
                        ]
                    ];
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Price is the same.</p></div>";
                    ACLXeroHelper::csv_file( $nopricechange_csv, "{$sku},{$xeroPrice},{$wcPrice}" );
                }

                if ($dry_run) {
                    ACLXeroLogger::log_message("Dry Run: Would have updated price for SKU {$sku} to {$wcPrice}.", 'product_sync');
                    echo "<div class='notice notice-info'><p>Dry Run: Would have updated price for SKU <strong>{$sku}</strong> to {$wcPrice}.</p></div>";
                    return null; // No actual update, so return null
                }                
                ACLXeroLogger::log_message( "Product SKU <strong>{$sku}</strong> exists in Xero. Xero Price: {$xeroPrice}, WooCommerce Price: {$wcPrice}", 'product_sync' );
            } else {
                echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] does not exist in Xero.</p></div>";                
                ACLXeroLogger::log_message( "Product SKU <strong>{$sku}</strong> does not exist in Xero.", 'product_sync' );
            }
            return null;
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
            throw $e; // Re-throw so it's caught in sync_products
        }
    }

    // ... [Other methods remain unchanged]

    /**
     * Performs a batch update of items in Xero.
     *
     * @param array $itemsToUpdate Array of item data to update
     */
    private static function batch_update_xero_items( $itemsToUpdate ) {
        try {
            $accessToken = get_option( 'xero_access_token' );
            $tenantId = get_option( 'xero_tenant_id' );

            $headers = array(
                'Authorization: Bearer ' . $accessToken,
                'Xero-tenant-id: ' . $tenantId,
                'Content-Type: application/json'
            ); 

            $url = "https://api.xero.com/api.xro/2.0/Items";

            $ch = curl_init();

            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( array( 'Items' => $itemsToUpdate ) ) );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
            
            $response = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            if ( curl_errno( $ch ) ) {
                $errorMessage = 'Curl error: ' . curl_error( $ch );
                ACLXeroLogger::log_message( $errorMessage, 'xero_api_error' );
                throw new \Exception( $errorMessage );
            } 

            if ( $httpCode !== 200 ) {
                $errorMessage = "Failed to batch update items in Xero. HTTP Status: {$httpCode}. Response: {$response}";
                ACLXeroLogger::log_message( $errorMessage, 'xero_api_error' );
                throw new \Exception( $errorMessage );
            }

            ACLXeroLogger::log_message( count( $itemsToUpdate ) . " items updated successfully in batch. HTTP Status: {$httpCode}", 'product_sync' );
            echo "<div class='notice notice-info'><p>" . count( $itemsToUpdate ) . " items updated in Xero.</p></div>";            
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error in batch update: {$e->getMessage()}", 'product_sync' );
            echo "<div class='notice notice-error'><p>Batch update error: {$e->getMessage()}</p></div>";
        } finally {
            if ( isset( $ch ) ) {
                curl_close( $ch );
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
            ACLXeroLogger::log_message("Error fetching item [SKU: {$sku}] from Xero: {$e->getMessage()}", 'product_sync');
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
    
            ACLXeroLogger::log_message(" SKU: " . $sku, 'product_sync');
            
            $existing_items = $query->execute();                                   
            return !empty($existing_items);
        } catch (\Exception $e) {
            $errorDetails = json_decode($e->getMessage(), true);
            if ($errorDetails && isset($errorDetails['Detail']) && strpos($errorDetails['Detail'], 'TokenExpired') !== false) {
                ACLXeroLogger::log_message("Token expired during SKU check for " . $sku, 'product_sync');
                
                try {
                    // Attempt to refresh the token
                    $xero = ACLXeroHelper::initialize_xero_client(); // This should handle token refresh
                    ACLXeroLogger::log_message("Attempting to refresh token for SKU check.", 'xero_auth');
                    
                    // Retry the query with the potentially refreshed token
                    $query = $xero->load('Accounting\\Item')
                                  ->where('Code', $sku);
                    
                    $existing_items = $query->execute();
                    ACLXeroLogger::log_message("Token refresh and query retry successful for SKU " . $sku, 'xero_auth');
                    return !empty($existing_items);
                } catch (\Exception $refreshException) {
                    // If refresh fails, notify user to reauthorize
                    ACLXeroLogger::log_message("Failed to refresh token for SKU " . $sku . ": " . $refreshException->getMessage(), 'xero_auth');
                    echo "<div class='notice notice-error'><p>Token expired and could not be refreshed. Please reauthorize to sync product SKU: <strong>{$sku}</strong>.</p></div>";
                    return false; // Return false since we couldn't check the SKU
                }
            } else {
                // Log and display other types of errors
                ACLXeroLogger::log_message("Error querying Xero for SKU {$sku}: {$e->getMessage()}", 'product_sync');
                echo "<div class='notice notice-error'><p>Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}</p></div>";
                throw $e; // Re-throw to let the calling function know there was an error
            }
        }
    }    
}
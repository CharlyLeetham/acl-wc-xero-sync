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
    public static function sync_products( $dry_run = false, $category_id = null, $cogs = NULL, $salesacct = NULL, $cogstaxtype = NULL, $salestaxtype = NULL ) {
        $batch_size = 50;
        $offset = 0;
        $processed_count = 0;

        try {
            // Step 1: Fetch the total number of WooCommerce Products to process

            if ($category_id) {
                $args = array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'fields'         => 'ids', // Only get the IDs to count
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field'    => 'term_id',
                            'terms'    => $category_id,
                        ),
                    ),
                );
                $total_products = count(get_posts($args));
            } else {
                $total_products = wp_count_posts('product')->publish;
            }

            if ( empty( $total_products ) ) {
                ACLXeroLogger::log_message( 'No products found in WooCommerce.', 'product_sync' );
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";                
                flush();
                return;
            }

            echo "<div class='notice notice-info'><p>Syncing " . $total_products . " products in batches of {$batch_size}...</p></div>";
            flush();
            ACLXeroLogger::log_message( $total_products . ' products to sync from WooCommerce.', 'product_sync' );

            // Step 2: Initialize Xero Client
            $xero = ACLXeroHelper::initialize_xero_client();
            if ( is_wp_error( $xero ) ) {
                echo "<div class='notice notice-error'><p>" . $xero->get_error_message() . "</p></div>"; // Display the error message
                flush();
                wp_die(); // Stop further execution
            }  
            
            if ( !empty( $xero ) ) {
                echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: " . get_option( 'xero_tenant_id' ) . "</p></div>"; // Echo the captured output
                echo "<div class='notice notice-info'><p>Now syncing products</p></div>"; // Echo the captured output
                flush();
            } 

            // Setup the CSV files
            $timestamp = current_time("Y-m-d-H-i-s");
            $dryRunSuffix = $dry_run ? '_dryrun' : '';
            $nopricechange_csv = "nopricechange{$dryRunSuffix}_" . $timestamp . ".csv"; // Captures sync'd products that have no changes
            $pricechange_csv = "pricechange{$dryRunSuffix}_" . $timestamp . ".csv"; // Captures sync'd products that have changes.
            $newproducts_csv = "newproducts($dryRunSuffix}_" . $timestamp. "csv"; //Captures the new sync'd products.

            // Step 3: Process Each Product
            do {
                $products = ACLWCService::get_products($offset, $batch_size, $category_id); // Fetch in batches
                $itemsToUpdate = [];
                $batch_count = 0;

                foreach ($products as $product) {
                    if (empty($product['sku'])) {
                        ACLXeroLogger::log_message("Product [ID: {$product['id']}] skipped: Missing SKU.", 'product_sync');
                        echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
                        flush();
                        continue;
                    }
        
                    $sku = trim( $product['sku'] );
                    try {
                        $itemDetails = self::process_product($xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run, $cogs, $salesacct, $cogstaxtype, $salestaxtype);
                        if (!empty($itemDetails)) {
                            if ($dry_run) {
                                $xeroPurchasePrice = $itemDetails['PurchaseDetails']['UnitPrice'] ?? '';
                                $wcPurchasePrice = get_post_meta($product['id'], 'acl_wc_cost_price', true) ?? '';
                                $xeroSellPrice = $itemDetails['SalesDetails']['UnitPrice'] ?? '';
                                $wcSellPrice = get_post_meta($product['id'], '_price', true) ?? '';

                                ACLXeroLogger::log_message("Dry Run: Would have updated price for SKU {$sku}. Xero Purchase Price: {$xeroPurchasePrice}, WC Purchase Price: {$wcPurchasePrice}, Xero Sell Price: {$xeroSellPrice}, WC Sell Price: {$wcSellPrice}", 'product_sync');
                                echo "<div class='notice notice-info'><p>Dry Run: Would have run for SKU <strong>{$sku}</strong> Xero Purchase Price: {$xeroPurchasePrice}, WC Purchase Price: {$wcPurchasePrice}, Xero Sell Price: {$xeroSellPrice}, WC Sell Price: {$wcSellPrice}.</p></div>";
                                flush();
                            } else {
                                $itemsToUpdate[] = $itemDetails;
                            }
                        }
                        $batch_count++;
                        $processed_count++;
                    } catch (\Exception $e) {
                        ACLXeroLogger::log_message("Error processing product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync');
                        echo "<div class='notice notice-error'><p>Error processing product SKU: <strong>{$sku}</strong> - {$e->getMessage()}</p></div>";
                        flush();
                    }
                }
                
                // Perform batch update
                if ( !$dry_run && !empty( $itemsToUpdate ) ) {
                    self::batch_update_xero_items( $itemsToUpdate );
                }            

                echo "<div class='notice notice-info'><p>Batch processed: {$batch_count} products.</p></div>";
                flush();

                // Move to the next batch
                $offset += $batch_size;
            } while (!empty($products));

            // Clear status after process completes
            delete_transient('xero_sync_status');
        
            // Echo the number of successfully synced products
            echo "<div class='notice notice-success'><p>{$processed_count} Products Processed</p></div>";
            flush();

        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( 'Fatal error in sync process: ' . $e->getMessage(), 'product_sync' );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>"; 
            flush();           
        }

        // Log completion
        ACLXeroLogger::log_message('Sync process completed.', 'product_sync');        
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
    private static function process_product( $xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run, $cogs = NULL, $salesacct = NULL, $cogstaxtype = NULL, $salestaxtype = NULL ) {
        $sku = trim ( $product['sku'] );
        try {
         
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            if ( $exists ) {

                // Fetch item details from Xero
                $item = self::get_xero_item( $xero, $sku );
                
                // Assuming 'UnitPrice' is the field for sale price in Xero
                $xeroPrice = $item->SalesDetails->UnitPrice;
                $xeroPurchasePrice = $item->PurchaseDetails->UnitPrice;

                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true ); 
                $wcPurchasePrice = get_post_meta( $product['id'], 'acl_wc_cost_price', true );

                ACLXeroLogger::log_message( "Product SKU {$sku} exists in Xero. Xero Price: {$xeroPrice}, Xero Purchase Price: {$xeroPurchasePrice}, WooCommerce Price: {$wcPrice}, WooCommerce Purchase Price: {$wcPurchasePrice}", 'product_sync' );                

                $priceChange = false;
                $priceDetails = [];                

                // Compare prices
                if ( ( (float)$xeroPrice !== (float)$wcPrice ) || !$xeroPrice )  {
                    $priceChange = true;
                    $salesDetails = $item->getSalesDetails;                 
                    $priceDetails['SalesDetails'] = [
                            'UnitPrice' => (float)$wcPrice,
                            'AccountCode' => $salesDetails->AccountCode,
                            'TaxType' => $salesDetails->TaxType
                    ];
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Sell Price differs. wc: {$wcPrice} Xero: {$xeroPrice}. Dryrun is {$dry_run}</p></div>";                    
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Sell Price is the same. Dry is {$dry_run}</p></div>";
                }

                // Compare Purchase prices
                if ( ( (float)$xeroPurchasePrice !== (float)$wcPurchasePrice ) || !$xeroPurchasePrice )  {
                    $priceChange = true;
                    $purchaseDetails = $item->getPurchaseDetails; 
                    $priceDetails['PurchaseDetails']  = [
                            'UnitPrice' => (float)$wcPurchasePrice,
                            'AccountCode' => $purchaseDetails->AccountCode,
                            'TaxType' => $purchaseDetails->TaxType
                    ];
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Purchase price differs. wc: {$wcPurchasePrice} Xero: {$xeroPurchasePrice}. Dryrun is {$dry_run}</p></div>";                    
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Purchase Price is the same. Dryrun is {$dry_run}</p></p></div>";
                }

                // Write to CSV only after both checks are done
                if ( $priceChange ) {
                    ACLXeroHelper::csv_file( $pricechange_csv, "{$sku},{$xeroPurchasePrice},{$xeroPrice},{$wcPurchasePrice},{$wcPrice}" );
                    return [
                        'Code' => $sku,
                        'SalesDetails' => $priceDetails['SalesDetails'] ?? null,
                        'PurchaseDetails' => $priceDetails['PurchaseDetails'] ?? null
                    ];
                } else {
                    ACLXeroHelper::csv_file( $nopricechange_csv, "{$sku},{$xeroPurchasePrice},{$xeroPrice},{$wcPurchasePrice},{$wcPrice}" );
                }                
                


            } else {
                echo "<div class='notice notice-info'><p>Product [SKU: {$product['sku']}] does not exist in Xero. Creating now. WCPurchasePrice: {$wcPurchasePrice}, WCSellPrice: {$wcPrice}, COGS acct: {$cogs}, COGS Tax Type: {$cogstaxtype}, Sales acct: {$salesacct}, Sales Tax Type: {$salestaxtype}</p></div>";                
                ACLXeroLogger::log_message( "Product SKU <strong>{$sku}</strong> does not exist in Xero. Creating now. WCPurchasePrice: {$wcPurchasePrice}, WCSellPrice: {$wcPrice}, COGS acct: {$cogs}, COGS Tax Type: {$cogstaxtype}, Sales acct: {$salesacct}, Sales Tax Type: {$salestaxtype}", 'product_sync' );
                ACLXeroHelper::csv_file( $newproducts_csv, "{$sku},{$xeroPurchasePrice},{$xeroPrice},{$wcPurchasePrice},{$wcPrice},{$cogs},{$salesacct},{$cogstaxtype},{$salestaxtype}" );

                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true ); 
                $wcPurchasePrice = get_post_meta( $product['id'], 'acl_wc_cost_price', true );
                $cogsaccount =                 
    
                // Create a new item for batch update
                $newItem = [
                    'Code' => $sku,
                    'Name' => $product['name'],
                    'Description' => $product['description'],
                    'SalesDetails' => [
                        'UnitPrice' => (float)$wcPurchasePrice,
                        'AccountCode' => $cogs, 
                        'TaxType' => $cogstaxtype, 
                    ],
                    'PurchaseDetails' => [
                        'UnitPrice' => (float)$product['price'], // Assuming the purchase price is the same as sales price for simplicity
                        'AccountCode' => $salesacct, // Replace with your actual purchase account code
                        'TaxType' => $salestaxtype, // Replace with your actual tax type code
                    ]
                ];
    
                // Return the new item for batch update
                return $newItem;                
            }
            return null;
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
            throw $e; // Re-throw so it's caught in sync_products
        }
    }

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
    
            ACLXeroLogger::log_message(" SKU: |" . $sku ."|", 'product_sync');
            
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
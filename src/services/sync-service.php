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
    public static function sync_products() {
        try {
            // Step 1: Fetch WooCommerce Products
            $products = self::get_wc_products();
            if ( empty( $products ) ) {
                ACLXeroLogger::log_message('No products found in WooCommerce.', 'product_sync');
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";                
                return;
            }

            echo "<p>Syncing " . count($products) . " products...</p>";
            ACLXeroLogger::log_message(count($products) . ' products fetched from WooCommerce.', 'product_sync');

            // Step 2: Initialize Xero Client
            $client_id = get_option( 'acl_xero_consumer_key' );
            $client_secret = get_option( 'acl_xero_consumer_secret' );

            if ( empty( $client_id ) || empty( $client_secret ) ) {
                throw new \Exception( 'Missing Xero Consumer Key or Secret. Please update the settings.' );
            }

            $xero = ACLXeroHelper::initialize_xero_client();
            // Check for errors
            if (is_wp_error($xero)) {
                echo "<div class='notice notice-error'><p>".$xero->get_error_message()."</p></div>"; // Display the error message
                wp_die(); // Stop further execution
            }  
            
            if (!empty($xero)) {
                echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: ".get_option('xero_tenant_id')."</p></div>"; // Echo the captured output
                echo "<div class='notice notice-info'><p>Now syncing products</p></div>"; // Echo the captured output
            }            

            // Step 3: Process Each Product
            $count = 0;
            foreach ( $products as $product ) {
                try {
                    self::process_product( $xero, $product );
                    $count++;                    
                } catch (\Exception $e) {
                    $sku = $product['sku'] ?? 'No SKU';
                    ACLXeroLogger::log_message( "Error processing product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
                    echo "<div class='notice notice-error'><p>Error processing product SKU: <strong>{$sku}</strong> - {$e->getMessage()}</p></div>";
                }
            }

            // (a) Echo the number of successfully synced products
            echo "<div class='notice notice-success'><p>{$count} Products Successfully Sync'd</p></div>"; 

            // (b) Display list of CSV files in specified directory
            $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
            if (is_dir($folder_path)) {
                $files = glob($folder_path . '/*.csv');
                
                // Sort files in reverse chronological order
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });

                echo "<h3>CSV Files:</h3>";
                echo "<ul>";
                foreach ($files as $file) {
                    $filename = basename($file);
                    echo "<li><input type='checkbox' name='delete_files[]' value='" . esc_attr($filename) . "'> {$filename} ";
                    echo "<a href='" . wp_nonce_url(admin_url('admin-ajax.php?action=acl_download_csv&file=' . urlencode($filename)), 'download_csv') . "' class='button'>Download</a>";
                    echo "<button class='button delete-file' data-file='" . esc_attr($filename) . "'>Delete</button></li>";
                }
                echo "</ul>";
                echo "<button id='delete-selected' class='button'>Delete Selected</button>";
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Single file deletion
                    $('.delete-file').on('click', function(e) {
                        e.preventDefault();
                        var filename = $(this).data('file');
                        if (confirm('Are you sure you want to delete ' + filename + '?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv',
                                    file: filename,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('File deleted successfully!');
                                        $(e.target).closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });

                    // Multiple file deletion
                    $('#delete-selected').on('click', function(e) {
                        e.preventDefault();
                        var selectedFiles = $('input[name="delete_files[]"]:checked').map(function() {
                            return $(this).val();
                        }).get();
                        
                        if (selectedFiles.length === 0) {
                            alert('Please select at least one file to delete.');
                            return;
                        }

                        if (confirm('Are you sure you want to delete these ' + selectedFiles.length + ' files?')) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'acl_delete_csv_multiple',
                                    files: selectedFiles,
                                    _ajax_nonce: '<?php echo wp_create_nonce('delete_csv_multiple'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('Selected files deleted successfully!');
                                        // Remove all checked items
                                        $('input[name="delete_files[]"]:checked').closest('li').remove();
                                    } else {
                                        alert('Error: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        }
                    });
                });
                </script>
                <?php                
            } else {
                echo "<div class='notice notice-warning'><p>The 'acl-wc-xero-sync' folder does not exist.</p></div>";
            }           
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( 'Fatal error in sync process: ' . $e->getMessage(), 'product_sync' );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";            
        }
    }      

    /**
     * Processes a single product, checking its existence in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param array $product
     */
    private static function process_product( $xero, $product ) {
        if ( empty( $product['sku'] ) ) {
            ACLXeroLogger::log_message("Product [ID: {$product['id']}] - $sku skipped: Missing SKU.", 'product_sync');
            echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] - {$sku} skipped: Missing SKU.</p></div>";
            return;
        }

        $sku = $product['sku'];

        try {
         
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            /* Set up the csv files to export the results. */

            $nopricechange_csv = "nopricechange"; // Your base filename without extension
            $pricechange_csv = "pricechange";
            $date = current_time( "Y-m-d-H-i-s" ); // Format: Year-Month-Day-hour-minutes-seconds, adjust as needed
            $nopricechange_csv = $nopricechange_csv . "_" . $date . ".csv"; // Assuming CSV file
            $pricechange_csv = $pricechange_csv . "_" . $date . ".csv"; // Assuming CSV file            

            if ( $exists ) {

                // Fetch item details from Xero
                $item = self::get_xero_item( $xero, $sku );
                
                // Assuming 'UnitPrice' is the field for sale price in Xero
                $xeroPrice = $item->getSalesDetails()->getUnitPrice();
                
                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true );  

                // Compare prices
                if ((float)$xeroPrice !== (float)$wcPrice) {
                    echo "<div class='notice notice-info'><p>Product [ID: ".$product['id']."] - ".$sku." already in Xero. Price differs. Xero Price: $".$xeroPrice.". WooCommerce Price: $".$wcPrice." </p></div>";
                    ACLXeroHelper::csv_file( $pricechange_csv, $sku.','.$xeroPrice.','.$wcPrice );
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Price is the same.</p></div>";
                    ACLXeroHelper::csv_file( $nopricechange_csv, $sku.','.$xeroPrice.','.$wcPrice );
                }
                ACLXeroLogger::log_message ( "Product SKU <strong>".$sku."</strong> exists in Xero. Xero Price: $".$xeroPrice.", WooCommerce Price: $".$wcPrice, 'product_sync' );
            } else {
                echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] does not exist in Xero.</p></div>";                
                ACLXeroLogger::log_message( "Product SKU <strong>{$sku}</strong> does not exist in Xero.", 'product_sync' );
            }
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
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

    
}

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

            return $existing_items->count() > 0;

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
     * Syncs WooCommerce order as an invoice to Xero
     */
    public static function sync_order_to_xero_invoice( $order_id ) {
        try {
            // Get the WooCommerce order
            $order = wc_get_order( $order_id );
            if ( !$order ) {
                ACLXeroLogger::log_message( "Order ID {$order_id} not found.", 'invoice_sync' );
                return false;
            }

            // Initialize Xero client
            $xero = ACLXeroHelper::initialize_xero_client();
            if ( is_wp_error( $xero ) ) {
                ACLXeroLogger::log_message( "Xero client initialization failed for order {$order_id}: " . $xero->get_error_message(), 'invoice_sync' );
                return false;
            }

            // Check if invoice already exists in Xero (using order ID as reference)
            $existing_invoice = ACLXeroHelper::check_existing_xero_invoice( $xero, $order_id );
            if ( $existing_invoice ) {
                ACLXeroLogger::log_message( "Invoice for order {$order_id} already exists in Xero.", 'invoice_sync' );
                return true;
            } else {
                ACLXeroLogger::log_message( "Invoice for order {$order_id} does not exist in Xero.", 'invoice_sync' );
            }

            // Prepare invoice data
            $invoice = new \XeroPHP\Models\Accounting\Invoice( $xero );
            
            // Set invoice type (ACCREC for sales invoice)
            $invoice->setType( \XeroPHP\Models\Accounting\Invoice::INVOICE_TYPE_ACCREC );
            
            // Set reference to WooCommerce order ID
            $invoice->setReference( "WC Order #{$order_id}" );
            
            // Set invoice number (optional - Xero can auto-generate if not set)
            $invoice->setInvoiceNumber( "WC-{$order_id}" );
            
            // Set dates
            $invoice->setDate( new \DateTime( $order->get_date_created() ) );
            $invoice->setDueDate( new \DateTime( $order->get_date_created() ) ); // Adjust due date as needed
            
            // Determine invoice status based on payment status
            $payment_status = $order->is_paid() ? 
                \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_AUTHORISED : 
                \XeroPHP\Models\Accounting\Invoice::INVOICE_STATUS_DRAFT;
            $invoice->setStatus( $payment_status );

            // Get or create contact in Xero
            $contact = self::get_or_create_xero_contact( $xero, $order );
            $invoice->setContact( $contact );

            // Add line items
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $line_item = new \XeroPHP\Models\Accounting\LineItem( $xero );
                
                $line_item->setDescription( $item->get_name() );
                $line_item->setQuantity( $item->get_quantity() );
                $line_item->setUnitAmount( $item->get_subtotal() / $item->get_quantity() );
                $line_item->setLineAmount( $item->get_subtotal() );
                
                if ( $product && $product->get_sku() ) {
                    $line_item->setItemCode( $product->get_sku() );
                }
                
                // Add tax (you might need to adjust this based on your tax setup)
                $tax_amount = $item->get_subtotal_tax();
                if ( $tax_amount > 0 ) {
                    $line_item->setTaxType( 'OUTPUT' ); // Adjust tax type as needed
                }
                
                $invoice->addLineItem( $line_item );
            }

            // Add shipping as a line item if applicable
            if ( $order->get_shipping_total() > 0 ) {
                $shipping_item = new \XeroPHP\Models\Accounting\LineItem( $xero );
                $shipping_item->setDescription( 'Shipping' );
                $shipping_item->setQuantity( 1 );
                $shipping_item->setUnitAmount( $order->get_shipping_total() );
                $shipping_item->setLineAmount( $order->get_shipping_total() );
                $invoice->addLineItem( $shipping_item );
            }

            // Save the invoice to Xero
            $invoice->save();

            // Store the Xero Invoice ID in WooCommerce metadata
            $invoice_id = $invoice->getInvoiceID();
            update_post_meta( $order_id, '_xero_invoice_id', $invoice_id );

            // If paid, add payment
            if ( $order->is_paid() ) {
                $payment = new \XeroPHP\Models\Accounting\Payment( $xero );
                $payment->setInvoice( $invoice );
                $payment->setAccount( $xero->load('Accounting\\Account')->where('Code', '200')->first() ); // Adjust account code as needed
                $payment->setDate( new \DateTime( $order->get_date_paid() ) );
                $payment->setAmount( $order->get_total() );
                $payment->save();
            }

            ACLXeroLogger::log_message( "Successfully synced order {$order_id} as invoice {$invoice_id} to Xero. Status: {$payment_status}", 'invoice_sync' );
            return true;

        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error syncing order {$order_id} to Xero: {$e->getMessage()}", 'invoice_sync' );
            return false;
        }
    }



    /**
     * Get or create a Xero contact based on order data
     */
    public static function get_or_create_xero_contact( $xero, $order = null ) {
        try {

            if ( $order ) {
                $email = $order->get_billing_email();
            }
           
            // Try to find existing contact by email
            $accessToken = get_option( 'xero_access_token' );
            $tenantId = get_option( 'xero_tenant_id' );

            $headers = array(
                'Authorization: Bearer ' . $accessToken,
                'Xero-tenant-id: ' . $tenantId,
                'Content-Type: application/json'
            ); 

            $url = "https://api.xero.com/api.xro/2.0/Contacts";

            $ch = curl_init();

            ACLXeroLogger::log_message( 'URL: ' . $url , 'invoice_sync' );

            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
            
            $response = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            if ( curl_errno( $ch ) ) {
                $errorMessage = 'Curl error: ' . curl_error( $ch );
                ACLXeroLogger::log_message( $errorMessage, 'invoice_sync' );
                throw new \Exception( $errorMessage );
            } 

            if ( $httpCode !== 200 ) {
                $errorMessage = "Failed to retrieve Contacts. HTTP Status: {$httpCode}. Response: {$response}";
                ACLXeroLogger::log_message( $errorMessage, 'invoice_sync' );
                throw new \Exception( $errorMessage );
            }

            $contacts = json_decode($response, true);

            ACLXeroLogger::log_message("Email: |" . $email . "|", 'invoice_sync' );        
            ACLXeroLogger::log_message("Existing contacts: |" . print_r( $contacts, true ) . "|", 'invoice_sync');

            if ($contacts->count() > 0) {
                return $contacts->first();
            }

            if ( !$order ) { return; }

            // Create new contact
            $contact = new \XeroPHP\Models\Accounting\Contact($xero);
            $contact->setName($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $contact->setFirstName($order->get_billing_first_name());
            $contact->setLastName($order->get_billing_last_name());
            $contact->setEmailAddress($email);
            
            // Add billing address
            $address = new \XeroPHP\Models\Accounting\Address($xero);
            $address->setAddressType(\XeroPHP\Models\Accounting\Address::ADDRESS_TYPE_POBOX);
            $address->setAddressLine1($order->get_billing_address_1());
            $address->setAddressLine2($order->get_billing_address_2());
            $address->setCity($order->get_billing_city());
            $address->setRegion($order->get_billing_state());
            $address->setPostalCode($order->get_billing_postcode());
            $address->setCountry($order->get_billing_country());
            $contact->addAddress($address);

            $contact->save();
            return $contact;

        } catch (\Exception $e) {
            ACLXeroLogger::log_message( "Error handling contact for order {$order->get_id()}: {$e->getMessage()}", 'invoice_sync' );
            //ACLXeroLogger::log_message("Full Exception Details: " . var_export($e, true), 'invoice_sync');         
            throw $e;
        }
    }
    
}
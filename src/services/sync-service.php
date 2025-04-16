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
            if ( $category_id ) {
                $args = array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field'    => 'term_id',
                            'terms'    => $category_id,
                        ),
                    ),
                );
                $total_products = count( get_posts( $args ) );
            } else {
                $total_products = wp_count_posts( 'product' )->publish;
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
    
            // Step 2: Initialize Xero Client (for updates only)
            $xero = ACLXeroHelper::initialize_xero_client();
            if ( is_wp_error( $xero ) ) {
                echo "<div class='notice notice-error'><p>" . $xero->get_error_message() . "</p></div>";
                flush();
                wp_die();
            }
    
            if ( ! empty( $xero ) ) {
                echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: " . get_option( 'xero_tenant_id' ) . "</p></div>";
                echo "<div class='notice notice-info'><p>Now syncing products</p></div>";
                flush();
            }
    
            // Load xero_items.json
            $cache_dir = WP_CONTENT_DIR . '/uploads/xero_cache';
            $cache_file = $cache_dir . '/xero_items.json';
            $xero_items = array();
    
            if ( file_exists( $cache_file ) ) {
                $xero_items = json_decode( file_get_contents( $cache_file ), true );
                ACLXeroLogger::log_message( "Loaded " . count( $xero_items ) . " Xero items from cache.", 'product_sync' );
            } else {
                ACLXeroLogger::log_message( "Cache file not found: {$cache_file}", 'product_sync' );
                echo "<div class='notice notice-error'><p>Cache file not found. Please fetch Xero items first.</p></div>";
                flush();
                wp_die();
            }
    
            // Setup the CSV files
            $timestamp = current_time( "Y-m-d-H-i-s" );
            $dryRunSuffix = $dry_run ? '_dryrun' : '';
            $nopricechange_csv = "nopricechange{$dryRunSuffix}_" . $timestamp . ".csv";
            $pricechange_csv = "pricechange{$dryRunSuffix}_" . $timestamp . ".csv";
            $newproducts_csv = "newproducts{$dryRunSuffix}_" . $timestamp . ".csv";
    
            // Step 3: Process Each Product
            do {
                $products = ACLWCService::get_products( $offset, $batch_size, $category_id );
                $itemsToUpdate = array();
                $batch_count = 0;
    
                foreach ( $products as $product ) {
                    if ( empty( $product['sku'] ) ) {
                        ACLXeroLogger::log_message( "Product [ID: {$product['id']}] skipped: Missing SKU.", 'product_sync' );
                        echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
                        flush();
                        continue;
                    }
    
                    $sku = trim( $product['sku'] );
                    try {
                        $itemDetails = self::process_product( $xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run, $cogs, $salesacct, $cogstaxtype, $salestaxtype, $xero_items );
                        if ( ! empty( $itemDetails ) ) {
                            if ( $dry_run ) {
                                $xeroPurchasePrice = $itemDetails['PurchaseDetails']['UnitPrice'] ?? '';
                                $wcPurchasePrice = get_post_meta( $product['id'], 'acl_wc_cost_price', true ) ?? '';
                                $xeroSellPrice = $itemDetails['SalesDetails']['UnitPrice'] ?? '';
                                $wcSellPrice = get_post_meta( $product['id'], '_price', true ) ?? '';
    
                                ACLXeroLogger::log_message( "Dry Run: Would have updated price for SKU {$sku}. Xero Purchase Price: {$xeroPurchasePrice}, WC Purchase Price: {$wcPurchasePrice}, Xero Sell Price: {$xeroSellPrice}, WC Sell Price: {$wcSellPrice}", 'product_sync' );
                                echo "<div class='notice notice-info'><p>Dry Run: Would have run for SKU <strong>{$sku}</strong> Xero Purchase Price: {$xeroPurchasePrice}, WC Purchase Price: {$wcPurchasePrice}, Xero Sell Price: {$xeroSellPrice}, WC Sell Price: {$wcSellPrice}.</p></div>";
                                flush();
                            } else {
                                $itemsToUpdate[] = $itemDetails;
                            }
                        }
                        $batch_count++;
                        $processed_count++;
                    } catch ( \Exception $e ) {
                        ACLXeroLogger::log_message( "Error processing product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
                        echo "<div class='notice notice-error'><p>Error processing product SKU: <strong>{$sku}</strong> - {$e->getMessage()}</p></div>";
                        flush();
                    }
                }
    
                // Perform batch update
                if ( ! $dry_run && ! empty( $itemsToUpdate ) ) {
                    self::batch_update_xero_items( $itemsToUpdate );
                }
    
                echo "<div class='notice notice-info'><p>Batch processed: {$batch_count} products.</p></div>";
                flush();
    
                // Move to the next batch
                $offset += $batch_size;
            } while ( ! empty( $products ) );
    
            // Clear status after process completes
            delete_transient( 'xero_sync_status' );
    
            // Echo the number of successfully synced products
            echo "<div class='notice notice-success'><p>{$processed_count} Products Processed</p></div>";
            flush();
    
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( 'Fatal error in sync process: ' . $e->getMessage(), 'product_sync' );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";
            flush();
        }
    
        // Log completion
        ACLXeroLogger::log_message( 'Sync process completed.', 'product_sync' );
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
    private static function process_product( $xero, $product, $pricechange_csv, $nopricechange_csv, $dry_run, $cogs = NULL, $salesacct = NULL, $cogstaxtype = NULL, $salestaxtype = NULL, $xero_items = array() ) {
        $sku = trim( $product['sku'] );
        // Truncate SKU to 30 characters for Xero
        $xero_sku = substr( $sku, 0, 30 );

        if ( strlen( $sku ) > 30 ) {
            ACLXeroLogger::log_message( "SKU {$sku} truncated to {$xero_sku} for Xero (max 30 characters).", 'product_sync' );
            echo "<div class='notice notice-warning'><p>SKU <strong>{$sku}</strong> truncated to <strong>{$xero_sku}</strong> for Xero (max 30 characters).</p></div>";
            flush();
        }

        try {
            // Check if SKU exists in Xero cache
            $exists = self::check_if_sku_exists( $xero, $xero_sku, $xero_items );
    
            if ( $exists ) {
                // Fetch item details from cache
                $item = self::get_xero_item( $xero, $xero_sku, $xero_items );
    
                // Get prices from cache
                $xeroPrice = $item['sales_price'];
                $xeroPurchasePrice = $item['purchase_price'];
    
                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true );
                $wcPurchasePrice = get_post_meta( $product['id'], 'acl_wc_cost_price', true );
    
                ACLXeroLogger::log_message( "Product SKU {$sku} exists in Xero. Xero Price: {$xeroPrice}, Xero Purchase Price: {$xeroPurchasePrice}, WooCommerce Price: {$wcPrice}, WooCommerce Purchase Price: {$wcPurchasePrice}", 'product_sync' );
    
                $priceChange = false;
                $priceDetails = array();
    
                // Compare prices
                if ( ( (float)$xeroPrice !== (float)$wcPrice ) || ! $xeroPrice ) {
                    $priceChange = true;
                    $priceDetails['SalesDetails'] = array(
                        'UnitPrice' => (float)$wcPrice,
                        'AccountCode' => $item['sales_account'],
                        'TaxType' => $item['sales_tax']
                    );
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Sell Price differs. wc: {$wcPrice} Xero: {$xeroPrice}. Dryrun is {$dry_run}</p></div>";
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Sell Price is the same. Dryrun is {$dry_run}</p></div>";
                }
    
                // Compare Purchase prices
                if ( ( (float)$xeroPurchasePrice !== (float)$wcPurchasePrice ) || ! $xeroPurchasePrice ) {
                    $priceChange = true;
                    $priceDetails['PurchaseDetails'] = array(
                        'UnitPrice' => (float)$wcPurchasePrice,
                        'AccountCode' => $item['purchase_account'],
                        'TaxType' => $item['purchase_tax']
                    );
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Purchase price differs. wc: {$wcPurchasePrice} Xero: {$xeroPurchasePrice}. Dryrun is {$dry_run}</p></div>";
                } else {
                    echo "<div class='notice notice-info'><p>Product [ID: {$product['id']}] - {$sku} already in Xero. Purchase Price is the same. Dryrun is {$dry_run}</p></div>";
                }
    
                // Write to CSV only after both checks are done
                if ( $priceChange ) {
                    ACLXeroHelper::csv_file( $pricechange_csv, "{$sku},{$xeroPurchasePrice},{$xeroPrice},{$wcPurchasePrice},{$wcPrice}" );
                    return array(
                        'Code' => $xero_sku,
                        'SalesDetails' => $priceDetails['SalesDetails'] ?? null,
                        'PurchaseDetails' => $priceDetails['PurchaseDetails'] ?? null
                    );
                } else {
                    ACLXeroHelper::csv_file( $nopricechange_csv, "{$sku},{$xeroPurchasePrice},{$xeroPrice},{$wcPurchasePrice},{$wcPrice}" );
                }
    
            } else {
                echo "<div class='notice notice-info'><p>Product [SKU: {$sku}] does not exist in Xero. Creating now. WCPurchasePrice: {$wcPurchasePrice}, WCSellPrice: {$wcPrice}, COGS acct: {$cogs}, COGS Tax Type: {$cogstaxtype}, Sales acct: {$salesacct}, Sales Tax Type: {$salestaxtype}</p></div>";
                ACLXeroLogger::log_message( "Product SKU <strong>{$sku}</strong> does not exist in Xero. Creating now. WCPurchasePrice: {$wcPurchasePrice}, WCSellPrice: {$wcPrice}, COGS acct: {$cogs}, COGS Tax Type: {$cogstaxtype}, Sales acct: {$salesacct}, Sales Tax Type: {$salestaxtype}", 'product_sync' );
                ACLXeroHelper::csv_file( $newproducts_csv, "{$sku},,,{$wcPurchasePrice},{$wcPrice},{$cogs},{$salesacct},{$cogstaxtype},{$salestaxtype}" );
    
                // Get WooCommerce price
                $wcPrice = get_post_meta( $product['id'], '_price', true );
                $wcPurchasePrice = get_post_meta( $product['id'], 'acl_wc_cost_price', true );
    
                // Create a new item for batch update
                $newItem = array(
                    'Code' => $xero_sku,
                    'Name' => substr( $product['name'], 0, 50 ),
                    'Description' => isset( $product['short_description'] ) ? mb_substr( strip_tags( $product['short_description'] ), 0, 1000, 'UTF-8' ) : '',
                    'SalesDetails' => array(
                        'UnitPrice' => (float)$wcPrice,
                        'AccountCode' => $salesacct,
                        'TaxType' => $salestaxtype,
                    ),
                    'PurchaseDetails' => array(
                        'UnitPrice' => (float)$wcPurchasePrice,
                        'AccountCode' => $cogs,
                        'TaxType' => $cogstaxtype,
                    )
                );
    
                // Return the new item for batch update
                return $newItem;
            }
            return null;
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking product [SKU: {$sku}]: {$e->getMessage()}", 'product_sync' );
            throw $e;
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
    private static function get_xero_item( $xero, $sku, $xero_items = array() ) {
        try {
            if ( isset( $xero_items[$sku] ) ) {
                return $xero_items[$sku];
            }
            throw new \Exception( "Item with SKU {$sku} not found in cache." );
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error fetching item [SKU: {$sku}] from cache: {$e->getMessage()}", 'product_sync' );
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
    private static function check_if_sku_exists( $xero, $sku, $xero_items = array() ) {
        try {
            ACLXeroLogger::log_message( "Checking SKU: |{$sku}|", 'product_sync' );
            return isset( $xero_items[$sku] );
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking SKU {$sku}: {$e->getMessage()}", 'product_sync' );
            echo "<div class='notice notice-error'><p>Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}</p></div>";
            throw $e;
        }
    } 


    /**
     * Syncs WooCommerce order as an invoice to Xero
     */
    public static function sync_order_to_xero_invoice( $order_id ) {
        try {
            $order = wc_get_order( $order_id );
            if ( !$order ) {
                ACLXeroLogger::log_message( "Order ID {$order_id} not found. ", 'invoice_sync' );
                return false;
            }
    
            $xero = ACLXeroHelper::initialize_xero_client();
            if ( is_wp_error( $xero ) ) {
                ACLXeroLogger::log_message( "Xero client initialization failed for order {$order_id}: " . $xero->get_error_message() . " ", 'invoice_sync' );
                return false;
            }
    
            $existing_invoice = ACLXeroHelper::check_existing_xero_invoice( $xero, $order_id );
            if ( $existing_invoice ) {
                ACLXeroLogger::log_message( "Invoice for order {$order_id} already exists in Xero. ", 'invoice_sync' );
                delete_post_meta( $order_id, '_xero_sync_issue' );
                $invoice_id = get_post_meta( $order_id, '_xero_invoice_id', true );
                ACLXeroLogger::log_message( "Existing invoice ID for order {$order_id}: " . ( $invoice_id ?: 'none' ) . " ", 'invoice_sync' );
                return true;
            } else {
                ACLXeroLogger::log_message( "Invoice for order {$order_id} does not exist in Xero. ", 'invoice_sync' );
            }
    
            $accessToken = get_option( 'xero_access_token' );
            $tenantId = get_option( 'xero_tenant_id' );
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Xero-tenant-id: ' . $tenantId,
                'Content-Type: application/json',
                'Accept: application/json'
            ];
            $url = "https://api.xero.com/api.xro/2.0/Invoices";
    
            $contact = self::get_or_create_xero_contact( $xero, $order );
            $sales_account_code = get_option( 'acl_xero_sales_account', '200' );
            $shipping_account_code = get_option( 'acl_xero_shipping_account', '200' );
            $sales_tax_type = get_option( 'acl_xero_sales_tax_type', 'OUTPUT' );
            $line_items = [];
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $sku = $product ? $product->get_sku() : null;
                $line_item = [
                    'Description' => $item->get_name(),
                    'Quantity' => $item->get_quantity(),
                    'UnitAmount' => $item->get_subtotal() / $item->get_quantity(),
                    'LineAmount' => $item->get_subtotal(),
                    'AccountCode' => $sales_account_code
                ];
                if ( $sku ) {
                    $line_item['ItemCode'] = $sku;
                }
                $tax_amount = $item->get_subtotal_tax();
                if ( $tax_amount > 0 ) {
                    $line_item['TaxType'] = $sales_tax_type;
                    $line_item['TaxAmount'] = $tax_amount;
                }
                $line_items[] = $line_item;
            }
    
            if ( $order->get_shipping_total() > 0 ) {
                $line_items[] = [
                    'Description' => 'Shipping',
                    'Quantity' => 1,
                    'UnitAmount' => $order->get_shipping_total(),
                    'LineAmount' => $order->get_shipping_total(),
                    'AccountCode' => $shipping_account_code
                ];
            }
    
            $payment_status = $order->is_paid() ? 'AUTHORISED' : 'DRAFT';
            $invoice_data = [
                'Type' => 'ACCREC',
                'Contact' => [
                    'ContactID' => $contact->getContactID()
                ],
                'Reference' => "WC Order #{$order_id}",
                'InvoiceNumber' => "WC-{$order_id}",
                'Date' => $order->get_date_created()->format( 'Y-m-d' ),
                'DueDate' => $order->get_date_created()->format( 'Y-m-d' ),
                'Status' => $payment_status,
                'LineItems' => $line_items
            ];
    
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $invoice_data ) );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    
            $response = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    
            if ( curl_errno( $ch ) ) {
                $errorMessage = 'Curl error: ' . curl_error( $ch );
                ACLXeroLogger::log_message( $errorMessage , 'invoice_sync' );
                throw new \Exception( $errorMessage );
            }
    
            if ( $httpCode !== 200 ) {
                $errorMessage = "Failed to sync order {$order_id} to Xero. HTTP Status: {$httpCode}. Response: {$response}";
                ACLXeroLogger::log_message( $errorMessage , 'invoice_sync' );
                throw new \Exception( $errorMessage );
            }
    
            $invoice_response = json_decode( $response, true );
            $invoice_id = $invoice_response['Invoices'][0]['InvoiceID'];
            update_post_meta( $order_id, '_xero_invoice_id', $invoice_id );
            ACLXeroLogger::log_message( "Set _xero_invoice_id to {$invoice_id} for order {$order_id} ", 'invoice_sync' );
            delete_post_meta( $order_id, '_xero_sync_issue' );
            ACLXeroLogger::log_message( "Cleared _xero_sync_issue for order {$order_id} ", 'invoice_sync' );
    
            if ( $order->is_paid() ) {
                $payment_url = "https://api.xero.com/api.xro/2.0/Payments";
                $payment_data = [
                    'Invoice' => ['InvoiceID' => $invoice_id],
                    'Account' => ['Code' => get_option( 'acl_xero_default_bank_account', '200' )],
                    'Date' => $order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d' ) : ( new \DateTime() )->format( 'Y-m-d' ),
                    'Amount' => $order->get_total()
                ];
    
                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, $payment_url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_POST, true );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payment_data ) );
                curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    
                $payment_response = curl_exec( $ch );
                $payment_httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    
                if ( curl_errno( $ch ) || $payment_httpCode !== 200 ) {
                    $errorMessage = "Failed to add payment for order {$order_id}. HTTP Status: {$payment_httpCode}. Response: {$payment_response}";
                    ACLXeroLogger::log_message( $errorMessage , 'invoice_sync' );
                } else {
                    ACLXeroLogger::log_message( "Payment added for order {$order_id}. HTTP Status: {$payment_httpCode} ", 'invoice_sync' );
                }
                curl_close( $ch );
            }
    
            ACLXeroLogger::log_message( "Successfully synced order {$order_id} as invoice {$invoice_id} to Xero. Status: {$payment_status} ", 'invoice_sync' );
            curl_close( $ch );
            return true;
    
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error syncing order {$order_id} to Xero: " . $e->getMessage() . " ", 'invoice_sync' );
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
                $full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    
                // Check for existing contact by email
                $contacts_by_email = $xero->load( 'Accounting\\Contact' )
                    ->where( 'EmailAddress', $email )
                    ->execute();
                ACLXeroLogger::log_message( "Email check: |" . $email . "| , Found: " . $contacts_by_email->count() . " ", 'invoice_sync' );
                $email_contacts_array = [];
                foreach ( $contacts_by_email as $contact ) {
                    $email_contacts_array[] = [
                        'ContactID' => $contact->getContactID(),
                        'Name' => $contact->getName(),
                        'EmailAddress' => $contact->getEmailAddress()
                    ];
                }
                ACLXeroLogger::log_message( "Email contacts raw: " . print_r( $email_contacts_array, true ) . " ", 'invoice_sync' );
    
                if ( $contacts_by_email->count() > 0 ) {
                    $contact = $contacts_by_email->first();
                    ACLXeroLogger::log_message( "Returning existing contact by email: " . $contact->getContactID() . " ", 'invoice_sync' );
                    return $contact;
                }
    
                // Check for existing contact by name
                $contacts_by_name = $xero->load( 'Accounting\\Contact' )
                    ->where( 'Name', $full_name )
                    ->execute();
                ACLXeroLogger::log_message( "Name check: |" . $full_name . "| , Found: " . $contacts_by_name->count() . " ", 'invoice_sync' );
                $name_contacts_array = [];
                foreach ( $contacts_by_name as $contact ) {
                    $name_contacts_array[] = [
                        'ContactID' => $contact->getContactID(),
                        'Name' => $contact->getName(),
                        'EmailAddress' => $contact->getEmailAddress()
                    ];
                }
                ACLXeroLogger::log_message( "Name contacts raw: " . print_r( $name_contacts_array, true ) . " ", 'invoice_sync' );
    
                if ( $contacts_by_name->count() > 0 ) {
                    $existing_contact = $contacts_by_name->first();
                    $existing_email = $existing_contact->getEmailAddress();
                    // Log raw emails for debugging
                    ACLXeroLogger::log_message( "Comparing emails - Existing: |" . $existing_email . "| vs Order: |" . $email . "| ", 'invoice_sync' );
                    // Case-insensitive comparison with trimming
                    if ( trim( strtolower( $existing_email ) ) !== trim( strtolower( $email ) ) ) {
                        $error_message = "Contact name '" . $full_name . "' exists with different email '" . $existing_email . "' (Order email: '" . $email . "')";
                        ACLXeroLogger::log_message( $error_message . ". Skipping sync for order " . $order->get_id() . ". ", 'invoice_sync' );
                        update_post_meta( $order->get_id(), '_xero_sync_issue', $error_message );
                        throw new \Exception( $error_message . ". Invoice not synced." );
                    }
                    // Emails match, use this contact
                    ACLXeroLogger::log_message( "Name '" . $full_name . "' matches existing contact with same email '" . $existing_email . "'. Using it. ", 'invoice_sync' );
                    return $existing_contact;
                }
    
                // Create new contact
                $contact = new \XeroPHP\Models\Accounting\Contact( $xero );
                $contact->setName( $full_name );
                $contact->setFirstName( $order->get_billing_first_name() );
                $contact->setLastName( $order->get_billing_last_name() );
                $contact->setEmailAddress( $email );
    
                $address = new \XeroPHP\Models\Accounting\Address( $xero );
                $address->setAddressType( \XeroPHP\Models\Accounting\Address::ADDRESS_TYPE_POBOX );
                $address->setAddressLine1( $order->get_billing_address_1() );
                $address->setAddressLine2( $order->get_billing_address_2() );
                $address->setCity( $order->get_billing_city() );
                $address->setRegion( $order->get_billing_state() );
                $address->setPostalCode( $order->get_billing_postcode() );
                $address->setCountry( $order->get_billing_country() );
                $contact->addAddress( $address );
    
                $contact->save();
                ACLXeroLogger::log_message( "Created new contact: " . $contact->getContactID() . " ", 'invoice_sync' );
                return $contact;
            } else {
                $contacts = $xero->load( 'Accounting\\Contact' )->execute();
                ACLXeroLogger::log_message( "No order provided. All contacts: |" . print_r( $contacts, true ) . "| ", 'invoice_sync' );
                return $contacts->count() > 0 ? $contacts->first() : null;
            }
        } catch ( \Exception $e ) {
            $order_id = $order ? $order->get_id() : 'N/A';
            ACLXeroLogger::log_message( "Error handling contact for order " . $order_id . ": " . $e->getMessage() . " ", 'invoice_sync' );
            throw $e;
        }
    } 
    
    public static function fetch_xero_items( $xero ) {
        $xero_items = array();
        $accessToken = get_option( 'xero_access_token' );
        $tenantId = get_option( 'xero_tenant_id' );
    
        if ( ! $accessToken || ! $tenantId ) {
            ACLXeroLogger::log_message( "Missing credentials: token=" . ( $accessToken ? 'set' : 'unset' ) . ", tenant=" . ( $tenantId ? 'set' : 'unset' ), 'product_sync' );
            throw new \Exception( "Missing Xero credentials." );
        }
    
        $headers = array(
            'Authorization: Bearer ' . $accessToken,
            'Xero-tenant-id: ' . $tenantId,
            'Accept: application/json',
        );
    
        try {
            $url = "https://api.xero.com/api.xro/2.0/Items";
            ACLXeroLogger::log_message( "Fetching all items: {$url}", 'product_sync' );
    
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
    
            $response = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    
            if ( curl_errno( $ch ) ) {
                $error = curl_error( $ch );
                curl_close( $ch );
                throw new \Exception( "Curl error: {$error}" );
            }
    
            if ( $httpCode !== 200 ) {
                curl_close( $ch );
                throw new \Exception( "Failed to fetch items. Status: {$httpCode}" );
            }
    
            $data = json_decode( $response, true );
            $items = isset( $data['Items'] ) ? $data['Items'] : array();
    
            foreach ( $items as $item ) {
                $xero_items[ $item['Code'] ] = array(
                    'sales_price' => isset( $item['SalesDetails']['UnitPrice'] ) ? $item['SalesDetails']['UnitPrice'] : null,
                    'purchase_price' => isset( $item['PurchaseDetails']['UnitPrice'] ) ? $item['PurchaseDetails']['UnitPrice'] : null,
                    'sales_account' => isset( $item['SalesDetails']['AccountCode'] ) ? $item['SalesDetails']['AccountCode'] : null,
                    'sales_tax' => isset( $item['SalesDetails']['TaxType'] ) ? $item['SalesDetails']['TaxType'] : null,
                    'purchase_account' => isset( $item['PurchaseDetails']['AccountCode'] ) ? $item['PurchaseDetails']['AccountCode'] : null,
                    'purchase_tax' => isset( $item['PurchaseDetails']['TaxType'] ) ? $item['PurchaseDetails']['TaxType'] : null,
                );
            }
    
            curl_close( $ch );
            ACLXeroLogger::log_message( "Fetched " . count( $items ) . " items.", 'product_sync' );
            return $xero_items;
        } catch ( \Exception $e ) {
            if ( isset( $ch ) ) {
                curl_close( $ch );
            }
            throw $e;
        }
    }   
}
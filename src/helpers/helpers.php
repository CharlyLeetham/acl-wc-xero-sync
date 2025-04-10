<?php
namespace ACLWcXeroSync\Helpers;
use ACLWcXeroSync\Services\ACLSyncService;
use ACLWcXeroSync\Admin\ACLProductSyncPage;
use ACLWcXeroSync\Helpers\ACLXeroLoggers;

class ACLXeroHelper {

    /**
     * Initializes the Xero client using Consumer Key and Secret.
     *
     * @param string $client_id The Consumer Key.
     * @param string $client_secret The Consumer Secret.
     * @return \XeroPHP\Application
     */    

    public static function initialize_xero_client() {
        ACLXeroLogger::log_message( 'Initializing Xero client.', 'xero_auth' );
    
        try {
            // Retrieve stored credentials
            $accessToken = get_option( 'xero_access_token' );
            $refreshToken = get_option( 'xero_refresh_token' );
            $tenantId = get_option( 'xero_tenant_id' );
            $tokenExpires = get_option( 'xero_token_expires', 0 );
    
            // Validate credentials
            if (!$accessToken || !$refreshToken || !$tenantId) {
                throw new \Exception( "Missing Xero credentials. Please reauthorize." );
            }
    
            // Refresh token if expired
            if (time() > $tokenExpires) {
                ACLXeroLogger::log_message( 'Access token expired. Attempting to refresh...', 'xero_auth' );
                //$accessToken = self::refresh_access_token($refreshToken);
                $clientId = get_option( 'acl_xero_consumer_key' );
                $clientSecret = get_option( 'acl_xero_consumer_secret' );
    
                if (!$clientId || !$clientSecret) {
                    throw new \Exception( "Xero Client ID or Secret is missing. Please configure your settings." );
                }
    
                $provider = new \Calcinai\OAuth2\Client\Provider\Xero([
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                ]);
    
                try {
                    $newAccessToken = $provider->getAccessToken('refresh_token', [
                        'refresh_token' => $refreshToken,
                    ]);
    
                    // Update stored credentials
                    $accessToken = $newAccessToken->getToken();
                    update_option( 'xero_access_token', $accessToken );
                    update_option( 'xero_refresh_token', $newAccessToken->getRefreshToken() );
                    update_option( 'xero_token_expires', time() + $newAccessToken->getExpires() );
    
                    ACLXeroLogger::log_message( 'Tokens refreshed successfully.', 'xero_auth' );
                } catch (\Exception $e) {
                    ACLXeroLogger::log_message( 'Token refresh failed: ' . $e->getMessage(), 'xero_auth' );
                    throw new \Exception( "Failed to refresh the Xero access token. Please reauthorize." );
                }                
            }

            $config = [
                'base_url' => 'https://api.xero.com/api.xro/2.0', // No trailing slash after .com
            ];
    
            // Initialize the Xero client
            $xero = new \XeroPHP\Application( $accessToken, $tenantId, $config );
    
            // Test client connection
            try {
                $xero->load('Accounting\\Organisation')->execute();
            } catch (\XeroPHP\Remote\Exception $e) {
                // Handle 401 Unauthorized error gracefully
                if (strpos($e->getMessage(), '401 Unauthorized') !== false) {
                    ACLXeroLogger::log_message( "Unauthorized access detected: " . $e->getMessage(), 'xero_auth' );
                    throw new \Exception( "Access token is invalid. Please reauthorize the Xero connection." );
                }
    
                // Re-throw other exceptions
                throw new \Exception( "Failed to verify Xero connection: " . $e->getMessage() );
            }
    
            ACLXeroLogger::log_message( "Xero client initialized successfully with Tenant ID: $tenantId", 'xero_auth' );
            return $xero;
    
        } catch (\Exception $e) {
            ACLXeroLogger::log_message( "Error initializing Xero client: " . $e->getMessage(), 'xero_auth' );
            return new \WP_Error( 'initialization_error', 'Error initializing Xero client: ' . $e->getMessage() );                      
        }
    }

    /* CSV files for Product Sync */
    
    public static function csv_file( $filename, $message, $context = 'product_sync' ) {

        /* Check to see if folder for csv's exist. If not create it */

        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $folder_path = $upload_dir . $folder_name;
        
        if ( !is_dir( $folder_path ) ) {
            if ( mkdir( $folder_path, 0755, true ) ) {
                ACLXeroLogger::log_message( "Create directory $folder_path", 'product_sync' );
            } else {
                // Handle the error, e.g., log it
                ACLXeroLogger::log_message( "Failed to create directory $folder_path", 'product_sync' );
            }
        } 

        $csv_file = $folder_path .'/'. $filename;
        // Check if the file exists before opening it
        $file_exists = file_exists( $csv_file );

        // File locking
        $fp = fopen( $csv_file, 'a' ); // Open file in append mode
        if ($fp === false) {
            ACLXeroLogger::log_message( "Failed to open $csv_file for appending", 'product_sync' );
            return;
        }

        if ( flock( $fp, LOCK_EX ) ) { // Attempt to acquire an exclusive lock
            if ( !$file_exists ) {
                // Write the header
                if ( $context === 'product_sync' ) {
                    fwrite( $fp, "SKU,Xero Purchase Price,Xero Price,WC Purchase Price,WC Price,COGS Acct,Sales Acct,COGS Tax,Sales Tax\n" );
                } elseif ( $context === 'invoice_sync_test' ) {
                    fwrite( $fp, "Order ID,Status,Payment Status,Total,Xero Invoice ID,Action\n" );
                }
                ACLXeroLogger::log_message( "Created $csv_file", 'product_sync' );
            }
            // Write the message
            fwrite( $fp, $message . "\n" );
            ACLXeroLogger::log_message( "Wrote line.", 'product_sync' );            
            flock( $fp, LOCK_UN ); // Release the lock
        } else {
            ACLXeroLogger::log_message( "Failed to acquire lock for $csv_file", 'product_sync' );
        }
        fclose( $fp );
    }  

    public static function update_csv_display() {
        check_ajax_referer('update_csv_display', 'nonce');
    
        $filetype = 'csv';
        
        // Start output buffering
        ob_start();
        // Call display_files, which will echo directly into the buffer
        $default_file = self::display_files($filetype);
        $html = ob_get_clean(); // Capture the buffered output
    
        if ($html) {
            wp_send_json_success([
                'html' => $html,
                'defaultLog' => $default_file
            ]);
        } else {
            wp_send_json_error('Failed to fetch CSV files.');
        }
    }


    /* Handle file download functions */

    public static function handle_file_download() {

        ACLXeroLogger::log_message("Handle file download", 'product_sync');

        if (!check_ajax_referer('download_file', false, false)) {
            ACLXeroLogger::log_message("Download Nonce failed", 'product_sync');
            wp_send_json_error(array('message' => 'Nonce verification failed. Please try again or refresh the page.'));
            exit;
        }
        
        $file = $_GET['file'];
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $file_path = $folder_path . '/' . $file;
    
        if (file_exists($file_path)) {
            $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $content_type = ($file_extension === 'csv') ? 'text/csv' : 'text/plain'; // Set content type based on file extension
    
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
        } else {
            wp_die('File not found.');
        }
        exit;
    } 
    
    /* Delete CSV Function */

    public static function handle_delete_csv() {
        check_ajax_referer('delete_csv');

        $file = sanitize_file_name($_POST['file']);
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $file_path = $folder_path . '/' . $file;

        if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) ) {
            $content_type = ($file_extension === 'csv') ? 'text/csv' : 'text/plain'; // Set content type based on file extension
            if (unlink($file_path)) {
                wp_send_json_success('File deleted successfully.');
            } else {
                wp_send_json_error('Failed to delete the file.');
            }
        } else {
            wp_send_json_error('File not found or not a CSV.');
        }
        wp_die();
    }
    
    public static function handle_delete_csv_multiple() {
        check_ajax_referer('delete_csv_multiple');

        $files = $_POST['files'] ?? [];
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $deleted_files = [];
        $error_files = [];

        foreach ($files as $file) {
            $file = sanitize_file_name($file);
            $file_path = $folder_path . '/' . $file;
            
            if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'csv') {
                if (unlink($file_path)) {
                    $deleted_files[] = $file;
                } else {
                    $error_files[] = $file;
                }
            } else {
                $error_files[] = $file; // File doesn't exist or not a CSV
            }
        }

        if (empty($error_files)) {
            wp_send_json_success('Files deleted successfully: ' . implode(', ', $deleted_files));
        } else {
            $error_message = 'Some files could not be deleted: ' . implode(', ', $error_files);
            if (!empty($deleted_files)) {
                $error_message .= '. Successfully deleted: ' . implode(', ', $deleted_files);
            }
            wp_send_json_error($error_message);
        }

        wp_die();
    } 
    
    
    // Display the log files

    public static function display_files( $filetype, $filter_string = '' ) {  
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        ACLXeroLogger::log_message( "filepath:".$folder_path, 'xero_logging' );
        ACLXeroLogger::log_message( "filetype:".$filetype, 'xero_logging' );

        if (is_dir($folder_path)) {

            $all_files = glob( $folder_path . '/*.' . $filetype );
            
            $files = [];
    
            if ( $filter_string ) {
                foreach ( $all_files as $file ) {
                    $filename = basename( $file );
                    if ( strpos( $filename, $filter_string ) !== false ) {
                        $files[] = $file;
                    }
                }
            } else {
                $files = $all_files;
            }           
        
            ob_start();
            print_r( $files );
            $files_string = ob_get_clean();            
            
            ACLXeroLogger::log_message( "files:".$files_string, 'xero_logging' );

            if ( $files !== false && ! empty( $files ) ) {
                usort( $files, function( $a, $b ) {
                    return filemtime( $b ) - filemtime( $a );
                });
            }
                        
            
            if (empty($files)) {
                ACLXeroLogger::log_message( "When do we get here?", 'xero_logging' );
                echo "<p>There are no log files to display</p>";
            } else {
                echo "<ul>";
                echo "<li><input type='checkbox' id='select-all' name='select-all' value='all'> <label for='select-all'>Select All</label></li>";
                
                foreach ($files as $file) {
                    $filename = basename($file);
                    echo "<li><input type='checkbox' name='delete_files[]' value='" . esc_attr($filename) . "'> {$filename}";
                    echo "<button class='button acl-display-file' data-file='" . esc_attr($filename) . "'>Display</button>";
                    echo "<button class='button acl-download-file' data-file='" . esc_attr($filename) . "'>Download</button>";
                    echo "<button class='button acl-delete-file' data-file='" . esc_attr($filename) . "'>Delete</button></li>";
                }
                echo "</ul>";

                echo "<button id='delete-selected' class='button'>Delete Selected</button>";
                
                echo '<div id="error-container" style="display: none;"></div>';
                echo '<div id="log-display-area"><h2>Content: <span id="current-filename"></span></h2><pre id="log-content" style="height: 400px; overflow-y: scroll; border: 1px solid #ccc; padding: 10px;"></pre></div>';

                // Set this variable for use outside this function's scope
                $default_file = basename($files[0]);
                ACLXeroLogger::log_message( "Default File {$default_file}", 'xero_logging' );
 
                // You can either echo this directly or return it for use elsewhere
                return $default_file;
                
            }
        } else {
            echo "<div class='notice notice-warning'><p>The 'acl-wc-xero-sync' folder does not exist.</p></div>";
        }
    }

    //Display the contents of the log file
    
    public static function get_log_content() {
        check_ajax_referer('get_log_content', '_ajax_nonce');
        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $filename = sanitize_file_name($_POST['file']);
        $log_file = $upload_dir . $folder_name . '/' . $filename;
    
        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            // Limit the log to the last 1000 lines to prevent memory issues with large logs
            $lines = explode("\n", $content);
            $limited_lines = array_slice($lines, -1000); // Get last 1000 lines
    
            // Check if the file is a .log file
            if (strtolower(substr($filename, -4)) === '.log') {
                $processed_content = implode("\n", array_reverse($limited_lines));
            } else {
                $processed_content = implode("\n", $limited_lines);
            }
            
            wp_send_json_success($processed_content);
        } else {
            wp_send_json_error('Log file not found.');
        }
    }
        


    /**
     * Handles the AJAX request for testing the Xero connection.
     */
    public static function handle_test_connection() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
    
        //ob_start();
        $xero = ACLXeroHelper::initialize_xero_client();

        // Check for errors
        if (is_wp_error($xero)) {
            echo "<div class='notice notice-error'><p>".$xero->get_error_message()."</p></div>"; // Display the error message
            wp_die(); // Stop further execution
        }
        
        if (!empty($xero)) {
            echo "<div class='notice notice-info'><p>Xero client initialized successfully with Tenant ID: ".get_option('xero_tenant_id')."</p></div>"; // Echo the captured output
        } else {
            echo "No output from sync process.";
        }
        wp_die(); // This is required to end the AJAX call properly
    }    

    public static function handle_sync_ajax() {
        ACLXeroLogger::log_message( "Entering sync ajax", 'product_sync' );
        check_ajax_referer('xero_sync_products_ajax', 'nonce');
        
        $dry_run = isset($_POST['dry_run']) ? ($_POST['dry_run'] == '1') : false;
        $category_id = isset($_POST['category_id']) ? $_POST['category_id'] : null;
        $cogs = isset($_POST['cogs']) ? $_POST['cogs'] : null;
        $salesacct = isset($_POST['salesacct']) ? $_POST['salesacct'] : null;
        $cogstaxtype = isset($_POST['cogstaxtype']) ? $_POST['cogstaxtype'] : null;
        $salestaxtype = isset($_POST['salestaxtype']) ? $_POST['salestaxtype'] : null;                
       
        // Check if the user has permission to perform this action
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        // Disable output buffering
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Set headers for streaming
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');

        try {
            ACLSyncService::sync_products($dry_run, $category_id, $cogs, $salesacct, $cogstaxtype, $salestaxtype );
        } catch (\Exception $e) {
            echo "<div class='notice notice-error'><p>Error in Sync Process: " . htmlspecialchars($e->getMessage()) . "</p></div>";
            flush();
        }    
 
        
        // Final echo if needed
        echo "<div class='notice notice-success'><p>Sync Process Completed</p></div>";
        flush();
        wp_die(); // This is required to end the AJAX call properly
    }

    public static function handle_sync_status() {
        check_ajax_referer('xero_sync_status_ajax', '_ajax_nonce');
        
        // Store process status in a transient or similar temporary storage
        $status = get_transient( 'xero_sync_status' );
        
        if ($status) {
            wp_send_json_success( $status );
        } else {
            wp_send_json_success( array( 'status' => 'Idle' ) );
        }
    } 
    
    /**
     * Sends a message to the client for immediate display.
     *
     * @param string $message The message to send.
     */
    public static function send_message( $message ) {
        // Send the message to the client
        echo $message;
        if (ob_get_level() > 0) ob_flush(); // Flush the output buffer if it's active
        flush(); // Send output to browser
    }    

    public static function GetXeroAccounts( $xero ) {
        try {

            $accounts = $xero->load('Accounting\\Account')->execute();
            $result = [];
            foreach ($accounts as $account) {
                // Assuming you want to filter by type, you can do this here or return all and filter later
                $result[] = [
                    'Code' => $account->getCode(),
                    'Name' => $account->getName(),
                    'Type' => $account->getType()
                ];
            }
            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function GetXeroTaxTypes( $xero ) {
    
        try {
            $taxRates = $xero->load('Accounting\\TaxRate')->execute();
            $result = [];
            foreach ($taxRates as $taxRate) {
                $result[] = [
                    'TaxType' => $taxRate->getTaxType(), // Assuming getName returns the tax type name
                    'Name' => $taxRate->getName(), // Display name for dropdown
                    'Revenue' => $taxRate->getCanApplyToRevenue(), // Revenue Taxes
                    'Expenses' => $taxRate->getCanApplyToExpenses(), // Expenses Taxes
                ];
            }
            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function test_invoice_sync( $dry_run = false, $specific_order_ids = null ) {
        try {
            $xero = ACLXeroHelper::initialize_xero_client( );
            if ( is_wp_error( $xero ) ) {
                echo "<div class='notice notice-error'><p>Xero client initialization failed: " . $xero->get_error_message( ) . "</p></div>";
                ACLXeroLogger::log_message( "Xero client initialization failed: " . $xero->get_error_message( ), 'invoice_sync_test' );
                return;
            }
    
            // Get orders to process
            $args = array(
                'status' => array( 'completed', 'processing', 'pending' ),
                'limit' => -1,
                'return' => 'ids',
            );
            
            if ( $specific_order_ids !== null ) {
                $args['post__in'] = $specific_order_ids;
            }
            
            $order_ids = wc_get_orders( $args );
            $total_orders = count( $order_ids );
    
            if ( empty( $order_ids ) ) {
                echo "<div class='notice notice-warning'><p>No orders found to sync.</p></div>";
                ACLXeroLogger::log_message( "No orders found to sync.", 'invoice_sync_test' );
                return;
            }
    
            echo "<div class='notice notice-info'><p>Processing {$total_orders} orders.</p></div>";
            ACLXeroLogger::log_message( "Processing {$total_orders} orders.", 'invoice_sync_test' );
    
            $timestamp = current_time( "Y-m-d-H-i-s" );
            $dry_run_suffix = $dry_run ? '_dryrun' : '';
            $csv_filename = "invoice_sync_test{$dry_run_suffix}_{$timestamp}.csv";
   
            $synced_count = 0;
            $to_sync_count = 0;
    
            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                $existing_invoice = self::check_existing_xero_invoice( $xero, $order_id );
                
                $payment_status = $order->is_paid( ) ? 'Paid' : 'Unpaid';
                $order_total = $order->get_total( );
    
                if ( $existing_invoice ) {
                    $synced_count++;
                    $invoice_id = $existing_invoice->getInvoiceID( );
                    ACLXeroHelper::csv_file( $csv_filename, "{$order_id},{$order->get_status( )},{$payment_status},{$order_total},{$invoice_id},Already Synced", 'invoice_sync_test' );
                    echo "<div class='notice notice-success'><p>Order #{$order_id} - Already synced (Invoice ID: {$invoice_id})</p></div>";
                } else {
                    $to_sync_count++;
                    ACLXeroHelper::csv_file( $csv_filename, "{$order_id},{$order->get_status( )},{$payment_status},{$order_total},N/A," . ( $dry_run ? 'Dry Run' : 'Synced' ), 'invoice_sync_test' );
                    
                    if ( $dry_run ) {
                        echo "<div class='notice notice-info'><p>Order #{$order_id} - Would be synced (Dry Run)</p></div>";
                    } else {
                        $result = ACLSyncService::sync_order_to_xero_invoice( $order_id );
                        echo "<div class='notice notice-" . ( $result ? 'success' : 'error' ) . "'><p>Order #{$order_id} - " . ( $result ? 'Successfully synced' : 'Failed to sync' ) . "</p></div>";
                    }
                }
            }
    
            $summary = sprintf(
                "Test Results:\nTotal Orders: %d\nAlready Synced: %d\nProcessed: %d\nMode: %s",
                $total_orders,
                $synced_count,
                $to_sync_count,
                $dry_run ? 'Dry Run' : 'Live Sync'
            );
            
            echo "<div class='notice notice-info'><p>{$summary}</p></div>";
            echo "<div class='notice notice-info'><p>Results saved to CSV: {$csv_filename}</p></div>";
            ACLXeroLogger::log_message( $summary, 'invoice_sync_test' );
    
        } catch ( \Exception $e ) {
            echo "<div class='notice notice-error'><p>Error during test sync: {$e->getMessage( )}</p></div>";
            ACLXeroLogger::log_message( "Error during test sync: {$e->getMessage( )}", 'invoice_sync_test' );
        }
    }

    /**
     * Check if an invoice already exists in Xero for this order
     */
    public static function check_existing_xero_invoice( $xero, $order_id ) {
        try {
            $invoices = $xero->load('Accounting\\Invoice')
                ->where( 'Reference', "WC Order #{$order_id}" )
                ->execute();
            
            return $invoices->count() > 0 ? $invoices->first() : null;
        } catch ( \Exception $e ) {
            ACLXeroLogger::log_message( "Error checking existing invoice for order {$order_id}: {$e->getMessage()}", 'invoice_sync' );
            return null;
        }
    }    
}
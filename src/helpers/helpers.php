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
        ACLXeroLogger::log_message('Initializing Xero client.', 'xero_auth');
    
        try {
            // Retrieve stored credentials
            $accessToken = get_option('xero_access_token');
            $refreshToken = get_option('xero_refresh_token');
            $tenantId = get_option('xero_tenant_id');
            $tokenExpires = get_option('xero_token_expires', 0);
    
            // Validate credentials
            if (!$accessToken || !$refreshToken || !$tenantId) {
                throw new \Exception("Missing Xero credentials. Please reauthorize.");
            }
    
            // Refresh token if expired
            if (time() > $tokenExpires) {
                ACLXeroLogger::log_message('Access token expired. Attempting to refresh...', 'xero_auth');
                //$accessToken = self::refresh_access_token($refreshToken);
                $clientId = get_option('acl_xero_consumer_key');
                $clientSecret = get_option('acl_xero_consumer_secret');
    
                if (!$clientId || !$clientSecret) {
                    throw new \Exception("Xero Client ID or Secret is missing. Please configure your settings.");
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
                    update_option('xero_access_token', $accessToken);
                    update_option('xero_refresh_token', $newAccessToken->getRefreshToken());
                    update_option('xero_token_expires', time() + $newAccessToken->getExpires());
    
                    ACLXeroLogger::log_message('Tokens refreshed successfully.', 'xero_auth');
                } catch (\Exception $e) {
                    ACLXeroLogger::log_message('Token refresh failed: ' . $e->getMessage(), 'xero_auth');
                    throw new \Exception("Failed to refresh the Xero access token. Please reauthorize.");
                }                
            }
    
            // Initialize the Xero client
            $xero = new \XeroPHP\Application($accessToken, $tenantId);
    
            // Test client connection
            try {
                $xero->load('Accounting\\Organisation')->execute();
            } catch (\XeroPHP\Remote\Exception $e) {
                // Handle 401 Unauthorized error gracefully
                if (strpos($e->getMessage(), '401 Unauthorized') !== false) {
                    ACLXeroLogger::log_message("Unauthorized access detected: " . $e->getMessage(), 'xero_auth');
                    throw new \Exception("Access token is invalid. Please reauthorize the Xero connection.");
                }
    
                // Re-throw other exceptions
                throw new \Exception("Failed to verify Xero connection: " . $e->getMessage());
            }
    
            ACLXeroLogger::log_message("Xero client initialized successfully with Tenant ID: $tenantId", 'xero_auth');
            return $xero;
    
        } catch (\Exception $e) {
            ACLXeroLogger::log_message("Error initializing Xero client: " . $e->getMessage(), 'xero_auth');
            return new \WP_Error('initialization_error', 'Error initializing Xero client: ' . $e->getMessage());                      
        }
    }
    
    public static function csv_file($filename, $message) {

        /* Check to see if folder for csv's exist. If not create it */

        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $folder_path = $upload_dir . $folder_name;
        
        if (!is_dir($folder_path)) {
            if (mkdir($folder_path, 0755, true)) {
                self::log_message("Create directory $folder_path", 'product_sync');
            } else {
                // Handle the error, e.g., log it
                self::log_message("Failed to create directory $folder_path", 'product_sync');
            }
        } 

        $csv_file = $folder_path .'/'. $filename;

        if (!file_exists($csv_file)) {
            // Write the first line if the file does not exist
            $initial_content = "SKU,Xero Price,WC Price\n"; // Define what should be the first line
            if (file_put_contents($csv_file, $initial_content) === false) {
                self::log_message("Failed to create $csv_file", 'product_sync');
                return; // Exit the function if we couldn't create the file
            }
        }

        if (file_put_contents( $csv_file, $message . "\n", FILE_APPEND ) === false) {
            // Handle error, perhaps log it or throw an exception
            self::log_message( "Failed to write to $csv_file", 'product_sync' );
        }
    }    


    public static function handle_csv_download() {
        check_ajax_referer('download_csv');
        
        $file = $_GET['file'];
        $folder_path = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync';
        $file_path = $folder_path . '/' . $file;
    
        if (file_exists($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
        } else {
            wp_die('File not found or not a CSV.');
        }
        exit;
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
        ACLXeroLogger::log_message("Entering sync ajax", 'product_sync');
       
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
            echo "<div class='notice notice-info'><p>No output from sync process.</p>";
        }
        wp_die(); // This is required to end the AJAX call properly
    }

}
<?php
namespace ACLWcXeroSync\Helpers;
use ACLWcXeroSync\Services\ACLSyncService;
use ACLWcXeroSync\Admin\ACLProductSyncPage;
use ACLWcXeroSync\Helpers\ACLXeroLoggers;

class ACLXeroHelper {


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
        ACLXeroLoggers::log_message("Entering sync ajax", 'product_sync');
       
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
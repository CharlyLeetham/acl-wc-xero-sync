<?php
namespace ACLWcXeroSync\Helpers;

class ACLXeroLogger {

        /**
     * Logs a message to a custom log file.
     *
     * @param string $message The message to log.
     *  'xero_auth' => 'Xero Authorisation',
     *  'xero_connection' => 'Xero Connection for Sync',
     *  'product_sync' => 'Product Sync',
     * 
     */
    public static function log_message($message, $level = 'none') {
        $upload_dir = WP_CONTENT_DIR . '/uploads/';
        $folder_name = 'acl-wc-xero-sync';
        $folder_path = $upload_dir . $folder_name;  
        
        // Log attempt to create directory
        if (!is_dir($folder_path)) {
            error_log("Attempting to create log directory at $folder_path");
            if (mkdir($folder_path, 0755, true)) {
                error_log("Successfully created log directory at $folder_path");
            } else {
                error_log("Failed to create log directory at $folder_path");
            }
        } else {
            error_log("Log directory already exists at $folder_path");
        }         
    
        $log_file = $folder_path . '/acl-xero-sync.log';
        $log_enabled = get_option('acl_xero_log_' . $level, '0') == '1'; // Default to disabled if not set
    
        // Log whether logging is enabled for this level
        if ($log_enabled) {
            error_log("Logging is enabled for level $level");
            $timestamp = current_time('Y-m-d H:i:s');
            if (file_put_contents($log_file, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND) !== false) {
                error_log("Successfully logged message: $message to $log_file");
            } else {
                error_log("Failed to log message: $message to $log_file");
            }
        } else {
            error_log("Logging is disabled for level $level");
        }
    }  
}
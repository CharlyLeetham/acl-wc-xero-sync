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
        
        if (!is_dir($folder_path)) {
            mkdir($folder_path, 0755, true);
        }         

        $log_file = $folder_path .'acl-xero-sync.log';
        error_log ('here', 'info');
        $log_enabled = get_option('acl_xero_log_' . $level, '0') == '1'; // Default to disabled if not set
    
        if ($log_enabled) {
            $timestamp = current_time('Y-m-d H:i:s');
            file_put_contents($log_file, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND);
        }
    }  
}
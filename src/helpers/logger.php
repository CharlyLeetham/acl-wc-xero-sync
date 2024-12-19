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
            $timestamp = current_time('Y-m-d H:i:s');
            if (file_put_contents($log_file, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND) !== false) {
                //
            } else {
                //
            }
        } else {
            //
        }
    } 
    
    public static function log_rotation_init() {
        if (!wp_next_scheduled('acl_xero_log_rotation_event')) {
            wp_schedule_event(time(), 'daily', 'self::acl_xero_log_rotation_event');
        }
    }
      
    public static function log_rotation() {
        $log_file = WP_CONTENT_DIR . '/uploads/acl-wc-xero-sync/acl-xero-sync.log';
        $max_size = 10 * 1024 * 1024; // 10MB
        if (file_exists($log_file) && filesize($log_file) > $max_size) {
            $old_log = $log_file . '.' . date('Y-m-d-H-i-s');
            rename($log_file, $old_log);
            // Here, you might compress or delete old logs
        }
    }    
}
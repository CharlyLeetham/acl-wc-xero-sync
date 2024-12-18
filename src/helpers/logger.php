<?php
namespace ACLWcXeroSync\Loggers;

class ACLXeroLoggers {

        /**
     * Logs a message to a custom log file.
     *
     * @param string $message The message to log.
     */
    public static function log_message($message, $level = 'none') {
        $log_file = WP_CONTENT_DIR . '/uploads/acl-xero-sync.log';
        $log_enabled = get_option('acl_xero_log_' . $level, '0') == '1'; // Default to disabled if not set
    
        if ($log_enabled) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($log_file, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND);
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

}
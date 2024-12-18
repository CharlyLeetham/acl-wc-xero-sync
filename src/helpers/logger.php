<?php
namespace ACLWcXeroSync\Helpers;

class ACLXeroLogger {

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

}
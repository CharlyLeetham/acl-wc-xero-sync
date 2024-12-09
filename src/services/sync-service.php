<?php
namespace ACLWcXeroSync\Services;

use XeroPHP\Application\PrivateApplication;

class ACLSyncService {
    /**
     * Syncs WooCommerce products to Xero by checking their existence only.
     */
    public static function sync_products() {
        try {
            $products = ACLWCService::get_products();
    
            // Ensure Xero client initialization is error-handled
            $xero = new \XeroPHP\Application\PrivateApplication([
                'oauth' => [
                    'consumer_key'    => get_option( 'acl_xero_consumer_key' ),
                    'consumer_secret' => get_option( 'acl_xero_consumer_secret' ),
                    'token'           => get_option( 'xero_access_token' ),
                    'token_secret'    => get_option( 'xero_refresh_token' ),
                ],
            ]);
    
            foreach ( $products as $product ) {
                try {
                    // Skip if no SKU
                    if ( empty( $product['sku'] ) ) {
                        self::log_message( "Product [ID: {$product['id']}] skipped: Missing SKU." );
                        echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
                        continue;
                    }
    
                    $sku = $product['sku'];
    
                    // Check if SKU exists in Xero
                    $existing_items = $xero->load( 'Accounting\\Item' )
                                           ->where( 'Code', $sku )
                                           ->execute();
    
                    if ( ! empty( $existing_items ) ) {
                        self::log_message( "Product [SKU: {$sku}] exists in Xero." );
                        echo "<div class='notice notice-info'><p>Product SKU <strong>{$sku}</strong> exists in Xero.</p></div>";
                    } else {
                        self::log_message( "Product [SKU: {$sku}] does not exist in Xero." );
                        echo "<div class='notice notice-warning'><p>Product SKU <strong>{$sku}</strong> does not exist in Xero.</p></div>";
                    }
                } catch ( \Exception $e ) {
                    self::log_message( "Error checking product [SKU: {$sku}]: {$e->getMessage()}" );
                    echo "<div class='notice notice-error'><p>Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}</p></div>";
                }
            }
        } catch ( \Exception $e ) {
            // Log and handle general exceptions
            self::log_message( "Fatal error in sync process: {$e->getMessage()}" );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";
        }
    }

    /**
     * Logs a message to a custom log file.
     *
     * @param string $message The message to log.
     */
    private static function log_message( $message ) {
        $log_file = WP_CONTENT_DIR . '/uploads/acl-xero-sync.log';
        $timestamp = date( 'Y-m-d H:i:s' );
        file_put_contents( $log_file, "[{$timestamp}] {$message}\n", FILE_APPEND );
    }
}

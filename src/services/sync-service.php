<?php
/**
 * Handles syncing WooCommerce products with Xero.
 */

namespace ACLWcXeroSync\Services;

class ACLSyncService {
    /**
     * Syncs WooCommerce products with Xero by checking their existence.
     */
    public static function sync_products() {
        try {
            // Step 1: Fetch WooCommerce Products
            $products = self::get_wc_products();
            if ( empty( $products ) ) {
                self::log_message( 'No products found in WooCommerce.' );
                echo "<div class='notice notice-warning'><p>No products found in WooCommerce.</p></div>";
                return;
            }

            self::log_message( count( $products ) . ' products fetched from WooCommerce.' );

            // Step 2: Initialize Xero Client
            $client_id = get_option( 'acl_xero_consumer_key' );
            $client_secret = get_option( 'acl_xero_consumer_secret' );

            if ( empty( $client_id ) || empty( $client_secret ) ) {
                throw new \Exception( 'Missing Xero Consumer Key or Secret. Please update the settings.' );
            }

            $xero = self::initialize_xero_client( $client_id, $client_secret );

            // Step 3: Process Each Product
            foreach ( $products as $product ) {
                self::process_product( $xero, $product );
            }
        } catch ( \Exception $e ) {
            self::log_message( 'Fatal error in sync process: ' . $e->getMessage() );
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";
        }
    }

    /**
     * Initializes the Xero client using Consumer Key and Secret.
     *
     * @param string $client_id The Consumer Key.
     * @param string $client_secret The Consumer Secret.
     * @return \XeroPHP\Application
     */
    private static function initialize_xero_client( $client_id, $client_secret ) {
        echo $client_id." ".$client_secret;
        try {
            $config = [
                'oauth' => [
                    'consumer_key'    => $client_id,
                    'consumer_secret' => $client_secret,
                ],
            ];

            $xero = new \XeroPHP\Application($client_id, $client_secret);
            return $xero;

            // Instantiate Xero client
            //return new \XeroPHP\Application($config);
        } catch ( \Exception $e ) {
            self::log_message( 'Error initializing Xero client: ' . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Processes a single product, checking its existence in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param array $product
     */
    private static function process_product( $xero, $product ) {
        if ( empty( $product['sku'] ) ) {
            self::log_message( "Product [ID: {$product['id']}] skipped: Missing SKU." );
            echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
            return;
        }

        $sku = $product['sku'];

        try {
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists( $xero, $sku );

            if ( $exists ) {
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

    /**
     * Checks if a product SKU exists in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param string $sku
     * @return bool
     */
    private static function check_if_sku_exists( $xero, $sku ) {
        try {
            $existing_items = $xero->load( 'Accounting\\Item' )
                                   ->where( 'Code', $sku );
          
            self::log_message($request);
            
            $existing_items = $query->execute();                                   
            return ! empty( $existing_items );
        } catch ( \Exception $e ) {
            self::log_message( "Error querying Xero for SKU {$sku}: {$e->getMessage()}" );
            throw $e;
        }
    }

    /**
     * Fetches products from WooCommerce using a direct database query.
     *
     * @return array List of WooCommerce products.
     */
    private static function get_wc_products() {
        global $wpdb;

        $query = "
            SELECT p.ID as id, pm.meta_value as sku
            FROM {$wpdb->prefix}posts p
            JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_key = '_sku'
        ";

        return $wpdb->get_results( $query, ARRAY_A ) ?: [];
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

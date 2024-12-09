<?php

namespace ACLWcXeroSync\Services;

use XeroPHP\Remote\Exception\NotFoundException;

class ACLSyncService {
    /**
     * Syncs WooCommerce products to Xero by checking their existence only.
     */
    public static function sync_products() {
        try {
            // Step 1: Fetch WooCommerce Products
            $products = ACLWCService::get_products();
            self::log_message(count($products) . " products fetched from WooCommerce.");

            // Step 2: Initialize Xero Client
            $xero = self::initialize_xero_client();
            if (!$xero) {
                throw new \Exception("Failed to initialize Xero client.");
            }

            // Step 3: Process Each Product
            foreach ($products as $product) {
                self::process_product($xero, $product);
            }
        } catch (\Exception $e) {
            self::log_message("Fatal error in sync process: {$e->getMessage()}");
            echo "<div class='notice notice-error'><p>Fatal error: {$e->getMessage()}</p></div>";
        }
    }

    /**
     * Initializes the Xero client with OAuth credentials.
     *
     * @return \XeroPHP\Application|false
     */
    private static function initialize_xero_client() {
        try {
            $config = [
                'oauth' => [
                    'consumer_key' => get_option('acl_xero_consumer_key'),
                    'consumer_secret' => get_option('acl_xero_consumer_secret'),
                    'token' => get_option('xero_access_token'),
                    'token_secret' => get_option('xero_refresh_token'),
                ],
            ];
            return new \XeroPHP\Application($config);
        } catch (\Exception $e) {
            self::log_message("Error initializing Xero client: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Processes a single product, checking its existence in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param array $product
     */
    private static function process_product($xero, $product) {
        if (!self::validate_product($product)) {
            return;
        }

        $sku = $product['sku'];

        try {
            // Check if SKU exists in Xero
            $exists = self::check_if_sku_exists($xero, $sku);

            if ($exists) {
                self::log_message("Product [SKU: {$sku}] exists in Xero.");
                echo "<div class='notice notice-info'><p>Product SKU <strong>{$sku}</strong> exists in Xero.</p></div>";
            } else {
                self::log_message("Product [SKU: {$sku}] does not exist in Xero.");
                echo "<div class='notice notice-warning'><p>Product SKU <strong>{$sku}</strong> does not exist in Xero.</p></div>";
            }
        } catch (\Exception $e) {
            self::log_message("Error checking product [SKU: {$sku}]: {$e->getMessage()}");
            echo "<div class='notice notice-error'><p>Error checking product SKU <strong>{$sku}</strong>: {$e->getMessage()}</p></div>";
        }
    }

    /**
     * Validates a WooCommerce product to ensure it has a valid SKU.
     *
     * @param array $product
     * @return bool
     */
    private static function validate_product($product) {
        if (empty($product['sku'])) {
            self::log_message("Product [ID: {$product['id']}] skipped: Missing SKU.");
            echo "<div class='notice notice-error'><p>Product [ID: {$product['id']}] skipped: Missing SKU.</p></div>";
            return false;
        }
        return true;
    }

    /**
     * Checks if a product SKU exists in Xero.
     *
     * @param \XeroPHP\Application $xero
     * @param string $sku
     * @return bool
     */
    private static function check_if_sku_exists($xero, $sku) {
        try {
            $existing_items = $xero->load('Accounting\\Item')
                ->where('Code', $sku)
                ->execute();
            return !empty($existing_items);
        } catch (NotFoundException $e) {
            // Not found exception means the SKU doesn't exist
            return false;
        } catch (\Exception $e) {
            self::log_message("Error querying Xero for SKU {$sku}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Logs a message to a custom log file.
     *
     * @param string $message The message to log.
     */
    private static function log_message($message) {
        $log_file = WP_CONTENT_DIR . '/uploads/acl-xero-sync.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
